<?php

namespace Tests\Feature;

use App\Services\FFLogsService;
use App\Services\TimelineBuilder;
use App\Support\FFLogsCredentialStore;
use App\Support\PlayerAliases;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\UiData;
use Tests\TestCase;

class MitigationHideNamesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    /** フィクスチャの「Player N」を実名らしい名前にし、文章に埋め込まれた名前と表に出ない参加者も足す */
    private function analysis(): array
    {
        $data = json_decode(preg_replace('/Player (\d)/', 'Real Person $1', json_encode(UiData::timeline())), true);
        $data['playerTimelines']['Real Person 1'][0]['enemy_action'] = 'Bench Warmer と Real Person 2 への Test attack';
        $data['roster'] = ['Bench Warmer' => 'AST'] + array_combine(
            array_map(fn($i) => "Real Person {$i}", range(1, 8)),
            ['PLD', 'DRK', 'WHM', 'SCH', 'SAM', 'NIN', 'BRD', 'PCT'],
        );
        return $data;
    }

    private function analyze(array $form): string
    {
        $data = $this->analysis();
        $this->mock(FFLogsService::class, function ($mock) use ($data) {
            $mock->shouldReceive('parseUrl')->andReturn(['code' => 'Fixture', 'fightId' => 1]);
            $mock->shouldReceive('getFightDetails')->andReturn($data['fight']);
        });
        $this->mock(TimelineBuilder::class, fn($mock) => $mock->shouldReceive('build')->andReturn($data));
        return $this->withCookie(FFLogsCredentialStore::COOKIE, json_encode(['id' => 'test-id', 'secret' => 'test-secret']))
            ->post('/analyze', ['url' => 'fixture'] + $form)->assertStatus(303)->headers->get('Location');
    }

    #[Test]
    public function hidden_names_never_reach_the_page_or_the_share(): void
    {
        $resultUrl = $this->analyze(['hide_names' => '1']);
        $page = $this->get($resultUrl)->assertOk()->assertSee('プレイヤー名を非表示')->assertSee('"SCH"', false);
        foreach (['Real Person', 'Bench Warmer'] as $name) {
            $page->assertDontSee($name);
        }

        $this->postJson(route('timeline-shares.store'), ['draft' => basename($resultUrl)])->assertOk();
        $plaintext = Crypt::decryptString(Storage::disk('local')->get(Storage::disk('local')->files('timeline-shares')[0]));
        $this->assertStringContainsString('"hideNames":true', $plaintext);
        foreach (['Real Person', 'Bench Warmer', '"roster"'] as $secret) {
            $this->assertStringNotContainsString($secret, $plaintext);
        }
    }

    #[Test]
    public function names_stay_when_the_box_is_not_checked(): void
    {
        $this->get($this->analyze([]))->assertOk()->assertSee('Real Person 1')->assertDontSee('プレイヤー名を非表示');
    }

    #[Test]
    public function aliases_number_the_same_job_by_name_and_replace_embedded_names(): void
    {
        $alias = PlayerAliases::make(['Zed Healer' => 'SCH', 'Amy Healer' => 'SCH', 'Tank One' => 'PLD', 'No Job' => null]);
        $this->assertEquals(['Amy Healer' => 'SCH1', 'Zed Healer' => 'SCH2', 'Tank One' => 'PLD', 'No Job' => 'PT'], $alias);
        $this->assertSame('SCH(B2)', PlayerAliases::make(['Zed Healer' => 'SCH', 'Amy Healer' => 'SCH'], 'B')['Zed Healer']);

        $out = PlayerAliases::apply(['Tank One' => ['src' => 'Zed Healer', 'text' => 'Amy Healer と Zed Healer'], 'n' => 3], $alias);
        $this->assertSame(['PLD' => ['src' => 'SCH2', 'text' => 'SCH1 と SCH2'], 'n' => 3], $out);
    }
}
