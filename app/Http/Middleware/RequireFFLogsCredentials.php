<?php

namespace App\Http\Middleware;

use App\Exceptions\FFLogsCredentialsMissing;
use App\Support\FFLogsCredentialStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FFLogs API を呼ぶ処理の前に、API キーが設定されているかを確かめる。
 *
 * 無ければ FFLogsCredentialsMissing を投げ、bootstrap/app.php が設定画面への案内
 * （画面なら設定画面へリダイレクト、AJAX なら 401 の JSON）に変換する。
 * 処理の途中で気づくより先に止めたほうが、待たせずに済む。
 */
class RequireFFLogsCredentials
{
    public function __construct(private readonly FFLogsCredentialStore $credentials) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->credentials->current() === null) {
            throw new FFLogsCredentialsMissing();
        }

        return $next($request);
    }
}
