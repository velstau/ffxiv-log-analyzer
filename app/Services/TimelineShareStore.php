<?php

namespace App\Services;

use App\Exceptions\TimelineShareLimitReached;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/** Server-generated results only; neither request payloads nor API credentials are stored. */
class TimelineShareStore
{
    public const DAYS = 30;
    public const MAX_PER_OWNER = 10;
    public const MAX_TOTAL = 500;
    private const META = 'timeline-shares/meta/';
    /** 種別ごとに保存する項目。種別のない古い共有は timeline（軽減・バリア）とみなす */
    private const KEYS = [
        'timeline' => ['fight', 'events', 'playerColumns', 'mitigationColumns', 'mitigationGroups', 'mitigationGroupIcons', 'playerDetails', 'playerTimelines', 'start_time', 'synergyWindows', 'deaths', 'hasPhases', 'hideNames'],
        'compare' => ['A', 'B', 'phaseIds', 'advice', 'hideNames'],
    ];

    public function draft(array $data, string $owner, string $kind = 'timeline'): string
    {
        $id = Str::random(48);
        if (!Cache::put('timeline-draft:' . $id, ['owner' => hash('sha256', $owner), 'kind' => $kind, 'data' => Arr::only($data, self::KEYS[$kind])], now()->addHours(2))) {
            throw new RuntimeException('Could not prepare timeline sharing.');
        }
        return $id;
    }

    public function findDraft(string $id, string $owner): ?array
    {
        if ($owner === '' || !preg_match('/\A[A-Za-z0-9]{48}\z/', $id)) {
            return null;
        }
        $draft = Cache::get('timeline-draft:' . $id);
        return $draft && hash_equals($draft['owner'], hash('sha256', $owner)) ? $draft : null;
    }

    /**
     * @param  callable|null $prepare DPS 比較の共有を新しく作るときだけ、保存する直前（レポート番号を外す前）に
     *                                データを渡して差し替えさせる（範囲再集計用の取り置きを足すため）
     */
    public function publish(string $draft, string $owner, ?callable $prepare = null): ?array
    {
        $data = $this->findDraft($draft, $owner);
        if (!$data) {
            return null;
        }
        // 同じ解析結果からは同じ共有を返す（作り直しでファイルを増やさない）
        $draftKey = hash('sha256', $draft);
        // DPS 比較は保存前の取り置き（$prepare）に数秒かかる。その間に同じ結果から 2 本作らないよう、
        // 同じ下書きの作成は 1 本ずつ通す（後から来た方は、先の共有を重複判定で受け取る）
        try {
            return Cache::lock('timeline-share-publish:' . $draftKey, 120)
                ->block(100, fn() => $this->publishOnce($data, $draftKey, $prepare));
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('Timed out waiting for another share of the same result.');
        }
    }

