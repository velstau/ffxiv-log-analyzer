<?php

namespace App\Exceptions;

/**
 * API キーでアクセストークンを取得できなかった（FFLogs 側でクライアントを削除・再発行した等）。
 *
 * 通信できなかった場合はキーの問題ではないので、この例外ではなく FFLogsRequestFailed を使う。
 */
class FFLogsCredentialsInvalid extends FFLogsCredentialsProblem
{
    /** @param string $source 'user'（利用者のキー）/ 'server'（開発用の .env のキー） */
    public function __construct(private readonly string $source = 'user')
    {
        parent::__construct("FFLogs API credentials were rejected ({$source}).");
    }

    public function userMessage(): string
    {
        return $this->source === 'server'
            ? '.env の FFLogs API キー（開発用）でアクセストークンを取得できませんでした。'
            : '設定されている FFLogs API キーでアクセストークンを取得できませんでした。FFLogs 側でクライアントを削除・再発行した場合は、設定し直してください。';
    }
}
