<?php

namespace App\Http\Controllers;

use App\Exceptions\FFLogsRequestFailed;
use App\Services\FFLogsService;
use App\Support\FFLogsCredentialStore;
use Illuminate\Http\Request;

/**
 * 利用者自身の FFLogs API キー（Client ID / Client Secret）の設定画面。
 *
 * キーはこのブラウザの Cookie（暗号化・HttpOnly）に置き、サーバーには保存しない（{@see FFLogsCredentialStore}）。
 * 保存前に FFLogs でトークンを取得してみて、使えるキーだけを受け付ける。
 */
class ApiKeyController extends Controller
{
    /** 設定後に戻る先をセッションに置くときのキー */
    public const RETURN_KEY = 'api_key_return_to';

    public function __construct(
        private readonly FFLogsCredentialStore $credentials,
        private readonly FFLogsService $fflogs,
    ) {}

    public function edit()
    {
        return view('settings.api_key', [
            'status' => $this->credentials->status(),
            'remembered' => $this->credentials->fromCookie() !== null,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:200', 'regex:/^\S+$/'],
            'client_secret' => ['required', 'string', 'max:200', 'regex:/^\S+$/'],
            'remember' => ['nullable', 'boolean'],
        ], [
            'client_id.required' => 'Client ID を入力してください。',
            'client_secret.required' => 'Client Secret を入力してください。',
            'client_id.regex' => 'Client ID に空白が含まれています。',
            'client_secret.regex' => 'Client Secret に空白が含まれています。',
        ]);

        $id = $validated['client_id'];
        $secret = $validated['client_secret'];

        try {
            $ok = $this->fflogs->verifyCredentials($id, $secret);
        } catch (FFLogsRequestFailed $e) {
            // シークレットは入力欄に戻さない（セッションに残さないため）
            return back()->with('error', $e->getMessage())->withInput($request->only('client_id', 'remember'));
        }

        if (!$ok) {
            return back()
                ->with('error', 'この Client ID / Client Secret ではアクセストークンを取得できませんでした。値をもう一度確認してください。')
                ->withInput($request->only('client_id', 'remember'));
        }

        $returnTo = $request->session()->pull(self::RETURN_KEY);

        return redirect()->to($this->safeReturn($returnTo) ?? route('api_key.edit'))
            ->withCookie($this->credentials->makeCookie($id, $secret, (bool) ($validated['remember'] ?? false)))
            ->with('status', 'FFLogs API キーを設定しました。');
    }

    public function destroy()
    {
        return redirect()->route('api_key.edit')
            ->withCookie($this->credentials->forgetCookie())
            ->with('status', 'このブラウザから FFLogs API キーを削除しました。');
    }

    /** 戻り先はこのアプリ内（同じホスト・配信パス配下）だけにする */
    private function safeReturn(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $root = rtrim(url('/'), '/') . '/';

        return str_starts_with($url, $root) ? $url : null;
    }
}
