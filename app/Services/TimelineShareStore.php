<?php

namespace App\Services;

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
    private const KEYS = ['fight', 'events', 'playerColumns', 'mitigationColumns', 'mitigationGroups', 'mitigationGroupIcons', 'playerDetails', 'playerTimelines', 'start_time', 'synergyWindows', 'deaths', 'hasPhases'];

    public function draft(array $data, string $owner): string
    {
        $id = Str::random(48);
        if (!Cache::put('timeline-draft:' . $id, ['owner' => hash('sha256', $owner), 'data' => Arr::only($data, self::KEYS)], now()->addHours(2))) {
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

    public function publish(string $draft, string $owner): ?array
    {
        $data = $this->findDraft($draft, $owner);
        if (!$data) {
            return null;
        }
        $id = Str::random(48);
        $snapshot = ['version' => 1, 'owner' => $data['owner'], 'created_at' => now()->toIso8601String(), 'expires_at' => now()->addDays(self::DAYS)->toIso8601String(), 'data' => $data['data']];
        $encrypted = Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR));
        if (!Storage::disk('local')->put('timeline-shares/' . $id . '.enc', $encrypted)) {
            throw new RuntimeException('Could not save shared timeline.');
        }
        return ['id' => $id, 'expires_at' => $snapshot['expires_at']];
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
                Storage::disk('local')->delete($path);
                return null;
            }
            return $snapshot;
        } catch (DecryptException|JsonException $e) {
            return null;
        }
    }

    public function ownedBy(array $snapshot, string $owner): bool
    {
        return $owner !== '' && hash_equals($snapshot['owner'], hash('sha256', $owner));
    }

    public function delete(string $id): bool
    {
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
        return $count;
    }
}
