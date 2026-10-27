<?php

namespace App\Http\Controllers;

use App\Exceptions\TimelineShareLimitReached;
use App\Services\ReportComparer;
use App\Services\TimelineShareStore;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TimelineShareController extends Controller
{
    public static function owner(Request $request): string
    {
        if (!$request->session()->has('timeline_share_owner')) {
            $request->session()->put('timeline_share_owner', Str::random(48));
        }
        return $request->session()->get('timeline_share_owner');
    }

    /** 軽減・バリアの結果と DPS 比較の結果は、同じ下書き・共有の仕組みに種別を付けて載せている */
    private static function viewOf(array $stored): string
    {
        return TimelineShareStore::kindOf($stored) === 'compare' ? 'fflogs.compare' : 'fflogs.timeline';
    }

    public function result(Request $request, string $id, TimelineShareStore $shares)
    {
        $draft = $shares->findDraft($id, (string) $request->session()->get('timeline_share_owner', ''));
        $response = $draft
            ? response()->view(self::viewOf($draft), $draft['data'] + ['shareDraft' => $id, 'cachedResult' => true])
            : response()->view('fflogs.share-unavailable', ['temporaryResult' => true], 410);
        return $response->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer']);
    }

    public function store(Request $request, TimelineShareStore $shares, ReportComparer $comparer)
    {
        $validated = $request->validate(['draft' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{48}\z/']]);
        try {
            // DPS 比較は、閲覧者がキーなしで範囲を再集計できるよう、作成者のキーで 10 秒区間の集計を取り置く
            $share = $shares->publish($validated['draft'], (string) $request->session()->get('timeline_share_owner', ''), fn(array $data) => $comparer->withRangeIndex($data));
        } catch (TimelineShareLimitReached $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => '共有結果を保存できませんでした。時間をおいて再度お試しください。'], 503);
        }
        if (!$share) {
            return response()->json(['message' => '共有の準備情報が期限切れか、このブラウザの解析結果ではありません。もう一度解析してください。'], 410);
        }
        return response()->json(['url' => route('timeline-shares.show', $share['id']), 'expires_at' => $share['expires_at']])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $id, TimelineShareStore $shares)
    {
        $snapshot = $shares->find($id);
        $response = $snapshot
            ? response()->view(self::viewOf($snapshot), $snapshot['data'] + ['isShared' => true, 'shareId' => $id, 'shareExpiresAt' => $snapshot['expires_at'], 'canDeleteShare' => $shares->ownedBy($snapshot, (string) $request->session()->get('timeline_share_owner', ''))])
            : response()->view('fflogs.share-unavailable', [], 404);
        return $response->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer']);
    }

    /**
     * 共有した DPS 比較の、グラフで選んだ範囲の再集計（AJAX）。閲覧者のキーは使わない。
     * 共有を作るときに取り置いた 10 秒区間の集計（rangeIndex）を足し合わせ、FFLogsController::rangeStats と同じ形で返す。
     * t0・t1 はフェーズ開始からの秒。範囲は 10 秒単位に広げて丸め、実際の範囲を a.win・b.win で返す。
     */
    public function range(Request $request, string $id, TimelineShareStore $shares, ReportComparer $comparer)
    {
        // 共有ファイルの復号は重いので、パラメータを先に確かめる
        $pid = (string) $request->query('phase', '');
        $t0 = (float) $request->query('t0', 0);
        $t1 = (float) $request->query('t1', 0);
        if (!preg_match('/\A[0-9]{1,3}\z/', $pid) || $t0 < 0 || $t1 - $t0 < 1) {
            return response()->json(['error' => 'パラメータが不正です。'], 422);
        }
        $snapshot = $shares->find($id);
        if (!$snapshot || TimelineShareStore::kindOf($snapshot) !== 'compare') {
            return response()->json(['error' => '共有が見つかりません。'], 404);
        }

        $out = [];
        $indexed = false;
        foreach (['a' => 'A', 'b' => 'B'] as $k => $side) {
            $index = $snapshot['data'][$side]['rangeIndex'] ?? null;
            if (!is_array($index)) {
                continue;
            }
            $indexed = true;
            $sum = $comparer->summarizeStoredWindow($index, $pid, $t0, $t1);
            if ($sum !== null) {
                $out[$k] = $sum;
            }
        }
        if (!$indexed) {
            return response()->json(['error' => 'この共有には範囲の再集計用のデータがありません。'], 409);
        }
        if (!$out) {
            return response()->json(['error' => '選択範囲がどちらのフェーズにも掛かっていません。'], 422);
        }
        return response()->json(FFLogsController::rangePayload($comparer, $out))
            ->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive']);
    }

    public function destroy(Request $request, string $id, TimelineShareStore $shares)
    {
        $snapshot = $shares->find($id);
        abort_unless($snapshot, 404);
        abort_unless($shares->ownedBy($snapshot, (string) $request->session()->get('timeline_share_owner', '')), 403);
        abort_unless($shares->delete($id), 503);
        return response()->view('fflogs.share-unavailable', ['deleted' => true])->withHeaders(['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
