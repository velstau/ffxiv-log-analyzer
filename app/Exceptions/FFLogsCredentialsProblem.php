<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * FFLogs の API キー（Client ID / Client Secret）に問題があり、API を呼べないとき。
 *
 * 利用者が自分のキーを設定する作りなので、どちらの場合も「設定画面へ案内する」で対処する
 * （bootstrap/app.php で設定画面へのリダイレクト、または 401 の JSON に変換する）。
 * サービス層の catch (\Throwable) で握りつぶさないこと。握りつぶすと「データが無い」に化ける。
 */
abstract class FFLogsCredentialsProblem extends RuntimeException
{
    /** 画面に出す説明（利用者向け） */
    abstract public function userMessage(): string;
}
