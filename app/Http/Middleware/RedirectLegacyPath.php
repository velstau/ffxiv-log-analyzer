<?php

namespace App\Http\Middleware;

use App\Support\FFLogsCredentialStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 旧名（logs2timeline）の URL に来たリクエストを、新しい URL（partyscope）へ転送する。
 *
 * nginx は旧パスのリクエストも、このアプリに旧パスのまま（SCRIPT_NAME=/ff14tools/logs2timeline/index.php）
 * 渡し、目印に LEGACY_PATH=1 を付ける。nginx だけで転送すると、旧パスに保存された API キーの Cookie が
 * 新しいパスに届かず、利用者が入れ直すことになるため。
 *
 * ルートの解決や CSRF の検査より先に答えるよう、グローバルの先頭に置く（bootstrap/app.php）。
 * そのため Cookie はまだ復号されていない。暗号化された値をそのまま新しいパスに付け直す
 * （暗号化の鍵と Cookie 名は同じなので、新しいパスでもそのまま読める。平文に戻さない）。
 */
class RedirectLegacyPath
{
    public const LEGACY_BASE = '/ff14tools/logs2timeline'; // FFLogsCredentialStore::LEGACY_COOKIE_PATH と揃える

    public const NEW_BASE = '/ff14tools/partyscope';

    public function handle(Request $request, Closure $next): Response
    {
        $base = rtrim($request->getBasePath(), '/');
        $fromLegacy = $request->server->get('LEGACY_PATH') === '1';
        if ($base !== self::LEGACY_BASE && !$fromLegacy) {
            return $next($request);
        }

        // 旧パスを %6c のようにエンコードして来たときは、残りのパスを正しく切り出せないので新しい TOP へ送る
        $rest = $base === self::LEGACY_BASE ? $request->getPathInfo() : '/';
        $query = $request->server->get('QUERY_STRING');
        // 転送先はパスだけにする（Host ヘッダーやスキームの判定に左右されない）
        $target = self::NEW_BASE . $rest . ($query ? '?' . $query : '');
        $keepMethod = !in_array($request->getMethod(), ['GET', 'HEAD'], true);

        $raw = $request->cookies->get(FFLogsCredentialStore::COOKIE);
        if (!is_string($raw) || $raw === '') {
            return new RedirectResponse($target, $keepMethod ? 308 : 301);
        }

        // Cookie を付け直す応答は、ブラウザや途中のキャッシュに残させない（一時的な転送にする）
        $response = new RedirectResponse($target, $keepMethod ? 307 : 302, ['Cache-Control' => 'private, no-store']);
        // 旧パスの Cookie の残り期間と「このブラウザに保存」を選んだかは分からないので、保存を選んだときと同じ期間で付け直す
        $response->headers->setCookie(new Cookie(
            FFLogsCredentialStore::COOKIE,
            $raw,
            time() + FFLogsCredentialStore::REMEMBER_MINUTES * 60,
            self::NEW_BASE . '/',
            null,
            $request->isSecure(),
            true,
            true,
            'lax',
        ));
        $response->headers->setCookie(FFLogsCredentialStore::forgetLegacyCookie($request->isSecure()));

        return $response;
    }
}
