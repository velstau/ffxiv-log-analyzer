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

    /** nginx と同じく、旧パスのまま（SCRIPT_NAME=旧パス/index.php、目印 LEGACY_PATH=1）アプリに渡す */
    private function legacy(string $method, string $path, array $cookies = [], array $params = [], string $base = '/ff14tools/logs2timeline')
    {
        return $this->call($method, 'http://localhost' . $base . $path, $params, $cookies, [], [
            'SCRIPT_NAME' => $base . '/index.php',
            'SCRIPT_FILENAME' => public_path('index.php'),
            'LEGACY_PATH' => '1',
        ]);
    }

    #[Test]
    public function old_urls_move_to_the_new_path_with_the_query(): void
    {
        // 転送先はパスだけ（Host ヘッダーに左右されない）
        $this->legacy('GET', '/shared/abc?x=1&y=2')->assertStatus(301)
            ->assertHeader('Location', '/ff14tools/partyscope/shared/abc?x=1&y=2');
        $this->legacy('GET', '/?from=bookmark')->assertStatus(301)->assertHeader('Location', '/ff14tools/partyscope/?from=bookmark');
        $this->legacy('HEAD', '/compare')->assertStatus(301);
        // 古い画面から送られたフォームは、CSRF の検査より先に 308 で送り直させる
        $this->legacy('POST', '/analyze', [], ['url' => 'x'])->assertStatus(308)
            ->assertHeader('Location', '/ff14tools/partyscope/analyze');
        $this->legacy('OPTIONS', '/analyze')->assertStatus(308);
    }

    #[Test]
    public function an_encoded_old_path_still_leaves_for_the_new_top(): void
    {
        $this->legacy('GET', '/api-key', [], [], '/ff14tools/%6cogs2timeline')->assertStatus(301)
            ->assertHeader('Location', '/ff14tools/partyscope/');
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
        $response = $this->legacy('GET', '/api-key', [FFLogsCredentialStore::COOKIE => $encrypted])->assertStatus(302)
            ->assertHeader('Location', '/ff14tools/partyscope/api-key');
        // Cookie を付け直す応答はキャッシュさせない
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->legacy('POST', '/analyze', [FFLogsCredentialStore::COOKIE => $encrypted])->assertStatus(307);

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
    public function deleting_the_key_on_the_new_path_also_clears_the_old_path_cookie(): void
    {
        // 旧 Cookie が残っていると、あとで旧 URL を開いたときに消したキーが戻ってくるため
        $response = $this->withUnencryptedCookie(FFLogsCredentialStore::COOKIE, $this->savedKeyCookie())->post('/api-key/delete')->assertRedirect();
        $cleared = collect($response->headers->getCookies())->filter(fn($c) => $c->getPath() === '/ff14tools/logs2timeline/' && $c->isCleared());
        $this->assertCount(1, $cleared);
    }

    #[Test]
    public function the_new_path_is_served_normally(): void
    {
        $this->get('/api-key')->assertOk();
    }
}
