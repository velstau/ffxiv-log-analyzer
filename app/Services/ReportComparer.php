<?php

namespace App\Services;

use App\Support\MitigationSpec;
use App\Support\TimelineJobMeta;
use Illuminate\Support\Facades\Cache;

/**
 * 同じボスの2つのログを突き合わせて、フェーズ別・分別に火力とローテーションの差を出す。
 *
 * 画面（FFLogsController::compare / rangeStats）はこのサービスを呼ぶだけで、
 * 「どのイベントを取ってきて何を数えるか」はここに閉じている。
 *
 * 用語:
 *   side    … 比較する片側のログ1本ぶんの集計結果
 *   loss    … 被弾・死亡・ダメージ低下などで失った火力の内訳
 *   advice  … A/B の差から出す「どこで差がついたか」のまとめ
 *
 * 集計の考え方:
 *
 *  - **レート化の分母は壁時計ではなく activeTime（各プレイヤーの実稼働時間）を使う。**
 *    壁時計だとフェーズ内の不稼働時間ぶん低く出て、FFLogs の公式表示とズレる。
 *    パーティ合計だけは戦闘の combatTime を分母にする。
 *  - **「なぜ火力が出ていないか」を分解して出す。** DPSの数字だけ並べても改善点は分からない。
 *    被弾・死亡・ダメージ低下デバフで失った量を {@see buildLossContext()} で内訳にする。
 *  - **ダメージ低下デバフの低下率はログに出ない。** コンテンツごとに異なるため
 *    {@see MitigationSpec::damageDownRate()} の想定値で概算する。
 */
class ReportComparer
{
    /** 薬(強化薬/ジェムドラフト)使用中のダメージ倍率の想定値。上乗せDPS概算に使用（+8%想定）。 */
    private const POTION_MULT = 1.08;

    /** フェーズ内 rDPS推移グラフのバケット幅（秒）。 */
    private const SERIES_BUCKET_SEC = 10.0;

    /** 末尾バケットがこの秒数未満なら手前のバケットに併合する（数秒間の値はスパイクになり意味が薄い）。 */
    private const SERIES_TAIL_MIN_SEC = 4.0;

    public function __construct(private readonly FFLogsService $fflogs) {}

    /**
     * DamageDoneテーブルを指定窓で集計（パーティ総ダメ・DPS・rDPS・aDPS、プレイヤー別）。
     * FFLogsと一致させるため、レート化の分母は「各プレイヤーの activeTime（実稼働時間）」を使う。
     * （壁時計だとフェーズ内の不稼働時間ぶん低く出る＝公式値とズレる）。
     * パーティ合計は戦闘 active(combatTime) を分母にする。
     */
    private function summarizeDamageTable(array $table, float $startMs, float $endMs): array
    {
        $wallSec = max(1.0, ($endMs - $startMs) / 1000);
        // FFLogsのDPS/rDPS/aDPS分母は「ボスが攻撃可能だった実時間」= combatTime − damageDowntime（全員共通）。
        // 例: combatTime208.373s − downtime10.364s = 198.009s → total/198.009 が公式DPSと一致。
        $denomSec = (($table['combatTime'] ?? 0) - ($table['damageDowntime'] ?? 0)) / 1000;
        if ($denomSec < 1.0) {
            $denomSec = $wallSec;
        } // フォールバック
        $totalTimeMs = (float) ($table['totalTime'] ?? 0);
        if ($totalTimeMs < 1.0) {
            $totalTimeMs = $wallSec * 1000;
        }
        $players = [];
        $partyTotal = 0.0;
        $partyRdpsTotal = 0.0;
        $partyAdpsTotal = 0.0;
        foreach ($table['entries'] ?? [] as $e) {
            $type = $e['type'] ?? '';
            $total = (float) ($e['total'] ?? 0);
            $rdps = (float) ($e['totalRDPS'] ?? $total);
            $adps = (float) ($e['totalADPS'] ?? $total);
            $activePct = round((float) ($e['activeTime'] ?? 0) / $totalTimeMs * 100, 2);
            $meta = TimelineJobMeta::of($type);
            $players[] = [
                'name' => $e['name'] ?? '?', 'job' => $meta['abbr'], 'role' => $meta['role'],
                'color' => $meta['color'], 'roleOrder' => $meta['roleOrder'], 'iconFile' => $meta['iconFile'],
                'total' => $total, 'dps' => $total / $denomSec, 'rdps' => $rdps / $denomSec, 'adps' => $adps / $denomSec,
                'activePct' => $activePct,
            ];
            $partyTotal += $total;
            $partyRdpsTotal += $rdps;
            $partyAdpsTotal += $adps;
        }
        usort($players, fn($a, $b) => $b['rdps'] <=> $a['rdps']);
        return [
            'sec' => $wallSec,
            'combatSec' => $denomSec,
            'partyTotal' => $partyTotal,
            'partyDps' => $partyTotal / $denomSec,
            'partyRdps' => $partyRdpsTotal / $denomSec,
            'partyAdps' => $partyAdpsTotal / $denomSec,
            'players' => $players,
        ];
    }

    /**
     * 敵別被ダメージテーブル（getEnemyDamageTable の生エントリ）を「敵名 => 被ダメ合計」に整形。
     * 同名の複数体（分裂体など）は名前で合算。被ダメ0はボスとして意味が薄いので除外。
     * @return array{total: float, enemies: array<int, array{name:string,type:string,icon:?string,total:float}>}
     */
    private function summarizeEnemyTable(array $entries): array
    {
        $byName = [];
        $sum = 0.0;
        foreach ($entries as $e) {
            $total = (float) ($e['total'] ?? 0);
            if ($total <= 0) {
                continue;
            }
            $name = $e['name'] ?? '?';
            if (!isset($byName[$name])) {
                $byName[$name] = ['name' => $name, 'type' => $e['type'] ?? '', 'icon' => $e['icon'] ?? null, 'total' => 0.0];
            }
            $byName[$name]['total'] += $total;
            $sum += $total;
        }
        $enemies = array_values($byName);
        usort($enemies, fn($a, $b) => $b['total'] <=> $a['total']);
        return ['total' => $sum, 'enemies' => $enemies];
    }

