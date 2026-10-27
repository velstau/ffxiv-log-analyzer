<?php

namespace Tests\Feature;

use App\Support\FFLogsCredentialStore;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LegacyPathRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** nginx と同じく、旧パスのまま（SCRIPT_NAME=旧パス/index.php）アプリに渡す */
    private function legacy(string $method, string $path, array $cookies = [], array $params = [])
    {
        return $this->call($method, 'http://localhost/ff14tools/logs2timeline' . $path, $params, $cookies, [], [
            'SCRIPT_NAME' => '/ff14tools/logs2timeline/index.php',
            'SCRIPT_FILENAME' => public_path('index.php'),
        ]);
    }

    #[Test]
    public function old_urls_move_to_the_new_path_with_the_query(): void
    {
        $this->legacy('GET', '/shared/abc?x=1&y=2')->assertStatus(301)
            ->assertHeader('Location', 'http://localhost/ff14tools/partyscope/shared/abc?x=1&y=2');
        $this->legacy('GET', '/?from=bookmark')->assertStatus(301)->assertHeader('Location', 'http://localhost/ff14tools/partyscope/?from=bookmark');
        // 古い画面から送られたフォームは、CSRF の検査より先に 308 で送り直させる
        $this->legacy('POST', '/analyze', [], ['url' => 'x'])->assertStatus(308)
            ->assertHeader('Location', 'http://localhost/ff14tools/partyscope/analyze');
    }

    /** ブラウザが旧パスで持っている、暗号化済みの API キーの Cookie */
    private function savedKeyCookie(): string
    {
        return encrypt(\Illuminate\Cookie\CookieValuePrefix::create(FFLogsCredentialStore::COOKIE, app('encrypter')->getKey())
            . json_encode(['id' => 'test-id', 'secret' => 'test-secret']), false);
    }

    #[Test]
    public function the_saved_api_key_moves_to_the_new_path_still_encrypted(): void
    {
        $encrypted = $this->savedKeyCookie();
        $response = $this->legacy('GET', '/api-key', [FFLogsCredentialStore::COOKIE => $encrypted])->assertStatus(301);

        $cookies = collect($response->headers->getCookies())->keyBy(fn($c) => $c->getPath());
        $moved = $cookies['/ff14tools/partyscope/'];
        // 復号せず、暗号化された値をそのまま付け直す（平文のシークレットを Cookie に書かない）
        $this->assertSame($encrypted, $moved->getValue());
        $this->assertStringNotContainsString('test-secret', $moved->getValue());
        $this->assertTrue($moved->isHttpOnly());
        $this->assertGreaterThan(time() + 86400 * 29, $moved->getExpiresTime());
        $this->assertTrue($cookies['/ff14tools/logs2timeline/']->isCleared());
    }

    #[Test]
    public function the_moved_cookie_value_is_read_as_the_api_key_on_the_new_path(): void
    {
        $this->withUnencryptedCookie(FFLogsCredentialStore::COOKIE, $this->savedKeyCookie())
            ->get('/api-key')->assertOk()->assertSee('設定済み');
    }

    #[Test]
    public function the_new_path_is_served_normally(): void
    {
        $this->get('/api-key')->assertOk();
    }
}
