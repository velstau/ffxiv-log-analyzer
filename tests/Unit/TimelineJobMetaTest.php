<?php

namespace Tests\Unit;

use App\Support\FFXIVJobs;
use App\Support\TimelineJobMeta;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ジョブ情報の各投影。
 *
 * ジョブの実体は {@see FFXIVJobs} 1箇所に集約してあり、用途ごとに形を変えて返す。
 *  - PT構成検索は FFLogs の fightRankings に合わせた複数形ロール（tanks / healers / …）
 *  - タイムラインは表示用の単数形ロール（tank / healer / …）と並び順
 *  - 軽減列の一致判定は「表示名 → 略称」と「略称 → type」
 *
 * 形が違うだけで中身は同じはずなので、投影どうしが食い違っていないことをここで押さえる。
 */
class TimelineJobMetaTest extends TestCase
{
    #[Test]
    public function it_resolves_a_known_job(): void
    {
        $sage = TimelineJobMeta::of('Sage');

        $this->assertSame('SGE', $sage['abbr']);
        $this->assertSame('healer', $sage['role']);
        $this->assertSame('sage', $sage['iconFile']);
        $this->assertSame(1, $sage['roleOrder'], 'ロール順はタンク→ヒーラー→近接→遠隔→キャス');
    }

    #[Test]
    public function it_falls_back_for_an_unknown_job_instead_of_breaking_the_view(): void
    {
        $unknown = TimelineJobMeta::of('NotAJob');

        $this->assertSame('NotAJob', $unknown['abbr'], '未知のジョブは名前をそのまま出す');
        $this->assertSame('other', $unknown['role']);
        $this->assertNull($unknown['iconFile'], 'アイコンが無いことを呼び出し側が判定できる');
        $this->assertSame('#94a3b8', $unknown['color']);
    }

    #[Test]
    public function it_knows_the_jobs_that_party_search_does_not_track(): void
    {
        // ランキングに載らないので検索候補には出さないが、タイムラインには出てくる。
        $this->assertSame('BLU', TimelineJobMeta::of('BlueMage')['abbr']);
        $this->assertSame('LB', TimelineJobMeta::of('LimitBreak')['abbr']);

        $this->assertFalse(FFXIVJobs::exists('BlueMage'), '青魔道士は検索候補に出さない');
        $this->assertArrayNotHasKey('BlueMage', FFXIVJobs::all());
    }

    #[Test]
    public function the_limit_break_has_no_role_or_icon(): void
    {
        $lb = TimelineJobMeta::of('LimitBreak');

        $this->assertSame('other', $lb['role'], 'リミットブレイクはジョブではない');
        $this->assertNull($lb['iconFile']);
    }

    #[Test]
    public function roles_are_ordered_consistently(): void
    {
        $order = array_map(
            static fn(string $type) => TimelineJobMeta::of($type)['roleOrder'],
            ['Paladin', 'Sage', 'Samurai', 'Bard', 'BlackMage', 'NotAJob'],
        );

        $this->assertSame([0, 1, 2, 3, 4, 5], $order);
    }

    #[Test]
    public function the_search_and_timeline_views_agree_on_every_job(): void
    {
        foreach (FFXIVJobs::all() as $type => $job) {
            $timeline = TimelineJobMeta::of($type);

            $this->assertSame($job['abbr'], $timeline['abbr'], "{$type} の略称が食い違っている");
            $this->assertSame($job['color'], $timeline['color'], "{$type} のロール色が食い違っている");
            $this->assertSame(
                '/icons/jobs/' . $timeline['iconFile'] . '.png',
                $job['icon'],
                "{$type} のアイコンが食い違っている",
            );
            $this->assertStringStartsWith(
                $timeline['role'],
                $job['role'],
                "{$type} のロールが食い違っている（単数形と複数形は同じロールを指すこと）",
            );
        }
    }

    #[Test]
    public function the_display_name_lookup_covers_every_job_that_can_appear_in_a_column(): void
    {
        $byName = FFXIVJobs::abbrByDisplayName();

        // FFLogs は "DarkKnight" ではなく "Dark Knight" の表記で返してくる
        $this->assertSame('DRK', $byName['Dark Knight']);
        $this->assertSame('WHM', $byName['White Mage']);
        $this->assertSame('BLU', $byName['Blue Mage']);
        $this->assertArrayNotHasKey('Limit Break', $byName, 'リミットブレイクはジョブ列に出ない');

        foreach (FFXIVJobs::all() as $job) {
            $this->assertContains($job['abbr'], $byName, "{$job['abbr']} が表示名から引けない");
        }
    }

    #[Test]
    public function the_abbreviation_lookup_round_trips(): void
    {
        $typesByAbbr = FFXIVJobs::typesByAbbr();

        foreach (FFXIVJobs::all() as $type => $job) {
            $this->assertSame(
                [$type],
                $typesByAbbr[$job['abbr']] ?? null,
                "{$job['abbr']} から {$type} を引けない",
            );
        }
    }

    #[Test]
    public function the_role_group_projections_agree_with_each_other(): void
    {
        $byAbbr = FFXIVJobs::roleGroupByAbbr();
        $abbrsByGroup = FFXIVJobs::abbrsByRoleGroup();
        $membersByGroup = FFXIVJobs::membersByRoleGroup();

        foreach ($byAbbr as $abbr => $group) {
            $this->assertContains($abbr, $abbrsByGroup[$group], "{$abbr} が {$group} の一覧に無い");
            $this->assertContains($abbr, $membersByGroup[$group], "{$abbr} が {$group} のメンバーに無い");
        }

        foreach ($abbrsByGroup as $group => $abbrs) {
            $this->assertNotEmpty($abbrs, "{$group} が空");
            foreach ($abbrs as $abbr) {
                $this->assertSame($group, $byAbbr[$abbr], "{$abbr} の所属ロールが食い違っている");
            }
        }
    }

    #[Test]
    public function role_group_members_carry_both_spellings(): void
    {
        // 発生源のジョブが type でも略称でも来るので、両方で引けないと一致判定が漏れる。
        $members = FFXIVJobs::membersByRoleGroup()['Tank Role'];

        foreach (['Paladin', 'Warrior', 'DarkKnight', 'Gunbreaker'] as $type) {
            $this->assertContains($type, $members);
        }
        foreach (['PLD', 'WAR', 'DRK', 'GNB'] as $abbr) {
            $this->assertContains($abbr, $members);
        }
    }

    #[Test]
    public function the_type_lookup_includes_blue_mage_but_not_the_limit_break(): void
    {
        $byType = FFXIVJobs::abbrByType();

        $this->assertSame('BLU', $byType['BlueMage'], '青魔道士はタイムラインに出るので略称が要る');
        $this->assertArrayNotHasKey('LimitBreak', $byType, 'リミットブレイクはジョブ列に出ない');
        $this->assertSame(count(FFXIVJobs::all()) + 1, count($byType));
    }
}