    /**
     * A/Bのプレイヤーを対戦ペアに組む（①同ジョブ優先 → ②残りは同ロール → ③余りは単独）。
     * blade側の個人別比較と同じ規則。アドバイス生成で再利用する。
     * @return array<int, array{a:?array,b:?array,role:bool}>
     */
    private function pairPlayers(array $aList, array $bList): array
    {
        $usedA = [];
        $usedB = [];
        $rows = [];
        foreach ($aList as $ai => $a) {
            foreach ($bList as $bi => $b) {
                if (isset($usedA[$ai]) || isset($usedB[$bi])) {
                    continue;
                }
                if ($a['job'] === $b['job']) {
                    $usedA[$ai] = $usedB[$bi] = true;
                    $rows[] = ['a' => $a, 'b' => $b, 'role' => false];
                    break;
                }
            }
        }
        foreach ($aList as $ai => $a) {
            if (isset($usedA[$ai])) {
                continue;
            } foreach ($bList as $bi => $b) {
                if (isset($usedB[$bi])) {
                    continue;
                }
                if ($a['role'] === $b['role']) {
                    $usedA[$ai] = $usedB[$bi] = true;
                    $rows[] = ['a' => $a, 'b' => $b, 'role' => true];
                    break;
                }
            }
        }
        foreach ($aList as $ai => $a) {
            if (!isset($usedA[$ai])) {
                $rows[] = ['a' => $a, 'b' => null, 'role' => false];
            }
        }
        foreach ($bList as $bi => $b) {
            if (!isset($usedB[$bi])) {
                $rows[] = ['a' => null, 'b' => $b, 'role' => false];
            }
        }
        return $rows;
    }

    /**
     * フェーズ単位で「DPSが不足している側」を特定し、ルールベースの改善アドバイスを生成する。
     * 使う材料はすべて既存の集計値（パーティrDPS・個人rDPS・稼働率activePct・薬使用）で、推測は最小限。
     *
     * @param  array|null $as Aサイドのフェーズsummary（partyRdps, players[]）
     * @param  array|null $bs Bサイドのフェーズsummary
     * @return array{low:?string, high:?string, gap:float, gapPct:float, tips:array<int,string>}|null
     */
    public function buildPhaseAdvice(?array $as, ?array $bs): ?array
    {
        if (!$as || !$bs) {
            return null;
        } // 片側にしか無いフェーズは比較不能
        $ard = (float) ($as['partyRdps'] ?? 0);
        $brd = (float) ($bs['partyRdps'] ?? 0);
        if ($ard <= 0 || $brd <= 0) {
            return null;
        }

        $gap = abs($brd - $ard);
        $gapPct = $gap / max($ard, $brd) * 100;
        $low = $brd < $ard ? 'B' : 'A';
        $high = $low === 'A' ? 'B' : 'A';

        // パーティ差が小さければ「互角」で終える（誤差レベルに助言しない）
        if ($gapPct < 1.5) {
            return ['low' => null, 'high' => null, 'gap' => $gap, 'gapPct' => $gapPct, 'tips' => []];
        }

        $lowList = $low === 'A' ? ($as['players'] ?? []) : ($bs['players'] ?? []);
        $hiList  = $low === 'A' ? ($bs['players'] ?? []) : ($as['players'] ?? []);
        $pairs = $this->pairPlayers($lowList, $hiList); // a=不足側, b=優勢側

        // 不足側の各人が相方に対してどれだけ遅れているかを集計
        $laggards = [];
        foreach ($pairs as $p) {
            $lp = $p['a'];
            $hp = $p['b'];
            if (!$lp || !$hp) {
                continue;
            }
            $delta = (float) $hp['rdps'] - (float) $lp['rdps']; // 正＝不足側が遅れている
            if ($delta <= 0) {
                continue;
            }
            $laggards[] = [
                'job' => $lp['job'], 'name' => $lp['name'], 'delta' => $delta, 'approx' => $p['role'],
                'lowActive' => (float) ($lp['activePct'] ?? 0), 'hiActive' => (float) ($hp['activePct'] ?? 0),
                'lowPotion' => !empty($lp['potion']), 'hiPotion' => !empty($hp['potion']),
                'lowDeaths' => (int) ($lp['deathsHarm'] ?? 0), 'hiDeaths' => (int) ($hp['deathsHarm'] ?? 0),
                'lowDd' => (float) ($lp['ddSec'] ?? 0), 'hiDd' => (float) ($hp['ddSec'] ?? 0),
                'lowWeak' => (float) ($lp['weakSec'] ?? 0), 'hiWeak' => (float) ($hp['weakSec'] ?? 0),
            ];
        }
        usort($laggards, fn($a, $b) => $b['delta'] <=> $a['delta']);

        $fmt = fn($n) => number_format((int) round($n));
        $tips = [];

        // パーティ全体の死亡数差（不足側の方が多ければ最初に指摘：立て直しが最優先のため）
        // deathsHarm＝戦闘終了間際（実害なし）を除いた死亡数
        $lowDeathsTotal = array_sum(array_map(fn($p) => (int) ($p['deathsHarm'] ?? 0), $lowList));
        $hiDeathsTotal  = array_sum(array_map(fn($p) => (int) ($p['deathsHarm'] ?? 0), $hiList));
        if ($lowDeathsTotal > $hiDeathsTotal) {
            $tips[] = "このフェーズの死亡：{$low}側 {$lowDeathsTotal}回 vs 相手 {$hiDeathsTotal}回。まずギミック処理の安定化が火力改善より優先";
        }

        // 想定回復分：死亡・衰弱/頽廃・ダメ低下が無ければ戻るrDPSの推定（相手側の同ロスとの差引で差の説明率も出す）
        $recOf = fn($list) => array_sum(array_map(fn($p) => max(0.0, ($p['potentialRdps'] ?? $p['rdps']) - $p['rdps']), $list));
        $lowRec = $recOf($lowList);
        $hiRec = $recOf($hiList);
        $netRec = $lowRec - $hiRec;
        if ($lowRec > 0 && $netRec > $gap * 0.1) {
            $pct = min(999, (int) round($netRec / $gap * 100));
            $tips[] = "死亡・衰弱・ダメージ低下が無ければ想定 +{$fmt($lowRec)} rDPS 回復（相手側ロス差引でパーティ差の約{$pct}%を説明）";
        }

        // 遅れの大きい上位3名に、原因の手掛かり（死亡・デバフ・稼働率・薬）を添える
        foreach (array_slice($laggards, 0, 3) as $lg) {
            if ($lg['delta'] < 200) {
                continue;
            } // 200 rDPS未満の差は指摘しない（誤差）
            $line = "{$lg['job']}（{$lg['name']}）が相手より -{$fmt($lg['delta'])} rDPS";
            $reasons = [];
            // 死亡・与ダメ低下デバフを優先して指摘（稼働率低下の「原因」であることが多い）
            if ($lg['lowDeaths'] > $lg['hiDeaths']) {
                $reasons[] = "死亡{$lg['lowDeaths']}回（相手{$lg['hiDeaths']}回）";
            }
            if ($lg['lowWeak'] - $lg['hiWeak'] >= 10.0) {
                $reasons[] = "衰弱/頽廃 " . round($lg['lowWeak']) . "秒（死亡復帰ペナルティで火力減）";
            }
            if ($lg['lowDd'] >= 5.0 && $lg['lowDd'] - $lg['hiDd'] >= 3.0) {
                $reasons[] = "ダメージ低下デバフ " . round($lg['lowDd']) . "秒（ギミックミス被弾）";
            }
            if ($lg['hiActive'] - $lg['lowActive'] >= 2.0) {
                $reasons[] = "稼働率 {$fmt($lg['lowActive'])}% ＜ 相手 {$fmt($lg['hiActive'])}%（手が止まっている可能性）";
            }
            if ($lg['hiPotion'] && !$lg['lowPotion']) {
                $reasons[] = "このフェーズで薬未使用（相手は使用）";
            }
            if ($lg['approx']) {
                $reasons[] = "※ジョブ不一致のため同ロール概算比較";
            }
            if ($reasons) {
                $line .= '：' . implode('／', $reasons);
            }
            $tips[] = $line;
        }

        // 個人差で説明しきれない残差はパーティ全体要因（バフ足並み・全体停止時間など）として明示
        $explained = array_sum(array_map(fn($l) => $l['delta'], array_slice($laggards, 0, 3)));
        if ($gap - $explained > max(500, $gap * 0.3)) {
            $tips[] = "上記だけでは説明しきれない差 約 -{$fmt($gap - $explained)} rDPS：全員の火力窓（バースト）の足並みや全体の手止まりを確認";
        }
        if (empty($tips)) {
            $tips[] = "個人差は小さく、僅差。バースト同時押しの精度で埋められる範囲";
        }

        return ['low' => $low, 'high' => $high, 'gap' => $gap, 'gapPct' => $gapPct, 'tips' => $tips];
    }

