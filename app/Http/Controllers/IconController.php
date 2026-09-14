<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 画面で使うアイコン画像の配信。
 *
 * スキル／ジョブのアイコンは外部（rpglogs・xivapi）にしかないが、1ページで数百枚使うため
 * 毎回取りに行くと外部側に弾かれる。初回だけ取得して public/icons 配下に永続保存し、
 * 2回目以降は nginx が静的ファイルとして直接返す（PHPを通らない）。
 *
 * 取得した画像はスクウェア・エニックスの著作物なので public/icons はリポジトリに含めない。
 */
class IconController extends Controller
{
    public function proxyImage(Request $request)
    {
        $url = $request->input('url');
        if (!$url) {
            return abort(404);
        }

        // Security: Only allow specific domains
        $parsed = parse_url($url);
        if (!isset($parsed['host']) || !in_array($parsed['host'], ['assets.rpglogs.com', 'raw.githubusercontent.com'])) {
            Log::error("PROXY_FORBIDDEN: " . ($parsed['host'] ?? 'no-host'));
            return abort(403, 'Forbidden Domain');
        }

        // Fetch
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
            ],
        ]);
        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            Log::error("PROXY_FETCH_FAIL: " . $url);
            return abort(404);
        }

        $ext = pathinfo($parsed['path'], PATHINFO_EXTENSION);
        $mime = 'image/png';
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $mime = 'image/jpeg';
        }
        if ($ext === 'webp') {
            $mime = 'image/webp';
        }

        return response($content)
            ->header('Content-Type', $mime)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=604800, immutable'); // 7日キャッシュ（アイコンは静的）
    }

    /**
     * スキルアイコンをローカル静的ファイルとして配信する。
     * public/icons/abilities/{file} に存在すればnginxが直接配信し（PHPを経由しない）、
     * 無い場合のみ初回にrpglogsからダウンロードして同パスに永続保存する。
     * これにより「毎回外部取得」をやめ、同時大量アクセスでのproxy失敗を解消する。
     */
    public function abilityIcon($file)
    {
        return $this->serveCachedIcon(
            public_path('icons/abilities/' . $file),
            'https://assets.rpglogs.com/img/ff/abilities/' . $file,
        );
    }

    /**
     * ジョブアイコンをローカル静的ファイルとして配信する（github classjob-iconsをディスクに永続保存）。
     */
    public function jobIcon($file)
    {
        return $this->serveCachedIcon(
            public_path('icons/jobs/' . $file),
            'https://raw.githubusercontent.com/xivapi/classjob-icons/master/icons/' . $file,
        );
    }

    /**
     * ローカルパスにファイルが無ければ remoteUrl からダウンロードして保存し、その内容を配信する。
     */
    private function serveCachedIcon($path, $remoteUrl)
    {
        if (!is_file($path)) {
            $context = stream_context_create([
                'http' => [
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
                    'timeout' => 10,
                ],
            ]);
            $content = @file_get_contents($remoteUrl, false, $context);
            if ($content === false) {
                return abort(404);
            }
            if (!is_dir(dirname($path))) {
                @mkdir(dirname($path), 0775, true);
            }
            @file_put_contents($path, $content);
        } else {
            $content = file_get_contents($path);
        }

        return response($content)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=2592000, immutable'); // 30日
    }
}
