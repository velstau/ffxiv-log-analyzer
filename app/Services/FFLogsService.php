<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * FFLogs API v2（GraphQL）クライアント。
 *
 * このアプリで FFLogs を叩くのはここだけ。呼び出し側は GraphQL を知らなくてよい。
 *
 * 扱ううえで押さえておくべき制約:
 *
 *  - **レート制限はポイント制（既定 3,600 points/hour）。** リクエスト数ではなくクエリの
 *    複雑さで消費する。使い切ると 429 が返り、1時間復旧しない。
 *    残量は `rateLimitData { limitPerHour pointsSpentThisHour pointsResetIn }` で確認できる。
 *  - **イベントは1回で取り切れない。** nextPageTimestamp を辿るページングが必要
 *    （{@see fetchPagedData()}）。
 *  - **ランキングはページ数が固定。** 並列度を上げても消費ポイントは変わらず往復回数だけ減る。
 *  - **キャッシュ書き込みの失敗で止めない。** {@see cacheRemember()} は書けなくても値を返す。
 *    キャッシュは高速化のためのものなので、書けないことを致命傷にしない。
 *
 * 認証情報は `config('services.fflogs.*')` から読む。`env()` を直接呼ぶと
 * `php artisan config:cache` したときに null になるため使わないこと。
 */
class FFLogsService
{
    /**
     * characterRankings を並列取得するときの同時リクエスト数。
     * 1ページあたりの所要は 10並列で約0.12秒、40並列で約0.05秒（実測）。
     * ページ数は固定なので、並列度を上げてもAPIの消費ポイントは変わらず往復回数だけ減る。
     */
    private const RANKING_WAVE = 40;

    /** playerDetails のバッチ（1件＝レポート25本ぶん）を並列取得するときの同時リクエスト数。 */
    private const PLAYER_DETAILS_WAVE = 20;

    private readonly string $clientId;

    private readonly string $clientSecret;

    private readonly string $tokenUrl;

    private readonly string $apiUrl;

    public function __construct()
    {
        $this->clientId = (string) config('services.fflogs.client_id', '');
        $this->clientSecret = (string) config('services.fflogs.client_secret', '');
        $this->tokenUrl = (string) config('services.fflogs.token_url');
        $this->apiUrl = (string) config('services.fflogs.api_url');
    }