    /** 比較1サイド分のデータ構築（フェーズ窓・分窓のテーブル＋全ローテ） */
    public function buildCompareSide(string $code, int $fightId, string $label): ?array
    {
        $fight = $this->fflogs->getFightDetails($code, $fightId);
        if (!$fight) {
            return null;
        }
        $st = (float) $fight['startTime'];
        $et = (float) $fight['endTime'];
        $durMs = $et - $st;

        // フェーズ窓
        $pts = $fight['phaseTransitions'] ?? [];
        usort($pts, fn($a, $b) => ($a['startTime'] ?? 0) <=> ($b['startTime'] ?? 0));
        $phases = [];
        for ($i = 0; $i < count($pts); $i++) {
            $pStart = (float) $pts[$i]['startTime'];
            $pEnd = ($i + 1 < count($pts)) ? (float) $pts[$i + 1]['startTime'] : $et;
            $phases[] = ['id' => $pts[$i]['id'], 'start' => $pStart, 'end' => $pEnd];
        }

        $master = $this->fflogs->getMasterData($code);
        $actors = collect($master['actors'] ?? [])->keyBy('id');
        $abilities = collect($master['abilities'] ?? [])->keyBy('gameID');

        // 想定ダメージ算出の素材（薬・死亡・与ダメ低下デバフ）をまとめて収集
        $loss = $this->buildLossContext($code, $fightId, $st, $et, $actors, $abilities);
        $potionByPlayer = $loss['potionByPlayer'];
        $potionIconByPlayer = $loss['potionIconByPlayer'];
        $deathsByPlayer = $loss['deathsByPlayer'];
        $dmgDebuffSegs = $loss['dmgDebuffSegs'];
        $ddIconByPlayer = $loss['ddIconByPlayer'];
        $deadSegs = $loss['deadSegs'];
        $lossCtx = $loss;

        // クローズドポジション［被］(status 1824) のパートナーと稼働区間。誰に付いていたかをフェーズ別に出す。
        $cpEvents = $this->fflogs->getBuffEventsByStatus($code, $fightId, $st, $et, 1001824);
        usort($cpEvents, fn($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));
        $cpSegments = [];
        $cur = null;
        foreach ($cpEvents as $ev) {
            $tgt = $actors->get($ev['targetID'] ?? 0);
            if (!$tgt) {
                continue;
            }
            $tname = $tgt['name'] ?? '?';
            $sname = $actors->get($ev['sourceID'] ?? 0)['name'] ?? '';
            $t = $ev['timestamp'] ?? 0;
            $type = $ev['type'] ?? '';
            if ($type === 'removebuff') {
                if ($cur && $cur['tgt'] === $tname) {
                    $cur['end'] = $t;
                    $cpSegments[] = $cur;
                    $cur = null;
                }
                continue;
            }
            if ($cur === null) {
                $cur = ['tgt' => $tname, 'src' => $sname, 'start' => (empty($cpSegments) ? $st : $t), 'end' => $t];
            } elseif ($cur['tgt'] === $tname) {
                $cur['end'] = $t;
            } else { // 相方変更
                $cur['end'] = $t;
                $cpSegments[] = $cur;
                $cur = ['tgt' => $tname, 'src' => $sname, 'start' => $t, 'end' => $t];
            }
        }
        if ($cur) {
            $cur['end'] = $et;
            $cpSegments[] = $cur;
        } // 最後まで継続

        // 全体・フェーズ別テーブル
        $overallTable = $this->fflogs->getDamageTable($code, $fightId, $st, $et);
        $overall = $this->summarizeDamageTable($overallTable, $st, $et);
        // 推移グラフ用に与ダメ生イベントを集計する対象アクターID（プレイヤー＋そのペット）。
        // FFLogsはLBの合成アクター「Multiple Players」をDamageDoneテーブルから除外するため、
        // 生イベントを無条件に足すとその分（実測で数%）テーブル値より過大になる。
        // テーブルに載っているアクターに限定して、グラフとフェーズ集計の基準を揃える。
        $dmgSourceIds = [];
        foreach ($overallTable['entries'] ?? [] as $e) {
            if (isset($e['id'])) {
                $dmgSourceIds[(int) $e['id']] = true;
            }
            foreach ($e['pets'] ?? [] as $pet) {
                if (isset($pet['id'])) {
                    $dmgSourceIds[(int) $pet['id']] = true;
                }
            }
        }
        // 全体の敵別被ダメージ（どのボス/雑魚にどれだけ入れたか）
        $overall['enemies'] = $this->summarizeEnemyTable($this->fflogs->getEnemyDamageTable($code, $fightId, $st, $et))['enemies'];

        // このコンテンツのダメージ低下の低下率（想定ダメージ算出用。絶妖星乱舞=-90%等）
        $ddr = MitigationSpec::damageDownRate($fight['name'] ?? '', $fight['gameZone']['name'] ?? '');

        // 与ダメ生イベント（フェーズ内rDPS推移グラフ・範囲指定集計用）。時刻順ソート済み想定だが念のため。
        $dmgEvents = $this->fflogs->getDamageDoneEvents($code, $fightId, $st, $et);

        // 敵キャスト（推移グラフに「その時点で来ている敵の攻撃」を重ねる用）。
        $enemyCastEvents = $this->buildEnemyCastMarks($code, $fightId, $st, $et, $actors, $abilities);

        $phaseSummaries = [];
        foreach ($phases as $ph) {
            $t = $this->fflogs->getDamageTable($code, $fightId, $ph['start'], $ph['end']);
            $summary = $this->summarizeDamageTable($t, $ph['start'], $ph['end']);
            $phasePotionGain = $this->enrichPlayerLosses(
                $summary,
                (float) $ph['start'],
                (float) $ph['end'],
                (float) $et,
                $lossCtx,
                $ddr,
                true,
                $code,
                $fightId,
            );
            // このフェーズのクローズドポジション付与先と稼働率
            $phaseDur = max(1, $ph['end'] - $ph['start']);
            $cpAgg = [];
            foreach ($cpSegments as $sg) {
                $ov = min($sg['end'], $ph['end']) - max($sg['start'], $ph['start']);
                if ($ov <= 0) {
                    continue;
                }
                if (!isset($cpAgg[$sg['tgt']])) {
                    $cpAgg[$sg['tgt']] = ['name' => $sg['tgt'], 'src' => $sg['src'], 'ms' => 0];
                }
                $cpAgg[$sg['tgt']]['ms'] += $ov;
            }
            $cpList = [];
            foreach ($cpAgg as $c) {
                $cpList[] = ['name' => $c['name'], 'src' => $c['src'], 'pct' => round($c['ms'] / $phaseDur * 100)];
            }
            usort($cpList, fn($a, $b) => $b['pct'] <=> $a['pct']);

            // このフェーズで各敵に入った総ダメージ（ボス別内訳）
            $phaseEnemies = $this->summarizeEnemyTable($this->fflogs->getEnemyDamageTable($code, $fightId, $ph['start'], $ph['end']))['enemies'];

            // パーティ想定rDPS（死亡・衰弱・ダメ低下が無かった場合。個人の想定rDPSの合計）
            $summary['partyPotentialRdps'] = array_sum(array_map(
                fn($p) => $p['potentialRdps'] ?? $p['rdps'],
                $summary['players'],
            ));

            // フェーズ内のパーティrDPS推移（t=バケット終端秒, v=バケット内DPS）。
            // パーティ合計のrDPSはraw合計と一致する（シナジー配分はパーティ内ゼロサム）ため、
            // バケット合計÷秒数がそのままパーティrDPSになる。
            $phDurSec = ($ph['end'] - $ph['start']) / 1000;
            $bw = self::SERIES_BUCKET_SEC;
            $bounds = [];
            for ($bs = 0.0; $bs < $phDurSec; $bs += $bw) {
                $bounds[] = [$bs, min($phDurSec, $bs + $bw)];
            }
            $nB = count($bounds);
            if ($nB > 1 && ($bounds[$nB - 1][1] - $bounds[$nB - 1][0]) < self::SERIES_TAIL_MIN_SEC) {
                $bounds[$nB - 2][1] = $bounds[$nB - 1][1];
                array_pop($bounds);
                $nB--;
            }
            $bucketDmg = array_fill(0, $nB, 0.0);
            // 1秒毎のパーティ与ダメ（グラフ上でドラッグ選択した任意範囲のrDPSをブラウザ側で即時に出すため）。
            $nSec = max(1, (int) ceil($phDurSec));
            $secDmg = array_fill(0, $nSec, 0.0);
            foreach ($dmgEvents as $de) {
                $ts = $de['timestamp'] ?? 0;
                if ($ts < $ph['start'] || $ts >= $ph['end']) {
                    continue;
                }
                // テーブルに載っていないアクター（LBの合成アクター等）は除外。IDが取れない場合は従来どおり全件。
                if ($dmgSourceIds && !isset($dmgSourceIds[(int) ($de['sourceID'] ?? 0)])) {
                    continue;
                }
                $rel = ($ts - $ph['start']) / 1000;
                $amt = (float) ($de['amount'] ?? 0);
                $idx = (int) ($rel / $bw);
                if ($idx >= $nB) {
                    $idx = $nB - 1;
                } // 併合した末尾バケット
                $bucketDmg[$idx] += $amt;
                $si = (int) $rel;
                if ($si >= $nSec) {
                    $si = $nSec - 1;
                }
                $secDmg[$si] += $amt;
            }
            $series = [];
            foreach ($bounds as $bi => [$bs, $be]) {
                $series[] = ['t' => (int) round($be), 'v' => (int) round($bucketDmg[$bi] / max(1.0, $be - $bs))];
            }
            // フェーズ末尾の無ダメージ区間（ボス撃破〜次フェーズ開始の遷移時間）は点を打たない
            while (count($series) > 1 && end($series)['v'] <= 0) {
                array_pop($series);
            }

            // このフェーズ内の敵キャスト（フェーズ開始からの相対秒に変換）
            $phaseCasts = [];
            foreach ($enemyCastEvents as $ec) {
                if ($ec['ts'] < $ph['start'] || $ec['ts'] >= $ph['end']) {
                    continue;
                }
                $phaseCasts[] = [
                    't' => round(($ec['ts'] - $ph['start']) / 1000, 1),
                    'n' => $ec['name'],
                    's' => $ec['src'],
                ];
            }

            $phaseSummaries[] = [
                'id' => $ph['id'],
                'startRel' => ($ph['start'] - $st) / 1000,
                'endRel' => ($ph['end'] - $st) / 1000,
                'summary' => $summary,
                'potionGainDps' => $phasePotionGain,
                'closedPos' => $cpList,
                'enemies' => $phaseEnemies,
                'series' => $series,
                'secDmg' => array_map(fn($v) => (int) round($v), $secDmg), // 1秒毎パーティ与ダメ（範囲指定集計用）
                'startMs' => $ph['start'], // 絶対時刻（範囲指定の個人別rDPS再集計APIに渡す）
                'endMs' => $ph['end'],
                'enemyCasts' => $phaseCasts,
            ];
        }

        return [
            'label' => $label,
            'code' => $code,
            'fightId' => $fightId,
            'name' => $fight['name'] ?? 'Unknown',
            'zoneName' => $fight['gameZone']['name'] ?? '',
            'durationSec' => $durMs / 1000,
            'hasPhases' => count($phases) > 1,
            'overall' => $overall,
            'phases' => $phaseSummaries,
        ];
    }

