<?php

namespace Tests\Feature;

use App\Services\FFLogsService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\UiData;
use Tests\TestCase;

class UiPageTest extends TestCase
{
    #[Test]
    public function entry_pages_use_tabler_without_tailwind_and_keep_form_contracts(): void
    {
        foreach (['/', '/mitigation', '/compare', '/api-key'] as $path) {
            $this->get($path)->assertOk()->assertSee('data-bs-theme="dark"', false)
                ->assertSee('vendor/tabler/1.7.1/tabler.min.css', false)
                ->assertDontSee('tailwindcss')->assertSee('ご利用にあたって');
        }
        $this->get('/mitigation')->assertSee('name="url"', false)->assertSee('name="_token"', false);
        $this->get('/compare')->assertSee('name="url_a"', false)->assertSee('name="url_b"', false);
    }

    #[Test]
    public function timeline_renders_all_six_states_and_keeps_real_data_and_exports(): void
    {
        foreach ([true, false] as $hasPhases) {
            $this->view('fflogs.timeline', UiData::timeline($hasPhases))
                ->assertSee('UI fixture')->assertSee('id="mitigation-table"', false)
                ->assertSee('downloadHojoringXML()')->assertDontSee('onclick="saveFullTimeline()"', false)
                ->assertSee('mit-used')->assertSee('mit-on')->assertSee('mit-up')
                ->assertSee('mit-cd')->assertSee('mit-x')->assertSee('mit-off')
                ->assertSee('timeline-xrange')->assertSee('000000-000806.png');
        }
    }

    #[Test]
    public function comparison_result_keeps_phase_graph_and_range_controls(): void
    {
        $this->view('fflogs.compare', UiData::compare())->assertSee('UI fixture')
            ->assertSee('Chart')->assertSee('phase-ts')->assertSee('RANGE_URL');
    }

    #[Test]
    public function party_form_renders_existing_job_values_without_calling_external_api(): void
    {
        $this->mock(FFLogsService::class, fn($mock) => $mock->shouldReceive('getZoneTree')->once()->andReturn(UiData::zones()));
        $this->get('/party-search')->assertOk()->assertSee('data-job="Paladin"', false)
            ->assertSee('data-job="Pictomancer"', false)->assertSee('name="jobs[]"', false);
    }
}
