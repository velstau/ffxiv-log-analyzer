<?php

namespace App\Services;

use App\Exceptions\FFLogsRequestFailed;
use App\Support\DamageBreakdown;
use App\Support\FFXIVJobs;
use App\Support\MitigationSpec;

/**
 * 軽減タイムライン（/analyze）の組み立て。
 *
 * 1本のログから、ボスの攻撃1発ごとに「誰が何を撃って何%軽減できていたか」の表を作る。
 *
 * build() は次の順に積み上げるパイプラインで、各段は独立した private メソッドになっている。
 * 順番には理由があり、入れ替えると壊れる。
 *
 *   1. eventQueryIds()             取得対象のIDを決める（軽減・バリア・シナジー）
 *   2. FFLogsService::getEvents()  1回のGraphQLでまとめて取る
 *   3. nameResolver()              ID→名前（ペットは飼い主に寄せる）
 *   4. buildPlayerColumns()        出ているジョブを確定させる
 *   5. buildMitigationColumns()    ── 4 の結果で列を絞るので 4 の後
 *   6. flattenMitigationActionIds() 監視するアクションIDと、撃てるジョブの対応
 *   7. buildStatusMaxDurations()   バリアの効果時間上限（割れ判定用）
 *   8. buildEnemyDebuffWindows()   敵デバフの有効区間
 *   9. buildActiveBuffWindows()    味方バフの有効区間
 *  10. groupDamageEvents()         ── 8,9 を引きながら被弾行を作るので 8,9 の後
 *  11. buildPlayerTimelines()      プレイヤーごとの使用スキル時系列
 *  12. markSkillUsage()            ── 使用マーカーを「最も近い被弾行」に置くので 10 の後
 *  13. annotatePhases()            イベントにフェーズ番号を振る
 *  14. buildSynergyWindows()       シナジーをバースト窓単位にまとめる
 *  15. buildDeathSnapshots()       死亡ごとのスナップショット
 *
 * 返すのはビューに渡す連想配列。キーは compact() で作るため、
 * 名前を変えるときは resources/views/fflogs/timeline.blade.php も同時に直すこと
 * （文字列参照なので静的解析では追えない）。
 */
class TimelineBuilder
{
    public function __construct(
        private readonly FFLogsService $fflogs,
        private readonly DeathCheckerBuilder $deathChecker,
    ) {}

