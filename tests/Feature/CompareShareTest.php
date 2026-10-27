<?php

namespace Tests\Feature;

use App\Services\FFLogsService;
use App\Services\ReportComparer;
use App\Support\FFLogsCredentialStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\UiData;
use Tests\TestCase;

class CompareShareTest extends TestCase
{
    private const REAL_NAME = 'Fixture Player with a long name';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function credentials(): static
    {
        return $this->withCookie(FFLogsCredentialStore::COOKIE, json_encode(['id' => 'test-id', 'secret' => 'test-secret']));
    }

    /** FFLogs への問い合わせを除き、匿名化・アドバイスは本物の ReportComparer で動かす */
    private function fakeComparison(): void
    {
        $data = UiData::compare();
        $this->mock(FFLogsService::class, fn($mock) => $mock->shouldReceive('parseUrl')->andReturn(['code' => 'FixtureA', 'fightId' => 1], ['code' => 'FixtureB', 'fightId' => 1]));
        $this->partialMock(ReportComparer::class, fn($mock) => $mock->shouldReceive('buildCompareSide')->andReturn(
            $data['A'] + ['roster' => [self::REAL_NAME => 'PLD']],
            $data['B'] + ['roster' => [self::REAL_NAME => 'PLD']],
        ));
    }

    #[Test]
    public function a_comparison_is_kept_for_reload_and_can_be_shared_without_report_codes(): void
    {
        $this->fakeComparison();
        $response = $this->credentials()->post('/compare', ['url_a' => 'a', 'url_b' => 'b'])->assertStatus(303);
        $resultUrl = $response->headers->get('Location');
        $this->get($resultUrl)->assertOk()->assertSee('結果を一時保存中（2時間）')->assertSee('URLで共有')->assertSee(self::REAL_NAME);

        $share = $this->postJson(route('timeline-shares.store'), ['draft' => basename($resultUrl)])->assertOk()->json();
        $plaintext = Crypt::decryptString(Storage::disk('local')->get(Storage::disk('local')->files('timeline-shares')[0]));
        $this->assertStringContainsString('"kind":"compare"', $plaintext);
        foreach (['FixtureA', 'FixtureB', 'test-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $plaintext);
        }

        // 閲覧者は API キーもセッションもないので、範囲の個人別再集計は出さない
        $this->flushSession();
        // この比較は 10 秒区間の取り置きを取れていない（FFLogs の偽物が答えない）ので、範囲は概算だけ
        $this->get($share['url'])->assertOk()->assertSee('共有結果（APIキー不要）')->assertSee('const RANGE_MODE = "none"', false);
        $this->getJson($share['url'] . '/range?phase=1&t0=0&t1=20')->assertStatus(409);
        Http::assertNothingSent();
    }

    /**
     * 共有の範囲再集計の材料。各 10 秒区間に、総ダメージが区間ごとに増える 1 人だけを置く。
     * totalTime 10 秒・combatTime 9 秒（窓ごとに 1 秒引かれる）・activeTime 8 秒。
     */
    private function fakeBuckets(): void
    {
        foreach (['FixtureA', 'FixtureB'] as $code) {
            Cache::put("fflogs:fight:{$code}:1", ['startTime' => 0, 'endTime' => 200000, 'name' => 'Test boss', 'gameZone' => ['name' => 'Test zone'], 'encounterID' => 0], 600);
            Cache::put("fflogs:losscontext:{$code}:1", ['potionByPlayer' => [], 'potionIconByPlayer' => [], 'deathsByPlayer' => [], 'dmgDebuffSegs' => [], 'ddIconByPlayer' => [], 'deadSegs' => []], 600);
            Cache::put("fflogs:roster:{$code}:1", [self::REAL_NAME => 'PLD'], 600);
        }
        $data = UiData::compare();
        $this->mock(FFLogsService::class, function ($mock) {
            $mock->shouldReceive('parseUrl')->andReturn(['code' => 'FixtureA', 'fightId' => 1], ['code' => 'FixtureB', 'fightId' => 1]);
            $mock->shouldReceive('getDamageTables')->andReturnUsing(fn($code, $fightId, array $windows) => array_map(fn($i) => [
                'totalTime' => 10000, 'combatTime' => 9000, 'damageDowntime' => 0,
                'entries' => [['name' => self::REAL_NAME, 'type' => 'Paladin', 'total' => 100000 * ($i + 1), 'totalRDPS' => 100000 * ($i + 1), 'totalADPS' => 100000 * ($i + 1), 'activeTime' => 8000]],
            ], array_keys($windows)));
        });
        // 取り置きは本物の ReportComparer で作る（partialMock はコンストラクタを通らず FFLogsService が入らない）
        $comparer = \Mockery::mock(ReportComparer::class, [app(FFLogsService::class)])->makePartial();
        $comparer->shouldReceive('buildCompareSide')->andReturn(
            $data['A'] + ['roster' => [self::REAL_NAME => 'PLD']],
            $data['B'] + ['roster' => [self::REAL_NAME => 'PLD']],
        );
        $this->instance(ReportComparer::class, $comparer);
    }

    #[Test]
    public function shared_ranges_are_recomputed_from_kept_buckets_without_a_viewer_key(): void
    {
        $this->fakeBuckets();
        $resultUrl = $this->credentials()->post('/compare', ['url_a' => 'a', 'url_b' => 'b'])->assertStatus(303)->headers->get('Location');
        $share = $this->credentials()->postJson(route('timeline-shares.store'), ['draft' => basename($resultUrl)])->assertOk()->json();
        $plaintext = Crypt::decryptString(Storage::disk('local')->get(Storage::disk('local')->files('timeline-shares')[0]));
        $this->assertStringContainsString('rangeIndex', $plaintext);
        foreach (['FixtureA', 'FixtureB', 'test-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $plaintext);
        }

        // 閲覧者はキーもセッションもない
        $this->flushSession();
        $this->get($share['url'])->assertOk()->assertSee('const RANGE_MODE = "shared"', false);
        // 0〜20 秒＝最初の 2 区間：(10万+20万) ÷ (20 秒 − 1 秒)
        $first = $this->getJson($share['url'] . '/range?phase=1&t0=0&t1=20')->assertOk()->json();
        $this->assertEqualsWithDelta(300000 / 19, $first['a']['partyRdps'], 0.01);
        $this->assertSame([0, 20], array_map('intval', $first['a']['win']));
        // 稼働時間は境目 1 つぶん足し戻す：フェーズ 90 秒の 99% − 区間の和 72 秒 を 8 境目で割った値
        $fix = round((0.99 * 90000 - 72000) / 8);
        $this->assertEqualsWithDelta((16000 + $fix) / 20000 * 100, $first['a']['players'][0]['activePct'], 0.01);
        $this->assertStringContainsString(self::REAL_NAME, $first['html']);

        // 範囲は 10 秒単位に広げて丸める（5〜25 秒 → 0〜30 秒）。範囲が違えば値も違う
        $wide = $this->getJson($share['url'] . '/range?phase=1&t0=5&t1=25')->assertOk()->json();
        $this->assertSame([0, 30], array_map('intval', $wide['a']['win']));
        $this->assertEqualsWithDelta(600000 / 29, $wide['a']['partyRdps'], 0.01);

        $this->getJson($share['url'] . '/range?phase=9&t0=0&t1=20')->assertStatus(422);
        $this->getJson($share['url'] . '/range?phase=1&t0=20&t1=20')->assertStatus(422);
        Http::assertNothingSent();
    }

    #[Test]
    public function shared_ranges_keep_names_hidden(): void
    {
        $this->fakeBuckets();
        $resultUrl = $this->credentials()->post('/compare', ['url_a' => 'a', 'url_b' => 'b', 'hide_names' => '1'])->assertStatus(303)->headers->get('Location');
        $share = $this->credentials()->postJson(route('timeline-shares.store'), ['draft' => basename($resultUrl)])->assertOk()->json();
        $plaintext = Crypt::decryptString(Storage::disk('local')->get(Storage::disk('local')->files('timeline-shares')[0]));
        $this->assertStringContainsString('rangeIndex', $plaintext);
        $this->assertStringNotContainsString(self::REAL_NAME, $plaintext);

        $this->flushSession();
        $range = $this->getJson($share['url'] . '/range?phase=1&t0=0&t1=20')->assertOk()->assertDontSee(self::REAL_NAME);
        $this->assertSame('PLD(A)', $range->json('a.players.0.name'));
        $this->assertSame('PLD(B)', $range->json('b.players.0.name'));
    }

    #[Test]
    public function hidden_names_never_reach_the_page_or_the_share(): void
    {
        $this->fakeComparison();
        $response = $this->credentials()->post('/compare', ['url_a' => 'a', 'url_b' => 'b', 'hide_names' => '1'])->assertStatus(303);
        $resultUrl = $response->headers->get('Location');
        $this->get($resultUrl)->assertOk()->assertSee('PLD(A)')->assertSee('PLD(B)')->assertSee('プレイヤー名を非表示')->assertDontSee(self::REAL_NAME);

        $this->postJson(route('timeline-shares.store'), ['draft' => basename($resultUrl)])->assertOk();
        $plaintext = Crypt::decryptString(Storage::disk('local')->get(Storage::disk('local')->files('timeline-shares')[0]));
        $this->assertStringNotContainsString(self::REAL_NAME, $plaintext);
    }

    #[Test]
    public function aliases_are_stable_across_windows_and_cover_every_place_a_name_appears(): void
    {
        $comparer = $this->partialMock(ReportComparer::class);
        $roster = ['Zed Healer' => 'SCH', 'Amy Healer' => 'SCH', 'Tank One' => 'PLD'];
        $p = fn($name, $job, $rdps) => ['name' => $name, 'job' => $job, 'role' => 'healer', 'rdps' => $rdps];

        // rDPS の並びが違っても、同じ人は同じ番号（番号は実名順）
        $phase1 = $comparer->anonymize(['players' => [$p('Zed Healer', 'SCH', 9000), $p('Amy Healer', 'SCH', 8000)]], $roster, 'A');
        $phase2 = $comparer->anonymize(['players' => [$p('Amy Healer', 'SCH', 9000), $p('Zed Healer', 'SCH', 8000)]], $roster, 'A');
        $this->assertSame(['SCH(A2)', 'SCH(A1)'], array_column($phase1['players'], 'name'));
        $this->assertSame(['SCH(A1)', 'SCH(A2)'], array_column($phase2['players'], 'name'));

        // 一覧以外（クローズドポジションの付与先）も置き換え、表にない人も伏せる
        $other = $comparer->anonymize(['closedPos' => [['name' => 'Tank One', 'src' => 'Amy Healer', 'pct' => 90]], 'players' => [$p('Newcomer', 'WHM', 1)]], $roster, 'B');
        $this->assertSame('PLD(B)', $other['closedPos'][0]['name']);
        $this->assertSame('SCH(B1)', $other['closedPos'][0]['src']);
        $this->assertSame('WHM(B)', $other['players'][0]['name']);
        $this->assertStringNotContainsString('Tank One', json_encode($other));
    }

    #[Test]
    public function advice_uses_the_alias_without_repeating_the_job(): void
    {
        $comparer = $this->partialMock(ReportComparer::class);
        $side = fn($rdps) => ['partyRdps' => $rdps, 'players' => [['name' => 'Real Name', 'job' => 'SCH', 'role' => 'healer', 'rdps' => $rdps]]];
        $a = $comparer->anonymize($side(10000), ['Real Name' => 'SCH'], 'A');
        $b = $comparer->anonymize($side(20000), ['Real Name' => 'SCH'], 'B');
        $tips = implode("\n", $comparer->buildPhaseAdvice($a, $b)['tips']);
        $this->assertStringContainsString('SCH(A)が相手より', $tips);
        $this->assertStringNotContainsString('Real Name', $tips);
    }

    #[Test]
    public function a_failed_roster_cache_write_does_not_break_the_comparison(): void
    {
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('remember')->andReturn(['friendlyPlayers' => [1]]);
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('disk full'));
        $this->mock(FFLogsService::class, fn($mock) => $mock->shouldReceive('getMasterData')->andReturn(['actors' => [['id' => 1, 'name' => 'Real Name', 'type' => 'Player', 'subType' => 'Scholar']]]));
        $this->assertSame(['Real Name'], array_keys(app(ReportComparer::class)->roster('Code', 1)));
    }

    #[Test]
    public function range_stats_return_the_same_aliases_when_names_are_hidden(): void
    {
        $summary = UiData::compare()['A']['overall'];
        $summary['players'][0]['job'] = 'PLD';
        $this->partialMock(ReportComparer::class, function ($mock) use ($summary) {
            $mock->shouldReceive('summarizeWindow')->andReturn($summary);
            $mock->shouldReceive('roster')->andReturn([self::REAL_NAME => 'PLD']);
        });
        $query = ['a' => ['code' => 'FixtureA', 'fight' => 1, 'start' => 0, 'end' => 5000]];
        $this->credentials()->withCredentials()->getJson('/compare/range?' . http_build_query($query + ['anon' => 1]))
            ->assertOk()->assertJsonPath('a.players.0.name', 'PLD(A)')->assertDontSee(self::REAL_NAME);
        $this->credentials()->withCredentials()->getJson('/compare/range?' . http_build_query($query))
            ->assertOk()->assertJsonPath('a.players.0.name', self::REAL_NAME);
    }
}