    /**
     * 「想定ダメージ（死亡・衰弱/頽廃・ダメージ低下が無かった場合）」の算出に必要な素材を戦闘全体ぶん集める。
     * フェーズ集計と、グラフで選択した任意範囲の再集計（rangeStats）の両方から使う。
     *
     * @return array{potionByPlayer:array, potionIconByPlayer:array, deathsByPlayer:array,
     *               dmgDebuffSegs:array, ddIconByPlayer:array, deadSegs:array}
     */
    private function buildLossContext($code, $fightId, float $st, float $et, $actors, $abilities): array
    {
        // 薬（Medicated=49）使用：プレイヤー名 => [使用時刻(絶対ms)]
        $potionByPlayer = [];
        foreach ($this->fflogs->getBuffApplies($code, $fightId, $st, $et, 49) as $ev) {
            $tgt = $actors->get($ev['targetID'] ?? 0);
            if (!$tgt || ($tgt['type'] ?? '') !== 'Player') {
                continue;
            } // ペット除外
            $potionByPlayer[$tgt['name']][] = $ev['timestamp'];
        }

        // 薬の実アイコン（◯◯の宝薬/ジェムドラフト）を詠唱から取得。薬詠唱はアイテムアイコン(020xxx)。
        // 各薬使用時刻の直前(±)に同プレイヤーが撃ったアイテム詠唱を薬とみなす。プレイヤー名=>iconファイル。
        $potionIconByPlayer = [];
        if (!empty($potionByPlayer)) {
            $casts = $this->fflogs->getAllFriendCasts($code, $fightId, $st, $et);
            foreach ($casts as $c) {
                $sid = $c['sourceID'] ?? 0;
                $actor = $actors->get($sid);
                if (!$actor || ($actor['type'] ?? '') !== 'Player') {
                    continue;
                }
                $name = $actor['name'] ?? '';
                if (isset($potionIconByPlayer[$name]) || empty($potionByPlayer[$name])) {
                    continue;
                }
                // 薬(アイテム詠唱)のIDは34xxxxxx等の巨大IDで masterData は生IDで保持。生ID優先で照合。
                $rawId = (int) ($c['abilityGameID'] ?? 0);
                $ic = $abilities->get($rawId)['icon'] ?? ($abilities->get($rawId % 1000000)['icon'] ?? '');
                if (strpos((string) $ic, '020') !== 0) {
                    continue;
                } // アイテムアイコンのみ（薬）
                // 薬使用時刻の近傍か
                foreach ($potionByPlayer[$name] as $pts) {
                    if (abs($c['timestamp'] - $pts) <= 3000) {
                        $potionIconByPlayer[$name] = $ic;
                        break;
                    }
                }
            }
        }

        // 死亡イベント：プレイヤー名 => [死亡時刻(絶対ms)]。ペット等は除外。
        $deathsByPlayer = [];
        foreach ($this->fflogs->getDeathEvents($code, $fightId, $st, $et) as $ev) {
            $tgt = $actors->get($ev['targetID'] ?? 0);
            if (!$tgt || ($tgt['type'] ?? '') !== 'Player') {
                continue;
            }
            $deathsByPlayer[$tgt['name']][] = $ev['timestamp'] ?? 0;
        }

        // 与ダメ低下系デバフの稼働区間：プレイヤー名 => kind('dd'|'weak25'|'weak50') => [[start,end],...]
        // dd = ダメージ低下（ギミック失敗デバフ。IDが戦闘ごとに違うため名前で判定。低下率は MitigationSpec::DD_REDUCTION で仮定）
        // weak25 = 衰弱(43, -25%) / weak50 = 頽廃(44, -50%)（死亡復帰ペナルティ・低下率はゲーム仕様で固定）
        $dmgDebuffSegs = [];
        $ddIconByPlayer = []; // プレイヤー名 => ダメージ低下の実アイコン（ビュー表示用）
        $reviveTimes = []; // プレイヤー名 => [蘇生時刻]（衰弱/頽廃の付与＝蘇生の瞬間。死亡時間の終端検出に使う）
        $openDebuffs = []; // "targetID_statusID" => start
        foreach ($this->fflogs->getFriendlyDebuffs($code, $fightId, $st, $et) as $ev) {
            $rawId = (int) ($ev['abilityGameID'] ?? 0);
            $sid = $rawId >= 1000000 ? $rawId - 1000000 : $rawId;
            // 種別判定：衰弱(43)/頽廃(44)はID固定、ダメージ低下は名前照合（IDがコンテンツごとに異なる）。
            // masterDataのステータスはオフセット付きID(100xxxx)で登録されているため生ID優先で引く。
            if ($sid === 43) {
                $kind = 'weak25';
            } elseif ($sid === 44) {
                $kind = 'weak50';
            } else {
                $abName = $abilities->get($rawId)['name'] ?? ($abilities->get($sid)['name'] ?? '');
                if (!preg_match('/ダメージ低下|Damage Down/iu', $abName)) {
                    continue;
                }
                $kind = 'dd';
            }
            $tgt = $actors->get($ev['targetID'] ?? 0);
            if (!$tgt || ($tgt['type'] ?? '') !== 'Player') {
                continue;
            }
            if ($kind === 'dd' && !isset($ddIconByPlayer[$tgt['name']])) {
                $ic = $abilities->get($rawId)['icon'] ?? ($abilities->get($sid)['icon'] ?? null);
                if ($ic) {
                    $ddIconByPlayer[$tgt['name']] = $ic;
                }
            }
            $key = ($ev['targetID'] ?? 0) . '_' . $sid;
            $t = $ev['timestamp'] ?? 0;
            if (($ev['type'] ?? '') === 'applydebuff') {
                $openDebuffs[$key] = ['start' => $t, 'name' => $tgt['name'], 'kind' => $kind];
                if ($kind !== 'dd') {
                    $reviveTimes[$tgt['name']][] = $t;
                } // 衰弱/頽廃の付与＝蘇生
            } elseif (($ev['type'] ?? '') === 'removedebuff' && isset($openDebuffs[$key])) {
                $o = $openDebuffs[$key];
                $dmgDebuffSegs[$o['name']][$o['kind']][] = [$o['start'], $t];
                unset($openDebuffs[$key]);
            }
        }
        foreach ($openDebuffs as $o) { // 戦闘終了まで残ったデバフ
            $dmgDebuffSegs[$o['name']][$o['kind']][] = [$o['start'], $et];
        }

        // 死亡区間：プレイヤー名 => [[死亡時刻, 蘇生時刻], ...]。
        // 蘇生時刻＝その死亡より後の最初の衰弱/頽廃付与。蘇生が無ければ戦闘終了まで死亡扱い。
        $deadSegs = [];
        foreach ($deathsByPlayer as $pname => $dts) {
            $revs = $reviveTimes[$pname] ?? [];
            sort($revs);
            $sorted = $dts;
            sort($sorted);
            foreach ($sorted as $dt) {
                $rv = $et;
                foreach ($revs as $r) {
                    if ($r > $dt) {
                        $rv = min($r, $et);
                        break;
                    }
                }
                $deadSegs[$pname][] = [$dt, $rv];
            }
        }

        return compact('potionByPlayer', 'potionIconByPlayer', 'deathsByPlayer', 'dmgDebuffSegs', 'ddIconByPlayer', 'deadSegs');
    }