    /**
     * 軽減タイムライン画面に渡すデータ一式を組み立てる。
     *
     * @param  array  $fight  FFLogs の fight（startTime / endTime / phaseTransitions などを含む）
     * @return array{fight:array, events:array, playerColumns:array, mitigationColumns:array,
     *   mitigationGroups:array, mitigationGroupIcons:array, playerDetails:array,
     *   playerTimelines:array, start_time:int, synergyWindows:array, deaths:array, hasPhases:bool}
     */
    public function build(array $parsed, array $fight): array
    {
        $mitigationSpec = MitigationSpec::columns();
        $barrierMaxDurations = MitigationSpec::barrierDurations();
        $barrierIDs = MitigationSpec::barriers();
        $synergySpec = MitigationSpec::synergies();

        $reportData = $this->fflogs->getEvents(
            $parsed['code'],
            $parsed['fightId'],
            $fight['startTime'],
            $fight['endTime'],
            $this->eventQueryIds($mitigationSpec, $barrierIDs, $synergySpec),
        );

        if (isset($reportData['errors'])) {
            throw FFLogsRequestFailed::fromApiErrors($reportData['errors']);
        }

        $masterData = $reportData["masterData"] ?? [];

        $damageEvents = $reportData['damageTaken']['data'] ?? [];
        $masterActors = collect($reportData['masterData']['actors'] ?? [])->keyBy('id');
        $masterAbilities = collect($reportData['masterData']['abilities'] ?? [])->keyBy('gameID');

        $startTime = $fight['startTime'];

        $getName = $this->nameResolver($masterData);

        [
            'details' => $playerDetails,
            'stats' => $playerStats,
            'columns' => $playerColumns,
            'jobIcons' => $jobIconNameMap,
            'spec' => $mitigationSpec,
        ] = $this->buildPlayerColumns($damageEvents, $masterActors, $fight, $mitigationSpec, $getName);

        [
            'columns' => $mitigationColumns,
            'groups' => $mitigationGroups,
            'groupIcons' => $mitigationGroupIcons,
            'gameIcons' => $gameIconMap,
        ] = $this->buildMitigationColumns($mitigationSpec, $masterAbilities, $jobIconNameMap);

        ['actionIds' => $allMitigationActionIds, 'jobMap' => $actionIdJobMap]
            = $this->flattenMitigationActionIds($mitigationColumns);

        $abbrByName = FFXIVJobs::abbrByDisplayName();

        $statusMaxDurations = $this->buildStatusMaxDurations($barrierIDs, $barrierMaxDurations);
        $enemyDebuffsMap = $this->buildEnemyDebuffWindows($reportData, $fight, $getName);
        $activeBuffsMap = $this->buildActiveBuffWindows(
            $reportData,
            $fight,
            $statusMaxDurations,
            $masterActors,
            $getName,
        );
        $processedEvents = $this->groupDamageEvents(
            $damageEvents,
            $mitigationColumns,
            $activeBuffsMap,
            $enemyDebuffsMap,
            $playerDetails,
            $abbrByName,
            $jobIconNameMap,
            $getName,
            $startTime,
        );

        // プレイヤーのキャストは行として出さない。軽減スキルの使用は「使用マーカー」として
        // ダメージ行の上に重ねる方式にしたため、ここで行を足すとタイムラインが二重になり、
        // かつ敵スキルと見分けがつかなくなる（実際にそう報告された）。

        // Sort combined events
        usort($processedEvents, function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        // 生データは巨大なうえ後段では使わないので、ここで落としておく
        unset($reportData['_raw']);

        $rawFriendCasts = $reportData['friendCasts']['data'] ?? [];

        $playerTimelines = $this->buildPlayerTimelines(
            $rawFriendCasts,
            $playerDetails,
            $processedEvents,
            $activeBuffsMap,
            $actionIdJobMap,
            $allMitigationActionIds,
            $barrierIDs,
            $barrierMaxDurations,
            $masterActors,
            $masterAbilities,
            $getName,
            $startTime,
        );
        $this->markSkillUsage(
            $processedEvents,
            $mitigationColumns,
            $rawFriendCasts,
            $masterActors,
            $getName,
            $startTime,
        );

        $events = $processedEvents;
        $start_time = $fight['startTime']; // Ensure variable exists for compact

        $hasPhases = $this->annotatePhases($events, $fight);

        $synergyWindows = $this->buildSynergyWindows(
            $synergySpec,
            $rawFriendCasts,
            $reportData,
            $playerDetails,
            $masterActors,
            $masterAbilities,
            $gameIconMap,
            $getName,
            $startTime,
        );
        $deaths = $this->buildDeathSnapshots(
            $reportData,
            $fight,
            $mitigationColumns,
            $barrierIDs,
            $damageEvents,
            $masterActors,
            $masterAbilities,
            $playerDetails,
            $playerStats,
            $startTime,
        );
        return compact(
            'fight',
            'events',
            'playerColumns',
            'mitigationColumns',
            'mitigationGroups',
            'mitigationGroupIcons',
            'playerDetails',
            'playerTimelines',
            'start_time',
            'synergyWindows',
            'deaths',
            'hasPhases',
        );
    }

    /**
     * 死亡ごとのスナップショット（死因チェッカー）を作る。
     *
     * 死亡時に効いていた軽減／バリアを引けるよう、表示中の軽減列からステータスIDの逆引き表を作り、
     * とどめ・被弾・敵の詠唱に出てくるアクションのAoE形状もまとめて解決してから組み立てる。
     */
    private function buildDeathSnapshots(
        array $reportData,
        array $fight,
        array $mitigationColumns,
        array $barrierIDs,
        array $damageEvents,
        $masterActors,
        $masterAbilities,
        array $playerDetails,
        array $playerStats,
        int $startTime,
    ): array {
        $deathEvents = $reportData['deaths']['data'] ?? [];

        // 死亡時バフ照合用：ステータスID → 軽減/バリアスキル{name,icon}（表示中の軽減列から構築）
        $mitByStatus = [];
        foreach ($mitigationColumns as $col) {
            foreach (($col['ids'] ?? []) as $sid) {
                $sid = (int) $sid;
                if (!isset($mitByStatus[$sid])) {
                    $mitByStatus[$sid] = ['name' => $col['name'], 'icon' => $col['icon'] ?? null];
                }
            }
        }
        // バリア（シールド）に該当するステータスID集合
        $barrierSet = [];
        foreach ($barrierIDs as $act => $st) {
            foreach ((array) $st as $s) {
                $barrierSet[(int) $s] = true;
            }
        }

        // AoEジオメトリ（形状・範囲）対象ID＝とどめ＋被弾アビリティ＋敵キャスト（テレグラフ）
        $enemyCasts = $reportData['enemyCasts']['data'] ?? [];
        $geoIds = [];
        foreach ($deathEvents as $de) {
            if (!empty($de['killingAbilityGameID'])) {
                $geoIds[] = (int) $de['killingAbilityGameID'];
            }
        }
        foreach ($damageEvents as $dmg) {
            if (!empty($dmg['abilityGameID'])) {
                $geoIds[] = (int) $dmg['abilityGameID'];
            }
        }
        foreach ($enemyCasts as $ec) {
            if (!empty($ec['abilityGameID'])) {
                $geoIds[] = (int) $ec['abilityGameID'];
            }
        }
        $actionGeo = $this->fflogs->getActionGeometry(array_values(array_unique($geoIds)));

        $deaths = $this->deathChecker->build(
            $deathEvents,
            $damageEvents,
            $masterActors,
            $masterAbilities,
            $playerDetails,
            $playerStats,
            $startTime,
            $mitByStatus,
            $barrierSet,
            $actionGeo,
            $enemyCasts,
            $reportData['friendPositions']['data'] ?? [],
            $reportData['headmarkers']['data'] ?? [],
            (int) ($fight['gameZone']['id'] ?? 0),
            $reportData['shapeMarkers']['data'] ?? [],
            $reportData['enemyDebuffs']['data'] ?? [],
        );

        return $deaths;
    }

    /**
     * シナジー（攻撃強化バフ・薬）の使用を「バースト窓」単位にまとめる。
     *
     * 1行＝1人ぶんの使用で、近接した使用を1つの窓に束ねる。窓はリキャスト60秒以上のスキルと薬
     * （＝アンカー）だけで形成し、短いリキャストのスキルは窓に時間が重なったときだけ乗せる。
     * オフバーストで撃たれた短CTスキルを窓に混ぜると、合わせたように見えてしまうため。
     *
     * @param  \Closure  $getName  ID から名前を引くヘルパー
     */
    private function buildSynergyWindows(
        array $synergySpec,
        array $rawFriendCasts,
        array $reportData,
        array $playerDetails,
        $masterActors,
        $masterAbilities,
        array $gameIconMap,
        \Closure $getName,
        int $startTime,
    ): array {
        // 行＝1使用イベント。時系列に並べ、近接した使用を「バースト窓」としてグループ化する。
        $synergyActionMap = []; // actionId => ['skill','cd','dur','job']
        foreach ($synergySpec as $job => $cols) {
            foreach ($cols as $skillName => $c) {
                foreach (($c['actionIds'] ?? []) as $aid) {
                    // グループキーが MNK2 等の場合は 'job' 指定で実ジョブ略称に補正
                    $synergyActionMap[$aid] = ['skill' => $skillName, 'cd' => $c['cd'], 'dur' => $c['dur'], 'job' => $c['job'] ?? $job];
                }
            }
        }

        $synergyEvents = [];
        // (1) レイドバフ・個人バフ：friendCasts から1キャスト=1行
        foreach ($rawFriendCasts as $ev) {
            $abId = $ev['abilityGameID'] ?? 0;
            if ($abId > 1000000) {
                $abId = $abId % 1000000;
            }
            if (!isset($synergyActionMap[$abId])) {
                continue;
            }
            $meta = $synergyActionMap[$abId];

            $srcId = $ev['sourceID'] ?? 0;
            $sActor = $masterActors->get($srcId);
            if ($sActor && isset($sActor['petOwner'])) {
                $srcId = $sActor['petOwner'];
            }
            $srcName = $getName($srcId, 'actor');
            if (!isset($playerDetails[$srcName])) {
                continue;
            } // プレイヤーの使用のみ

            $tgtId = $ev['targetID'] ?? 0;
            $tgtName = $getName($tgtId, 'actor');
            // 自己／全体バフ（対象がEnvironment・自分自身・不明）は対象表示しない。カードや敵デバフのみ対象を出す。
            $isSelf = ($tgtId === ($ev['sourceID'] ?? -1))
                || ($tgtName === $srcName)
                || ($tgtName === 'Environment')
                || ($tgtName === '')
                || (strpos($tgtName, 'Unknown') !== false);

            $icon = '';
            if ($ab = $masterAbilities->get((int) $abId)) {
                $icon = $ab['icon'];
            } elseif (!empty($gameIconMap[$abId])) {
                $icon = $gameIconMap[$abId];
            }

            $synergyEvents[] = [
                'timestamp' => $ev['timestamp'],
                'rel_time' => ($ev['timestamp'] - $startTime) / 1000,
                'player' => $srcName,
                'job' => $playerDetails[$srcName]['job'] ?? $meta['job'],
                'player_icon' => $playerDetails[$srcName]['icon'] ?? null,
                'skill' => $meta['skill'],
                'skill_icon' => $icon,
                'target' => $tgtName,
                'self_target' => $isSelf,
                'cd' => $meta['cd'],
                'is_item' => false,
                'sort_job' => $meta['job'], // ソート用：スキルが属するジョブ
                'dur' => $meta['dur'] / 1000,
            ];
        }
        // (2) 薬：friendBuffs の Medicated(49) applybuff から検出（薬はアクションIDが多様なためステータスで判定）。
        //     ・ペット（アーサリースター/分身等）は術者のMedicatedを継承するため除外（実プレイヤーのみ）
        //     ・薬はリキャスト270秒。同名プレイヤーは270秒以内の重複（複数アクターID／継承等）を除外し1人1回にする
        $potionRaw = [];
        foreach ($reportData['friendBuffs']['data'] ?? [] as $ev) {
            $abId = $ev['abilityGameID'] ?? 0;
            if ($abId > 1000000) {
                $abId = $abId % 1000000;
            }
            if ($abId !== 49) {
                continue;
            }
            if (($ev['type'] ?? '') !== 'applybuff') {
                continue;
            }
            $tgtActor = $masterActors->get($ev['targetID'] ?? 0);
            if (!$tgtActor || ($tgtActor['type'] ?? '') !== 'Player') {
                continue;
            } // ペット除外
            $tgtName = $tgtActor['name'];
            if (!isset($playerDetails[$tgtName])) {
                continue;
            }
            $potionRaw[] = ['ts' => $ev['timestamp'], 'name' => $tgtName];
        }
        usort($potionRaw, fn($a, $b) => $a['ts'] <=> $b['ts']);
        $potionLast = [];
        foreach ($potionRaw as $pe) {
            // 同一プレイヤーがリキャスト(270秒)以内に複数回出てきたら最初の1回だけ採用
            if (isset($potionLast[$pe['name']]) && ($pe['ts'] - $potionLast[$pe['name']]) < 270000) {
                continue;
            }
            $potionLast[$pe['name']] = $pe['ts'];

            $synergyEvents[] = [
                'timestamp' => $pe['ts'],
                'rel_time' => ($pe['ts'] - $startTime) / 1000,
                'player' => $pe['name'],
                'job' => $playerDetails[$pe['name']]['job'] ?? '',
                'player_icon' => $playerDetails[$pe['name']]['icon'] ?? null,
                'skill' => '薬',
                'skill_icon' => '',
                'target' => $pe['name'],
                'self_target' => true,
                'cd' => 0,
                'is_item' => true,
                'sort_job' => 'ITEM',
                'dur' => 30,
            ];
        }

        // アンカー（2分/1分=CT60秒以上＋薬）と短CT（マイナー＝CT60秒未満）に分離。
        // バースト窓はアンカーのみで形成し、短CTはその窓に時間が重なったときだけ表示する（オフバースト使用は除外）。
        $anchors = array_values(array_filter($synergyEvents, fn($e) => $e['is_item'] || $e['cd'] >= 60));
        $minors  = array_values(array_filter($synergyEvents, fn($e) => !$e['is_item'] && $e['cd'] < 60));

        usort($anchors, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        $burstGap = 8000;
        $grp = 0;
        $prevT = null;
        $windowRanges = []; // group => ['start'=>ts,'end'=>ts]
        foreach ($anchors as &$se) {
            if ($prevT !== null && ($se['timestamp'] - $prevT) > $burstGap) {
                $grp++;
            }
            $se['group'] = $grp;
            $prevT = $se['timestamp'];
            if (!isset($windowRanges[$grp])) {
                $windowRanges[$grp] = ['start' => $se['timestamp'], 'end' => $se['timestamp']];
            }
            $windowRanges[$grp]['start'] = min($windowRanges[$grp]['start'], $se['timestamp']);
            $windowRanges[$grp]['end'] = max($windowRanges[$grp]['end'], $se['timestamp']);
        }
        unset($se);

        // 短CTスキルを、時間が重なる窓へ割り当て（前後7秒のバッファ）。どの窓にも入らなければ捨てる。
        // 1分窓（アンカーが少なく範囲が狭い）でも30秒スキル等を取りこぼさないよう広めに取る。
        $buffer = 7000;
        $assignedMinors = [];
        foreach ($minors as $m) {
            foreach ($windowRanges as $gi => $r) {
                if ($m['timestamp'] >= $r['start'] - $buffer && $m['timestamp'] <= $r['end'] + $buffer) {
                    $m['group'] = $gi;
                    $assignedMinors[] = $m;
                    break;
                }
            }
        }

        // アンカー＋割り当て済み短CT を集約対象にする
        $synergyEvents = array_merge($anchors, $assignedMinors);

        // ジョブの並び順（タンク→ヒラ→近接→遠隔→キャスター）。薬は最後。
        $jobOrder = FFXIVJobs::displayOrderByAbbr();

        // バースト窓ごとに「人単位で1行」に集約（各人が窓内で使ったシナジースキルを横並び）
        $windowsMap = [];
        foreach ($synergyEvents as $se) {
            $g = $se['group'];
            $pkey = $se['player'];
            if (!isset($windowsMap[$g])) {
                $windowsMap[$g] = ['start' => $se['rel_time'], 'end' => $se['rel_time'], 'players' => []];
            }
            $windowsMap[$g]['start'] = min($windowsMap[$g]['start'], $se['rel_time']);
            $windowsMap[$g]['end'] = max($windowsMap[$g]['end'], $se['rel_time']);
            if (!isset($windowsMap[$g]['players'][$pkey])) {
                $windowsMap[$g]['players'][$pkey] = [
                    'player' => $se['player'],
                    'job' => $se['job'],
                    'player_icon' => $se['player_icon'],
                    'first_time' => $se['rel_time'],
                    'skills' => [],
                ];
            }
            $p = &$windowsMap[$g]['players'][$pkey];
            $p['first_time'] = min($p['first_time'], $se['rel_time']);
            $p['skills'][] = [
                'skill' => $se['skill'],
                'skill_icon' => $se['skill_icon'],
                'cd' => $se['cd'],
                'is_item' => $se['is_item'],
                'target' => $se['target'],
                'self_target' => $se['self_target'],
                'rel_time' => $se['rel_time'],
            ];
            unset($p);
        }

        $synergyWindows = [];
        foreach ($windowsMap as $g => $w) {
            $players = array_values($w['players']);
            foreach ($players as &$p) {
                // 各人のスキルは CT の大きい順（2分→1分→短CT→薬）、同じなら時刻順
                usort($p['skills'], function ($a, $b) {
                    $ra = $a['is_item'] ? -1 : $a['cd'];
                    $rb = $b['is_item'] ? -1 : $b['cd'];
                    return $ra === $rb ? ($a['rel_time'] <=> $b['rel_time']) : ($rb <=> $ra);
                });
            }
            unset($p);
            // 窓内の人をジョブ順にソート（同順位は最初の使用時刻順）
            usort($players, function ($a, $b) use ($jobOrder) {
                $pa = $jobOrder[$a['job']] ?? 900;
                $pb = $jobOrder[$b['job']] ?? 900;
                return $pa === $pb ? ($a['first_time'] <=> $b['first_time']) : ($pa <=> $pb);
            });
            $total = array_sum(array_map(fn($p) => count($p['skills']), $players));
            $synergyWindows[] = [
                'index' => $g,
                'start' => $w['start'],
                'end' => $w['end'],
                'players_count' => count($players),
                'total' => $total,
                'players' => $players,
            ];
        }

        return $synergyWindows;
    }

    /**
     * 各イベントにフェーズ番号（P1〜P5等）を振る。
     *
     * phaseTransitions は「到達したフェーズ」だけが {id, startTime} で返る。
     * イベントの時刻を境界と突き合わせ、直近に到達しているフェーズの番号を付ける。
     *
     * @return bool フェーズ分けのあるボスなら true（無ければイベントに phase は付かない）
     */
    private function annotatePhases(array &$events, array $fight): bool
    {
        $phaseTransitions = $fight['phaseTransitions'] ?? [];
        $hasPhases = is_array($phaseTransitions) && count($phaseTransitions) > 1;
        if ($hasPhases) {
            usort($phaseTransitions, fn($a, $b) => ($a['startTime'] ?? 0) <=> ($b['startTime'] ?? 0));
            foreach ($events as &$evtP) {
                $tsP = $evtP['timestamp'] ?? 0;
                $phNum = $phaseTransitions[0]['id'] ?? 1;
                foreach ($phaseTransitions as $pt) {
                    if ($tsP >= ($pt['startTime'] ?? 0)) {
                        $phNum = $pt['id'] ?? $phNum;
                    } else {
                        break;
                    }
                }
                $evtP['phase'] = $phNum;
            }
            unset($evtP);
        }

        return $hasPhases;
    }

    /**
     * 各セルに「使用マーカー / 効果中 / リキャスト中」を付与する。
     *
     * マトリクスの行は敵のダメージイベントなので時刻が飛び飛びになる。スキルを使った瞬間に行が
     * 無いと使用箇所が見えないため、(1) 使用に最も近い行へ「使用」マーカーを置き、
     * (2) 効果時間中は「効果中」、(3) 効果後リキャスト完了までは「リキャスト中」の帯を付ける。
     *
     * @param  \Closure  $getName  ID から名前を引くヘルパー
     */
    private function markSkillUsage(
        array &$processedEvents,
        array $mitigationColumns,
        array $rawFriendCasts,
        $masterActors,
        \Closure $getName,
        int $startTime,
    ): void {
        $cooldownMap = MitigationSpec::cooldowns();
        $effectMap = MitigationSpec::effectDurations();
        $subTypeToAbbr = FFXIVJobs::abbrByType();
        $roleMembers = FFXIVJobs::abbrsByRoleGroup();

        // 列ごとにキャスト（使用時刻・効果終了・リキャスト終了）を収集
        $columnCasts = []; // uniqueKey => [ ['t'=>使用時刻, 'eff'=>効果終了, 'cd'=>リキャスト終了|null, 'src'=>使用者], ... ]
        foreach ($mitigationColumns as $ukey => $col) {
            $actIds = $col['actionIds'] ?? [];
            if (empty($actIds)) {
                continue;
            }
            $grp = $col['group'] ?? '';
            $allowedAbbrs = $roleMembers[$grp] ?? [$grp]; // ジョブ列はその略称、ロール列は所属ジョブ群
            $casts = [];
            foreach ($rawFriendCasts as $ev) {
                $abId = $ev['abilityGameID'] ?? 0;
                if ($abId > 1000000) {
                    $abId = $abId % 1000000;
                }
                if (!in_array($abId, $actIds)) {
                    continue;
                }
                // 使用者のジョブがこの列に合致するか
                $sActor = $masterActors->get($ev['sourceID'] ?? 0);
                $srcAbbr = $subTypeToAbbr[$sActor['subType'] ?? ''] ?? null;
                if ($srcAbbr === null || !in_array($srcAbbr, $allowedAbbrs)) {
                    continue;
                }

                $ts = $ev['timestamp'];
                $cd = $cooldownMap[$abId] ?? null;
                $eff = $effectMap[$abId] ?? 0;
                $casts[] = [
                    't' => $ts,
                    'eff' => $ts + $eff,
                    'cd' => $cd !== null ? $ts + $cd : null,
                    'src' => $getName($ev['sourceID'] ?? 0, 'actor'),
                ];
            }
            if (!empty($casts)) {
                $columnCasts[$ukey] = $casts;
            }
        }

        // (A) 各ダメージ行×列に「効果中(up) / リキャスト中(cooldown)」を付与
        foreach ($processedEvents as &$evt) {
            $t = $evt['timestamp'];
            foreach ($columnCasts as $ukey => $casts) {
                if (!isset($evt['cols'][$ukey])) {
                    continue;
                }
                if (!empty($evt['cols'][$ukey]['active'])) {
                    continue;
                } // 被弾を実際に軽減＝青チェック優先
                $state = null;
                foreach ($casts as $c) {
                    if ($t >= $c['t'] && $t < $c['eff']) {
                        $state = 'up';
                        break;
                    }          // 効果時間中
                    if ($c['cd'] !== null && $t >= $c['t'] && $t < $c['cd']) {
                        $state = 'cd';
                    } // 効果後〜リキャスト完了
                }
                if ($state === 'up') {
                    $evt['cols'][$ukey]['up'] = true;
                } elseif ($state === 'cd') {
                    $evt['cols'][$ukey]['cooldown'] = true;
                }
            }
        }
        unset($evt);

        // (B) 各キャストを「最も近いダメージ行」に使用マーカーとして配置（その時刻に行が無くても使用箇所が分かる）
        foreach ($columnCasts as $ukey => $casts) {
            foreach ($casts as $c) {
                $bestIdx = null;
                $bestDiff = PHP_INT_MAX;
                foreach ($processedEvents as $i => $e) {
                    $d = abs($e['timestamp'] - $c['t']);
                    if ($d < $bestDiff) {
                        $bestDiff = $d;
                        $bestIdx = $i;
                    }
                }
                if ($bestIdx !== null && isset($processedEvents[$bestIdx]['cols'][$ukey])) {
                    $processedEvents[$bestIdx]['cols'][$ukey]['used'] = true;
                    $processedEvents[$bestIdx]['cols'][$ukey]['used_time'] = ($c['t'] - $startTime) / 1000; // 実際の使用秒
                    $processedEvents[$bestIdx]['cols'][$ukey]['used_src'] = $c['src'];
                }
            }
        }
    }

    /**
     * プレイヤーごとの使用スキル時系列（被弾内訳モーダルで見るタイムライン）を作る。
     *
     * friendCasts を1件ずつ見て、そのスキルが軽減列のどれに当たるかを判定し、
     * バリアなら効果時間の上限と比べて「割れたか」も併せて記録する。
     *
     * @param  \Closure  $getName  ID から名前を引くヘルパー
     * @return array プレイヤー名 => 時系列（時刻順）
     */
    private function buildPlayerTimelines(
        array $rawFriendCasts,
        array $playerDetails,
        array $processedEvents,
        array $activeBuffsMap,
        array $actionIdJobMap,
        array $allMitigationActionIds,
        array $barrierIDs,
        array $barrierMaxDurations,
        $masterActors,
        $masterAbilities,
        \Closure $getName,
        int $startTime,
    ): array {
        $playerTimelines = [];

        foreach ($playerDetails as $pName => $d) {
            $playerTimelines[$pName] = [];
        }

        foreach ($rawFriendCasts as $event) {
            $abId = $event['abilityGameID'] ?? 0;
            if ($abId > 1000000) {
                $abId = $abId % 1000000;
            }

            // Allow matching by EITHER Action ID OR Buff ID (fallback)
            // Some skills might be returned as one but we tracked the other
            if (in_array($abId, $allMitigationActionIds)) {
                $sourceID = $event['sourceID'] ?? 0;

                // Check for Pet Owner
                $actorData = $masterActors->get($sourceID);
                if ($actorData && isset($actorData['petOwner'])) {
                    $sourceID = $actorData['petOwner'];
                }

                $sourceName = $getName($sourceID, 'actor');
                $sJob = $getName($sourceID, 'type');
                if (isset($actionIdJobMap[$abId])) {
                    $sActor = $masterActors->get($sourceID);
                    $sJob = $sActor["subType"] ?? "Unknown";
                    $allowed = $actionIdJobMap[$abId];
                    // Strict check: if job is not in allowed list, skip
                    $mappedJob = $abbrByName[$sJob] ?? $sJob;
                    if (!in_array($sJob, $allowed) && !in_array($mappedJob, $allowed)) {
                        continue;
                    }
                }

                // If sourceName is a Player, add to timeline
                if (isset($playerTimelines[$sourceName])) {
                    $name = $getName($abId, 'ability');

                    // 対象（ターゲット）名を解決。自分自身に使った場合は self 扱い
                    $targetActorId = $event['targetID'] ?? 0;
                    $targetName = $getName($targetActorId, 'actor');
                    $isSelfTarget = ($targetActorId === ($event['sourceID'] ?? -1)) || ($targetName === $sourceName);

                    $icon = '';
                    if ($ab = $masterAbilities->get((int) $abId)) {
                        $icon = $ab['icon'];
                    }

                    $realDuration = null;
                    $isBroken = false;
                    if (isset($barrierIDs[$abId])) {
                        $statusIds = (array) $barrierIDs[$abId];
                        $targetId = $event["targetID"] ?? 0;

                        foreach ($statusIds as $statusId) {
                            if (isset($activeBuffsMap[$targetId][$statusId])) {
                                $candidates = $activeBuffsMap[$targetId][$statusId];
                                foreach ($candidates as $buff) {
                                    $timeDiff = $buff["start"] - $event["timestamp"];
                                    if ($timeDiff >= -5000 && $timeDiff <= 5000) {
                                        $rDur = $buff["end"] - $buff["start"];

                                        // Use pre-calculated broken status from activeBuffsMap (Processing Loop)
                                        if ($buff['isBroken'] ?? false) {
                                            $isBroken = true;
                                        }

                                        if (isset($barrierMaxDurations[$abId])) {
                                            $maxD = $barrierMaxDurations[$abId];
                                            if ($realDuration === null || $rDur > $realDuration) {
                                                $realDuration = $rDur;
                                            }
                                            if ($rDur < ($maxD - 1000)) {
                                                $isBroken = true;
                                            }
                                        }
                                        break;
                                    }
                                }
                            }
                        }
                    }

                    $duration = 15000;
                    if ($realDuration !== null) {
                        $duration = $realDuration;
                    } else {
                        // FALLBACK HARDCODED DURATIONS
                        // TANK
                        if (in_array($abId, [30])) {
                            $duration = 10000;
                        } // インビンシブル
                        if (in_array($abId, [17, 36920])) {
                            $duration = 15000;
                        } // エクストリームガード / センチネル
                        if (in_array($abId, [25746])) {
                            $duration = 8000;
                        } // ホーリーシェルトロン
                        if (in_array($abId, [22])) {
                            $duration = 10000;
                        } // ブルワーク
                        if (in_array($abId, [3540])) {
                            $duration = 7000;
                        } // ディヴァインヴェール
                        if (in_array($abId, [7385])) {
                            $duration = 10000;
                        } // パッセージ・オブ・アームズ
                        // WAR
                        if (in_array($abId, [43])) {
                            $duration = 10000;
                        } // ホルムギャング
                        if (in_array($abId, [44, 36923])) {
                            $duration = 15000;
                        } // ダムネーション / ヴェンジェンス
                        if (in_array($abId, [16464])) {
                            $duration = 8000;
                        } // 原初の猛り
                        if (in_array($abId, [40])) {
                            $duration = 10000;
                        } // スリル・オブ・バトル
                        if (in_array($abId, [7388])) {
                            $duration = 10000;
                        } // シェイクオフ
                        // DRK
                        if (in_array($abId, [3638])) {
                            $duration = 10000;
                        } // リビングデッド
                        if (in_array($abId, [3636, 36927])) {
                            $duration = 15000;
                        } // シャドウヴィジル / シャドウウォール
                        if (in_array($abId, [3634])) {
                            $duration = 10000;
                        } // ダークマインド
                        if (in_array($abId, [7393])) {
                            $duration = 7000;
                        } // ブラックナイト
                        if (in_array($abId, [25754])) {
                            $duration = 10000;
                        } // オブレーション
                        if (in_array($abId, [16471])) {
                            $duration = 15000;
                        } // ダークミッショナリー
                        // GNB
                        if (in_array($abId, [16152])) {
                            $duration = 10000;
                        } // ボーライド
                        if (in_array($abId, [36935])) {
                            $duration = 15000;
                        } // グレートネビュラ / ネビュラ
                        if (in_array($abId, [16140])) {
                            $duration = 20000;
                        } // カモフラージュ
                        if (in_array($abId, [25758])) {
                            $duration = 8000;
                        } // ハート・オブ・コランダム
                        if (in_array($abId, [16160])) {
                            $duration = 15000;
                        } // ハート・オブ・ライト
                        // ROLE
                        if (in_array($abId, [199, 4241])) {
                            $duration = 10000;
                        } // タンクLB
                        if (in_array($abId, [7535, 22198])) {
                            $duration = 15000;
                        } // リプライザル
                        if (in_array($abId, [7531])) {
                            $duration = 20000;
                        } // ランパート
                        // WHM
                        if (in_array($abId, [3569])) {
                            $duration = 24000;
                        } // アサイラム
                        if (in_array($abId, [7432])) {
                            $duration = 30000;
                        } // ディヴァインベニゾン
                        if (in_array($abId, [25861])) {
                            $duration = 8000;
                        } // アクアヴェール
                        if (in_array($abId, [7433])) {
                            $duration = 10000;
                        } // インドゥルゲンティア
                        if (in_array($abId, [16536])) {
                            $duration = 20000;
                        } // テンパランス
                        if (in_array($abId, [37011])) {
                            $duration = 10000;
                        } // ディヴァインカレス
                        if (in_array($abId, [25862])) {
                            $duration = 20000;
                        } // リタージー・オブ・ベル
                        // SCH
                        if (in_array($abId, [188])) {
                            $duration = 15000;
                        } // 野戦治療の陣
                        if (in_array($abId, [805])) {
                            $duration = 20000;
                        } // フェイイルミネーション
                        if (in_array($abId, [185])) {
                            $duration = 30000;
                        } // 鼓舞激励の策
                        if (in_array($abId, [16545])) {
                            $duration = 22000;
                        } // サモン・セラフィム
                        if (in_array($abId, [16547])) {
                            $duration = 30000;
                        } // コンソレイション
                        if (in_array($abId, [25867])) {
                            $duration = 10000;
                        } // 生命回生法
                        if (in_array($abId, [25868])) {
                            $duration = 20000;
                        } // 疾風怒濤の計
                        if (in_array($abId, [37014])) {
                            $duration = 20000;
                        } // セラフィズム
                        // AST
                        if (in_array($abId, [3613])) {
                            $duration = 10000;
                        } // 運命の輪
                        if (in_array($abId, [16556])) {
                            $duration = 30000;
                        } // 星天交差
                        if (in_array($abId, [25873])) {
                            $duration = 8000;
                        } // エクザルテーション
                        if (in_array($abId, [16559])) {
                            $duration = 20000;
                        } // ニュートラルセクト
                        if (in_array($abId, [37031])) {
                            $duration = 15000;
                        } // サンサイン
                        // SGE
                        if (in_array($abId, [24298])) {
                            $duration = 15000;
                        } // ケーラコレ
                        if (in_array($abId, [24303])) {
                            $duration = 15000;
                        } // タウロコレ
                        if (in_array($abId, [24305])) {
                            $duration = 15000;
                        } // ハイマ
                        if (in_array($abId, [37034])) {
                            $duration = 30000;
                        } // エウクラシア・プログノシス
                        if (in_array($abId, [24310])) {
                            $duration = 20000;
                        } // ホーリズム
                        if (in_array($abId, [24311])) {
                            $duration = 15000;
                        } // パンハイマ
                        if (in_array($abId, [37035])) {
                            $duration = 20000;
                        } // フィロソフィア
                        // DPS
                        if (in_array($abId, [65])) {
                            $duration = 15000;
                        } // マントラ
                        if (in_array($abId, [7394])) {
                            $duration = 15000;
                        } // 金剛の極意
                        if (in_array($abId, [2241])) {
                            $duration = 10000;
                        } // 残影
                        if (in_array($abId, [36962])) {
                            $duration = 4000;
                        } // 天眼通
                        if (in_array($abId, [24404])) {
                            $duration = 5000;
                        } // アルケインクレスト
                        if (in_array($abId, [7549])) {
                            $duration = 15000;
                        } // 牽制
                        if (in_array($abId, [7405])) {
                            $duration = 15000;
                        } // トルバドゥール
                        if (in_array($abId, [7408])) {
                            $duration = 15000;
                        } // 地神のミンネ
                        if (in_array($abId, [16012])) {
                            $duration = 15000;
                        } // 守りのサンバ
                        if (in_array($abId, [16014])) {
                            $duration = 15000;
                        } // インプロビゼーション
                        if (in_array($abId, [16889])) {
                            $duration = 15000;
                        } // タクティシャン
                        if (in_array($abId, [2887])) {
                            $duration = 10000;
                        } // ウェポンブレイク
                        if (in_array($abId, [157])) {
                            $duration = 20000;
                        } // マバリア
                        if (in_array($abId, [25799])) {
                            $duration = 30000;
                        } // 守りの光
                        if (in_array($abId, [25857])) {
                            $duration = 10000;
                        } // バマジク
                        if (in_array($abId, [34685])) {
                            $duration = 10000;
                        } // テンペラコート
                        if (in_array($abId, [34686])) {
                            $duration = 10000;
                        } // テンペラグラッサ
                        if (in_array($abId, [7560])) {
                            $duration = 15000;
                        } // アドル
                    }

                    $expires = $event["timestamp"] + $duration;

                    // Keep nextEnemyAction for reference
                    $nextEnemyAction = "-";
                    $nextEnemyActionTime = null;
                    foreach ($processedEvents as $pe) {
                        if ($pe["timestamp"] >= $event["timestamp"]) {
                            $nextEnemyAction = $pe["ability"];
                            $nextEnemyActionTime = $pe["timestamp"];
                            break;
                        }
                    }
                    $playerTimelines[$sourceName][] = [
                        "enemy_action" => $nextEnemyAction,
                        "enemy_action_time" => $nextEnemyActionTime,
                        "expires" => $expires,
                        "timestamp" => $event["timestamp"],
                        "rel_time" => ($event["timestamp"] - $startTime) / 1000,
                        "ability_id" => $abId,
                        "name" => $name,
                        "target" => $targetName,
                        "self_target" => $isSelfTarget,
                        "icon" => $icon, "broken" => $isBroken ?? false,
                    ];
                }
            }
        }

        // Sort each timeline by time (should be already, but ensure)
        foreach ($playerTimelines as &$timeline) {
            usort($timeline, function ($a, $b) {
                return $a['timestamp'] <=> $b['timestamp'];
            });
        }
        unset($timeline);

        return $playerTimelines;
    }

    /**
     * 被弾イベントを「ボスの一撃」単位の行にまとめる。
     *
     * FFLogs の damage イベントは対象ごとに1件ずつ来るので、同じ攻撃の複数人ぶんを1行に束ねる。
     * 行ごとに、そのタイミングで各軽減列が効いていたか（誰が撃ったか）を判定し、
     * 被弾内訳と軽減率も併せて持たせる。
     *
     * @param  \Closure  $getName  ID から名前を引くヘルパー
     * @return array 被弾行の一覧
     */
    private function groupDamageEvents(
        array $damageEvents,
        array $mitigationColumns,
        array $activeBuffsMap,
        array $enemyDebuffsMap,
        array $playerDetails,
        array $abbrByName,
        array $jobIconNameMap,
        \Closure $getName,
        int $startTime,
    ): array {
        $roleActionDurations = MitigationSpec::roleActionDurations();
        $groupedDamage = [];

        foreach ($damageEvents as $event) {
            if (($event['type'] ?? '') === 'calculateddamage') {
                continue;
            }

            $timestamp = $event['timestamp'];
            $abilityId = $event['abilityGameID'] ?? ($event['ability']['gameID'] ?? 0);

            // Names
            $masterName = $getName($abilityId, 'ability');
            $eventName = $event['ability']['name'] ?? '';
            if (strpos($masterName, 'Unknown Ability') !== false && $eventName) {
                $abilityName = $eventName;
            } else {
                $abilityName = $masterName;
            }

            $targetName = $getName($event['targetID'] ?? 0, 'actor');

            // Resolve Buffs (Handle Array or String)
            $rawBuffs = $event['buffs'] ?? null;
            $activeIds = [];

            if (is_array($rawBuffs)) {
                $activeIds = $rawBuffs;
            } elseif (is_string($rawBuffs)) {
                $activeIds = array_filter(explode('.', $rawBuffs));
            }

            // Map High IDs (100xxxx) to Standard IDs
            $activeIds = array_map(function ($id) {
                $intId = (int) $id;
                return $intId > 1000000 ? $intId % 1000000 : $intId;
            }, $activeIds);

            // Check columns (Mitigation)
            $colFlags = [];
            foreach ($mitigationColumns as $key => $conf) {
                $colFlags[$key] = ['active' => false, 'sources' => []];

                // 1. Check Enemy Timelines (Source Tracking for Debuffs)
                if (($conf['type'] ?? '') === 'debuff') {
                    foreach ($conf['ids'] as $chkId) {
                        if (isset($enemyDebuffsMap[$chkId])) {
                            foreach ($enemyDebuffsMap[$chkId] as $window) {
                                if ($timestamp >= $window['start'] && $timestamp <= $window['end'] + (($window['isBroken'] ?? false) ? 2000 : 500)) {
                                    // Check Source Job Match (Debuff)
                                    $srcName = $window['source'];
                                    $srcJob = $playerDetails[$srcName]['job'] ?? null;

                                    $allowed = [$conf['group']];
                                    if (isset($conf['group']) && isset($jobIconNameMap[$conf['group']]) && !in_array($srcJob, $allowed)) {
                                        continue;
                                    }

                                    $colFlags[$key]['active'] = true;
                                    // 残り時間（窓終了＝実際に剥がれた時刻 まで）。最長を採用。
                                    // デバフ窓は複数適用でマージされ過大になるため名目持続でキャップ。
                                    $rem = ($window['end'] - $timestamp) / 1000;
                                    $capMs = 0;
                                    foreach (($conf['actionIds'] ?? []) as $aid) {
                                        if (isset($roleActionDurations[$aid])) {
                                            $capMs = max($capMs, $roleActionDurations[$aid]);
                                        }
                                    }
                                    if ($capMs > 0) {
                                        $rem = min($rem, $capMs / 1000);
                                    }
                                    if ($rem > 0 && (!isset($colFlags[$key]['remaining']) || $rem > $colFlags[$key]['remaining'])) {
                                        $colFlags[$key]['remaining'] = round($rem, 1);
                                    }
                                    if (!in_array($window['source'], $colFlags[$key]['sources'])) {
                                        $colFlags[$key]['sources'][] = $window['source'];
                                    }
                                }
                            }
                        }
                    }
                }

                // 2. Check Friend Buffs (Buffs on Target) AND Global Buffs
                $tId = $event['targetID'] ?? 0;
                $checkTargets = array_unique([$tId, 0]);

                foreach ($conf['ids'] as $chkId) {
                    foreach ($checkTargets as $currT) {
                        if (isset($activeBuffsMap[$currT][$chkId])) {
                            foreach ($activeBuffsMap[$currT][$chkId] as $window) {
                                if ($timestamp >= $window['start'] && $timestamp <= $window['end'] + (($window['isBroken'] ?? false) ? 2000 : 500)) {
                                    // Check Source Job Match
                                    $srcName = $window['source'];
                                    $srcJob = $playerDetails[$srcName]['job'] ?? null;

                                    $allowed = [$conf['group']];

                                    if (isset($jobIconNameMap[$conf['group']]) && !in_array($srcJob, $allowed)) {
                                        continue;
                                    }

                                    $colFlags[$key]['active'] = true;
                                    if ($window['isBroken'] ?? false) {
                                        $colFlags[$key]['is_broken'] = true;
                                    }

                                    // 残り時間（窓終了＝実際に剥がれた時刻 まで）。最長を採用。
                                    // 窓が複数適用でマージされ過大になるため名目持続でキャップ（既知スキルのみ）。
                                    $rem = ($window['end'] - $timestamp) / 1000;
                                    $capMs = 0;
                                    foreach (($conf['actionIds'] ?? []) as $aid) {
                                        if (isset($roleActionDurations[$aid])) {
                                            $capMs = max($capMs, $roleActionDurations[$aid]);
                                        }
                                    }
                                    if ($capMs > 0) {
                                        $rem = min($rem, $capMs / 1000);
                                    }
                                    if ($rem > 0 && (!isset($colFlags[$key]['remaining']) || $rem > $colFlags[$key]['remaining'])) {
                                        $colFlags[$key]['remaining'] = round($rem, 1);
                                    }

                                    if (!in_array($window['source'], $colFlags[$key]['sources'])) {
                                        $colFlags[$key]['sources'][] = $window['source'];
                                    }
                                }
                            }
                        }
                    }
                }

                // 3. Fallback: Check Player Buffs (Active IDs from Event)
                $isJobCol = (isset($conf['group']) && isset($jobIconNameMap[$conf['group']]));

                if (strpos($key, 'ランパート') !== false) {
                }

                if (!$colFlags[$key]['active']) {
                    // Fallback Logic: Checks if ID is present in Active IDs (Buffs on Player).
                    // This logic lacks Source Info, so it causes merging for Shared Skills (Rampart, Reprisal).
                    // We SKIP this fallback for known Shared IDs, forcing them to rely on strict Source checks (via Synthetic Injection).
                    // We ALLOW this fallback for Unique Skills (Sentinel, etc.) to ensure they appear even if Source info is missing.

                    // Shared IDs to Skip Fallback
                    $sharedIds = [
                        1193, 2101, 753, // Reprisal
                        1195, // Feint
                        1203, // Addle
                        1191, 71, // Rampart
                        89, // ダムネーション（WAR専用なので本来は不要だが、従来の挙動を保つため残している）
                    ];

                    // Ensure Reprisal/Feint/Addle (Debuffs) are skipped too just in case type check fails
                    if (($conf['type'] ?? '') !== 'debuff' && !array_intersect($conf['ids'], $sharedIds)) {
                        foreach ($conf['ids'] as $chkId) {
                            if (in_array($chkId, $activeIds)) {
                                $colFlags[$key]['active'] = true;
                                break;
                            }
                        }
                    }
                }
            }

            // Per-Hit Data
            // amount は「実被弾 = HPに向かった量（バリア通過後・オーバーキル込み）」に揃える。
            // 内訳（HP減 / バリア吸収 / オーバーキル）はツールチップ表示用に保持する。
            $break = DamageBreakdown::of($event);
            $hitData = [
                'amount' => $break['effective'],
                'unmitigated' => $break['base'],
                'unmit_full' => $break['unmit_full'],  // 素のダメージ（バリア吸収分を含む）
                'mitigated' => $break['total'],        // 軽減後・バリア吸収前
                'hp_lost' => $break['hp_lost'],
                'absorbed' => $break['absorbed'],
                'overkill' => $break['overkill'],
                'buffs' => $activeIds, // Store raw IDs or resolved ones? Raw is fine for flagging
            ];

            // Grouping Logic - Aggregate by Time + Ability
            $lastIdx = count($groupedDamage) - 1;
            $merged = false;

            if ($lastIdx >= 0) {
                $lastEvent = &$groupedDamage[$lastIdx];
                $timeDiff = abs($timestamp - $lastEvent['raw_timestamp']);

                // Group by Ability (Time window)
                if ($lastEvent['ability'] === $abilityName && $timeDiff < 2000) {
                    $lastEvent['hits']++;
                    $lastEvent['amount'] += $break['effective'];
                    $lastEvent['unmitigatedAmount'] += $break['unmit_full'];
                    $lastEvent['absorbed_total'] += $break['absorbed'];

                    // Track Max Damage in this group.
                    // 生ダメージ・軽減後ダメージは「最も痛かったヒット」のものを併せて持つ。別ヒットの
                    // 最大値と組み合わせると軽減率が実在しない値になるため、必ず同一ヒットで揃える。
                    if ($break['effective'] > $lastEvent['max_amount']) {
                        $lastEvent['max_amount'] = $break['effective'];
                        $lastEvent['max_unmitigated'] = $break['unmit_full'];
                        $lastEvent['max_mitigated'] = $break['total'];
                        $lastEvent['max_absorbed'] = $break['absorbed'];
                    }

                    // Add/Update Player Data
                    if (isset($lastEvent['players'][$targetName])) {
                        $lastEvent['players'][$targetName]['amount'] += $hitData['amount'];
                        $lastEvent['players'][$targetName]['unmitigated'] += $hitData['unmitigated'];
                        $lastEvent['players'][$targetName]['unmit_full'] += $hitData['unmit_full'];
                        $lastEvent['players'][$targetName]['mitigated'] += $hitData['mitigated'];
                        $lastEvent['players'][$targetName]['hp_lost'] += $hitData['hp_lost'];
                        $lastEvent['players'][$targetName]['absorbed'] += $hitData['absorbed'];
                        $lastEvent['players'][$targetName]['overkill'] += $hitData['overkill'];
                    } else {
                        $lastEvent['players'][$targetName] = $hitData;
                    }

                    // Merge Flags: OR logic for 'active', Merge 'sources'
                    foreach ($colFlags as $k => $v) {
                        if ($v['active']) {
                            // Ensure existing is array format (should be from init)
                            if (!isset($lastEvent['cols'][$k]) || !is_array($lastEvent['cols'][$k])) {
                                $lastEvent['cols'][$k] = ['active' => false, 'sources' => []];
                            }

                            $lastEvent['cols'][$k]['active'] = true;
                            // Merge sources without duplicates
                            $lastEvent['cols'][$k]['sources'] = array_unique(array_merge(
                                $lastEvent['cols'][$k]['sources'],
                                $v['sources'],
                            ));
                        }
                    }

                    // Propagate is_dot flag
                    if (($event['tick'] ?? false)) {
                        $lastEvent['is_dot'] = true;
                    }

                    $merged = true;
                }
            }

            if (!$merged) {
                $groupedDamage[] = [
                    'type' => 'damage',
                    'raw_timestamp' => $timestamp,
                    'timestamp' => $timestamp,
                    'rel_time' => ($event['timestamp'] - $startTime) / 1000,
                    'ability' => $abilityName,
                    'amount' => $break['effective'],
                    'max_amount' => $break['effective'],      // 実被弾（HPに向かった量）
                    'max_unmitigated' => $break['unmit_full'], // 素のダメージ（軽減前・バリア吸収前）
                    'max_mitigated' => $break['total'],        // 軽減後・バリア吸収前
                    'max_absorbed' => $break['absorbed'],      // 最大被弾ヒットでバリアが吸った量
                    'absorbed_total' => $break['absorbed'],    // グループ内でバリアが吸った量の合計
                    'unmitigatedAmount' => $break['unmit_full'],
                    'hits' => 1,
                    'cols' => $colFlags, // Mitigation flags (Union of all hits)
                    'players' => [
                        $targetName => $hitData,
                    ],
                    'is_dot' => ($event['tick'] ?? false),
                ];
            }
        }

        // Final Pass: Calculate Mitigation Rate based on MAX values
        foreach ($groupedDamage as &$evt) {
            // Fix: Force "Ether Bullet" to not be DoT
            if ($evt["ability"] === "エーテルバレット" || $evt["ability"] === "Ether Bullet") {
                $evt["is_dot"] = false;
            }
            $evt['mitigation_rate'] = 0;
            // 軽減率は「素のダメージ → 軽減後ダメージ」で出す。実被弾（max_amount）と比べると
            // バリアが吸った分まで軽減率に混ざるため、必ず max_mitigated を使うこと。
            $rawDmg = $evt['max_unmitigated'] ?? 0;
            $mitDmg = $evt['max_mitigated'] ?? ($evt['max_amount'] ?? 0);

            if ($rawDmg > 0 && $rawDmg > $mitDmg) {
                $evt['mitigation_rate'] = round((1 - ($mitDmg / $rawDmg)) * 100);
            }
        }
        unset($evt);

        return $groupedDamage;
    }

    /**
     * バフ／デバフが効いていた区間を targetId => abilityId => [{start, end, source, isBroken}] の形で作る。
     *
     * 「その瞬間に何が効いていたか」を後段（被弾行の生成）から定数時間で引けるようにするのが目的。
     *
     * 3つの経路から集める。
     *  1. friendBuffs の applybuff / removebuff をペアにする（本来の経路）
     *  2. ロール共通アクション（リプライザル・牽制・アドル等）はバフイベントが出ないことがあるため、
     *     キャストから効果時間ぶんの窓を合成する
     *  3. セラフィム等のペットが付与したバフは、飼い主を発生源に付け替える
     *     （でないとジョブ一致チェックで弾かれ、コンソレイション等が漏れる）
     *
     * isBroken は「効果時間より早く消えた」＝バリアが割れた、の印。
     *
     * @param  \Closure  $getName  ID から名前を引くヘルパー
     */
    private function buildActiveBuffWindows(
        array $reportData,
        array $fight,
        array $statusMaxDurations,
        $masterActors,
        \Closure $getName,
    ): array {
        $activeBuffsMap = [];
        // Synthetic Buff Injection from Casts (Robust Separation)
        // We inject into activeBuffsMap so the standard logic (which checks Source) picks it up.
        $roleActionDurations = MitigationSpec::roleActionDurations();
        $actionToStatusMap = MitigationSpec::roleActionStatuses();

        foreach ($reportData['friendCasts']['data'] ?? [] as $cast) {
            $abId = $cast['abilityGameID'] ?? 0;
            if ($abId > 1000000) {
                $abId = $abId % 1000000;
            }

            if (isset($actionToStatusMap[$abId])) {
                $statusId = $actionToStatusMap[$abId];
                $duration = $roleActionDurations[$abId] ?? 15000;
                $srcId = $cast['sourceID'] ?? 0;
                $srcName = $getName($srcId, 'actor');

                // DEBUG INJECT

                // Heuristic: Status 1193 (Reprisal), 1195 (Feint), 1203 (Addle), 860 (Wpn Break) are Global.
                $isGlobal = in_array($statusId, [1193, 1195, 1203, 860]);
                $targetKey = $isGlobal ? 0 : $srcId;

                $activeBuffsMap[$targetKey][$statusId][] = [
                    'start' => $cast['timestamp'],
                    'end' => $cast['timestamp'] + $duration,
                    'source' => $srcName,
                    'isBroken' => false, // Assumed full duration from cast
                ];
            }
        }

        $rawFriendBuffs = $reportData['friendBuffs']['data'] ?? [];

        $openFriendBuffs = [];

        foreach ($rawFriendBuffs as $event) {
            $abId = $event['abilityGameID'] ?? 0;
            if ($abId > 1000000) {
                $abId = $abId % 1000000;
            }
            $targetId = $event['targetID'] ?? 0;
            $type = $event['type'];
            $key = $targetId . '_' . $abId;

            if ($type === 'applybuff') {
                // ペット(セラフィム等)が付与したバフは飼い主(SCH等)を発生源にする。
                // でないとジョブ一致チェックで弾かれ、コンソレイション等が漏れる。
                $srcId = $event['sourceID'] ?? 0;
                $srcActor = $masterActors->get($srcId);
                if ($srcActor && isset($srcActor['petOwner'])) {
                    $srcId = $srcActor['petOwner'];
                }
                $sourceName = $getName($srcId, 'actor');
                $openFriendBuffs[$key] = ['start' => $event['timestamp'], 'source' => $sourceName];
            } elseif ($type === 'removebuff') {
                if (isset($openFriendBuffs[$key])) {
                    $d = $openFriendBuffs[$key];
                    $duration = $event["timestamp"] - $d["start"];
                    $isBroken = false;

                    if (isset($statusMaxDurations[$abId])) {
                        $maxD = $statusMaxDurations[$abId];
                        if ($duration < ($maxD - 1000)) {
                            $isBroken = true;
                        }
                    }
                    $activeBuffsMap[$targetId][$abId][] = [
                        "start" => $d["start"],
                        "end" => $event["timestamp"],
                        "source" => $d["source"],
                        "isBroken" => $isBroken,
                    ];
                    unset($openFriendBuffs[$key]);
                }
            }
        }
        // Close open friend buffs
        foreach ($openFriendBuffs as $key => $d) {
            list($tId, $abId) = explode('_', $key);
            $activeBuffsMap[$tId][$abId][] = [                 'start' => $d['start'],                 'end' => $fight['endTime'],                 'source' => $d['source'],                 'isBroken' => false             ];
        }

        // Inject Virtual Buffs (Seraph, Deploy) into activeBuffsMap (Global Target 0)
        $rawCasts = $reportData['friendCasts']['data'] ?? [];
        foreach ($rawCasts as $cast) {
            $abId = $cast['abilityGameID'] ?? 0;
            if ($abId > 1000000) {
                $abId = $abId % 1000000;
            }

            // Map Action IDs to Status IDs
            if ($abId == 7535 || $abId == 22198) {
                $abId = 1193;
            } // Reprisal Action -> Status
            if ($abId == 7549) {
                $abId = 1195;
            } // Feint Action -> Status
            if ($abId == 7560) {
                $abId = 1203;
            } // Addle Action -> Status

            $duration = 0;
            if ($abId == 16545) {
                $duration = 22000;
            } // Seraph
            if ($abId == 3585) {
                $duration = 4000;
            }   // Deploy
            if ($abId == 1195) {
                $duration = 10000;
            }  // Feint (10s)
            if ($abId == 1203) {
                $duration = 10000;
            }  // Addle (10s)
            if ($abId == 1193) {
                $duration = 10000;
            }  // Reprisal (10s)

            if ($duration > 0) {
                // 注入はキャスト時刻＋効果時間の窓にする。
                // 以前は直前のfriendBuffsループの残骸変数($event/$d)を誤用し、
                // 終了=試合終了の巨大窓になってリプライザル/牽制/アドルが「ずっと有効」化していた。
                $sourceName = $getName($cast['sourceID'] ?? 0, 'actor');
                $start = $cast['timestamp'];
                $end = $start + $duration;
                $targetId = 0; // Global Buff
                $activeBuffsMap[$targetId][$abId][] = [
                    "start" => $start,
                    "end" => $end,
                    "source" => $sourceName,
                    "isBroken" => false,
                ];
            }
        }

        return $activeBuffsMap;
    }

    /**
     * ステータスID => 効果時間の上限（ミリ秒）。バリアが割れたかの判定に使う。
     *
     * 効果時間はアクションID側にもステータスID側にも定義されうるので、両方から引く。
     */
    private function buildStatusMaxDurations(array $barrierIDs, array $barrierMaxDurations): array
    {
        $statusMaxDurations = [];
        foreach ($barrierIDs as $actionId => $statusId) {
            $maxDur = null;
            if (isset($barrierMaxDurations[$actionId])) {
                $maxDur = $barrierMaxDurations[$actionId];
            }
            if (!$maxDur && !is_array($statusId) && isset($barrierMaxDurations[$statusId])) {
                $maxDur = $barrierMaxDurations[$statusId];
            }

            if ($maxDur) {
                if (is_array($statusId)) {
                    foreach ($statusId as $sId) {
                        $statusMaxDurations[$sId] = $maxDur;
                    }
                } else {
                    $statusMaxDurations[$statusId] = $maxDur;
                }
            }
        }

        return $statusMaxDurations;
    }

    /**
     * 敵に入っているデバフ（リプライザル・牽制・アドル等）の有効区間を、発生源つきで作る。
     *
     * 誰が入れたデバフかを持たないと、軽減列のジョブ一致チェックができない。
     * 戦闘終了時点で開いたままのデバフは、戦闘終了時刻で閉じる。
     *
     * @param  \Closure  $getName  ID から名前を引くヘルパー
     * @return array abilityId => [{start, end, source}]
     */
    private function buildEnemyDebuffWindows(array $reportData, array $fight, \Closure $getName): array
    {
        // Map: AbilityID -> [ [start, end, sourceName], ... ]
        $enemyDebuffsMap = [];
        $rawDebuffs = $reportData['enemyDebuffs']['data'] ?? [];
        if (!empty($rawDebuffs)) {

        }

        $openDebuffs = []; // abilityID -> ['start' => time, 'source' => name]
        foreach ($rawDebuffs as $event) {
            $abId = $event['abilityGameID'] ?? 0;
            if ($abId > 1000000) {
                $abId = $abId % 1000000;
            }

            $type = $event['type'];

            if ($type === 'applydebuff') {
                $sourceName = $getName($event['sourceID'] ?? 0, 'actor');
                $key = $abId . '_' . ($event['sourceID'] ?? 0);
                $openDebuffs[$key] = ['start' => $event['timestamp'], 'source' => $sourceName];
            } elseif ($type === 'removedebuff') {
                $key = $abId . '_' . ($event['sourceID'] ?? 0);
                if (isset($openDebuffs[$key])) {
                    $d = $openDebuffs[$key];
                    $enemyDebuffsMap[$abId][] = [
                        'start' => $d['start'],
                        'end' => $event['timestamp'],
                        'source' => $d['source'],
                    ];
                    unset($openDebuffs[$key]);
                }
            }
        }
        foreach ($openDebuffs as $abIdSource => $d) {
            list($abId, $sourceId) = explode('_', $abIdSource);
            $abId = (int) $abId;
            $enemyDebuffsMap[$abId][] = [
                'start' => $d['start'],
                'end' => $fight['endTime'],
                'source' => $d['source'],
            ];
        }

        return $enemyDebuffsMap;
    }

    /**
     * 軽減列から「監視すべきアクションID」と「そのIDを撃てるジョブ」を集める。
     *
     * 同じアクションIDが複数の列に出る（ランパート等のロール共通スキル）ので、
     * ID単位で許可ジョブの一覧を持たせ、あとでキャストの発生源と突き合わせて列を決める。
     * ロール列は所属ジョブへ展開しておく。
     *
     * @return array{actionIds: list<int>, jobMap: array<int, list<string>>}
     */
    private function flattenMitigationActionIds(array $mitigationColumns): array
    {
        // Flatten IDs (Buff IDs) AND Action IDs
        $actionIdJobMap = []; // Map ID => [AllowedJob1, AllowedJob2]

        // Helper to expand Roles
        $typesByAbbr = FFXIVJobs::typesByAbbr();
        $roleMap = FFXIVJobs::membersByRoleGroup();
        $allMitigationActionIds = [];
        foreach ($mitigationColumns as $col) {
            $allowedJobs = [];
            // Resolve Job/Group
            $grp = $col["group"] ?? "Unknown";
            if (isset($roleMap[$grp])) {
                $allowedJobs = $roleMap[$grp];
            } else {
                $allowedJobs = [$grp];
                if (isset($typesByAbbr[$grp])) {
                    $allowedJobs = array_merge($allowedJobs, $typesByAbbr[$grp]);
                }
            }
            foreach ($col['ids'] as $id) {
                // aggressive fallback: include buff ID as potential action ID
                $allMitigationActionIds[] = $id;
                if (!isset($actionIdJobMap[$id])) {
                    $actionIdJobMap[$id] = [];
                }
                $actionIdJobMap[$id] = array_merge($actionIdJobMap[$id], $allowedJobs);
            }
            if (isset($col['actionIds'])) {
                foreach ($col['actionIds'] as $aid) {
                    $allMitigationActionIds[] = $aid;
                    if (!isset($actionIdJobMap[$aid])) {
                        $actionIdJobMap[$aid] = [];
                    }
                    $actionIdJobMap[$aid] = array_merge($actionIdJobMap[$aid], $allowedJobs);
                }
            }
        }
        return [
            'actionIds' => array_unique($allMitigationActionIds),
            'jobMap' => $actionIdJobMap,
        ];
    }

    /**
     * 表示する軽減列とそのアイコンを決める。
     *
     * アイコンの解決順は「レポートのアクションアイコン → gameData のアクションアイコン →
     * レポートのステータスアイコン → gameData のステータスアイコン」。ステータスIDは gameData 上で
     * 別スキルや汎用アイコンに化けることがあるため、アクションID側を先に試す。
     *
     * gameData から引くIDは「フィルタ前の全スキル」で集める。そうしないとレポートのジョブ構成ごとに
     * キャッシュキーが変わり、断片化した不完全なマップが固定化されてしまう。
     *
     * @return array{columns: array, groups: array, groupIcons: array, gameIcons: array}
     */
    private function buildMitigationColumns(
        array $mitigationSpec,
        $masterAbilities,
        array $jobIconNameMap,
    ): array {
        // gameData API から軽減スキルのアイコンを取得（レポートに含まれないスキルでも常にアイコン表示するため）。
        // フィルタ前の全スペックからID収集することで、レポートのジョブ構成に依存しない安定したキャッシュキーにする
        // （= 一度取得すれば全レポートで再利用でき、ジョブ構成ごとのキャッシュ断片化／不完全マップ固定化を防ぐ）。
        $iconLookupIds = [];
        foreach (MitigationSpec::columns() as $cols) {
            foreach ($cols as $col) {
                foreach (($col['actionIds'] ?? []) as $aid) {
                    $iconLookupIds[] = $aid;
                }
                foreach ($col['ids'] as $bid) {
                    $iconLookupIds[] = $bid;
                }
            }
        }
        // シナジースキルのアクションIDも含める（アイコン解決を確実にする）
        foreach (MitigationSpec::synergies() as $cols) {
            foreach ($cols as $col) {
                foreach (($col['actionIds'] ?? []) as $aid) {
                    $iconLookupIds[] = $aid;
                }
            }
        }
        $gameIconMap = $this->fflogs->getAbilityIconMap($iconLookupIds);

        // Resolve Icons & Build Groups (Same as before but on filtered Spec)
        $mitigationGroups = [];
        $mitigationColumns = [];
        $mitPowerSpec = MitigationSpec::power();
        foreach ($mitigationSpec as $groupName => $cols) {
            $activeGroupCols = [];
            foreach ($cols as $key => $col) {
                $col['name'] = $key;
                $col['group'] = $groupName;
                $col['icon'] = '';

                // 軽減率・種別（被弾内訳モーダルでの軽減量推定に使う）
                $power = $mitPowerSpec[$key] ?? [];
                $mitBase = (int) ($power['mit'] ?? 0);
                $col['kind'] = $power['kind'] ?? 'mit';
                $col['mit_phys'] = (int) ($power['mit_phys'] ?? $mitBase);
                $col['mit_magic'] = (int) ($power['mit_magic'] ?? $mitBase);
                $col['has_barrier'] = ($col['kind'] === 'barrier') || !empty($power['barrier']);

                // 解決優先順位：
                // 1) レポートmasterDataのアクションアイコン（実使用時の正確なアイコン）
                // 2) gameDataのアクションアイコン（スキル本来のアイコン・常に正確） ← ステータスID照合より優先
                // 3) レポートmasterDataのステータス/バフID
                // 4) gameDataのステータス/バフID
                // ※ ステータスIDはgameData上で別スキルや汎用アイコンに化けることがあるため、アクションID解決を先に行う
                if (isset($col['actionIds'])) {
                    foreach ($col['actionIds'] as $actId) {
                        if ($ability = $masterAbilities->get((int) $actId)) {
                            $col['icon'] = $ability['icon'];
                            break;
                        }
                    }
                }
                if (empty($col['icon']) && isset($col['actionIds'])) {
                    foreach ($col['actionIds'] as $actId) {
                        if (!empty($gameIconMap[(int) $actId])) {
                            $col['icon'] = $gameIconMap[(int) $actId];
                            break;
                        }
                    }
                }
                if (empty($col['icon'])) {
                    foreach ($col['ids'] as $id) {
                        if ($ability = $masterAbilities->get((int) $id)) {
                            $col['icon'] = $ability['icon'];
                            break;
                        }
                    }
                }
                if (empty($col['icon'])) {
                    foreach ($col['ids'] as $id) {
                        if (!empty($gameIconMap[(int) $id])) {
                            $col['icon'] = $gameIconMap[(int) $id];
                            break;
                        }
                    }
                }

                // Force Icon Overrides (User Feedback)
                if ($key === 'ShieldSamba') {
                    $col['icon'] = '003000-003469.png';
                }

                // Keep column even if icon is missing (View handles text fallback)
                $uniqueKey = $groupName . '_' . $key;
                $activeGroupCols[$uniqueKey] = $col;
            }

            if (!empty($activeGroupCols)) {
                // Mark the first column as the start of the group for border styling
                $firstKey = array_key_first($activeGroupCols);
                if ($firstKey !== null) {
                    $activeGroupCols[$firstKey]['group_start'] = true;
                }

                $mitigationGroups[$groupName] = $activeGroupCols;
                $mitigationColumns = array_merge($mitigationColumns, $activeGroupCols);
            }
        }

        // Prepare Icons for Mitigation Group Headers (Right Side)
        $mitigationGroupIcons = [];
        foreach ($mitigationGroups as $groupName => $_) {
            if (isset($jobIconNameMap[$groupName])) {
                $mitigationGroupIcons[$groupName] = "/icons/jobs/" . $jobIconNameMap[$groupName] . ".png"; // ローカル配信
            }
        }

        return [
            'columns' => $mitigationColumns,
            'groups' => $mitigationGroups,
            'groupIcons' => $mitigationGroupIcons,
            'gameIcons' => $gameIconMap,
        ];
    }

    /**
     * 被弾したプレイヤーを列に並べ、同時に「この戦闘に出ているジョブ／ロール」を確定させる。
     *
     * 列の並びはロール順（タンク→ヒラ→近接→遠隔→キャス）→名前順で固定する。毎回同じ並びでないと
     * 同じログを開き直したときに表が動いて読みにくいため。
     *
     * 併せて $mitigationSpec を絞り込む。出ていないジョブの軽減列を出しても意味が無いので、
     * 実在するジョブ列（PLD 等）とロール列（Tank Role 等）だけを残す。
     *
     * @param  \Closure  $getName  ID から名前を引くヘルパー
     * @return array{details: array, stats: array, columns: list<string>, jobIcons: array, spec: array}
     */
    private function buildPlayerColumns(
        array $damageEvents,
        $masterActors,
        array $fight,
        array $mitigationSpec,
        \Closure $getName,
    ): array {
        $playerStats = []; // name => latest_target_id
        foreach ($damageEvents as $event) {
            $tId = $event['targetID'] ?? 0;
            $tName = $getName($tId, 'actor');
            if ($tName !== 'Unknown') {
                $playerStats[$tName] = $tId; // Store ID
            }
        }
        // Identify Active Jobs and Roles
        $jobMap = FFXIVJobs::abbrByType();
        $typesByAbbr = FFXIVJobs::typesByAbbr();
        $roleMap = FFXIVJobs::roleGroupByAbbr();

        // 列の並び順とアイコンはジョブ表から引く
        $jobPriorityMap = FFXIVJobs::displayOrderByAbbr();
        $jobIconNameMap = FFXIVJobs::iconFileByAbbr();

        $playerDetails = [];
        foreach ($playerStats as $pName => $actorId) {
            // Find actor by ID (Correct) instead of Name (Ambiguous)
            $actor = $masterActors->get((int) $actorId);

            // Reserve Fallback: If ID lookup fails (shouldn't), try Name
            if (!$actor) {
                $actor = $masterActors->firstWhere('name', $pName);
            }

            $jobMap = FFXIVJobs::abbrByType();

            $priority = 99;
            $icon = null;
            $jobKey = null;

            if ($actor && isset($actor['subType'])) {
                $subType = $actor['subType']; // e.g. 'Paladin'
                $jobKey = $jobMap[$subType] ?? $subType;
                $priority = $jobPriorityMap[$jobKey] ?? 99;

                // Use mapped name if available, otherwise fallback to lower case subType
                $fileName = $jobIconNameMap[$jobKey] ?? strtolower($subType);
                $icon = "/icons/jobs/" . $fileName . ".png"; // ローカル配信（初回DL後はnginx直配信）
            }

            $playerDetails[$pName] = [
                'name' => $pName,
                'icon' => $icon,
                'priority' => $priority,
                'job' => $jobKey,
            ];
        }

        // Sort by Priority then Name
        uasort($playerDetails, function ($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return strcmp($a['name'], $b['name']);
            }
            return $a['priority'] <=> $b['priority'];
        });

        $playerColumns = array_keys($playerDetails); // Sorted List

        $presentJobs = [];
        $presentRoles = [];
        $fightActorIds = $fight['friendlyPlayers'] ?? [];

        foreach ($masterActors as $actor) {
            // Only consider actors actually in this fight
            if (!in_array($actor['id'], $fightActorIds)) {
                continue;
            }

            if (isset($actor['type']) && $actor['type'] === 'Player' && isset($actor['subType']) && isset($jobMap[$actor['subType']])) {
                $job = $jobMap[$actor['subType']];
                $presentJobs[$job] = true;
                if (isset($roleMap[$job])) {
                    $presentRoles[$roleMap[$job]] = true;
                }
            }
        }
        // 軽減列の構成：レポートに出てくるジョブ（'PLD'）とロール（'Tank Role'）の列だけを残す。
        // $mitigationSpec のキーはそのどちらかなので、両方に無いものを落とせばよい。
        foreach (array_keys($mitigationSpec) as $group) {
            if (!isset($presentJobs[$group]) && !isset($presentRoles[$group])) {
                unset($mitigationSpec[$group]);
            }
        }

        return [
            'details' => $playerDetails,
            'stats' => $playerStats,
            'columns' => $playerColumns,
            'jobIcons' => $jobIconNameMap,
            'spec' => $mitigationSpec,
        ];
    }

    /**
     * ID から名前を引くヘルパーを作る。
     *
     * ペット（セラフィム・分身等）は飼い主の名前に寄せる。ペット名のまま扱うと、
     * 誰が撃った軽減なのかが分からなくなりジョブ一致チェックで落ちてしまうため。
     *
     * @return \Closure (int $id, string $type = "actor"|"ability"): string
     */
    private function nameResolver(array $masterData): \Closure
    {
        $actorMap = [];
        $petOwnerMap = [];
        foreach (($masterData["actors"] ?? []) as $actor) {
            $actorMap[$actor["id"]] = $actor["name"];
            if (isset($actor["petOwner"])) {
                $petOwnerMap[$actor["id"]] = $actor["petOwner"];
            }
        }

        $getName = function ($id, $type = "actor") use ($masterData, $actorMap, $petOwnerMap) {
            $nameOverrides = [
                38374 => 'System Interaction (38374)',
                45716 => 'Unknown Ability (45716)',
            ];

            if ($type === "actor") {
                // Resolve Pet to Owner
                if (isset($petOwnerMap[$id])) {
                    $ownerId = $petOwnerMap[$id];
                    return $actorMap[$ownerId] ?? "Unknown Owner";
                }
                return $actorMap[$id] ?? "Unknown Actor ($id)";
            }

            if ($type === "ability") {
                foreach ($masterData["abilities"] ?? [] as $ability) {
                    if ($ability["gameID"] == $id) {
                        return $ability["name"];
                    }
                }
                return "Unknown Ability ($id)";
            }
            return "Unknown";

        };

        return $getName;
    }

    /**
     * FFLogs に投げるイベント取得のフィルタIDを組み立てる。
     *
     * 軽減スキルはアクションID（使用）とステータスID（効果）のどちらでログに出るかが
     * スキルによって違うため、両方を対象に入れる。バリアのステータスIDと、
     * シナジー（攻撃強化バフ）のIDも同じクエリで拾う。
     *
     * @return list<int>
     */
    private function eventQueryIds(array $mitigationSpec, array $barrierIDs, array $synergySpec): array
    {
        $queryActionIds = [];
        $queryActionIds = [];
        foreach ($mitigationSpec as $group => $cols) {
            foreach ($cols as $c) {
                if (isset($c['actionIds'])) {
                    foreach ($c['actionIds'] as $aid) {
                        $queryActionIds[] = $aid;
                    }
                }
                // also include buff IDs as fallback
                foreach ($c['ids'] as $bid) {
                    $queryActionIds[] = $bid;
                }
            }
        }

        // Add Barrier Status IDs to Query IDs so they are fetched by Optimized Query
        foreach ($barrierIDs as $actId => $stId) {
            if (is_array($stId)) {
                foreach ($stId as $s) {
                    $queryActionIds[] = $s;
                }
            } else {
                $queryActionIds[] = $stId;
            }
        }

        // シナジー（攻撃強化バフ）のアクションID／ステータスIDもキャスト取得対象に追加する
        foreach ($synergySpec as $cols) {
            foreach ($cols as $c) {
                foreach (($c['actionIds'] ?? []) as $aid) {
                    $queryActionIds[] = $aid;
                }
                foreach (($c['ids'] ?? []) as $bid) {
                    $queryActionIds[] = $bid;
                }
            }
        }

        return array_values(array_unique($queryActionIds));
    }

}
