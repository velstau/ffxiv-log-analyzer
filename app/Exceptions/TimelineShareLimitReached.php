<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 共有結果の件数が上限に達し、新しい共有を作れないとき。
 *
 * 保存の失敗（RuntimeException）とは別に扱い、利用者に理由を伝える。
 */
class TimelineShareLimitReached extends RuntimeException
{
    public static function perOwner(int $limit): self
    {
        return new self("このブラウザで作成できる共有は{$limit}件までです。有効期限（30日）が切れるか、作成済みの共有を停止すると、新しく作成できます。");
    }

    public static function total(): self
    {
        return new self('共有結果の保存数が上限に達しています。時間をおいて再度お試しください。');
    }
}