    /**
     * summary['players'] に「死亡・衰弱/頽廃・ダメージ低下」の被弾状況と、それが無かった場合の
     * 想定ダメージ／想定rDPSを付与する。窓（フェーズ or グラフで選択した任意範囲）を指定して使う。
     *
     * @param  array $ctx buildLossContext() の戻り値
     * @param  bool  $withPotionGain 薬の上乗せDPSも推定するか（薬使用者ごとにFFLogsへ追加問い合わせが走る）
     * @return float 窓内の薬の推定上乗せDPS合計（$withPotionGain=false なら 0）
     */
    private function enrichPlayerLosses(
        array &$summary,
        float $winStart,
        float $winEnd,
        float $fightEnd,
        array $ctx,
        float $ddr,
        bool $withPotionGain,
        $code = null,
        $fightId = null,
    ): float {
        $potionByPlayer = $ctx['potionByPlayer'];
        $potionIconByPlayer = $ctx['potionIconByPlayer'];
        $deathsByPlayer = $ctx['deathsByPlayer'];
        $dmgDebuffSegs = $ctx['dmgDebuffSegs'];
        $ddIconByPlayer = $ctx['ddIconByPlayer'];
        $deadSegs = $ctx['deadSegs'];
        $et = $fightEnd;

        $phasePotionGain = 0.0;
        // このフェーズ窓で薬を使ったプレイヤーにフラグ＋推定上乗せDPS
        foreach ($summary['players'] as &$pp) {
            $pp['potion'] = false;
            $pp['potionIcon'] = null;
            $pp['potionGainDps'] = 0.0;
            // フェーズ内の死亡回数（deaths=表示用の全数 / deathsHarm=アドバイス用）
            // 戦闘終了10秒以内の死亡は火力損失がほぼ無い（討伐時の巻き込まれ・ワイプ死）ため deathsHarm から除外
            $phaseDeaths = array_filter(
                $deathsByPlayer[$pp['name']] ?? [],
                fn($dt) => $dt >= $winStart && $dt < $winEnd,
            );
            $pp['deaths'] = count($phaseDeaths);
            $pp['deathsHarm'] = count(array_filter($phaseDeaths, fn($dt) => ($et - $dt) > 10000));
            // 与ダメ低下デバフのフェーズ内稼働秒数（dd=ダメージ低下, weak25=衰弱, weak50=頽廃）
            foreach (['dd' => 'ddSec', 'weak25' => 'weak25Sec', 'weak50' => 'weak50Sec'] as $kind => $fld) {
                $sec = 0.0;
                foreach ($dmgDebuffSegs[$pp['name']][$kind] ?? [] as [$s0, $e0]) {
                    $ov = min($e0, $winEnd) - max($s0, $winStart);
                    if ($ov > 0) {
                        $sec += $ov / 1000;
                    }
                }
                $pp[$fld] = round($sec, 1);
            }
            $pp['weakSec'] = round($pp['weak25Sec'] + $pp['weak50Sec'], 1); // 表示用（合算）
            $pp['ddIcon'] = $pp['ddSec'] > 0 ? ($ddIconByPlayer[$pp['name']] ?? null) : null;

            // フェーズ内の死亡時間（死亡→蘇生のオーバーラップ合計・秒）
            $deadSec = 0.0;
            foreach ($deadSegs[$pp['name']] ?? [] as [$s0, $e0]) {
                $ov = min($e0, $winEnd) - max($s0, $winStart);
                if ($ov > 0) {
                    $deadSec += $ov / 1000;
                }
            }
            $pp['deadSec'] = round($deadSec, 1);

            // ===== 想定ダメージ（死亡・衰弱/頽廃・ダメージ低下が無かった場合の推定） =====
            // 自己整合モデル：観測総ダメージ＝本来DPS×（生存時間−Σ低下率×低下秒数）から
            // 「本来のDPS」を逆算し、逸失分＝本来DPS×（死亡時間＋Σ低下率×低下秒数）とする。
            // ※単純な「平均DPS×r/(1-r)」は低下率が大きい（例:絶妖星乱舞の-90%）と
            //   窓内DPS≒平均DPSの前提が崩れて破綻するため使わない。
            // 近似の限界: バースト（2分）との重なりは補正しない。重複窓は加算後に95%でクランプ。
            $combatSec = max(1.0, $summary['combatSec']);
            $aliveSec = max(1.0, $combatSec - $deadSec);
            // 低下率換算の「失われた実効稼働秒数」（衰弱-25%固定・頽廃-50%固定・ダメ低下はコンテンツ別）
            $weakRedSec = min($pp['weak25Sec'], $aliveSec) * 0.25 + min($pp['weak50Sec'], $aliveSec) * 0.50;
            $ddRedSec = min($pp['ddSec'], $aliveSec) * $ddr;
            $redSec = min($weakRedSec + $ddRedSec, $aliveSec * 0.95); // 重複・過大化ガード
            $normDps = $pp['total'] > 0 ? $pp['total'] / max(1.0, $aliveSec - $redSec) : 0.0; // 本来のDPS
            $lossDeath = $deadSec * $normDps;
            $lossWeak = $weakRedSec * $normDps;
            $lossDd = $ddRedSec * $normDps;
            $lossTotal = $lossDeath + $lossWeak + $lossDd;
            // 想定総ダメージ（実測＋逸失分）と想定rDPS（rawベースのロス比率をrDPSにも適用する近似）
            $pp['potentialTotal'] = $pp['total'] + $lossTotal;
            $pp['potentialRdps'] = ($pp['total'] > 0 && $lossTotal > 0)
                ? $pp['rdps'] * (1 + $lossTotal / $pp['total'])
                : $pp['rdps'];
            $pp['lossDeathDps'] = $lossDeath / $combatSec;
            $pp['lossWeakDps'] = $lossWeak / $combatSec;
            $pp['lossDdDps'] = $lossDd / $combatSec;
            foreach ($potionByPlayer[$pp['name']] ?? [] as $pts) {
                if ($pts >= $winStart && $pts < $winEnd) {
                    $pp['potion'] = true;
                    $pp['potionIcon'] = $potionIconByPlayer[$pp['name']] ?? null;
                    // 薬の上乗せ推定は薬使用者ぶんFFLogsへ追加問い合わせが走る。任意範囲の再集計では省く。
                    if (!$withPotionGain) {
                        break;
                    }
                    // 薬30秒窓のそのプレイヤーのダメージ → 上乗せ推定 = 窓内ダメ×(mult-1)/mult
                    // ※ $winEnd（集計窓の終端）とは別物なので変数名を分ける
                    $potWinEnd = min($pts + 30000, $et);
                    $winTbl = $this->fflogs->getDamageTable($code, $fightId, $pts, $potWinEnd);
                    $winDmg = 0.0;
                    foreach ($winTbl['entries'] ?? [] as $we) {
                        if (($we['name'] ?? '') === $pp['name']) {
                            $winDmg = (float) ($we['total'] ?? 0);
                            break;
                        }
                    }
                    $gainTotal = $winDmg * (self::POTION_MULT - 1) / self::POTION_MULT;
                    $pp['potionGainDps'] = $gainTotal / $summary['sec']; // 窓のDPS換算の上乗せ分
                    $phasePotionGain += $pp['potionGainDps'];
                    break;
                }
            }
        }
        unset($pp);

        return $phasePotionGain;
    }

