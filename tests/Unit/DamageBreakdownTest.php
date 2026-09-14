<?php

namespace Tests\Unit;

use App\Support\DamageBreakdown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 被弾イベントの分解。軽減率の表示とタイムラインの両方がここに依存しているので、
 * 「バリア吸収」「オーバーキル」「生ダメージ情報なし」の扱いを固定しておく。
 */
class DamageBreakdownTest extends TestCase
{
    #[Test]
    public function it_reads_the_mitigation_rate_from_the_unmitigated_amount(): void
    {
        $b = DamageBreakdown::of(['amount' => 1000, 'unmitigatedAmount' => 2000]);

        $this->assertSame(1000, $b['effective']);
        $this->assertSame(2000, $b['base']);
        $this->assertSame(50, $b['rate']);
    }

    #[Test]
    public function it_counts_absorbed_damage_outside_of_the_hp_loss(): void
    {
        // バリアが500吸った。HPが減ったのは1000だが、軽減率は「HPに向かった量」で出す。
        $b = DamageBreakdown::of(['amount' => 1000, 'absorbed' => 500, 'unmitigatedAmount' => 3000]);

        $this->assertSame(1000, $b['effective'], 'バリア吸収分は実被弾に含めない');
        $this->assertSame(1500, $b['total'], 'バリアが吸う前の被弾量');
        $this->assertSame(3000, $b['unmit_full']);
        $this->assertSame(500, $b['absorbed']);
        // 生ダメージは実被弾の比率で按分する: 3000 * 1000 / 1500 = 2000
        $this->assertSame(2000, $b['base']);
        $this->assertSame(50, $b['rate']);
    }

    #[Test]
    public function it_includes_overkill_in_the_damage_actually_taken(): void
    {
        // 残HP100で1000喰らった場合、amount は残HPで頭打ちになり overkill に900が入る。
        $b = DamageBreakdown::of(['amount' => 100, 'overkill' => 900, 'unmitigatedAmount' => 2000]);

        $this->assertSame(1000, $b['effective'], 'overkill を足さないと軽減率が化ける');
        $this->assertSame(100, $b['hp_lost']);
        $this->assertSame(900, $b['overkill']);
        $this->assertSame(50, $b['rate']);
    }

    #[Test]
    public function it_falls_back_to_the_multiplier_when_the_unmitigated_amount_is_missing(): void
    {
        $b = DamageBreakdown::of(['amount' => 1000, 'multiplier' => 0.5]);

        $this->assertSame(2000, $b['base']);
        $this->assertSame(50, $b['rate']);
    }

    #[Test]
    public function it_reports_no_mitigation_when_the_event_carries_no_raw_damage(): void
    {
        // DoT tick など。推測で軽減率を作らない。
        $b = DamageBreakdown::of(['amount' => 1000]);

        $this->assertSame(1000, $b['base']);
        $this->assertSame(0, $b['rate']);
    }

    #[Test]
    public function it_clamps_the_rate_at_zero_when_damage_was_amplified(): void
    {
        // ダメージ上昇デバフで base < effective になっても負の軽減率にはしない。
        $b = DamageBreakdown::of(['amount' => 3000, 'unmitigatedAmount' => 1000]);

        $this->assertSame(3000, $b['base']);
        $this->assertSame(0, $b['rate']);
    }

    #[Test]
    public function it_handles_a_hit_fully_absorbed_by_a_barrier(): void
    {
        $b = DamageBreakdown::of(['amount' => 0, 'absorbed' => 2000, 'unmitigatedAmount' => 2000]);

        $this->assertSame(0, $b['effective']);
        $this->assertSame(2000, $b['total']);
        $this->assertSame(0, $b['rate'], '実被弾0なら軽減率は出さない');
    }

    #[Test]
    public function it_prefers_the_unmitigated_amount_over_the_rounded_multiplier(): void
    {
        // multiplier は小数2桁に丸められているので、両方あるときは unmitigatedAmount を使う。
        $b = DamageBreakdown::of([
            'amount' => 500, 'absorbed' => 100, 'overkill' => 50,
            'unmitigatedAmount' => 1200, 'multiplier' => 0.45,
        ]);

        $this->assertSame(550, $b['effective']);
        $this->assertSame(650, $b['total']);
        // 1200 * 550 / 650 = 1015.38… -> 1015
        $this->assertSame(1015, $b['base']);
        $this->assertSame(46, $b['rate']);
    }
}
