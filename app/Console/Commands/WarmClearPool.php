<?php

namespace App\Console\Commands;

use App\Services\FFLogsService;
use Illuminate\Console\Command;

/**
 * PT構成検索が使う討伐ログのプールを、あらかじめ取得してキャッシュに載せる。
 *
 * プールは1ボスにつき数十秒〜数分かかるので、初回アクセスの待ち時間を消したい場合は
 * これをスケジュール実行しておく（キャッシュは6時間で切れる）。
 */
class WarmClearPool extends Command
{
    protected $signature = 'fflogs:warm-pool
                            {encounter?* : エンカウンターID（省略時は --zone のゾーン、それも無ければ何もしない）}
                            {--zone= : ゾーンIDを指定すると、そのゾーンの全ボスを温める}
                            {--force : キャッシュ済みでも取得し直す}';

    protected $description = 'PT構成検索用の討伐ログプールを事前取得してキャッシュする';

    public function handle(FFLogsService $fflogs): int
    {
        $ids = array_map('intval', (array) $this->argument('encounter'));

        if ($this->option('zone')) {
            $ids = array_merge($ids, $this->encountersInZone($fflogs, (int) $this->option('zone')));
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if (empty($ids)) {
            $this->error('温める対象がありません。エンカウンターIDか --zone を指定してください。');

            return self::FAILURE;
        }

        foreach ($ids as $id) {
            if (! $this->option('force') && $fflogs->hasClearPool($id)) {
                $this->line("  <fg=gray>skip</>    encounter {$id}（キャッシュ済み）");

                continue;
            }

            $started = microtime(true);
            $warmed = $fflogs->warmClearPool($id);
            $elapsed = number_format(microtime(true) - $started, 1);

            if (! $warmed) {
                $this->line("  <fg=yellow>busy</>    encounter {$id}（別プロセスが取得中）");

                continue;
            }

            $count = count($fflogs->getClearPool($id)['fights']);
            $this->line("  <fg=green>done</>    encounter {$id} — {$count} 件 / {$elapsed}s");
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function encountersInZone(FFLogsService $fflogs, int $zoneId): array
    {
        foreach ($fflogs->getZoneTree() as $expansion) {
            foreach ($expansion['zones'] ?? [] as $zone) {
                if ((int) ($zone['id'] ?? 0) !== $zoneId) {
                    continue;
                }

                return array_map(
                    static fn($encounter) => (int) $encounter['id'],
                    $zone['encounters'] ?? [],
                );
            }
        }

        $this->warn("ゾーン {$zoneId} が見つかりませんでした。");

        return [];
    }
}
