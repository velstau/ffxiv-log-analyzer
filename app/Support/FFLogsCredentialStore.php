<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * FFLogs API キー（Client ID / Client Secret）の置き場所。
 *
 * 公開版では運営者のキーを使わず、利用者が自分のキーを設定する。
 *
 *  - 利用者のキーは **利用者のブラウザの Cookie** に置く。サーバーには保存しない。
 *    Cookie は Laravel の EncryptCookies で暗号化され（中身は APP_KEY が無いと読めない）、
 *    HttpOnly（JavaScript から読めない）・このアプリのパスにだけ送られる設定にする。
 *  - サーバーの .env のキー（services.fflogs.client_id/secret）は、
 *    services.fflogs.allow_server_credentials が true のとき（開発用）にだけ使う。本番では false にして .env にも置かない。
 *
 * Cookie は必ず「使う時点で」読む。コントローラはミドルウェア（Cookie の復号）より先に生成されるため、
 * コンストラクタで読むと暗号化されたままの値を掴む。
 */
class FFLogsCredentialStore
{
    public const COOKIE = 'fflogs_api';

    /** 「このブラウザに保存」を選んだときの保存期間（分） */
    public const REMEMBER_MINUTES = 60 * 24 * 30;

    /**
     * 今のリクエストで使うキー。
     *
     * @return array{id: string, secret: string, source: 'user'|'server'}|null
     */
    public function current(): ?array
    {
        $user = $this->fromCookie();
        if ($user !== null) {
            return $user + ['source' => 'user'];
        }

        if (config('services.fflogs.allow_server_credentials')) {
            $id = (string) config('services.fflogs.client_id', '');
            $secret = (string) config('services.fflogs.client_secret', '');
            if ($id !== '' && $secret !== '') {
                return ['id' => $id, 'secret' => $secret, 'source' => 'server'];
            }
        }

        return null;
    }

    /**
     * 画面表示用の状態（シークレットは含めない）。
     *
     * @return array{state: 'user'|'server'|'none', id_hint: ?string}
     */
    public function status(): array
    {
        $cred = $this->current();

        return [
            'state' => $cred['source'] ?? 'none',
            'id_hint' => $cred !== null ? self::mask($cred['id']) : null,
        ];
    }

    /** @return array{id: string, secret: string}|null */
    public function fromCookie(): ?array
    {
        $raw = request()?->cookie(self::COOKIE);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !is_string($data['id'] ?? null) || !is_string($data['secret'] ?? null)) {
            return null;
        }
        if ($data['id'] === '' || $data['secret'] === '') {
            return null;
        }

        return ['id' => $data['id'], 'secret' => $data['secret']];
    }

    /** 保存用の Cookie。$remember が false ならブラウザを閉じるまで。 */
    public function makeCookie(string $id, string $secret, bool $remember): Cookie
    {
        return cookie(
            self::COOKIE,
            json_encode(['id' => $id, 'secret' => $secret]),
            $remember ? self::REMEMBER_MINUTES : 0,
            $this->cookiePath(),
            null,
            request()->isSecure(),
            true,
            false,
            'lax',
        );
    }

    public function forgetCookie(): Cookie
    {
        return cookie()->forget(self::COOKIE, $this->cookiePath());
    }

    /** Client ID の末尾 4 文字だけを見せる（どのキーを設定したかの確認用） */
    public static function mask(string $id): string
    {
        return '…' . mb_substr($id, -4);
    }

    /** このアプリの配信パス（例: /ff14tools/logs2timeline/）にだけ Cookie を送らせる */
    private function cookiePath(): string
    {
        return rtrim(request()->getBasePath(), '/') . '/';
    }
}
