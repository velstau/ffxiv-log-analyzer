<?php

namespace Tests\Feature;

use App\Services\FFLogsService;
use App\Support\FFLogsCredentialStore;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 軽減タイムラインの失敗経路。
 *
 * 正常系は実際のログで確認できるが、失敗系は API が壊れないと再現しない。
 * ここは組み立てを担うサービスが失敗を投げたときに、画面がエラー表示に落ちることを押さえる。
 */
class AnalyzeErrorHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 解析は API キーが無いと設定画面へ案内される。ここでは失敗経路だけを見たいので、キーは設定済みにする
        $this->withCookie(FFLogsCredentialStore::COOKIE, json_encode(['id' => 'test-id', 'secret' => 'test-secret']));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_shows_an_error_when_the_url_cannot_be_parsed(): void
    {
        $this->from('/mitigation')
            ->post('/analyze', ['url' => 'https://example.invalid/not-a-report'])
            ->assertRedirect('/mitigation')
            ->assertSessionHas('error');
    }

    #[Test]
    public function it_shows_an_error_when_the_fight_is_not_accessible(): void
    {
        $this->mock(FFLogsService::class, function ($mock) {
            $mock->shouldReceive('parseUrl')->andReturn(['code' => 'AbCd1234', 'fightId' => '11']);
            $mock->shouldReceive('getFightDetails')->andReturn(null);
        });

        $this->from('/mitigation')
            ->post('/analyze', ['url' => 'https://ja.fflogs.com/reports/AbCd1234?fight=11'])
            ->assertRedirect('/mitigation')
            ->assertSessionHas('error', 'Fight not found or access denied.');
    }

    #[Test]
    public function it_shows_the_api_error_instead_of_crashing(): void
    {
        // FFLogs が GraphQL エラーを返したケース。
        // 組み立て側は配列を返す契約なので、ここは例外で抜けてコントローラが受け止める。
        $this->mock(FFLogsService::class, function ($mock) {
            $mock->shouldReceive('parseUrl')->andReturn(['code' => 'AbCd1234', 'fightId' => '11']);
            $mock->shouldReceive('getFightDetails')->andReturn([
                'id' => 11, 'name' => 'Test', 'startTime' => 0, 'endTime' => 1000,
            ]);
            $mock->shouldReceive('getEvents')->andReturn([
                'errors' => [['message' => 'You do not have permission to view this report.']],
            ]);
        });

        $response = $this->from('/mitigation')
            ->post('/analyze', ['url' => 'https://ja.fflogs.com/reports/AbCd1234?fight=11']);

        $response->assertRedirect('/mitigation')->assertSessionHas('error');
        $this->assertStringContainsString(
            'do not have permission',
            (string) session('error'),
            'APIが返した理由がそのまま画面に出ること',
        );
    }
}
