<?php

namespace App\Exceptions;

/** API キーが設定されていない（利用者の Cookie に無く、サーバーのキーも使えない）。 */
class FFLogsCredentialsMissing extends FFLogsCredentialsProblem
{
    public function __construct()
    {
        parent::__construct('FFLogs API credentials are not set.');
    }

    public function userMessage(): string
    {
        return 'このツールを使うには、ご自身の FFLogs API キーを設定してください。';
    }
}
