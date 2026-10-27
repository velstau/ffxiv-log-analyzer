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
 * nginx は旧パスのリクエストも、このアプリに旧パスのまま（SCRIPT_NAME=/ff14tools/logs2timeline/index.php）渡す。
 * nginx だけで転送すると、旧パスに保存された API キーの Cookie が新しいパスに届かず、利用者が入れ直すことになるため。
 *
 * ルートの解決や CSRF の検査より先に答えるよう、グローバルの先頭に置く（bootstrap/app.php）。
 * そのため Cookie はまだ復号されていない。暗号化された値をそのまま新しいパスに付け直す
 * （暗号化の鍵と Cookie 名は同じなので、新しいパスでもそのまま読める。平文に戻さない）。
 */
class RedirectLegacyPath
{
    public const LEGACY_BASE = '/ff14tools/logs2timeline';

    public const NEW_BASE = '/ff14tools/partyscope';

    public function handle(Request $request, Closure $next): Response
    {
        if (rtrim($request->getBasePath(), '/') !== self::LEGACY_BASE) {
            return $next($request);
        }

        $query = $request->server->get('QUERY_STRING');
        $target = $request->getSchemeAndHttpHost() . self::NEW_BASE . $request->getPathInfo() . ($query ? '?' . $query : '');
        // GET 以外は 308 にして、メソッドと本文を保ったまま送り直させる
        $response = new RedirectResponse($target, $request->isMethodSafe() ? 301 : 308);

        $raw = $request->cookies->get(FFLogsCredentialStore::COOKIE);
        if (is_string($raw) && $raw !== '') {
            // 旧パスの Cookie の残り期間は分からないので、「このブラウザに保存」と同じ期間で付け直す
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
            $response->headers->clearCookie(FFLogsCredentialStore::COOKIE, self::LEGACY_BASE . '/', null, $request->isSecure(), true, 'lax');
        }

        return $response;
    }
}
