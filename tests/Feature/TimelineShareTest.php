<?php

namespace Tests\Feature;

use App\Services\FFLogsService;
use App\Services\TimelineBuilder;
use App\Services\TimelineShareStore;
use App\Support\FFLogsCredentialStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\UiData;
use Tests\TestCase;

class TimelineShareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function publish(string $owner = 'creator'): array
    {
        $draft = app(TimelineShareStore::class)->draft(UiData::timeline(), $owner);
        return $this->withSession(['timeline_share_owner' => $owner])->postJson(route('timeline-shares.store'), ['draft' => $draft])->assertOk()->json();
    }

    #[Test]
    public function analyzed_results_can_be_published_without_storing_credentials(): void
    {
        $data = UiData::timeline();
        $this->mock(FFLogsService::class, function ($mock) use ($data) {
            $mock->shouldReceive('parseUrl')->once()->andReturn(['code' => 'Fixture', 'fightId' => 1]);
            $mock->shouldReceive('getFightDetails')->once()->andReturn($data['fight']);
        });
        $this->mock(TimelineBuilder::class, fn($mock) => $mock->shouldReceive('build')->once()->andReturn($data + ['client_secret' => 'never-store-this']));
        $analysis = $this->withCookie(FFLogsCredentialStore::COOKIE, json_encode(['id' => 'test-id', 'secret' => 'test-secret']))
            ->post('/analyze', ['url' => 'fixture'])->assertStatus(303);
        $resultUrl = $analysis->headers->get('Location');
        $this->get($resultUrl)->assertOk()->assertSee('URLで共有');
        $this->get($resultUrl)->assertOk()->assertSee('結果を一時保存中（2時間）');
        $share = $this->postJson(route('timeline-shares.store'), ['draft' => basename($resultUrl)])->assertOk()->json();
        $files = Storage::disk('local')->files('timeline-shares');
        $this->assertCount(1, $files);
        $encrypted = Storage::disk('local')->get($files[0]);
        $this->assertStringNotContainsString('Player 1', $encrypted);
        $plaintext = Crypt::decryptString($encrypted);
        foreach (['never-store-this', 'test-secret', 'test-id', 'client_secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $plaintext);
        }
        $this->assertStringContainsString('/shared/', $share['url']);
        $this->assertEquals(now()->addDays(30)->timestamp, strtotime($share['expires_at']));
    }

    #[Test]
    public function temporary_results_require_the_original_session_and_expire_without_api_calls(): void
    {
        $id = app(TimelineShareStore::class)->draft(UiData::timeline(), 'creator');
        $url = route('timeline-results.show', $id);
        $this->withSession(['timeline_share_owner' => 'creator'])->get($url)->assertOk()->assertSee('UI fixture');
        $this->withSession(['timeline_share_owner' => 'other'])->get($url)->assertStatus(410)->assertDontSee('UI fixture');
        $this->travel(121)->minutes();
        $this->withSession(['timeline_share_owner' => 'creator'])->get($url)->assertStatus(410)->assertSee('もう一度解析');
        Http::assertNothingSent();
    }

    #[Test]
    public function a_sharing_storage_failure_does_not_hide_the_analysis(): void
    {
        $data = UiData::timeline();
        $this->mock(FFLogsService::class, function ($mock) use ($data) {
            $mock->shouldReceive('parseUrl')->once()->andReturn(['code' => 'Fixture', 'fightId' => 1]);
            $mock->shouldReceive('getFightDetails')->once()->andReturn($data['fight']);
        });
        $this->mock(TimelineBuilder::class, fn($mock) => $mock->shouldReceive('build')->once()->andReturn($data));
        $this->mock(TimelineShareStore::class, function ($mock) {
            $mock->shouldReceive('draft')->once()->andThrow(new \RuntimeException('Unavailable'));
            $mock->shouldReceive('publish')->once()->andThrow(new \RuntimeException('Unavailable'));
        });
        $this->withCookie(FFLogsCredentialStore::COOKIE, json_encode(['id' => 'test-id', 'secret' => 'test-secret']))
            ->post('/analyze', ['url' => 'fixture'])->assertOk()->assertSee('UI fixture')->assertSee('共有の準備に失敗しました');
        $this->postJson(route('timeline-shares.store'), ['draft' => str_repeat('x', 48)])->assertStatus(503)->assertJsonPath('message', '共有結果を保存できませんでした。時間をおいて再度お試しください。');
    }

    #[Test]
    public function a_guest_can_open_the_result_without_api_or_owner_session(): void
    {
        $share = $this->publish();
        session()->forget('timeline_share_owner');
        Cache::flush(); // Shared results must survive ordinary analysis cache eviction.
        $this->get($share['url'])->assertOk()->assertSee('共有結果（APIキー不要）')
            ->assertSee('UI fixture')->assertSee('Player 1')->assertSee('XML出力')->assertDontSee('onclick="saveFullTimeline()"', false)
            ->assertDontSee('共有を停止する')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        Http::assertNothingSent();
    }

    #[Test]
    public function only_the_creator_can_publish_a_draft_or_stop_sharing(): void
    {
        $draft = app(TimelineShareStore::class)->draft(UiData::timeline(), 'creator');
        $this->withSession(['timeline_share_owner' => 'other'])->postJson(route('timeline-shares.store'), ['draft' => $draft])->assertStatus(410);
        $share = $this->publish();
        $id = basename($share['url']);
        $this->withSession(['timeline_share_owner' => 'other'])->post(route('timeline-shares.destroy', $id))->assertForbidden();
        $this->withSession(['timeline_share_owner' => 'creator'])->get($share['url'])->assertSee('共有を停止する');
        $this->post(route('timeline-shares.destroy', $id))->assertOk()->assertSee('共有を停止しました');
        $this->get($share['url'])->assertNotFound();
    }

    #[Test]
    public function expired_and_invalid_links_are_not_viewable(): void
    {
        $share = $this->publish();
        $this->travel(30)->days();
        $this->get($share['url'])->assertNotFound()->assertSee('有効期限');
        $this->assertEmpty(Storage::disk('local')->files('timeline-shares'));
        $this->get('/shared/' . str_repeat('x', 48))->assertNotFound();
        $this->get('/shared/invalid')->assertNotFound();
    }

    #[Test]
    public function a_draft_expires_after_two_hours_and_client_data_is_not_accepted(): void
    {
        $draft = app(TimelineShareStore::class)->draft(UiData::timeline(), 'creator');
        $this->travel(121)->minutes();
        $this->withSession(['timeline_share_owner' => 'creator'])->postJson(route('timeline-shares.store'), ['draft' => $draft])->assertStatus(410);
        $this->postJson(route('timeline-shares.store'), ['data' => UiData::timeline()])->assertUnprocessable();
        $this->assertEmpty(Storage::disk('local')->files('timeline-shares'));
    }

    #[Test]
    public function the_prune_command_removes_only_expired_results(): void
    {
        $first = $this->publish();
        $this->travel(20)->days();
        $second = $this->publish();
        $this->travel(11)->days();
        $this->artisan('timeline-shares:prune')->assertSuccessful();
        $this->get($first['url'])->assertNotFound();
        $this->get($second['url'])->assertOk();
        $this->assertCount(1, Storage::disk('local')->files('timeline-shares'));
    }
}
