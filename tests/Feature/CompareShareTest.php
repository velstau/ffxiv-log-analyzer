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
        $this->get($share['url'])->assertOk()->assertSee('共有結果（APIキー不要）')->assertSee('const CAN_REFETCH = false', false);
        Http::assertNothingSent();
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
