<?php

namespace App\Http\Controllers;

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

    public function result(Request $request, string $id, TimelineShareStore $shares)
    {
        $draft = $shares->findDraft($id, (string) $request->session()->get('timeline_share_owner', ''));
        $response = $draft
            ? response()->view('fflogs.timeline', $draft['data'] + ['shareDraft' => $id, 'cachedResult' => true])
            : response()->view('fflogs.share-unavailable', ['temporaryResult' => true], 410);
        return $response->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer']);
    }

    public function store(Request $request, TimelineShareStore $shares)
    {
        $validated = $request->validate(['draft' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{48}\z/']]);
        try {
            $share = $shares->publish($validated['draft'], (string) $request->session()->get('timeline_share_owner', ''));
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
            ? response()->view('fflogs.timeline', $snapshot['data'] + ['isShared' => true, 'shareId' => $id, 'shareExpiresAt' => $snapshot['expires_at'], 'canDeleteShare' => $shares->ownedBy($snapshot, (string) $request->session()->get('timeline_share_owner', ''))])
            : response()->view('fflogs.share-unavailable', [], 404);
        return $response->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer']);
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
