<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * FFLogs API がエラーを返し、画面を組み立てられなかったとき。
 *
 * サービス層はリダイレクトの作り方を知らないので、失敗はこの例外で伝え、
 * ユーザーに何を見せるかはコントローラ側で決める。
 */
class FFLogsRequestFailed extends RuntimeException
{
    public static function fromApiErrors(mixed $errors): self
    {
        return new self('API Error: ' . json_encode($errors));
    }
}
