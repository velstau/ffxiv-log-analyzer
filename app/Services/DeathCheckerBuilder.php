<?php

namespace App\Services;

use App\Support\DamageBreakdown;
use App\Support\FFXIVJobs;

/**
 * 「死因チェッカー」の組み立て。
 *
 * death イベント1件ごとに、その直前に何を受けて、何を張っていて、何が足りなかったのかを
 * 復元する。FFLogs は「死んだ」ことしか教えてくれないので、被弾・バフ・バリア・位置・
 * 頭割りマーカー・敵の詠唱を時系列で突き合わせて推定するしかない。
 *
 */
class DeathCheckerBuilder
{
    public function __construct(private readonly FFLogsService $fflogs) {}

    /**
     * デスチェッカー用データを構築する。
     * 死亡イベントごとに「死因スキル・直前被弾・死亡時の各プレイヤー座標・残HP・被弾比較」をまとめる。
     * 座標／HP は events(includeResources:true) の targetResources / sourceResources から得る。
     *
     * @param  array      $deathEvents  Deaths イベント配列
     * @param  array      $damageEvents includeResources付きの被弾イベント配列
     * @param  \Illuminate\Support\Collection $masterActors   id => actor
     * @param  \Illuminate\Support\Collection $masterAbilities gameID => ability
     * @param  array      $playerDetails name => detail(icon/job/priority)
     * @param  array      $playerStats   name => actorId
     * @param  int        $startTime     fight開始タイムスタンプ(ms)
     * @param  array      $mitByStatus   ステータスID => 軽減/バリアスキル{name,icon}
     * @param  array      $barrierSet    バリア該当ステータスID集合
     * @param  array      $actionGeo     gameID => ['castType','range'] AoEジオメトリ
     * @param  array      $enemyCasts    敵キャスト（座標付き・テレグラフ描画用）
     * @param  array      $friendPositions 味方キャスト（座標付き・全員座標スナップショット用）
     * @param  array      $headmarkers   頭マーカーイベント（扇担当者特定用・フィット固有）
     * @param  int        $gameZone      gameZone ID（フィット固有ルールの分岐用）
     * @param  array      $shapeMarkers  形状マーカーデバフ（13DC/DD/DE）。扇担当の特定に使う。
     * @param  array      $enemyDebuffs  敵(ボス)へのデバフ。アドル/リプライザル/牽制等のデバフ系軽減判定に使う。
     * @return array      ビュー用の死亡スナップショット配列
     *
     * 処理の流れ:
     *   1. 下ごしらえ    … 敵デバフの有効区間、座標/HPの時系列、ID→名前の解決器を先に作る
     *   2. 死亡者の詳細  … 死亡1件ごとに「とどめ・直前の被弾列・致死前HP・張っていた軽減」を復元
     *   3. グループ化    … 同じ死因かつ5秒以内の死亡を1グループにまとめる（全滅は1件として見たい）
     *   4. 図の組み立て  … グループごとに、その瞬間の全員の立ち位置とAoEの形をSVG座標へ変換
     *
     * 座標は FFLogs の 1/100 単位（中心10000）で返るので、描画時に 0..200（中心100）へ射影する。
     */
    public function build($deathEvents, $damageEvents, $masterActors, $masterAbilities, $playerDetails, $playerStats, $startTime, $mitByStatus = [], $barrierSet = [], $actionGeo = [], $enemyCasts = [], $friendPositions = [], $headmarkers = [], $gameZone = 0, $shapeMarkers = [], $enemyDebuffs = [])
    {
        if (empty($deathEvents)) {
            return [];
        }

        // ===== 1. 下ごしらえ =====

        // 敵(ボス)へのデバフ系軽減（アドル/リプライザル/牽制等）の有効時間帯を status別に構築。
        // とどめ時にボスへ付いていたデバフ軽減を、被対象のbuffsに乗らなくても拾えるようにする。
        $debuffMitWindows = []; // statusId => [ [start,end], ... ]
        $openDeb = [];
        foreach ($enemyDebuffs as $db) {
            $sid = (int) ($db['abilityGameID'] ?? 0);
            if ($sid >= 1000000) {
                $sid -= 1000000;
            }
            if (!isset($mitByStatus[$sid])) {
                continue;
            } // 軽減として定義されたものだけ
            $eventType = $db['type'] ?? '';
            $key = $sid . '_' . ($db['sourceID'] ?? 0);
            if ($eventType === 'applydebuff') {
                $openDeb[$key] = $db['timestamp'] ?? 0;
            } elseif ($eventType === 'removedebuff' && isset($openDeb[$key])) {
                $debuffMitWindows[$sid][] = [$openDeb[$key], $db['timestamp'] ?? 0];
                unset($openDeb[$key]);
            }
        }
        foreach ($openDeb as $key => $s) {
            $sid = (int) explode('_', $key)[0];
            $debuffMitWindows[$sid][] = [$s, PHP_INT_MAX];
        }

        // ケフカ(Sigmascape V4, zone1363)固有：形状マーカー = 13DC(1005084)頭割り / 13DD(1005085)円 / 13DE(1005086)扇。
        // プレイヤーに付与され、塔(tele円)を踏むとそのマーカーの形状AoEを発動する。
        $kefka = ($gameZone === 1363);
        $coneShape = 1005086;   // 13DE = 扇
        $circleShape = 1005085; // 13DD = 円
        $stackShape = 1005084;  // 13DC = 頭割り
        // 形状マーカーの付与/除去イベント（保持者=targetID, mk=マーカーID）を時刻順に
        $shapeMarkEvents = [];
        if ($kefka) {
            foreach ($shapeMarkers as $sm) {
                $mk = (int) ($sm['abilityGameID'] ?? 0);
                if (!in_array($mk, [$coneShape, $circleShape, $stackShape])) {
                    continue;
                }
                $shapeMarkEvents[] = [
                    't'   => $sm['timestamp'] ?? 0,
                    'pid' => (int) ($sm['targetID'] ?? -1),
                    'mk'  => $mk,
                    'on'  => in_array($sm['type'] ?? '', ['applydebuff', 'refreshdebuff']),
                ];
            }
            usort($shapeMarkEvents, fn($a, $b) => $a['t'] <=> $b['t']);
        }

        // 死亡マップのドット配色は tank / healer / dps の3色だけなので、細かいロールは潰す
        $jobRole = FFXIVJobs::broadRoleByAbbr();
        $roleOf = static fn($job) => $jobRole[$job] ?? 'dps';

        // actorId => プレイヤー詳細（name/job/icon/role）を引けるようにする
        $playerById = [];
        foreach ($playerStats as $name => $id) {
            $d = $playerDetails[$name] ?? ['name' => $name, 'icon' => null, 'job' => null];
            $playerById[(int) $id] = [
                'id'   => (int) $id,
                'name' => $name,
                'job'  => $d['job'],
                'icon' => $d['icon'],
                'role' => $roleOf($d['job']),
            ];
        }
        $allPlayerIds = array_keys($playerById);

        // アイコン補完：死因スキル等が masterData に無い場合のフォールバック用 gameData アイコン
        $iconLookupIds = [];
        foreach ($deathEvents as $de) {
            if (!empty($de['killingAbilityGameID'])) {
                $iconLookupIds[] = (int) $de['killingAbilityGameID'];
            }
        }
        foreach ($damageEvents as $dmg) {
            if (!empty($dmg['abilityGameID'])) {
                $iconLookupIds[] = (int) $dmg['abilityGameID'];
            }
        }
        $suppIcons = $this->fflogs->getAbilityIconMap(array_values(array_unique($iconLookupIds)));

        // スキルID → {name, icon} 解決
        $abilityOf = function ($id) use ($masterAbilities, $suppIcons) {
            $id = (int) $id;
            $name = '#' . $id;
            $icon = null;
            if ($ability = $masterAbilities->get($id)) {
                $name = $ability['name'] ?? $name;
                $icon = $ability['icon'] ?? null;
            }
            if (empty($icon) && !empty($suppIcons[$id])) {
                $icon = $suppIcons[$id];
            }
            return ['id' => $id, 'name' => $name, 'icon' => $icon];
        };

        // ボス本体のアクターID集合（ヘルパー/Environment/雑魚の座標を混ぜると敵◆がズレるため除外）
        $bossIds = [];
        foreach ($masterActors as $actor) {
            if (($actor['type'] ?? '') === 'Boss' || ($actor['subType'] ?? '') === 'Boss') {
                $bossIds[(int) $actor['id']] = true;
            }
        }
        // フォールバック：Boss判定が無ければ、最も多く攻撃してきた敵を主ボスとみなす。
        // 討伐対象が複数いるコンテンツや、masterData に Boss フラグが無いログへの保険。
        if (empty($bossIds)) {
            $srcCount = [];
            foreach ($damageEvents as $dmg) {
                $sid = (int) ($dmg['sourceID'] ?? 0);
                if ($sid) {
                    $srcCount[$sid] = ($srcCount[$sid] ?? 0) + 1;
                }
            }
            if ($srcCount) {
                arsort($srcCount);
                $bossIds[array_key_first($srcCount)] = true;
            }
        }

        // 各プレイヤーの座標/HP時系列（targetResources）と、ボス座標時系列（sourceResources）を一括構築
        $posByPlayer = []; // id => [ [t,x,y,hp,maxhp], ... ]（昇順）
        $bossPos = [];     // [ [t,x,y], ... ]（ボス本体のみ）
        foreach ($damageEvents as $dmg) {
            $ts = $dmg['timestamp'] ?? null;
            if ($ts === null) {
                continue;
            }

            $tRes = $dmg['targetResources'] ?? null;
            $tid = $dmg['targetID'] ?? null;
            if ($tRes && $tid !== null && isset($tRes['x'], $tRes['y']) && isset($playerById[(int) $tid])) {
                $posByPlayer[(int) $tid][] = [
                    't' => $ts,
                    'x' => $tRes['x'],
                    'y' => $tRes['y'],
                    'hp' => $tRes['hitPoints'] ?? null,
                    'maxhp' => $tRes['maxHitPoints'] ?? null,
                ];
            }

            $sRes = $dmg['sourceResources'] ?? null;
            $sid = (int) ($dmg['sourceID'] ?? 0);
            if ($sRes && isset($sRes['x'], $sRes['y']) && isset($bossIds[$sid])) {
                $bossPos[] = ['t' => $ts, 'x' => $sRes['x'], 'y' => $sRes['y']];
            }
        }

        // 味方キャスト(sourceResources)も座標サンプルに加える。被弾していないプレイヤーも
        // 詠唱ごとに座標が取れるため、スナップショットの精度が大幅に上がる。
        foreach ($friendPositions as $fc) {
            if (($fc['type'] ?? '') !== 'cast') {
                continue;
            }
            $ts = $fc['timestamp'] ?? null;
            $sid = $fc['sourceID'] ?? null;
            $sourceResources = $fc['sourceResources'] ?? null;
            if ($ts === null || $sid === null || !$sourceResources || !isset($sourceResources['x'], $sourceResources['y'])) {
                continue;
            }
            if (!isset($playerById[(int) $sid])) {
                continue;
            }
            $posByPlayer[(int) $sid][] = [
                't' => $ts,
                'x' => $sourceResources['x'],
                'y' => $sourceResources['y'],
                'hp' => $sourceResources['hitPoints'] ?? null,
                'maxhp' => $sourceResources['maxHitPoints'] ?? null,
            ];
        }

        // ボス座標も敵キャスト(sourceResources)で補強（密度向上・扇の頂点精度向上）。ボス本体のみ。
        foreach ($enemyCasts as $ec) {
            if (($ec['type'] ?? '') !== 'cast') {
                continue;
            }
            $ts = $ec['timestamp'] ?? null;
            $sourceResources = $ec['sourceResources'] ?? null;
            if ($ts === null || !$sourceResources || !isset($sourceResources['x'], $sourceResources['y'])) {
                continue;
            }
            if (!isset($bossIds[(int) ($ec['sourceID'] ?? 0)])) {
                continue;
            }
            $bossPos[] = ['t' => $ts, 'x' => $sourceResources['x'], 'y' => $sourceResources['y']];
        }

        // 念のため時刻昇順に整列
        foreach ($posByPlayer as &$list) {
            usort($list, fn($a, $b) => $a['t'] <=> $b['t']);
        }
        unset($list);
        usort($bossPos, fn($a, $b) => $a['t'] <=> $b['t']);

        // 指定時刻の直近（その時刻以前を優先、無ければ直後）のサンプルを返す。
        //
        // 座標は「被弾したとき」「詠唱したとき」にしか記録されないので、死亡の瞬間ちょうどの
        // 座標は基本的に存在しない。前後のサンプルで代用するが、離れすぎた値を使うと
        // 全く違う位置に描いてしまうため $window（既定25秒）を超えたものは採用しない。
        $nearestPosition = function ($list, $ts, $window = 25000) {
            $bestPos = null;
            $bestDiff = PHP_INT_MAX;
            foreach ($list as $p) {
                $diff = $ts - $p['t'];
                // $t以前を優先（diff>=0）。範囲外は除外。
                $abs = abs($diff);
                if ($abs > $window) {
                    continue;
                }
                // $t以前を優先するため、未来側にはペナルティを与える
                $score = $diff >= 0 ? $diff : ($abs + 100000);
                if ($score < $bestDiff) {
                    $bestDiff = $score;
                    $bestPos = $p;
                }
            }
            return $bestPos;
        };

        // 座標(1/100単位, 中心10000) → SVG座標(0..200, 中心100) 変換器を生成
        $makeProjector = function ($points) {
            $maxR = 1.0; // yalm
            foreach ($points as $pt) {
                $dx = ($pt['x'] - 10000) / 100.0;
                $dy = ($pt['y'] - 10000) / 100.0;
                $r = sqrt($dx * $dx + $dy * $dy);
                if ($r > $maxR) {
                    $maxR = $r;
                }
            }
            // 半径90に収める。最小フィールド半径は20yalm（典型的アリーナ）に固定し、
            // プレイヤーが密集（頭割り等）しても過剰ズームせず、アリーナ的な見た目を保つ。
            $scale = 90.0 / max($maxR, 20.0);
            return function ($x, $y) use ($scale) {
                return [
                    'sx' => round(100 + (($x - 10000) / 100.0) * $scale, 1),
                    'sy' => round(100 + (($y - 10000) / 100.0) * $scale, 1),
                ];
            };
        };

        // 致死前HP：とどめ($lethalTs)直前で最新の hitPoints を返す
        $hpBefore = function ($vid, $lethalTs) use (&$posByPlayer) {
            $list = $posByPlayer[$vid] ?? [];
            $hp = null;
            $max = null;
            $bestDiff = PHP_INT_MAX;
            foreach ($list as $p) {
                if ($p['t'] >= $lethalTs) {
                    continue;
                }  // とどめ以前のみ
                if ($p['hp'] === null) {
                    continue;
                }
                $diff = $lethalTs - $p['t'];
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $hp = $p['hp'];
                    $max = $p['maxhp'];
                }
            }
            return ['hp' => $hp, 'maxhp' => $max];
        };

