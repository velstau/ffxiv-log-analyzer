<?php

namespace App\Http\Controllers;

use App\Services\FFLogsService;
use App\Support\FFXIVJobs;
use Illuminate\Http\Request;

/**
 * 指定したPT構成（ジョブの組み合わせ）と一致する討伐ログをFFLogsから探す。
 *
 * サイトのランキングUIはロール人数（タンク2/ヒラ2/近接2…）でしか絞れないため、
 * ジョブ単位の照合はAPI経由でしかできない。討伐ログと構成の取得は
 * FFLogsService::getClearPool() が担当する（characterRankings が主力）。
 *
 * 探せる範囲はランキングに載った討伐ログに限られる。絶妖星乱舞での実測では
 * 実在するクリアの約38%（サンプル69件中26件）で、残りはランキングに出ないログ。
 * 全件を対象にするには reportData.reports で全公開レポート（約13万件）を
 * 走査してDBに索引を作る必要がある。
 *
 * また「非標準構成」パーティション（タンク3など通常と違うロール比）は
 * ランキング自体がほぼ空なので、この方法では原理的に見つけられない。
 */
class PartySearchController extends Controller
{
    public function __construct(private readonly FFLogsService $fflogs) {}

    public function form()
    {
        return view('fflogs.party_search', $this->baseViewData());
    }

    public function search(Request $request)
    {
        $data = $this->baseViewData();

        $encounterId = (int) $request->input('encounter_id');
        $mode = $request->input('mode') === 'include' ? 'include' : 'exact';

        // 空スロットを捨てて、テーブルに載っているジョブだけ残す
        $target = array_values(array_filter(
            (array) $request->input('jobs', []),
            fn($j) => is_string($j) && FFXIVJobs::exists($j),
        ));
        $target = array_slice($target, 0, 8);

        $data['form'] = [
            'encounter_id' => $encounterId,
            'zone_id'      => (int) $request->input('zone_id'),
            'mode'         => $mode,
            'jobs'         => $target,
        ];

        if (!$encounterId) {
            return view('fflogs.party_search', array_merge($data, ['error' => 'ボスを選択してください。']));
        }
        if (empty($target)) {
            return view('fflogs.party_search', array_merge($data, ['error' => 'ジョブを1つ以上選んでください。']));
        }
        if ($mode === 'exact' && count($target) !== 8) {
            return view('fflogs.party_search', array_merge($data, [
                'error' => '完全一致で探すには8ジョブすべてを指定してください（' . count($target) . '個しか選ばれていません）。部分一致なら途中まででも検索できます。',
            ]));
        }

        // 未キャッシュのボスはランキングを数百ページなめるので数十秒かかる。
        // リクエストの中で待つとリバースプロキシの読み取りタイムアウトに掛かるため、
        // ここでは待たずに「準備中」を返し、取得は応答後に回す。
        if (! $this->fflogs->hasClearPool($encounterId)) {
            $this->warmAfterResponse($encounterId);

            return view('fflogs.party_search', array_merge($data, ['warming' => true]));
        }

        $pool = $this->fflogs->getClearPool($encounterId);
        $fights = $pool['fights'];

        if (empty($fights)) {
            return view('fflogs.party_search', array_merge($data, [
                'error' => 'このボスの討伐ログを取得できませんでした（ランキングがまだ無い可能性があります）。',
            ]));
        }

        // 構成はプールの時点で8ジョブ揃っているので、ここは純粋な多重集合の照合だけ。
        $wanted = $this->countJobs($target);
        $results = [];
        foreach ($fights as $fight) {
            if (empty($fight['comp']) || !$this->compMatches($fight['comp'], $wanted, $mode)) {
                continue;
            }
            $results[] = $this->formatResult($fight);
        }

        // 速い順。順位が付いているものは実測タイムも順位も同じ並びになる。
        usort($results, fn($a, $b) => $a['durationMs'] <=> $b['durationMs']);

        return view('fflogs.party_search', array_merge($data, [
            'results' => $results,
            'stats' => [
                'encounterName' => $pool['encounterName'],
                'pool'          => count($fights),
                'ranked'        => count(array_filter($fights, fn($f) => $f['rank'] !== null)),
                'matches'       => count($results),
            ],
        ]));
    }

    /** プールの取得状況。「準備中」の画面がこれをポーリングして、整い次第やり直す。 */
    public function status(Request $request)
    {
        return response()->json([
            'ready' => $this->fflogs->hasClearPool((int) $request->input('encounter_id')),
        ]);
    }

    /**
     * 応答を返しきったあとにプールを温める。
     *
     * public/index.php は Response::send()（＝ fastcgi_finish_request）のあとに
     * terminate を呼ぶので、ここに積んだ処理はクライアントとの接続を切ったあとに走る。
     * つまり nginx の読み取りタイムアウトの影響を受けない。
     */
    private function warmAfterResponse(int $encounterId): void
    {
        app()->terminating(function () use ($encounterId) {
            @set_time_limit(600);
            $this->fflogs->warmClearPool($encounterId);
        });
    }

    /** ['Paladin' => 1, 'Sage' => 2, ...] の形に数える（同ジョブ複数を扱うため）。 */
    private function countJobs(array $types): array
    {
        $c = [];
        foreach ($types as $t) {
            $c[$t] = ($c[$t] ?? 0) + 1;
        }
        return $c;
    }

    /** 完全一致＝多重集合が同じ。部分一致＝指定したジョブが必要数だけ含まれている。 */
    private function compMatches(array $jobs, array $wanted, string $mode): bool
    {
        $have = $this->countJobs($jobs);
        foreach ($wanted as $type => $n) {
            if (($have[$type] ?? 0) < $n) {
                return false;
            }
        }
        if ($mode === 'exact') {
            // 部分一致条件を満たした上で合計8人なら、余分なジョブは入り得ない
            return count($jobs) === array_sum($wanted);
        }
        return true;
    }

    private function formatResult(array $fight): array
    {
        $url = "https://ja.fflogs.com/reports/{$fight['code']}?fight={$fight['fightID']}";

        return [
            'rank'        => $fight['rank'],
            'jobs'        => array_map(fn($t) => FFXIVJobs::meta($t), FFXIVJobs::sortTypes($fight['comp'])),
            'duration'    => $this->formatDuration($fight['duration']),
            'durationMs'  => $fight['duration'],
            'deaths'      => $fight['deaths'],
            'server'      => trim(($fight['server']['name'] ?? '') . ' ' . ($fight['server']['region'] ?? '')),
            'guild'       => $fight['guild']['name'] ?? null,
            'date'        => isset($fight['startTime']) ? date('Y-m-d', (int) ($fight['startTime'] / 1000)) : null,
            'url'         => $url,
        ];
    }

    private function formatDuration($ms): string
    {
        $sec = (int) round($ms / 1000);
        return sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
    }

    private function baseViewData(): array
    {
        return [
            'zoneTree'  => $this->fflogs->getZoneTree(),
            'jobGroups' => FFXIVJobs::groupedByRole(),
            'results'   => null,
            'stats'     => null,
            'error'     => null,
            'warming'   => false,
            'form'      => [
                // 初期表示は絶妖星乱舞（zone 76 / encounter 1085）
                'encounter_id' => 1085,
                'zone_id'      => 76,
                'mode'         => 'exact',
                'jobs'         => [],
            ],
        ];
    }
}