    private function publishOnce(array $data, string $draftKey, ?callable $prepare): array
    {
        $owned = array_filter($this->activeMeta(), fn($meta) => $meta['owner'] === $data['owner']);
        foreach ($owned as $existing => $meta) {
            if ($meta['draft'] === $draftKey) {
                return ['id' => $existing, 'expires_at' => $meta['expires_at']];
            }
        }
        if (count($owned) >= self::MAX_PER_OWNER) {
            throw TimelineShareLimitReached::perOwner(self::MAX_PER_OWNER);
        }
        // 上限に達したときだけ期限切れを掃除して数え直す（定期の掃除が止まっていても塞がらない）
        if (count(Storage::disk('local')->files('timeline-shares')) >= self::MAX_TOTAL) {
            $this->prune();
            if (count(Storage::disk('local')->files('timeline-shares')) >= self::MAX_TOTAL) {
                throw TimelineShareLimitReached::total();
            }
        }
        $id = Str::random(48);
        $kind = self::kindOf($data);
        $payload = $data['data'];
        if ($kind === 'compare') {
            $payload = self::withoutReportCodes($prepare ? $prepare($payload) : $payload);
        }
        $snapshot = ['version' => 1, 'kind' => $kind, 'owner' => $data['owner'], 'created_at' => now()->toIso8601String(), 'expires_at' => now()->addDays(self::DAYS)->toIso8601String(), 'data' => $payload];
        $encrypted = Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR));
        if (!Storage::disk('local')->put('timeline-shares/' . $id . '.enc', $encrypted)) {
            throw new RuntimeException('Could not save shared timeline.');
        }
        // 件数を数えるための索引。作成者は乱数のハッシュだけで、結果の中身は入れない
        $meta = ['owner' => $data['owner'], 'draft' => $draftKey, 'expires_at' => $snapshot['expires_at']];
        try {
            // throw=false でも、フォルダを作れない失敗は例外で来る
            $indexed = Storage::disk('local')->put(self::META . $id . '.json', json_encode($meta, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $indexed = false;
        }
        if (!$indexed) {
            // 索引のない共有は件数にも重複判定にも入らないので、本体も残さない
            Storage::disk('local')->delete('timeline-shares/' . $id . '.enc');
            throw new RuntimeException('Could not index shared timeline.');
        }
        return ['id' => $id, 'expires_at' => $snapshot['expires_at']];
    }

    /** 有効な共有の索引（id => meta）。期限切れや本体のない索引はここで消す。 */
    private function activeMeta(): array
    {
        $disk = Storage::disk('local');
        $active = [];
        foreach ($disk->files(rtrim(self::META, '/')) as $path) {
            if (!preg_match('#/([A-Za-z0-9]{48})\.json$#', $path, $match)) {
                continue;
            }
            $meta = json_decode((string) $disk->get($path), true);
            $body = 'timeline-shares/' . $match[1] . '.enc';
            if (is_array($meta) && isset($meta['expires_at']) && now()->greaterThanOrEqualTo($meta['expires_at'])) {
                $disk->delete([$path, $body]); // 索引の期限は本体と同じ
                continue;
            }
            if (!is_array($meta) || !isset($meta['owner'], $meta['draft'], $meta['expires_at']) || !$disk->exists($body)) {
                $disk->delete($path);
                continue;
            }
            $active[$match[1]] = $meta;
        }
        return $active;
    }

    public function find(string $id): ?array
    {
        if (!preg_match('/\A[A-Za-z0-9]{48}\z/', $id)) {
            return null;
        }
        $path = 'timeline-shares/' . $id . '.enc';
        $encrypted = Storage::disk('local')->get($path);
        if ($encrypted === null) {
            return null;
        }
        try {
            $snapshot = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
            if (($snapshot['version'] ?? null) !== 1 || !isset($snapshot['expires_at'], $snapshot['owner'], $snapshot['data'])) {
                return null;
            }
            if (now()->greaterThanOrEqualTo($snapshot['expires_at'])) {
                Storage::disk('local')->delete([$path, self::META . $id . '.json']);
                return null;
            }
            return $snapshot;
        } catch (DecryptException|JsonException $e) {
            return null;
        }
    }

    /** 下書き・共有の種別（timeline / compare） */
    public static function kindOf(array $stored): string
    {
        return ($stored['kind'] ?? 'timeline') === 'compare' ? 'compare' : 'timeline';
    }

    /**
     * 共有にはレポート番号を残さない（軽減の共有と同じ扱い。名前を伏せてもレポートを開けば分かってしまう）。
     * 番号は範囲指定の再集計にだけ使うもので、共有画面の範囲指定は、共有を作るときに取り置いた
     * 10 秒区間の集計（rangeIndex。番号を含まない）から出す。
     */
    private static function withoutReportCodes(array $data): array
    {
        foreach (['A', 'B'] as $side) {
            if (isset($data[$side]) && is_array($data[$side])) {
                unset($data[$side]['code'], $data[$side]['fightId']);
            }
        }
        return $data;
    }

    public function ownedBy(array $snapshot, string $owner): bool
    {
        return $owner !== '' && hash_equals($snapshot['owner'], hash('sha256', $owner));
    }

    public function delete(string $id): bool
    {
        Storage::disk('local')->delete(self::META . $id . '.json');
        return Storage::disk('local')->delete('timeline-shares/' . $id . '.enc');
    }

    public function prune(): int
    {
        $count = 0;
        foreach (Storage::disk('local')->files('timeline-shares') as $path) {
            if (preg_match('#/([A-Za-z0-9]{48})\.enc$#', $path, $match)) {
                // Only find()'s confirmed expiry removes a file. Keep unreadable files
                // for recovery instead of deleting data if APP_KEY is misconfigured.
                $this->find($match[1]);
                if (!Storage::disk('local')->exists($path)) {
                    $count++;
                }
            }
        }
        $this->activeMeta(); // 本体の消えた索引を片付ける
        return $count;
    }
}