    /**
     * Get OAuth2 Access Token
     */
    private function getToken(): ?string
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException(
                'FFLogs credentials are missing. Set FFLOGS_CLIENT_ID and FFLOGS_CLIENT_SECRET in your .env file.',
            );
        }

        return Cache::remember('fflogs_token', 3000, function () {
            $response = Http::asForm()->post($this->tokenUrl, [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]);

            return $response->json()['access_token'];
        });
    }

    /**
     * 軽減スキルのアイコンファイル名を gameData API から解決する。
     * レポートの masterData に含まれないスキル（未使用ジョブのランパート等）でも
     * 常にアイコンを取得できるようにするためのフォールバック。
     * ゲームデータは静的なので長期キャッシュする。
     *
     * @param  array $ids  ability の gameID（actionId / buffId）の配列
     * @return array       [id => iconFileName]
     */
    public function getAbilityIconMap(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        sort($ids);
        if (empty($ids)) {
            return [];
        }

        $cacheKey = 'fflogs_ability_icons_' . md5(implode(',', $ids));

        // キャッシュ済みなら使う（ゲームデータは静的）
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $map = [];
        $failed = false;
        // GraphQLエイリアスで複数IDを1リクエストにまとめる（100件ずつ分割）
        foreach (array_chunk($ids, 100) as $chunk) {
            $fields = '';
            foreach ($chunk as $id) {
                $fields .= "a{$id}: ability(id: {$id}) { icon }\n";
            }
            $query = "query { gameData { {$fields} } }";

            try {
                $response = Http::withToken($this->getToken())->post($this->apiUrl, [
                    'query' => $query,
                ]);
                $data = $response->json()['data']['gameData'] ?? null;
                if ($data === null) {
                    $failed = true;
                    continue;
                }
                foreach ($data as $alias => $ability) {
                    if ($ability && !empty($ability['icon'])) {
                        $gid = (int) ltrim($alias, 'a');
                        $map[$gid] = $ability['icon'];
                    }
                }
            } catch (\Throwable $e) {
                $failed = true;
                Log::error('FFLogs gameData icon fetch failed: ' . $e->getMessage());
            }
        }

        // 全チャンク成功した場合のみ30日キャッシュ。部分失敗時はキャッシュせず次回再取得（不完全なマップが固定化するのを防ぐ）
        if (!$failed) {
            Cache::put($cacheKey, $map, 60 * 60 * 24 * 30);
        }

        return $map;
    }

    /**
     * スキルのAoEジオメトリ（形状CastType・効果範囲EffectRange[yalm]）を取得する。
     * FFLogsログには形状・半径が無いため、FFXIVゲームデータ(XIVAPI v2)から取得する。
     * ゲームデータは静的なので長期キャッシュする。
     *
     * @param  array $ids  action の gameID 配列
     * @return array       [id => ['castType'=>int, 'range'=>int]]
     */
    public function getActionGeometry(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        sort($ids);
        if (empty($ids)) {
            return [];
        }

        $cacheKey = 'xivapi_action_geo_' . md5(implode(',', $ids));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $map = [];
        $failed = false;
        // XIVAPI v2(beta) のシート一括取得（rows=カンマ区切り）。100件ずつ分割。
        foreach (array_chunk($ids, 100) as $chunk) {
            $url = 'https://beta.xivapi.com/api/1/sheet/Action?rows=' . implode(',', $chunk) . '&fields=CastType,EffectRange';
            try {
                $response = Http::timeout(15)->get($url);
                // 非2xx（429レート制限/5xx等）は失敗扱いにしてキャッシュしない（空マップ固定化の防止）
                if (!$response->successful()) {
                    $failed = true;
                    continue;
                }
                $rows = $response->json()['rows'] ?? null;
                if ($rows === null) {
                    $failed = true;
                    continue;
                }
                foreach ($rows as $row) {
                    $gid = (int) ($row['row_id'] ?? 0);
                    if (!$gid) {
                        continue;
                    }
                    $map[$gid] = [
                        'castType' => (int) ($row['fields']['CastType'] ?? 0),
                        'range'    => (int) ($row['fields']['EffectRange'] ?? 0),
                    ];
                }
            } catch (\Throwable $e) {
                $failed = true;
                Log::error('XIVAPI action geometry fetch failed: ' . $e->getMessage());
            }
        }

        // 全チャンク成功時のみ30日キャッシュ（部分失敗は固定化を避けキャッシュしない）
        if (!$failed) {
            Cache::put($cacheKey, $map, 60 * 60 * 24 * 30);
        }

        return $map;
    }

    /**
     * Parse report code and fight ID from URL
     */
    public function parseUrl($url): array
    {
        // Example: https://ja.fflogs.com/reports/XXXXXXXXXXXXXXXX?fight=11&...
        //   Code: XXXXXXXXXXXXXXXX / Fight: 11
        $parsed = parse_url($url);
        $pathParts = explode('/', trim($parsed['path'], '/'));

        // Path is usually reports/CODE or reports/CODE/
        $code = null;
        foreach ($pathParts as $index => $part) {
            if ($part === 'reports' && isset($pathParts[$index + 1])) {
                $code = $pathParts[$index + 1];
                break;
            }
        }

        $fightId = null;
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
            $fightId = $query['fight'] ?? null;
        }

        return ['code' => $code, 'fightId' => $fightId];
    }

    /**
     * Get Fight Details (Start/End time)
     */
    public function getFightDetails($code, $fightId): ?array
    {
        $query = <<<'GQL'
query($code: String!, $fightId: Int!) {
    reportData {
        report(code: $code) {
            fights(fightIDs: [$fightId]) {
                id
                startTime
                endTime
                name
                gameZone {
                    id
                    name
                }
                phaseTransitions {
                    id
                    startTime
                }
                friendlyPlayers
            }
        }
    }
}
GQL;

        $response = Http::withToken($this->getToken())
            ->post($this->apiUrl, [
                'query' => $query,
                'variables' => [
                    'code' => $code,
                    'fightId' => (int) $fightId,
                ],
            ]);

        return $response->json()['data']['reportData']['report']['fights'][0] ?? null;
    }

    /**
     * Get Events (Damage Taken and Casts)
     */
    /**
     * Get Events (Damage Taken, Casts, Buffs, Debuffs) - Optimized & Paginated
     */
    public function getEvents($code, $fightId, $startTime, $endTime, $filterIds = []): array
    {
        // 1. Get Master Data (Actors/Abilities)
        $masterData = $this->getMasterData($code);

        // 2. Prepare Filter IDs string
        // Default IDs for Seraph etc just in case
        $defaultIds = [16545, 3585, 7535, 22198, 7549, 7560];
        $allIds = array_unique(array_merge($defaultIds, $filterIds));
        $idString = implode(', ', $allIds);

        // 3. Fetch Data Streams Independently (to handle pagination correctly)

        // A. Damage Taken (Source = Enemy)
        // includeResources:true で各イベントに座標・HP（targetResources / sourceResources）を含める。
        // デスチェッカーの円形マップ／残HP表示に利用する。
        $damageTaken = $this->fetchPagedData($code, $startTime, $endTime, 'DamageTaken', "source.disposition = 'enemy'", true);

        // A-2. Deaths（味方の死亡イベント）。とどめスキル・死亡時刻・対象を取得する。
        $deaths = $this->fetchPagedData($code, $startTime, $endTime, 'Deaths', "target.disposition = 'friendly'");

        // A-3. 敵キャスト（座標付き）。被弾していないテレグラフ＝塔/回避AoE の描画に使う。
        // Casts は filterExpression(source.disposition) が効かないため hostilityType:Enemies を使う。
        $enemyCasts = $this->fetchPagedData($code, $startTime, $endTime, 'Casts', null, true, 'Enemies');

        // B. Enemy Debuffs（敵=ボスに付与されたデバフ）。アドル/リプライザル/牽制などのデバフ系軽減判定に使う。
        // hostilityType:Enemies 必須（未指定だと味方デバフになりボスへの軽減デバフが取れない）。
        // 除去は debuff なので removedebuff（removebuff ではない）。
        $enemyDebuffs = $this->fetchPagedData($code, $startTime, $endTime, 'Debuffs', "type IN ('applydebuff','removedebuff')", false, 'Enemies');

        // C. Friend Buffs (Type = applybuff/removebuff) - FILTER REMOVED (Diagnosis)
        // Optimization: ID filter causing empty results? fetching all for now.
        $friendBuffs = $this->fetchPagedData($code, $startTime, $endTime, 'Buffs', "type IN ('applybuff','removebuff')");

        // D. Friend Casts (Type = Casts, Filtered by IDs)
        // Replaces old 'abilityUse' which was too heavy.
        $friendCasts = $this->fetchPagedData($code, $startTime, $endTime, 'Casts', "ability.id IN ($idString)");

        // E. 味方の座標サンプル（全キャスト・includeResources）。デスチェッカーの全員座標スナップショットを
        //    密にするため（被弾していないプレイヤーも詠唱ごとに座標が取れる）。
        $friendPositions = $this->fetchPagedData($code, $startTime, $endTime, 'Casts', null, true, 'Friendlies');

        // F. 頭マーカー（dataType省略＝全種別から type=headmarker を抽出）。扇等の担当者特定に使う。
        $headmarkers = $this->fetchPagedData($code, $startTime, $endTime, null, "type = 'headmarker'");

        // G. 形状マーカーデバフ（ケフカ/Sigmascape V4: 13DC=1005084頭割り,13DD=1005085円,13DE=1005086扇）。
        //    プレイヤーに付与され、塔を踏むとその形状AoEを発動する。扇担当の特定に使う。
        $shapeMarkers = $this->fetchPagedData($code, $startTime, $endTime, 'Debuffs', "ability.id IN (1005084,1005085,1005086)");

        // Construct the Report array structure expected by Controller
        return [
            'masterData' => $masterData,
            'damageTaken' => ['data' => $damageTaken],
            'enemyDebuffs' => ['data' => $enemyDebuffs],
            'friendBuffs' => ['data' => $friendBuffs],
            'friendCasts' => ['data' => $friendCasts],
            'abilityUse' => ['data' => $friendCasts], // Map abilityUse to friendCasts for compatibility
            'deaths' => ['data' => $deaths],
            'enemyCasts' => ['data' => $enemyCasts],
            'friendPositions' => ['data' => $friendPositions],
            'headmarkers' => ['data' => $headmarkers],
            'shapeMarkers' => ['data' => $shapeMarkers],
        ];
    }

    /**
     * 指定期間の DamageDone テーブル（プレイヤー別の総ダメージ・rDPS・aDPS・スキル別内訳）を取得。
     * フェーズ窓や分窓を startTime/endTime で渡すと、その範囲の集計が返る（A/B火力比較用）。
     */
    public function getDamageTable($code, $fightId, $startTime, $endTime): array
    {
        $query = <<<'GQL'
query($c: String!, $f: Int!, $s: Float!, $e: Float!) {
    reportData {
        report(code: $c) {
            table(dataType: DamageDone, fightIDs: [$f], startTime: $s, endTime: $e)
        }
    }
}
GQL;
        $response = Http::withToken($this->getToken())->post($this->apiUrl, [
            'query' => $query,
            'variables' => ['c' => $code, 'f' => (int) $fightId, 's' => (float) $startTime, 'e' => (float) $endTime],
        ]);
        $tbl = $response->json()['data']['reportData']['report']['table'] ?? null;
        if (!$tbl) {
            return ['entries' => [], 'combatTime' => 0];
        }
        $data = $tbl['data'] ?? $tbl;
        return [
            'entries' => $data['entries'] ?? [],
            'combatTime' => $data['combatTime'] ?? 0,
            'totalTime' => $data['totalTime'] ?? 0,
            'damageDowntime' => $data['damageDowntime'] ?? 0,
        ];
    }

    /**
     * 指定期間に「各敵（ボス／雑魚）が受けた総ダメージ」テーブルを取得。
     * dataType: DamageTaken + hostilityType: Enemies で、敵アクター別の被ダメージ合計が返る。
     * フェーズ窓を渡せば「そのフェーズでどの敵にどれだけ火力を割いたか」が分かる（A/B比較用）。
     * 戻り値の各エントリ: name（敵名）, total（被ダメ合計）, type（Boss/NPC等）, icon 等。
     */
    public function getEnemyDamageTable($code, $fightId, $startTime, $endTime): array
    {
        $query = <<<'GQL'
query($c: String!, $f: Int!, $s: Float!, $e: Float!) {
    reportData {
        report(code: $c) {
            table(dataType: DamageTaken, hostilityType: Enemies, fightIDs: [$f], startTime: $s, endTime: $e)
        }
    }
}
GQL;
        $response = Http::withToken($this->getToken())->post($this->apiUrl, [
            'query' => $query,
            'variables' => ['c' => $code, 'f' => (int) $fightId, 's' => (float) $startTime, 'e' => (float) $endTime],
        ]);
        $tbl = $response->json()['data']['reportData']['report']['table'] ?? null;
        if (!$tbl) {
            return [];
        }
        $data = $tbl['data'] ?? $tbl;
        return $data['entries'] ?? [];
    }

    /**
     * 味方与ダメージの生イベント（フェーズ内のDPS推移グラフ・範囲指定集計用）。
     * パーティ合計のrDPSはraw合計と一致する（シナジー配分はパーティ内ゼロサム）ため、
     * バケット合計÷秒数がそのままパーティrDPSになる。
     */
    public function getDamageDoneEvents($code, $fightId, $startTime, $endTime): array
    {
        // FF14ログは calculateddamage（詠唱時計算値）と damage（着弾確定値）の2種が返り
        // 両方合計すると二重計上になるため、確定値のみに絞る。
        return $this->fetchPagedData($code, $startTime, $endTime, 'DamageDone', "type = 'damage'");
    }

    /**
     * 敵（ボス/雑魚）のキャストイベント。フェーズ内DPS推移グラフに「その時点で来ている敵の攻撃」を
     * 重ねるために使う。Casts は filterExpression(source.disposition) が効かないため hostilityType:Enemies。
     */
    public function getEnemyCasts($code, $fightId, $startTime, $endTime): array
    {
        return $this->fetchPagedData($code, $startTime, $endTime, 'Casts', null, false, 'Enemies');
    }

    /**
     * 味方の死亡イベント（A/B比較のアドバイス用）。targetID=死亡者, timestamp=死亡時刻。
     */
    public function getDeathEvents($code, $fightId, $startTime, $endTime): array
    {
        return $this->fetchPagedData($code, $startTime, $endTime, 'Deaths', "target.disposition = 'friendly'");
    }

    /**
     * 味方に付与されたデバフの apply/remove イベント（ダメージ低下・衰弱・頽廃の検出用）。
     * hostilityType 未指定＝Friendlies（getEvents の enemyDebuffs とは逆側）。
     */
    public function getFriendlyDebuffs($code, $fightId, $startTime, $endTime): array
    {
        return $this->fetchPagedData($code, $startTime, $endTime, 'Debuffs', "type IN ('applydebuff','removedebuff')");
    }

    /**
     * フレンドリーの全キャスト（ローテーション比較用）。アビリティ無制限。
     */
    public function getAllFriendCasts($code, $fightId, $startTime, $endTime): array
    {
        return $this->fetchPagedData($code, $startTime, $endTime, 'Casts', null, false, 'Friendlies');
    }

    /**
     * 指定ステータスのバフイベント(apply/refresh/remove)を取得。
     * バフイベントのサーバ側フィルタは +1000000 した「オフセットID」で効く（例: クローズドポジション［被］=1001824）。
     */
    public function getBuffEventsByStatus($code, $fightId, $startTime, $endTime, $offsetStatusId): array
    {
        return $this->fetchPagedData($code, $startTime, $endTime, 'Buffs', "ability.id = {$offsetStatusId}");
    }

    /**
     * 指定ステータスの applybuff イベント（薬=Medicated 49 検出などに使用）。
     * ステータスIDはイベント上 +1000000 オフセットで返るため、サーバ側 ability.id フィルタは効かない。
     * type のみサーバ側で絞り、ステータスIDはPHP側で照合する。
     */
    public function getBuffApplies($code, $fightId, $startTime, $endTime, $abilityId): array
    {
        $all = $this->fetchPagedData($code, $startTime, $endTime, 'Buffs', "type = 'applybuff'");
        return array_values(array_filter($all, function ($e) use ($abilityId) {
            $a = (int) ($e['abilityGameID'] ?? 0);
            if ($a > 1000000) {
                $a %= 1000000;
            }
            return $a === (int) $abilityId;
        }));
    }

    public function getMasterData($code): ?array
    {
        $query = <<<'GQL'
query($code: String!) {
    reportData {
        report(code: $code) {
            masterData {
                actors { id name type subType petOwner }
                abilities { gameID name icon }
            }
        }
    }
}
GQL;
        $response = Http::withToken($this->getToken())->post($this->apiUrl, [
            'query' => $query,
            'variables' => ['code' => $code],
        ]);
        return $response->json()['data']['reportData']['report']['masterData'] ?? null;
    }

    private function fetchPagedData($code, $startTime, $endTime, $dataType, $filterExpression, $includeResources = false, $hostilityType = null): array
    {
        $allData = [];
        $nextTimestamp = $startTime;

        // Safety Break
        $loops = 0;

        // includeResources:true のときは座標・HP（targetResources / sourceResources）を併せて取得する
        $resourcesArg = $includeResources ? ', includeResources: true' : '';
        // filterExpression と hostilityType は任意。Casts の敵抽出は filterExpression(source.disposition) が
        // 効かないため hostilityType: Enemies を使う必要がある。
        $filterArg = ($filterExpression !== null && $filterExpression !== '') ? ', filterExpression: "' . $filterExpression . '"' : '';
        $hostilityArg = $hostilityType ? ', hostilityType: ' . $hostilityType : '';
        // dataType は任意。null のときは省略して全種別から取得（headmarker 等の取得に使う）。
        $dataTypeArg = $dataType ? ('dataType: ' . $dataType . ', ') : '';

        while ($nextTimestamp !== null && $loops < 20) { // Limit 20 pages max to prevent infinite loops
            $loops++;

            $query = <<<'GQL'
query($code: String!, $startTime: Float!, $endTime: Float!) {
    reportData {
        report(code: $code) {
            events(%sstartTime: $startTime, endTime: $endTime, translate: true%s%s%s) {
                data
                nextPageTimestamp
            }
        }
    }
}
GQL;
            // Inject Enum type and args directly (safe chars)
            $query = sprintf($query, $dataTypeArg, $filterArg, $hostilityArg, $resourcesArg);

            $response = Http::withToken($this->getToken())->post($this->apiUrl, [
                'query' => $query,
                'variables' => [
                    'code' => $code,
                    'startTime' => (float) $nextTimestamp,
                    'endTime' => (float) $endTime,
                ],
            ]);

            $json = $response->json();

            if (isset($json['errors'])) {
                Log::error("FFLogs API Error ($dataType): " . json_encode($json['errors']));
            }

            $events = $json['data']['reportData']['report']['events'] ?? null;

            if (!$events) {
                break;
            }

            if (!empty($events['data'])) {
                $allData = array_merge($allData, $events['data']);
            }

            $nextTimestamp = $events['nextPageTimestamp'];
        }

        return $allData;
    }

    /* ===================== PT構成検索（ランキング横断） ===================== */

    /**
     * キャッシュ書き込みは失敗しても処理を止めない。
     *
     * storage/framework/cache/data 配下に root 所有のディレクトリが混ざっていると
     * （artisan を root で叩くと起きる）www-data 側から書けずに例外になる。
     * キャッシュはあくまで高速化のためのものなので、書けなくてもログだけ残して結果は返す。
     */
    private function cachePut($key, $value, $ttl): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (\Throwable $e) {
            Log::warning("Cache write failed ({$key}): " . $e->getMessage());
        }
    }

    /** Cache::remember と同じだが、書き込みに失敗しても値をそのまま返す。 */
    private function cacheRemember($key, $ttl, callable $callback): mixed
    {
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $callback();
        $this->cachePut($key, $value, $ttl);
        return $value;
    }

    /**
     * 拡張パッケージ → ゾーン → エンカウンターの一覧。PT構成検索のボス選択に使う。
     * ゲームデータ寄りでほぼ変化しないので長期キャッシュする。
     */
    public function getZoneTree(): array
    {
        return $this->cacheRemember('fflogs_zone_tree', 60 * 60 * 24 * 7, function () {
            $query = <<<'GQL'
query {
    worldData {
        expansions { id name zones { id name encounters { id name } } }
    }
}
GQL;
            $response = Http::withToken($this->getToken())->post($this->apiUrl, ['query' => $query]);
            $expansions = $response->json()['data']['worldData']['expansions'] ?? [];

            // エンカウンターが無いゾーンは選んでも意味が無いので落とす。新しい拡張が上に来るよう反転。
            $out = [];
            foreach (array_reverse($expansions) as $exp) {
                $zones = [];
                foreach ($exp['zones'] ?? [] as $zone) {
                    if (!empty($zone['encounters'])) {
                        $zones[] = $zone;
                    }
                }
                if ($zones) {
                    $out[] = ['id' => $exp['id'], 'name' => $exp['name'], 'zones' => $zones];
                }
            }
            return $out;
        });
    }

    /**
     * エンカウンターのランキング（fightRankings）を全ページ集めて1本の配列で返す。
     *
     * fightRankings が返すのは 1件 = 1討伐ログ で、
     * {server, duration, startTime, report:{code,fightID,startTime}, damageTaken, deaths,
     *  tanks, healers, melee, ranged, casters, guild, bracketData, size}。
     * **ジョブ名は入っていない**（ロール人数だけ）。ジョブは getFightJobs() で別途取る。
     *
     * ランキングは上位500件（50件×10ページ）で打ち止めになる＝検索できるのはその範囲だけ。
     */
    public function getFightRankingPool($encounterId, $metric = 'speed', $maxPages = 10): array
    {
        $encounterId = (int) $encounterId;
        // metric は GraphQL の enum なのでクエリ文字列に直接埋める。値はホワイトリストで固定する。
        $allowed = ['speed', 'execution', 'progress', 'default'];
        if (!in_array($metric, $allowed, true)) {
            $metric = 'speed';
        }

        $cacheKey = "fflogs_fight_ranking_pool_{$encounterId}_{$metric}_{$maxPages}";

        return $this->cacheRemember($cacheKey, 60 * 60, function () use ($encounterId, $metric, $maxPages) {
            $query = <<<GQL
query(\$e: Int!, \$p: Int!) {
    worldData { encounter(id: \$e) { name fightRankings(page: \$p, metric: {$metric}) } }
}
GQL;
            $rankings = [];
            $name = null;
            for ($page = 1; $page <= $maxPages; $page++) {
                $response = Http::withToken($this->getToken())->post($this->apiUrl, [
                    'query' => $query,
                    'variables' => ['e' => $encounterId, 'p' => $page],
                ]);
                $json = $response->json();
                if (isset($json['errors'])) {
                    Log::error('FFLogs fightRankings error: ' . json_encode($json['errors']));
                    break;
                }
                $enc = $json['data']['worldData']['encounter'] ?? null;
                if (!$enc) {
                    break;
                }
                $name = $name ?? ($enc['name'] ?? null);

                $fr = $enc['fightRankings'] ?? null;
                if (!is_array($fr) || empty($fr['rankings'])) {
                    break;
                }

                foreach ($fr['rankings'] as $i => $r) {
                    // ページを跨いだ通し順位。ランキング側に順位フィールドが無いので自前で振る。
                    $r['rank'] = ($page - 1) * 50 + $i + 1;
                    $rankings[] = $r;
                }

                if (empty($fr['hasMorePages'])) {
                    break;
                }
            }

            return ['encounterName' => $name, 'rankings' => $rankings];
        });
    }

    /**
     * 各ログの実際のジョブ構成を取得する。
     *
     * fightRankings にジョブが入っていないため、report.playerDetails を引く必要がある。
     * 1リクエストにGraphQLエイリアスで複数レポートを詰めて回数を抑え、
     * 結果は (レポートコード, fightID) 単位で長期キャッシュする（過去ログは変わらないため）。
     *
     * @param  array $fights [['code' => string, 'fightID' => int], ...]
     * @return array         ['<code>:<fightID>' => ['Paladin', 'Warrior', ...]]
     */
    public function getFightJobs(array $fights): array
    {
        $result = [];
        $misses = [];

        foreach ($fights as $f) {
            $code = (string) ($f['code'] ?? '');
            $fid  = (int) ($f['fightID'] ?? 0);
            // レポートコードはクエリ文字列に直接埋めるので、英数字以外が来たら捨てる。
            if ($code === '' || !preg_match('/^[A-Za-z0-9]+$/', $code) || $fid <= 0) {
                continue;
            }
            $key = "{$code}:{$fid}";
            if (isset($result[$key]) || isset($misses[$key])) {
                continue;
            }

            try {
                $cached = Cache::get("fflogs_fight_jobs_{$key}");
            } catch (\Throwable $e) {
                $cached = null;
            }
            if (is_array($cached)) {
                $result[$key] = $cached;
            } else {
                $misses[$key] = ['code' => $code, 'fightID' => $fid];
            }
        }

        // 1リクエストにレポート25件ぶんのエイリアスを詰め、そのリクエスト自体も並列に投げる。
        // 逐次だと未キャッシュのボス1体で20往復＝約30秒かかっていた。
        $batches = [];
        foreach (array_chunk($misses, 25, true) as $chunk) {
            $aliases = [];
            $fields = '';
            $i = 0;
            foreach ($chunk as $key => $f) {
                $alias = 'f' . $i++;
                $aliases[$alias] = $key;
                $fields .= "{$alias}: report(code: \"{$f['code']}\") { playerDetails(fightIDs: [{$f['fightID']}]) }\n";
            }
            $batches[] = ['aliases' => $aliases, 'query' => "query { reportData { {$fields} } }"];
        }

        $responses = [];
        foreach (array_chunk($batches, self::PLAYER_DETAILS_WAVE, true) as $wave) {
            $token = $this->getToken();

            try {
                $responses += Http::pool(function (Pool $pool) use ($wave, $token) {
                    $calls = [];
                    foreach ($wave as $bi => $batch) {
                        $calls[] = $pool->as("b{$bi}")
                            ->withToken($token)
                            ->post($this->apiUrl, ['query' => $batch['query']]);
                    }

                    return $calls;
                });
            } catch (\Throwable $e) {
                Log::error('FFLogs playerDetails pool failed: ' . $e->getMessage());
            }
        }

        foreach ($batches as $bi => $batch) {
            $aliases = $batch['aliases'];
            $response = $responses['b' . $bi] ?? null;

            if (!$response || $response instanceof \Throwable) {
                Log::warning("FFLogs playerDetails batch {$bi} failed");
                continue;
            }

            $json = $response->json();
            if (isset($json['errors'])) {
                Log::error('FFLogs playerDetails error: ' . json_encode($json['errors']));
            }
            $data = $json['data']['reportData'] ?? [];

            foreach ($aliases as $alias => $key) {
                $pd = $data[$alias]['playerDetails']['data']['playerDetails'] ?? null;
                if (!is_array($pd)) {
                    continue;
                }

                $jobs = [];
                foreach (['tanks', 'healers', 'dps'] as $group) {
                    foreach ($pd[$group] ?? [] as $player) {
                        if (!empty($player['type'])) {
                            $jobs[] = $player['type'];
                        }
                    }
                }
                // 8人揃っていないものはPT構成として照合できないので捨てる（キャッシュもしない）。
                if (count($jobs) !== 8) {
                    continue;
                }

                $result[$key] = $jobs;
                $this->cachePut("fflogs_fight_jobs_{$key}", $jobs, 60 * 60 * 24 * 30);
            }
        }

        return $result;
    }

    /**
     * そのボスの「討伐ログ + 8人のジョブ構成」をできるだけ広く集めたプール。
     *
     * 主力は characterRankings(includeOtherPlayers: true)。
     * これは1件が「プレイヤー1人の parse」だが allCharacters に**同じ戦闘の8人全員のジョブが入る**ため、
     * playerDetails を1件も引かずに構成が揃う。fightRankings が上位500件で頭打ちなのに対し、
     * こちらは metric ごとに約15,000 parse ぶん辿れる（絶妖星乱舞で実測 dps:5,431 + hps:+2,019 = 7,450 戦闘）。
     *
     * metric を dps と hps の2本にしているのは、両者で載るプレイヤーが違い母集団がズレるため
     * （hps 側だけに居る戦闘が実測で約2,000件あった）。
     * healercombineddps 等を足すとさらに数%増えるが伸びは鈍く、時間に見合わないので採っていない。
     *
     * 最後に fightRankings(speed) を重ねて、上位ランキングに載っている戦闘には
     * 順位・死亡数・被ダメージを付ける（10リクエストで済むので取っている）。
     *
     * @return array ['encounterName' => string|null, 'fights' => ['<code>:<fightID>' => [...]]]
     */
    /**
     * 討伐ログのプールがキャッシュ済みか。取得はしない（画面をブロックしてよいかの判定用）。
     */
    public function hasClearPool($encounterId): bool
    {
        try {
            return Cache::get('fflogs_clear_pool_' . (int) $encounterId) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * プールを構築してキャッシュに載せる。未キャッシュのボスでは数十秒〜数分かかるため、
     * リクエストの応答を返したあと（terminate 中）やコマンドから呼ぶことを想定している。
     *
     * 同じボスを同時に温めても無駄なので、ロックを取れたプロセスだけが実行する。
     *
     * @return bool 実際に取得したら true、他プロセスが取得中なら false
     */
    public function warmClearPool($encounterId): bool
    {
        $encounterId = (int) $encounterId;
        $lock = Cache::lock("fflogs_clear_pool_warm_{$encounterId}", 900);

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->getClearPool($encounterId);
        } finally {
            $lock->release();
        }

        return true;
    }

    public function getClearPool($encounterId, $maxPages = 200): array
    {
        $encounterId = (int) $encounterId;

        return $this->cacheRemember("fflogs_clear_pool_{$encounterId}", 60 * 60 * 6, function () use ($encounterId, $maxPages) {
            $fights = [];
            $encounterName = null;

            foreach (['dps', 'hps'] as $metric) {
                for ($page = 1; $page <= $maxPages; $page += self::RANKING_WAVE) {
                    $pages = range($page, min($page + self::RANKING_WAVE - 1, $maxPages));
                    $wave = $this->fetchCharacterRankingWave($encounterId, $metric, $pages);

                    $encounterName = $encounterName ?? $wave['encounterName'];
                    foreach ($wave['fights'] as $key => $fight) {
                        // 先に入ったほう（dps側）を優先。同じ戦闘なら中身は同じなので上書きしない。
                        $fights[$key] = $fights[$key] ?? $fight;
                    }

                    // 空ページに当たった＝そのmetricは終わり
                    if ($wave['empty']) {
                        break;
                    }
                }
            }

            $this->annotateWithFightRankings($encounterId, $fights);

            return ['encounterName' => $encounterName, 'fights' => $fights];
        });
    }

    /** characterRankings の複数ページを並列で取る（逐次だと1ボスぶんで3分かかるため）。 */
    private function fetchCharacterRankingWave($encounterId, $metric, array $pages): array
    {
        $token = $this->getToken();
        $query = <<<GQL
query(\$e: Int!, \$p: Int!) {
    worldData {
        encounter(id: \$e) {
            name
            characterRankings(page: \$p, includeOtherPlayers: true, metric: {$metric})
        }
    }
}
GQL;

        try {
            $responses = Http::pool(function (Pool $pool) use ($pages, $token, $query, $encounterId) {
                return array_map(fn($p) => $pool->as("p{$p}")->withToken($token)->post($this->apiUrl, [
                    'query' => $query,
                    'variables' => ['e' => $encounterId, 'p' => $p],
                ]), $pages);
            });
        } catch (\Throwable $e) {
            Log::error('FFLogs characterRankings pool failed: ' . $e->getMessage());
            return ['fights' => [], 'encounterName' => null, 'empty' => true];
        }

        $fights = [];
        $encounterName = null;
        $empty = false;

        foreach ($pages as $p) {
            $response = $responses["p{$p}"] ?? null;
            // 通信失敗は「ランキングの終端」ではない。ここで $empty を立てると
            // 呼び出し側がページ走査を打ち切り、プールが静かに欠損する。
            if (!$response || $response instanceof \Throwable) {
                Log::warning("FFLogs characterRankings page {$p} failed (encounter {$encounterId}, {$metric})");
                continue;
            }

            $encounter = $response->json()['data']['worldData']['encounter'] ?? null;
            $encounterName = $encounterName ?? ($encounter['name'] ?? null);

            $rankings = $encounter['characterRankings']['rankings'] ?? null;
            if (empty($rankings)) {
                $empty = true;
                continue;
            }

            foreach ($rankings as $r) {
                $code = $r['report']['code'] ?? null;
                $fightId = $r['report']['fightID'] ?? null;
                // allCharacters が無い＝同席者を出せないログ。構成が組めないので捨てる。
                $members = $r['allCharacters'] ?? null;
                if (!$code || $fightId === null || empty($members)) {
                    continue;
                }

                $comp = [];
                foreach ($members as $m) {
                    if (!empty($m['spec'])) {
                        $comp[] = $m['spec'];
                    }
                }
                if (count($comp) !== 8) {
                    continue;
                }

                $fights["{$code}:{$fightId}"] = [
                    'code'        => $code,
                    'fightID'     => (int) $fightId,
                    'comp'        => $comp,
                    'duration'    => $r['duration'] ?? 0,
                    'startTime'   => $r['startTime'] ?? null,
                    'server'      => $r['server'] ?? null,
                    'guild'       => $r['guild'] ?? null,
                    'rank'        => null,
                    'deaths'      => null,
                    'damageTaken' => null,
                ];
            }
        }

        return ['fights' => $fights, 'encounterName' => $encounterName, 'empty' => $empty];
    }

    /**
     * 速度ランキング上位500件の情報（順位・死亡数・被ダメ）をプールに重ねる。
     * characterRankings 側に出てこなかった戦闘は構成が無いので、そのぶんだけ playerDetails で補う。
     */
    private function annotateWithFightRankings($encounterId, array &$fights): void
    {
        $pool = $this->getFightRankingPool($encounterId, 'speed');
        $missing = [];

        foreach ($pool['rankings'] as $r) {
            $code = $r['report']['code'] ?? null;
            $fightId = $r['report']['fightID'] ?? null;
            if (!$code || $fightId === null) {
                continue;
            }
            $key = "{$code}:{$fightId}";

            if (isset($fights[$key])) {
                $fights[$key]['rank']        = $r['rank'] ?? null;
                $fights[$key]['deaths']      = $r['deaths'] ?? null;
                $fights[$key]['damageTaken'] = $r['damageTaken'] ?? null;
                continue;
            }

            $fights[$key] = [
                'code'        => $code,
                'fightID'     => (int) $fightId,
                'comp'        => null, // このあと playerDetails で埋める
                'duration'    => $r['duration'] ?? 0,
                'startTime'   => $r['startTime'] ?? null,
                'server'      => $r['server'] ?? null,
                'guild'       => $r['guild'] ?? null,
                'rank'        => $r['rank'] ?? null,
                'deaths'      => $r['deaths'] ?? null,
                'damageTaken' => $r['damageTaken'] ?? null,
            ];
            $missing[] = ['code' => $code, 'fightID' => (int) $fightId];
        }

        if (empty($missing)) {
            return;
        }

        $jobs = $this->getFightJobs($missing);
        foreach ($missing as $m) {
            $key = "{$m['code']}:{$m['fightID']}";
            if (isset($jobs[$key])) {
                $fights[$key]['comp'] = $jobs[$key];
            } else {
                unset($fights[$key]); // 構成が分からない戦闘は照合できないので落とす
            }
        }
    }
}
