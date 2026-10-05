<?php

use App\Exceptions\FFLogsCredentialsProblem;
use App\Http\Controllers\ApiKeyController;
use App\Http\Middleware\RequireFFLogsCredentials;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'fflogs.credentials' => RequireFFLogsCredentials::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 入力エラーで差し戻すとき、API キーのシークレットをセッションに残さない
        $exceptions->dontFlash(['client_secret']);

        // API キーが無い・通らない → 設定画面へ案内する（AJAX には 401 の JSON）
        $exceptions->render(function (FFLogsCredentialsProblem $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => $e->userMessage(),
                    'settings_url' => route('api_key.edit'),
                ], 401);
            }

            // 設定後に戻る先: フォームの送信（POST）ならフォームの画面、それ以外は今の画面
            $returnTo = $request->isMethod('GET') ? $request->fullUrl() : url()->previous();
            $request->session()->put(ApiKeyController::RETURN_KEY, $returnTo);

            return redirect()->route('api_key.edit')->with('error', $e->userMessage());
        });
    })->create();
