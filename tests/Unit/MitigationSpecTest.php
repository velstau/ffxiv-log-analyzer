<?php

namespace Tests\Unit;

use App\Support\MitigationSpec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 軽減仕様表の整合性。
 *
 * 中身の数値そのものはゲームの仕様なのでテストで固定しても意味が薄い。
 * ここで守りたいのは「パッチ追従でスキルを足したときに、表どうしの対応が崩れていないか」。
 * 特に columns() と power() のスキル名がズレると、そのスキルの軽減が静かに 0% として扱われる。
 */
class MitigationSpecTest extends TestCase
{
    #[Test]
    public function every_column_declares_ids_and_a_buff_or_debuff_type(): void
    {
        foreach (MitigationSpec::columns() as $job => $skills) {
            // 「Tank Role」等のロール共通枠は、共有軽減が無ければ空でよい（UIの列だけ確保する）。
            if (! str_ends_with($job, ' Role')) {
                $this->assertNotEmpty($skills, "{$job} のスキルが空");
            }

            foreach ($skills as $name => $spec) {
                $this->assertArrayHasKey('ids', $spec, "{$job} / {$name} に ids が無い");
                $this->assertIsArray($spec['ids']);
                $this->assertContains(
                    $spec['type'] ?? null,
                    ['buff', 'debuff'],
                    "{$job} / {$name} の type が buff/debuff ではない",
                );

                foreach ($spec['ids'] as $id) {
                    $this->assertIsInt($id, "{$job} / {$name} の ids に数値以外が混ざっている");
                }
                foreach ($spec['actionIds'] ?? [] as $id) {
                    $this->assertIsInt($id, "{$job} / {$name} の actionIds に数値以外が混ざっている");
                }
            }
        }
    }

    #[Test]
    public function every_skill_in_the_columns_has_a_mitigation_power_entry(): void
    {
        $power = MitigationSpec::power();
        $missing = [];

        foreach (MitigationSpec::columns() as $skills) {
            foreach (array_keys($skills) as $name) {
                if (! isset($power[$name])) {
                    $missing[$name] = true;
                }
            }
        }

        $this->assertSame(
            [],
            array_keys($missing),
            '軽減率の定義が無いスキル（このままだと軽減0%として集計される）',
        );
    }

    #[Test]
    public function every_mitigation_power_entry_declares_a_known_kind(): void
    {
        foreach (MitigationSpec::power() as $name => $spec) {
            $this->assertContains(
                $spec['kind'] ?? null,
                ['mit', 'barrier', 'special', 'other'],
                "{$name} の kind が未知",
            );

            if (($spec['kind'] ?? null) !== 'mit') {
                continue;
            }

            $hasFlat = isset($spec['mit']);
            $hasSplit = isset($spec['mit_phys']) || isset($spec['mit_magic']);
            $this->assertTrue(
                $hasFlat || $hasSplit,
                "{$name} は kind=mit なのに軽減率が入っていない",
            );

            // 物理・魔法で別々に持つスキルは片方が0のことがある（フェイイルミネーション等の魔法限定軽減）。
            // ただし両方0なら軽減にならないので、少なくとも1つは正の値であることを求める。
            $values = array_intersect_key($spec, array_flip(['mit', 'mit_phys', 'mit_magic']));
            $this->assertNotEmpty(
                array_filter($values, static fn($v) => $v > 0),
                "{$name} は kind=mit だが軽減率がすべて0",
            );

            foreach ($values as $key => $value) {
                $this->assertGreaterThanOrEqual(0, $value, "{$name}.{$key} が負");
                $this->assertLessThanOrEqual(100, $value, "{$name}.{$key} が100超");
            }
        }
    }

    #[Test]
    public function synergies_declare_a_cooldown_and_a_duration(): void
    {
        foreach (MitigationSpec::synergies() as $job => $skills) {
            foreach ($skills as $name => $spec) {
                $this->assertGreaterThan(0, $spec['cd'] ?? 0, "{$job} / {$name} の cd が未設定");
                $this->assertGreaterThan(0, $spec['dur'] ?? 0, "{$job} / {$name} の dur が未設定");
                $this->assertIsArray($spec['actionIds'] ?? null, "{$job} / {$name} の actionIds が配列でない");
            }
        }
    }

    #[Test]
    public function cooldowns_and_effect_durations_are_positive_numbers(): void
    {
        foreach (MitigationSpec::cooldowns() as $id => $cd) {
            $this->assertIsInt($id);
            $this->assertGreaterThan(0, $cd, "アクション {$id} のリキャストが0以下");
        }
        foreach (MitigationSpec::effectDurations() as $id => $dur) {
            $this->assertIsInt($id);
            $this->assertGreaterThan(0, $dur, "アクション {$id} の効果時間が0以下");
        }
    }

    #[Test]
    public function dancing_mad_uses_its_own_damage_down_rate(): void
    {
        // 絶妖星乱舞のダメージ低下は -90%。名前は日本語/英語のどちらでも来る。
        $this->assertSame(0.90, MitigationSpec::damageDownRate('Dancing Mad'));
        $this->assertSame(0.90, MitigationSpec::damageDownRate('絶妖星乱舞'));
        // fight 名がローカライズで揺れる場合はゾーン名と併せて判定する
        $this->assertSame(0.90, MitigationSpec::damageDownRate('ケフカ', 'シグマ次元'));
    }

    #[Test]
    public function unknown_content_falls_back_to_the_default_damage_down_rate(): void
    {
        $this->assertSame(MitigationSpec::DD_REDUCTION, MitigationSpec::damageDownRate('Alexander Prime', '機工城'));
        $this->assertSame(MitigationSpec::DD_REDUCTION, MitigationSpec::damageDownRate(''));
        // ゾーン名だけ一致しても、fight 名が噛み合わなければ既定値のまま
        $this->assertSame(MitigationSpec::DD_REDUCTION, MitigationSpec::damageDownRate('', 'シグマ次元'));
    }

    #[Test]
    public function every_barrier_can_resolve_its_maximum_duration(): void
    {
        // バリアが「割れた」判定は、消えた時刻を効果時間の上限と比べて行う。
        // 上限が引けないバリアがあると、そのスキルだけ常に「割れていない」と誤判定される。
        $durations = MitigationSpec::barrierDurations();
        $unresolved = [];

        foreach (MitigationSpec::barriers() as $actionId => $statusId) {
            $ids = array_merge([$actionId], (array) $statusId);
            $found = array_filter($ids, static fn($id) => isset($durations[$id]));

            if ($found === []) {
                $unresolved[] = $actionId;
            }
        }

        $this->assertSame([], $unresolved, '効果時間の上限が引けないバリア（アクションID）');
    }

    #[Test]
    public function barrier_durations_are_expressed_in_milliseconds(): void
    {
        foreach (MitigationSpec::barrierDurations() as $id => $ms) {
            $this->assertIsInt($id);
            // 秒とミリ秒の取り違えを防ぐ。バリアの効果時間は最短でも数秒ある。
            $this->assertGreaterThanOrEqual(1000, $ms, "アクション {$id} の効果時間がミリ秒に見えない");
            $this->assertLessThanOrEqual(60000, $ms, "アクション {$id} の効果時間が長すぎる");
        }
    }
}
