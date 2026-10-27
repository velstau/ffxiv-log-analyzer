<?php

namespace App\Http\Controllers;

use App\Exceptions\FFLogsRequestFailed;
use App\Services\FFLogsService;
use App\Services\ReportComparer;
use App\Services\TimelineBuilder;
use App\Services\TimelineShareStore;
use App\Support\PlayerAliases;
use Illuminate\Http\Request;

class FFLogsController extends Controller
{
    public function __construct(
        private readonly FFLogsService $fflogs,
        private readonly ReportComparer $comparer,
        private readonly TimelineBuilder $timeline,
    ) {}

    public function index()
    {
        return view('fflogs.index');
    }

    public function analyze(Request $request)
    {
        $parsed = $this->fflogs->parseUrl($request->input('url'));

        if (!$parsed['code'] || !$parsed['fightId']) {
            return back()->with('error', 'Invalid URL. Could not parse Report Code or Fight ID.');
        }

        $fight = $this->fflogs->getFightDetails($parsed['code'], $parsed['fightId']);

        if (!$fight) {
            return back()->with('error', 'Fight not found or access denied.');
        }

        try {
            $data = $this->timeline->build($parsed, $fight);
        } catch (FFLogsRequestFailed $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        // 名前を伏せるときは、一時保存・共有の前に置き換える（実名を保存しない）
        $data['hideNames'] = $request->boolean('hide_names');
        if ($data['hideNames']) {
            // 軽減列の定義はジョブ・ロールで組んだ構造なので触らない（実名がグループ名と同じでも列が壊れないように）
            $data = PlayerAliases::apply($data, PlayerAliases::make($data['roster'] ?? []), false, ['fight', 'mitigationColumns', 'mitigationGroups', 'mitigationGroupIcons']);
        }
        unset($data['roster']);

        try {
            $draft = app(TimelineShareStore::class)->draft($data, TimelineShareController::owner($request));
            // Post/Redirect/Get: reloading the result must not repeat FFLogs API calls.
            return redirect()->route('timeline-results.show', $draft, 303)->header('Cache-Control', 'private, no-store');
        } catch (\RuntimeException $e) {
            // A sharing storage outage must not discard a successful analysis.
            $data['sharePreparationFailed'] = true;
            \Illuminate\Support\Facades\Log::warning('Timeline sharing preparation failed.');
        }
        return response()->view('fflogs.timeline', $data)->header('Cache-Control', 'private, no-store');
    }

    // ===================== A/B比較ページ =====================

    /** 2つのログURLを入力するフォーム */
    public function compareForm()
    {
        return view('fflogs.compare_form');
    }

    /** 2ログをフェーズ別・分別に火力＆ローテ比較 */
    public function compare(Request $request)
    {
        $urlA = $request->input('url_a');
        $urlB = $request->input('url_b');
        $pa = $this->fflogs->parseUrl($urlA ?? '');
        $pb = $this->fflogs->parseUrl($urlB ?? '');
        if (!($pa['code'] ?? null) || !($pa['fightId'] ?? null) || !($pb['code'] ?? null) || !($pb['fightId'] ?? null)) {
            return back()->with('error', 'URLを2つとも正しく入力してください（?fight=◯ の指定が必須）。');
        }

        $A = $this->comparer->buildCompareSide($pa['code'], (int) $pa['fightId'], 'A');
        $B = $this->comparer->buildCompareSide($pb['code'], (int) $pb['fightId'], 'B');
        if (!$A || !$B) {
            return back()->with('error', 'ファイトが見つからない、またはアクセスできませんでした。');
        }
        // 名前を伏せるときは、下書き（＝共有の元）に実名を残さないよう、ここで置き換える。
        // アドバイス文も別名で作られるよう buildPhaseAdvice より前に行う
        $hideNames = $request->boolean('hide_names');
        if ($hideNames) {
            $A = $this->comparer->anonymize($A, $A['roster'], 'A');
            $B = $this->comparer->anonymize($B, $B['roster'], 'B');
        }
        unset($A['roster'], $B['roster']);

        // フェーズID和集合（A/Bを揃えて並べる）
        $phaseIds = collect(array_merge(array_column($A['phases'], 'id'), array_column($B['phases'], 'id')))
            ->unique()->sort()->values()->all();

        // フェーズ別アドバイス（DPS不足側への改善ヒント）を pid => advice で作る
        $aPhaseById = collect($A['phases'])->keyBy('id');
        $bPhaseById = collect($B['phases'])->keyBy('id');
        $advice = [];
        foreach ($phaseIds as $pid) {
            $advice[$pid] = $this->comparer->buildPhaseAdvice(
                $aPhaseById[$pid]['summary'] ?? null,
                $bPhaseById[$pid]['summary'] ?? null,
            );
        }

        $data = compact('A', 'B', 'phaseIds', 'advice', 'hideNames');
        try {
            $draft = app(TimelineShareStore::class)->draft($data, TimelineShareController::owner($request), 'compare');
            // Post/Redirect/Get: 再読み込みで FFLogs への問い合わせを繰り返さない（軽減・バリアの結果と同じ）
            return redirect()->route('timeline-results.show', $draft, 303)->header('Cache-Control', 'private, no-store');
        } catch (\RuntimeException $e) {
            \Illuminate\Support\Facades\Log::warning('Compare sharing preparation failed.');
        }
        return response()->view('fflogs.compare', $data)->header('Cache-Control', 'private, no-store');
    }

    /**
     * グラフ上でドラッグ選択した任意範囲を、フェーズ集計と同じ内容で作り直して返す（AJAX）。
     *
     * 返すのはフェーズカードと同じ材料一式（個人別rDPS・想定rDPS・死亡/デバフ・ロール別合計・不足側アドバイス）。
     * ブラウザ側で同じ見た目に描き直せるようにするのが目的で、rDPSのシナジー配分は生ダメージから
     * 復元できないため、DamageDoneテーブルを窓ごとに引き直している。
     *
     * パラメータは a[code],a[fight],a[start],a[end] / b[...]（絶対ms）。片側だけでもよい。
     * anon=1 のときは、フェーズカードと同じ別名（SCH(A) など）で返す。
     */
    public function rangeStats(Request $request)
    {
        $sides = [];
        foreach (['a', 'b'] as $k) {
            $in = $request->query($k);
            if (!is_array($in)) {
                continue;
            }
            $code = (string) ($in['code'] ?? '');
            $fightId = (int) ($in['fight'] ?? 0);
            $start = (float) ($in['start'] ?? 0);
            $end = (float) ($in['end'] ?? 0);
            if ($code === '' || $fightId <= 0 || $end - $start < 1000) {
                continue;
            } // 1秒未満の窓は集計しない
            $sides[$k] = compact('code', 'fightId', 'start', 'end');
        }
        if (!$sides) {
            return response()->json(['error' => 'パラメータが不正です。'], 422);
        }

        $out = [];
        foreach ($sides as $k => $sd) {
            $sum = $this->comparer->summarizeWindow($sd['code'], $sd['fightId'], $sd['start'], $sd['end']);
            if ($sum === null) {
                return response()->json(['error' => ($k === 'a' ? 'A' : 'B') . '側のデータを取得できませんでした。'], 502);
            }
            $out[$k] = $request->boolean('anon')
                ? $this->comparer->anonymize($sum, $this->comparer->roster($sd['code'], $sd['fightId']), strtoupper($k))
                : $sum;
        }
        return response()->json(self::rangePayload($this->comparer, $out));
    }

    /**
     * 範囲の再集計の応答（A/B の集計に、不足側アドバイスとフェーズカードと同じ3ブロックの HTML を足す）。
     * 共有画面の範囲集計（TimelineShareController::range）も同じ形で返す。
     */
    public static function rangePayload(ReportComparer $comparer, array $out): array
    {
        $out['advice'] = $comparer->buildPhaseAdvice($out['a'] ?? null, $out['b'] ?? null);

        // 表示部分はフェーズカードと同じパーシャルをサーバ側で描画して返す。
        // ブラウザ側で組み直すと同じ見た目を二重実装することになり、片方だけ直して食い違うため。
        $out['html'] = view('fflogs._compare_blocks', [
            'aList' => array_values($out['a']['players'] ?? []),
            'bList' => array_values($out['b']['players'] ?? []),
            'adv' => $out['advice'],
            'scope' => 'この範囲',
        ])->render();

        return $out;
    }

}