    /**
     * 敵キャストを「グラフに重ねる用のマーク」に整形する。
     *
     * 生の Casts は オートアタック・雑魚の連打が大半でそのままでは読めないため、次の規則で間引く。
     *  - オートアタック（ability.id 1/7・名前が attack 相当）は除外
     *  - 詠唱ありの技は begincast（＝テレグラフが出た瞬間）を採用。同じ敵・同じ技の cast は
     *    直前12秒以内に begincast があれば「その詠唱の着弾」なので捨てる（重複表示の防止）
     *  - 詠唱なしの技は cast をそのまま採用
     *  - 同じ敵・同じ技の連打は2.5秒以内なら1件に丸める
     *
     *  - 名前が取れない技（FFLogsの unknown_xxxx）は除外
     *
     * @return array<int, array{ts:float, name:string, src:string}> 時刻昇順
     */
    private function buildEnemyCastMarks($code, $fightId, float $st, float $et, $actors, $abilities): array
    {
        $events = $this->fflogs->getEnemyCasts($code, $fightId, $st, $et);
        usort($events, fn($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        $marks = [];
        $lastBegin = [];  // "srcID:abilityID" => begincast時刻
        $lastMark = [];   // "srcID:abilityID" => 直近で採用した時刻
        foreach ($events as $ev) {
            $type = $ev['type'] ?? '';
            if ($type !== 'begincast' && $type !== 'cast') {
                continue;
            }
            $ts = (float) ($ev['timestamp'] ?? 0);
            $rawId = (int) ($ev['abilityGameID'] ?? 0);
            if ($rawId === 1 || $rawId === 7) {
                continue;
            } // オートアタック
            $ab = $abilities->get($rawId) ?? $abilities->get($rawId % 1000000);
            $name = trim((string) ($ab['name'] ?? ''));
            // 名無しの技はFFLogs側で unknown_xxxx になる。表示しても意味が無いので捨てる。
            if ($name === '' || preg_match('/^(attack|アタック|通常攻撃)$/iu', $name) || preg_match('/^unknown/i', $name)) {
                continue;
            }

            $key = ($ev['sourceID'] ?? 0) . ':' . $rawId;
            if ($type === 'begincast') {
                $lastBegin[$key] = $ts;
            } elseif (isset($lastBegin[$key]) && ($ts - $lastBegin[$key]) <= 12000) {
                continue; // 直前の begincast の着弾＝同じ技なので二重に出さない
            }
            if (isset($lastMark[$key]) && ($ts - $lastMark[$key]) < 2500) {
                continue;
            } // 連打を丸める
            $lastMark[$key] = $ts;
            // 同じ技を複数体（分身など別アクターID）が同時に撃つと二重に出るので、技名でもまとめる
            $nk = 'n:' . $name;
            if (isset($lastMark[$nk]) && ($ts - $lastMark[$nk]) < 2500) {
                continue;
            }
            $lastMark[$nk] = $ts;

            $marks[] = [
                'ts' => $ts,
                'name' => $name,
                // 敵の技はFFLogs上ほぼ全て汎用アイコン（000000-000405.png）なのでアイコンは持たない
                'src' => $actors->get($ev['sourceID'] ?? 0)['name'] ?? '',
            ];
        }
        return $marks;
    }

    /**
     * 任意の窓（絶対ms）を、フェーズ集計と同じ手順で集計する。
     * 想定ダメージの素材（死亡・デバフ・薬）は戦闘全体ぶん必要で毎回取り直すと重いためキャッシュする。
     *
     * @return array|null summarizeDamageTable() の戻り値に想定値を付与したもの
     */
    public function summarizeWindow(string $code, int $fightId, float $start, float $end): ?array
    {
        $fight = Cache::remember("fflogs:fight:{$code}:{$fightId}", 1800, fn() => $this->fflogs->getFightDetails($code, $fightId));
        if (!$fight) {
            return null;
        }
        $st = (float) $fight['startTime'];
        $et = (float) $fight['endTime'];
        // 窓は戦闘の範囲内に収める
        $start = max($st, $start);
        $end = min($et, $end);
        if ($end - $start < 1000) {
            return null;
        }

        $ctx = Cache::remember("fflogs:losscontext:{$code}:{$fightId}", 1800, function () use ($code, $fightId, $st, $et) {
            $master = $this->fflogs->getMasterData($code);
            $actors = collect($master['actors'] ?? [])->keyBy('id');
            $abilities = collect($master['abilities'] ?? [])->keyBy('gameID');
            return $this->buildLossContext($code, $fightId, $st, $et, $actors, $abilities);
        });

        $table = $this->fflogs->getDamageTable($code, $fightId, $start, $end);
        if (empty($table)) {
            return null;
        }
        $summary = $this->summarizeDamageTable($table, $start, $end);

        $ddr = MitigationSpec::damageDownRate($fight['name'] ?? '', $fight['gameZone']['name'] ?? '');
        // 薬の上乗せ推定は薬使用者ぶん追加問い合わせが走るので、任意範囲の再集計では省く
        $this->enrichPlayerLosses($summary, $start, $end, $et, $ctx, $ddr, false);

        $summary['partyPotentialRdps'] = array_sum(array_map(
            fn($p) => $p['potentialRdps'] ?? $p['rdps'],
            $summary['players'],
        ));
        $summary['sec'] = round($summary['sec'], 1);
        $summary['combatSec'] = round($summary['combatSec'], 1);
        return $summary;
    }
}
