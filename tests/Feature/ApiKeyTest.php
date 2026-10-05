<?php

namespace Tests\Feature;

use App\Exceptions\FFLogsCredentialsInvalid;
use App\Exceptions\FFLogsCredentialsMissing;
use App\Http\Controllers\ApiKeyController;
use App\Services\FFLogsService;
use App\Support\FFLogsCredentialStore;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 利用者自身の FFLogs API キー。
 *
 * 公開版では運営者のキーを使わない。キーは利用者のブラウザの Cookie（暗号化・HttpOnly）に置き、
 * サーバーには保存しない。ここではその約束が崩れていないことを押さえる。
 */
class ApiKeyTest extends TestCase
{
    private const TOKEN_URL = 'https://ja.fflogs.com/oauth/token';

    private function credCookie(string $id = 'my-client-id', string $secret = 'my-client-secret'): string
    {
        return json_encode(['id' => $id, 'secret' => $secret]);
    }

    /** private な getToken() を呼ぶ（トークン取得の約束だけを確かめるため） */
    private function callGetToken(): string
    {
        $method = new ReflectionMethod(FFLogsService::class, 'getToken');

        return $method->invoke(app(FFLogsService::class));
    }

    #[Test]
    public function it_sends_the_user_to_the_settings_page_when_no_key_is_set(): void
    {
        $this->from('/mitigation')
            ->post('/analyze', ['url' => 'https://ja.fflogs.com/reports/AbCd1234?fight=11'])
            ->assertRedirect(route('api_key.edit'))
            ->assertSessionHas('error')
            ->assertSessionHas(ApiKeyController::RETURN_KEY, url('/mitigation'));
    }

    #[Test]
    public function ajax_requests_get_a_401_with_the_settings_url(): void
    {
        $this->getJson('/compare/range?a[code]=x')
            ->assertStatus(401)
            ->assertJsonPath('settings_url', route('api_key.edit'));
    }

    #[Test]
    public function the_server_key_is_not_used_unless_explicitly_allowed(): void
    {
        config([
            'services.fflogs.client_id' => 'owner-id',
            'services.fflogs.client_secret' => 'owner-secret',
            'services.fflogs.allow_server_credentials' => false,
        ]);
        $this->assertNull(app(FFLogsCredentialStore::class)->current(), '運営者のキーは既定では使わない');

        config(['services.fflogs.allow_server_credentials' => true]);
        $this->assertSame('server', app(FFLogsCredentialStore::class)->current()['source'], '開発用に許可したときだけ使う');
    }

    #[Test]
    public function the_users_key_takes_precedence_over_the_server_key(): void
    {
        config([
            'services.fflogs.client_id' => 'owner-id',
            'services.fflogs.client_secret' => 'owner-secret',
            'services.fflogs.allow_server_credentials' => true,
        ]);

        $this->withCookie(FFLogsCredentialStore::COOKIE, $this->credCookie())
            ->get('/api-key')
            ->assertOk()
            ->assertSee('設定済み')
            ->assertSee('…t-id', false)
            ->assertDontSee('my-client-secret');
    }

    #[Test]
    public function a_valid_key_is_saved_only_in_an_encrypted_httponly_cookie(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok'], 200)]);

        $response = $this->post('/api-key', ['client_id' => 'my-client-id', 'client_secret' => 'my-client-secret']);

        $response->assertRedirect(route('api_key.edit'))->assertSessionHas('status');
        $cookie = collect($response->headers->getCookies())->first(fn($c) => $c->getName() === FFLogsCredentialStore::COOKIE);
        $this->assertNotNull($cookie, 'Cookie に保存される');
        $this->assertTrue($cookie->isHttpOnly(), 'JavaScript から読めない');
        $this->assertSame(0, $cookie->getExpiresTime(), '「保存する」を選ばなければブラウザを閉じるまで');
        $this->assertStringNotContainsString('my-client-secret', (string) $cookie->getValue(), '暗号化されている');
        $this->assertStringNotContainsString('my-client-secret', json_encode(session()->all()), 'セッションに残らない');

        Http::assertSent(fn(HttpRequest $r) => $r->url() === self::TOKEN_URL && $r['client_id'] === 'my-client-id');
    }