        // ===== 2. 死亡者1人分の詳細を復元するクロージャ =====
        //
        // FFLogs は「死んだ」ことしか教えてくれないので、とどめの直前へ遡って
        //   ・何を受けたか（直前の被弾を時系列で列挙）
        //   ・そのとき残りHPがいくつだったか
        //   ・どの軽減／バリアが乗っていたか
        // を突き合わせ、「軽減が足りなかったのか、そもそも即死だったのか」を判断できるようにする。
        $victimDetail = function ($de) use ($damageEvents, $playerById, $abilityOf, $hpBefore, $startTime, $mitByStatus, $barrierSet, $debuffMitWindows) {
            $vid = (int) $de['targetID'];
            $ts = $de['timestamp'];
            $killId = (int) ($de['killingAbilityGameID'] ?? 0);

            // とどめの被弾イベント（abilityGameID一致・対象=死者・時刻近接、overkill優先）
            $lethal = null;
            foreach ($damageEvents as $dmg) {
                if ((int) ($dmg['targetID'] ?? -1) !== $vid) {
                    continue;
                }
                if ($killId && (int) ($dmg['abilityGameID'] ?? 0) !== $killId) {
                    continue;
                }
                if (abs(($dmg['timestamp'] ?? 0) - $ts) > 2500) {
                    continue;
                }
                if ($lethal === null || !empty($dmg['overkill'])) {
                    $lethal = $dmg;
                    if (!empty($dmg['overkill'])) {
                        break;
                    }
                }
            }
            $lethalTs = $lethal['timestamp'] ?? $ts;

            // 直前被弾シーケンス（死亡前15秒・被弾後HP付き）
            $seq = [];
            foreach ($damageEvents as $dmg) {
                if ((int) ($dmg['targetID'] ?? -1) !== $vid) {
                    continue;
                }
                $dt = $dmg['timestamp'] ?? 0;
                if ($dt < $ts - 15000 || $dt > $ts + 200) {
                    continue;
                }
                if (($dmg['type'] ?? '') === 'calculateddamage') {
                    continue;
                } // 計算前は除外（実ダメのみ）
                $abilityInfo = $abilityOf($dmg['abilityGameID'] ?? 0);
                $brk = DamageBreakdown::of($dmg);
                $tRes = $dmg['targetResources'] ?? null;
                $seq[] = [
                    'ts'        => $dt,
                    'rel'       => round(($dt - $startTime) / 1000, 1),
                    'name'      => $abilityInfo['name'],
                    'icon'      => $abilityInfo['icon'],
                    'amount'    => $brk['effective'],
                    'unmit'     => $brk['base'],
                    'rate'      => $brk['rate'],
                    'overkill'  => $brk['overkill'],
                    'absorbed'  => $brk['absorbed'],
                    'hp_post'   => $tRes['hitPoints'] ?? null,    // 被弾後の残HP
                    'maxhp'     => $tRes['maxHitPoints'] ?? null,
                    'is_lethal' => ($dt === $lethalTs),
                ];
            }

            // 時刻順に整列し、被弾前HP（=被弾後HP+被弾-過剰）と行間の回復量を算出する。
            // ※ ヒールイベントを別取得せず、被弾後HPの差分から回復を推定する。
            usort($seq, fn($a, $b) => $a['ts'] <=> $b['ts']);
            $prevPost = null;
            foreach ($seq as &$row) {
                $row['hp_pre'] = ($row['hp_post'] !== null)
                    ? $row['hp_post'] + $row['amount'] - $row['overkill']
                    : null;
                // 直前の被弾後HPより、今回の被弾前HPが高い＝間に回復が入った
                $row['recovery'] = ($prevPost !== null && $row['hp_pre'] !== null && $row['hp_pre'] > $prevPost)
                    ? $row['hp_pre'] - $prevPost
                    : 0;
                if ($row['hp_post'] !== null) {
                    $prevPost = $row['hp_post'];
                }
            }
            unset($row);

            $hpBefore = $hpBefore($vid, $lethalTs);

            // 死亡時に乗っていた軽減/バリア（とどめイベントの buffs=被弾時の被対象バフを照合）
            // buffsのID形式は 1000000 + ステータスID。軽減列/バリア集合に一致するものだけ抽出。
            $mitUp = [];
            $barrierUp = [];
            if ($lethal && !empty($lethal['buffs'])) {
                foreach (explode('.', $lethal['buffs']) as $bid) {
                    if ($bid === '') {
                        continue;
                    }
                    $sid = (int) $bid;
                    if ($sid >= 1000000) {
                        $sid -= 1000000;
                    }
                    $isBarrier = isset($barrierSet[$sid]);
                    if (isset($mitByStatus[$sid])) {
                        // 同名スキル（別ステータスID）の重複を避けるため name をキーにする
                        $key = $mitByStatus[$sid]['name'];
                        if ($isBarrier) {
                            $barrierUp[$key] = $mitByStatus[$sid];
                        } else {
                            $mitUp[$key] = $mitByStatus[$sid];
                        }
                    }
                }
            }
            // ボスへのデバフ系軽減（アドル/リプライザル/牽制等）も、とどめ時刻に有効なら軽減に加える。
            // これらは被対象のbuffsに乗らない場合がある（特に分身の攻撃）ため、敵デバフ時間帯から判定する。
            foreach ($debuffMitWindows as $sid => $wins) {
                if (!isset($mitByStatus[$sid])) {
                    continue;
                }
                foreach ($wins as $window) {
                    if ($lethalTs >= $window[0] && $lethalTs <= $window[1]) {
                        $mitUp[$mitByStatus[$sid]['name']] = $mitByStatus[$sid];
                        break;
                    }
                }
            }

            return [
                'victim'    => $playerById[$vid],
                'marker'    => $de['targetMarker'] ?? null,
                'rel_time'  => gmdate('i:s', (int) (($ts - $startTime) / 1000)),
                'mit_up'    => array_values($mitUp),
                'barrier_up' => array_values($barrierUp),
                'lethal'    => $lethal ? (function () use ($lethal) {
                    $breakdown = DamageBreakdown::of($lethal);
                    return [
                        'amount'   => $breakdown['effective'],
                        'unmit'    => $breakdown['base'],
                        'rate'     => $breakdown['rate'],
                        'overkill' => $breakdown['overkill'],
                        'absorbed' => $breakdown['absorbed'],
                        'hp_lost'  => $breakdown['hp_lost'],
                    ];
                })() : null,
                'hp_before' => $hpBefore['hp'],
                'maxhp'     => $hpBefore['maxhp'],
                'sequence'  => $seq,
                'lethal_ts' => $lethalTs, // とどめ着弾の実時刻（死亡イベントはラグがあるため位置の基準に使う）
            ];
        };

