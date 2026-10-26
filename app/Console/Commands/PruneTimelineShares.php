<?php

namespace App\Console\Commands;

use App\Services\TimelineShareStore;
use Illuminate\Console\Command;

class PruneTimelineShares extends Command
{
    protected $signature = 'timeline-shares:prune';
    protected $description = '期限切れの共有タイムラインを非公開ストレージから削除する';

    public function handle(TimelineShareStore $shares): int
    {
        $this->info($shares->prune() . '件の共有結果を削除しました。');
        return self::SUCCESS;
    }
}