    #[Test]
    public function remember_keeps_the_cookie_for_30_days(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok'], 200)]);

        $response = $this->post('/api-key', ['client_id' => 'id', 'client_secret' => 'secret', 'remember' => '1']);

        $cookie = collect($response->headers->getCookies())->first(fn($c) => $c->getName() === FFLogsCredentialStore::COOKIE);
        $this->assertGreaterThan(time() + 60 * 60 * 24 * 29, $cookie->getExpiresTime());
    }

    #[Test]
    public function a_rejected_key_is_not_saved_and_the_secret_is_not_kept(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

        $response = $this->from('/api-key')
            ->post('/api-key', ['client_id' => 'bad-id', 'client_secret' => 'bad-secret']);

        $response->assertRedirect('/api-key')->assertSessionHas('error');
        $this->assertNull(collect($response->headers->getCookies())->first(fn($c) => $c->getName() === FFLogsCredentialStore::COOKIE));
        $this->assertSame('bad-id', session()->getOldInput('client_id'));
        $this->assertNull(session()->getOldInput('client_secret'), 'シークレットは入力欄に戻さない');
    }

    #[Test]
    public function the_secret_is_not_flashed_when_validation_fails(): void
    {
        $this->from('/api-key')
            ->post('/api-key', ['client_id' => 'id', 'client_secret' => 'has space'])
            ->assertRedirect('/api-key')
            ->assertSessionHasErrors('client_secret');

        $this->assertNull(session()->getOldInput('client_secret'));
    }

    #[Test]
    public function after_saving_the_user_returns_to_the_page_they_came_from(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok'], 200)]);

        $this->withSession([ApiKeyController::RETURN_KEY => url('/compare')])
            ->post('/api-key', ['client_id' => 'id', 'client_secret' => 'secret'])
            ->assertRedirect(url('/compare'));
    }

    #[Test]
    public function the_return_url_must_stay_inside_this_app(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok'], 200)]);

        $this->withSession([ApiKeyController::RETURN_KEY => 'https://evil.example/'])
            ->post('/api-key', ['client_id' => 'id', 'client_secret' => 'secret'])
            ->assertRedirect(route('api_key.edit'));
    }

    #[Test]
    public function deleting_expires_the_cookie(): void
    {
        $response = $this->withCookie(FFLogsCredentialStore::COOKIE, $this->credCookie())->post('/api-key/delete');

        $response->assertRedirect(route('api_key.edit'));
        $cookie = collect($response->headers->getCookies())->first(fn($c) => $c->getName() === FFLogsCredentialStore::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertLessThan(time(), $cookie->getExpiresTime(), '期限切れの Cookie で上書きして消す');
    }

    #[Test]
    public function tokens_are_cached_per_key(): void
    {
        Http::fake([self::TOKEN_URL => Http::sequence()
            ->push(['access_token' => 'token-a'])
            ->push(['access_token' => 'token-b'])]);

        $this->withCookie(FFLogsCredentialStore::COOKIE, $this->credCookie('id-a', 's-a'))->get('/api-key');
        $this->assertSame('token-a', $this->callGetToken());
        $this->assertSame('token-a', $this->callGetToken(), '同じキーならキャッシュを使う');

        $this->withCookie(FFLogsCredentialStore::COOKIE, $this->credCookie('id-b', 's-b'))->get('/api-key');
        $this->assertSame('token-b', $this->callGetToken(), '別のキーのトークンを使い回さない');

        Http::assertSentCount(2);
    }

    #[Test]
    public function missing_and_rejected_keys_raise_dedicated_exceptions(): void
    {
        try {
            $this->callGetToken();
            $this->fail('キーが無ければ止まる');
        } catch (FFLogsCredentialsMissing) {
            $this->addToAssertionCount(1);
        }

        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);
        $this->withCookie(FFLogsCredentialStore::COOKIE, $this->credCookie())->get('/api-key');
        $this->expectException(FFLogsCredentialsInvalid::class);
        $this->callGetToken();
    }

    #[Test]
    public function a_rejected_key_during_analysis_leads_to_the_settings_page(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

        $this->withCookie(FFLogsCredentialStore::COOKIE, $this->credCookie())
            ->from('/mitigation')
            ->post('/analyze', ['url' => 'https://ja.fflogs.com/reports/AbCd1234?fight=11'])
            ->assertRedirect(route('api_key.edit'))
            ->assertSessionHas('error');
    }
}