        // ===== 3. 死亡のグループ化 =====
        // 全滅時に8人ぶんのカードが並ぶと読みづらい。同じ攻撃で連鎖した死は1件として扱う。

        // 死亡イベントを時刻順に整列（味方プレイヤーのみ）
        $validDeaths = [];
        foreach ($deathEvents as $de) {
            if (($de['type'] ?? '') !== 'death') {
                continue;
            }
            if (!isset($playerById[(int) ($de['targetID'] ?? 0)])) {
                continue;
            }
            $validDeaths[] = $de;
        }
        usort($validDeaths, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        // プレイヤーID => 死亡時刻リスト（既に死亡しているか＝❌表示の判定に使う）
        $deathTimesByPid = [];
        foreach ($validDeaths as $de) {
            $deathTimesByPid[(int) $de['targetID']][] = $de['timestamp'];
        }

        // 同じ死因(killId)かつ近接時刻(<=5秒)でグループ化する。
        // 5秒は「同じ攻撃で巻き込まれた」と「別の攻撃で続けて落ちた」を分ける経験的な閾値。
        $groupsRaw = [];
        foreach ($validDeaths as $de) {
            $kid = (int) ($de['killingAbilityGameID'] ?? 0);
            $ts = $de['timestamp'];
            $found = null;
            foreach ($groupsRaw as $gi => $g) {
                if ($g['kill_id'] === $kid && abs($ts - $g['first_ts']) <= 5000) {
                    $found = $gi;
                    break;
                }
            }
            if ($found === null) {
                $groupsRaw[] = ['kill_id' => $kid, 'first_ts' => $ts, 'last_ts' => $ts, 'deaths' => [$de]];
            } else {
                $groupsRaw[$found]['deaths'][] = $de;
                $groupsRaw[$found]['last_ts'] = max($groupsRaw[$found]['last_ts'], $ts);
            }
        }

        // ===== 4. グループごとの図とサマリを組み立てる =====
        // 「誰がどこにいて、何のAoEに当たったのか」を1枚の図で見られる形にする。

        $result = [];
        foreach ($groupsRaw as $g) {
            $kid = $g['kill_id'];
            $killAbility = $abilityOf($kid ?: (int) ($g['deaths'][0]['killingAbilityGameID'] ?? 0));

            // 死亡者ID集合 ＆ 各死亡者の詳細
            $victimIds = [];
            $victims = [];
            foreach ($g['deaths'] as $de) {
                $victimIds[(int) $de['targetID']] = true;
                $victims[] = $victimDetail($de);
            }

            // スナップショット基準時刻＝とどめ着弾の最早時刻（死亡イベントはラグがあるため位置がずれる）。
            // とどめが特定できなければ死亡イベント時刻にフォールバック。
            $ts = $g['first_ts'];
            $lethalTimes = array_filter(array_map(fn($v) => $v['lethal_ts'] ?? null, $victims));
            if (!empty($lethalTimes)) {
                $ts = min($lethalTimes);
            }

            // 被弾比較：とどめスキルを食らった人 / 回避した人（グループ時間帯）
            $hitIds = [];
            if ($kid) {
                foreach ($damageEvents as $dmg) {
                    if ((int) ($dmg['abilityGameID'] ?? 0) !== $kid) {
                        continue;
                    }
                    $dt = $dmg['timestamp'] ?? 0;
                    if ($dt < $g['first_ts'] - 2500 || $dt > $g['last_ts'] + 2500) {
                        continue;
                    }
                    if (($dmg['type'] ?? '') === 'calculateddamage') {
                        continue;
                    }
                    $tid = (int) ($dmg['targetID'] ?? -1);
                    if (isset($playerById[$tid])) {
                        $hitIds[$tid] = true;
                    }
                }
            }
            $hitPlayers = [];
            $safePlayers = [];
            foreach ($allPlayerIds as $pid) {
                if (isset($hitIds[$pid])) {
                    $hitPlayers[] = $playerById[$pid];
                } else {
                    $safePlayers[] = $playerById[$pid];
                }
            }

            // 座標スナップショット＋ボス座標
            $snapPoints = [];
            foreach ($allPlayerIds as $pid) {
                $p = $nearestPosition($posByPlayer[$pid] ?? [], $ts);
                if ($p) {
                    $snapPoints[$pid] = $p;
                }
            }
            $bossSnap = $nearestPosition($bossPos, $ts, 8000);

            // ===== AoE形状：キャスト駆動で構築（詠唱元座標＋対象から形状を決める）=====
            // 死亡イベントはラグがあるため、判定は $ts（とどめ着弾時刻）基準で行う。
            $boss = $bossSnap ? [$bossSnap['x'], $bossSnap['y']] : [10000, 10000];
            $lethalMax = !empty($lethalTimes) ? max($lethalTimes) : $g['last_ts'];
            // 詠唱テレグラフ（塔は詠唱が長い）を拾うため窓は広め。重なりは輪郭線のみ描画で緩和。
            $winStart = $ts - 8000;
            $winEnd   = $lethalMax + 1500;

            // 扇/直線ポリゴンを「頂点＋方向」から生成するヘルパー（ゲーム座標）
            $directionPolygon = function ($ct, $ax, $ay, $th, $rg) {
                if (in_array($ct, [3, 11, 13])) {
                    $half = 0.9; // 半角≈51.6°（角度はログに無く近似）
                    $pts = [[$ax, $ay]];
                    for ($i = -3; $i <= 3; $i++) {
                        $angle = $th + $half * ($i / 3);
                        $pts[] = [$ax + $rg * cos($angle), $ay + $rg * sin($angle)];
                    }
                    return ['kind' => 'poly', 'pts' => $pts];
                }
                $widthUnits = 600; // 直線幅6yalm（近似）
                $px = cos($th + M_PI / 2) * ($widthUnits / 2);
                $py = sin($th + M_PI / 2) * ($widthUnits / 2);
                $ex = $ax + $rg * cos($th);
                $ey = $ay + $rg * sin($th);
                return ['kind' => 'poly', 'pts' => [
                    [$ax + $px, $ay + $py], [$ex + $px, $ey + $py],
                    [$ex - $px, $ey - $py], [$ax - $px, $ay - $py],
                ]];
            };

            // 窓内で味方に着弾したアビリティ集合（赤=被弾AoE判定／全体攻撃ラベルにも使う）
            $dmgAids = [];
            foreach ($damageEvents as $dmg) {
                if (($dmg['type'] ?? '') === 'calculateddamage') {
                    continue;
                }
                if (!empty($dmg['tick'])) {
                    continue;
                }
                $dt = $dmg['timestamp'] ?? 0;
                if ($dt < $winStart || $dt > $winEnd) {
                    continue;
                }
                if (!isset($playerById[(int) ($dmg['targetID'] ?? -1)])) {
                    continue;
                }
                $aid = (int) ($dmg['abilityGameID'] ?? 0);
                if ($aid) {
                    $dmgAids[$aid] = true;
                }
            }

            // 全体攻撃ラベル（range>50。被弾アビリティから）
            $raidwideNames = [];
            foreach (array_keys($dmgAids) as $aid) {
                $gg = $actionGeo[$aid] ?? null;
                if ($gg && $gg['range'] > 50) {
                    $raidwideNames[$aid] = $abilityOf($aid)['name'];
                }
            }

            // キャストごとに形状を生成（詠唱元=sourceResources、対象=targetID）
            $shapesGame = [];
            $seenShape = [];
            $teleTowers = [];   // ケフカ: 描画した塔(tele円)のゲーム座標 [cx,cy,r]
            $coneCastInfo = []; // ケフカ: 扇cast(ct3/11/13)の時刻・範囲・被弾を収集
            $circleCastInfo = []; // ケフカ: 円/頭割りcast(ct2/7・プレイヤー対象)を収集
            foreach ($enemyCasts as $cast) {
                if (($cast['type'] ?? '') !== 'cast') {
                    continue;
                }
                $ctime = $cast['timestamp'] ?? 0;
                if ($ctime < $winStart || $ctime > $winEnd) {
                    continue;
                }
                $aid = (int) ($cast['abilityGameID'] ?? 0);
                if (!$aid) {
                    continue;
                }
                $geo = $actionGeo[$aid] ?? null;
                if (!$geo || $geo['range'] <= 0) {
                    continue;
                }
                $ct = $geo['castType'];
                if ($ct === 1) {
                    continue;
                }          // 単体
                if ($geo['range'] > 50) {          // 全体級は描かずラベル
                    $raidwideNames[$aid] = $abilityOf($aid)['name'];
                    continue;
                }
                $rg = $geo['range'] * 100;
                $hit = isset($dmgAids[$aid]); // 味方に着弾したか（赤）／否（黄=回避・受け止め）
                $sourceResources = $cast['sourceResources'] ?? null;
                $tgt = (int) ($cast['targetID'] ?? -1);
                // 対象プレイヤーの座標は「この詠唱時刻」のもの（テレグラフはこの時点でロックされ地面に固定）。
                // 死亡時刻ではない点に注意（プレイヤーは詠唱→着弾の間に移動する）。
                $tgtPos = isset($playerById[$tgt]) ? $nearestPosition($posByPlayer[$tgt] ?? [], $ctime, 6000) : null;


                $made = [];
                if (in_array($ct, [2, 7])) {
                    // ケフカ: プレイヤーに着弾した円/頭割り(=出し手が発動した実AoE)は後段で「マーカー保持∩塔踏み」
                    // から描き直す（cast情報のみ収集）。塔(tele=被弾なし)はそのまま描いてsoak判定に使う。
                    if ($kefka && $tgtPos && $hit) {
                        $circleCastInfo[] = ['ctime' => $ctime, 'rg' => $rg, 'hit' => $hit, 'aid' => $aid];
                        continue;
                    }
                    // 円（着弾点）：対象がプレイヤーならその位置、無ければ詠唱元（地面設置=塔等）
                    if ($tgtPos) {
                        $cx = $tgtPos['x'];
                        $cy = $tgtPos['y'];
                    } elseif ($sourceResources && isset($sourceResources['x'], $sourceResources['y'])) {
                        $cx = $sourceResources['x'];
                        $cy = $sourceResources['y'];
                    } else {
                        continue;
                    }
                    $made[] = ['kind' => 'circle', 'cx' => $cx, 'cy' => $cy, 'r' => $rg];
                } elseif ($ct === 5) {
                    // PBAoE＝詠唱元中心
                    if (!$sourceResources || !isset($sourceResources['x'], $sourceResources['y'])) {
                        continue;
                    }
                    $made[] = ['kind' => 'circle', 'cx' => $sourceResources['x'], 'cy' => $sourceResources['y'], 'r' => $rg];
                } elseif ($ct === 10) {
                    // ドーナツ＝詠唱元中心
                    if (!$sourceResources || !isset($sourceResources['x'], $sourceResources['y'])) {
                        continue;
                    }
                    $made[] = ['kind' => 'donut', 'cx' => $sourceResources['x'], 'cy' => $sourceResources['y'], 'ro' => $rg, 'ri' => $rg * 0.4];
                } elseif (in_array($ct, [3, 11, 13, 4, 8, 12])) {
                    // ケフカは後段で「扇マーカー(13DE)保持者∩塔踏み」から描く。ここではcast情報のみ収集。
                    if ($kefka && in_array($ct, [3, 11, 13])) {
                        $coneCastInfo[] = ['ctime' => $ctime, 'rg' => $rg, 'hit' => $hit, 'aid' => $aid];
                        continue;
                    }
                    // 通常＝cast対象プレイヤーを頂点、向き＝詠唱元→対象の外向き。
                    if (!$tgtPos) {
                        continue;
                    }
                    if ($sourceResources && isset($sourceResources['x'], $sourceResources['y'])) {
                        $th = atan2($tgtPos['y'] - $sourceResources['y'], $tgtPos['x'] - $sourceResources['x']);
                    } else {
                        $th = atan2($tgtPos['y'] - 10000, $tgtPos['x'] - 10000);
                    }
                    $made[] = $directionPolygon($ct, $tgtPos['x'], $tgtPos['y'], $th, $rg);
                } else {
                    continue;
                }

                foreach ($made as $one) {
                    $kx = $one['cx'] ?? ($one['pts'][0][0] ?? 0);
                    $ky = $one['cy'] ?? ($one['pts'][0][1] ?? 0);
                    $shapeKey = $aid . ':' . round($kx / 150) . ':' . round($ky / 150);
                    if (isset($seenShape[$shapeKey])) {
                        continue;
                    }
                    $seenShape[$shapeKey] = true;
                    $one['tele'] = !$hit;
                    $one['kill'] = ($aid === $kid); // 死因スキルか（強調表示用）
                    $shapesGame[] = $one;
                    // ケフカ塔踏み判定用：塔(tele円)のゲーム座標を控える
                    if ($kefka && !$hit && $one['kind'] === 'circle') {
                        $teleTowers[] = ['x' => $one['cx'], 'y' => $one['cy'], 'r' => $one['r']];
                    }
                }
            }

            // ケフカ：形状マーカー(扇13DE/円13DD/頭割り13DC)を持ち、かつ塔(tele円)に入った人＝AoEの出し手。
            // 各形状は「その形状のcast時刻」でマーカー保持と塔踏みを判定する（波の時刻で統一すると
            // 塔への移動タイミングがズレて誤判定するため）。
            if ($kefka && !empty($teleTowers)) {
                $shapeMarkerAt = function ($pid, $at) use ($shapeMarkEvents) {
                    $mk = 0;
                    foreach ($shapeMarkEvents as $me) {
                        if ($me['pid'] !== $pid) {
                            continue;
                        }
                        if ($me['t'] > $at) {
                            break;
                        }
                        if ($me['on']) {
                            $mk = $me['mk'];                       // 付与＝そのマーカーに
                        } elseif ($mk === $me['mk']) {
                            $mk = 0;                                   // 除去は現在のマーカーと一致時のみクリア
                        }
                    }
                    return $mk;
                };
                $inTower = function ($ow) use ($teleTowers) {
                    foreach ($teleTowers as $tw) {
                        if (sqrt(pow($ow['x'] - $tw['x'], 2) + pow($ow['y'] - $tw['y'], 2)) <= $tw['r']) {
                            return true;
                        }
                    }
                    return false;
                };
                $seenEmit = [];
                // 扇：13DE保持 ∩ 塔踏み（扇castごと・cast時刻で判定。マーカーは発動時に消えるので-1.5s）
                foreach ($coneCastInfo as $ci) {
                    $cct = $ci['ctime'];
                    // 死亡ビート付近のみ（離れた別ビートの出し手は移動済みで孤立円になるため除外）
                    if ($cct < $ts - 3000 || $cct > $lethalMax + 1500) {
                        continue;
                    }
                    foreach ($allPlayerIds as $pid) {
                        if ($shapeMarkerAt($pid, $cct - 1500) !== $coneShape) {
                            continue;
                        }
                        $ow = $nearestPosition($posByPlayer[$pid] ?? [], $cct, 6000);
                        if (!$ow || !$inTower($ow)) {
                            continue;
                        }
                        $shapeKey = 'cone:' . $pid . ':' . round($cct / 3000);
                        if (isset($seenEmit[$shapeKey])) {
                            continue;
                        }
                        $seenEmit[$shapeKey] = true;
                        $th = atan2($ow['y'] - 10000, $ow['x'] - 10000);
                        $poly = $directionPolygon(13, $ow['x'], $ow['y'], $th, $ci['rg']);
                        $poly['tele'] = !$ci['hit'];
                        $poly['kill'] = ($ci['aid'] === $kid);
                        $shapesGame[] = $poly;
                    }
                }
                // 円/頭割り：13DD/13DC保持 ∩ 塔踏み（円castごと）
                foreach ($circleCastInfo as $ci) {
                    $cct = $ci['ctime'];
                    // 死亡ビート付近のみ（離れた別ビートの出し手は移動済みで孤立円になるため除外）
                    if ($cct < $ts - 3000 || $cct > $lethalMax + 1500) {
                        continue;
                    }
                    foreach ($allPlayerIds as $pid) {
                        $mk = $shapeMarkerAt($pid, $cct - 1500);
                        if ($mk !== $circleShape && $mk !== $stackShape) {
                            continue;
                        }
                        $ow = $nearestPosition($posByPlayer[$pid] ?? [], $cct, 6000);
                        if (!$ow || !$inTower($ow)) {
                            continue;
                        }
                        $shapeKey = 'circ:' . $pid . ':' . round($cct / 3000);
                        if (isset($seenEmit[$shapeKey])) {
                            continue;
                        }
                        $seenEmit[$shapeKey] = true;
                        $shapesGame[] = [
                            'kind' => 'circle', 'cx' => $ow['x'], 'cy' => $ow['y'], 'r' => $ci['rg'],
                            'tele' => !$ci['hit'], 'kill' => ($ci['aid'] === $kid),
                        ];
                    }
                }
            }

            // 投影器はプレイヤー＋ボスのみで決める（大きなAoE=扇40y等に合わせるとプレイヤーが
            // 中央に縮こまり実態と乖離するため、AoEはスケールに含めない。AoEはSVG範囲外へはみ出し可）。
            $projInput = array_values($snapPoints);
            if ($bossSnap) {
                $projInput[] = $bossSnap;
            }
            $project = $makeProjector($projInput);

            // AoE形状をSVG座標へ変換
            $aoe = [];
            foreach ($shapesGame as $sh) {
                $tele = !empty($sh['tele']);
                $kill = !empty($sh['kill']);
                if ($sh['kind'] === 'circle') {
                    $c = $project($sh['cx'], $sh['cy']);
                    $screenRadius = abs($project($sh['cx'] + $sh['r'], $sh['cy'])['sx'] - $c['sx']);
                    $aoe[] = ['type' => 'circle', 'cx' => $c['sx'], 'cy' => $c['sy'], 'r' => round($screenRadius, 1), 'tele' => $tele, 'kill' => $kill];
                } elseif ($sh['kind'] === 'donut') {
                    $c = $project($sh['cx'], $sh['cy']);
                    $ro = abs($project($sh['cx'] + $sh['ro'], $sh['cy'])['sx'] - $c['sx']);
                    $riS = $sh['ri'] > 0 ? abs($project($sh['cx'] + $sh['ri'], $sh['cy'])['sx'] - $c['sx']) : 0;
                    $aoe[] = ['type' => 'donut', 'cx' => $c['sx'], 'cy' => $c['sy'], 'r_out' => round($ro, 1), 'r_in' => round($riS, 1), 'tele' => $tele, 'kill' => $kill];
                } else {
                    $poly = [];
                    foreach ($sh['pts'] as $pt) {
                        $xy2 = $project($pt[0], $pt[1]);
                        $poly[] = $xy2['sx'] . ',' . $xy2['sy'];
                    }
                    $aoe[] = ['type' => 'poly', 'points' => implode(' ', $poly), 'tele' => $tele, 'kill' => $kill];
                }
            }

            $dots = [];
            foreach ($snapPoints as $pid => $p) {
                $xy = $project($p['x'], $p['y']);
                $pl = $playerById[$pid];
                // 既に死亡（＝❌）か。このグループの死者(💀)は除く。蘇生されていれば生存扱い。
                $alreadyDead = false;
                if (!isset($victimIds[$pid])) {
                    $lastDeath = null;
                    foreach (($deathTimesByPid[$pid] ?? []) as $dts) {
                        if ($dts < $ts && ($lastDeath === null || $dts > $lastDeath)) {
                            $lastDeath = $dts;
                        }
                    }
                    if ($lastDeath !== null) {
                        // 最後の死亡後〜$tsに本人の活動(座標サンプル)があれば蘇生済み＝生存
                        $revived = false;
                        foreach ($posByPlayer[$pid] ?? [] as $ps) {
                            if ($ps['t'] > $lastDeath + 1000 && $ps['t'] <= $ts) {
                                $revived = true;
                                break;
                            }
                        }
                        $alreadyDead = !$revived;
                    }
                }
                $dots[] = [
                    'sx'           => $xy['sx'],
                    'sy'           => $xy['sy'],
                    'name'         => $pl['name'],
                    'job'          => $pl['job'],
                    'role'         => $pl['role'],
                    'icon'         => $pl['icon'],
                    'is_dead'      => isset($victimIds[$pid]),
                    'is_already_dead' => $alreadyDead,
                    'is_hit'       => isset($hitIds[$pid]),
                ];
            }
            $bossDot = null;
            if ($bossSnap) {
                $bxy = $project($bossSnap['x'], $bossSnap['y']);
                $bossDot = ['sx' => $bxy['sx'], 'sy' => $bxy['sy']];
            }

            $result[] = [
                'index'        => count($result),
                'time_start'   => gmdate('i:s', (int) (($g['first_ts'] - $startTime) / 1000)),
                'time_end'     => gmdate('i:s', (int) (($g['last_ts'] - $startTime) / 1000)),
                'multi_time'   => ($g['last_ts'] - $g['first_ts']) > 1500,
                'kill_ability' => $killAbility,
                'victim_count' => count($victims),
                'victims'      => $victims,
                'hit_players'  => $hitPlayers,
                'safe_players' => $safePlayers,
                'dots'         => $dots,
                'boss_dot'     => $bossDot,
                'aoe'          => $aoe,
                'raidwide_names' => array_values($raidwideNames),
            ];
        }

        return $result;
    }
}
