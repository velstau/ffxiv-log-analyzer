<?php

namespace Tests\Fixtures;

use App\Support\FFXIVJobs;

final class UiData
{
    public static function timeline(bool $hasPhases = true): array
    {
        $meta = FFXIVJobs::all();
        $types = ['Paladin', 'DarkKnight', 'WhiteMage', 'Scholar', 'Samurai', 'Ninja', 'Bard', 'Pictomancer'];
        $players = [];
        foreach ($types as $i => $type) {
            $name = 'Player ' . ($i + 1);
            $players[$name] = ['name' => $name, 'icon' => asset(ltrim($meta[$type]['icon'], '/')), 'job' => $type];
        }
        $columns = [];
        $groups = [];
        $icons = [];
        // All six real UI states, using local icons and synthetic events; no API request.
        foreach (['PLD', 'WAR', 'Tank Role', 'SCH', 'AST', 'MNK', 'RPR', 'DNC'] as $i => $group) {
            $groups[$group] = [];
            $icons[$group] = asset(ltrim($meta[$types[$i]]['icon'], '/'));
            foreach (range(0, [8, 7, 1, 9, 5, 1, 1, 2][$i] - 1) as $j) {
                $key = $group . '_' . $j;
                $column = ['name' => 'Test skill ' . $j, 'icon' => '000000-000806.png', 'group' => $group, 'ids' => [1193], 'actionIds' => [7535], 'kind' => 'mit', 'mit_phys' => 10, 'mit_magic' => 10, 'has_barrier' => false];
                $columns[$key] = $column;
                $groups[$group][$key] = $column;
            }
        }
        $events = [];
        foreach (range(0, 29) as $i) {
            $cols = [];
            foreach (array_keys($columns) as $j => $key) {
                $state = ($i + $j) % 6;
                $cols[$key] = ['active' => in_array($state, [1, 5]), 'used' => $state === 0, 'up' => $state === 2, 'cooldown' => $state === 3, 'is_broken' => $state === 5, 'sources' => ['Player 1'], 'used_time' => $i * 3, 'used_src' => 'Player 1'];
            }
            $damage = [];
            foreach (array_keys($players) as $player) {
                $damage[$player] = ['amount' => 120000, 'unmitigated' => 160000, 'absorbed' => 0, 'overkill' => 0, 'hp_lost' => 120000];
            }
            $events[] = ['ability' => $i === 1 ? 'Attack' : 'Test attack', 'timestamp' => 100000 + $i * 3000, 'rel_time' => $i * 3, 'phase' => 1 + intdiv($i, 10), 'is_dot' => false, 'max_unmitigated' => 160000, 'max_amount' => 120000, 'mitigation_rate' => 25, 'absorbed_total' => 0, 'max_absorbed' => 0, 'players' => $damage, 'cols' => $cols];
        }

        // Include a hit without mitigation/barrier badges for compact-row coverage.
        $events[1]['mitigation_rate'] = 0;

        return ['fight' => ['name' => 'UI fixture', 'startTime' => 100000, 'endTime' => 190000, 'zoneName' => 'Test zone'], 'events' => $events, 'hasPhases' => $hasPhases, 'playerColumns' => array_keys($players), 'playerDetails' => $players, 'playerTimelines' => ['Player 1' => [['rel_time' => 3, 'icon' => '000000-000806.png', 'enemy_action' => 'Test attack', 'name' => 'Test skill', 'target' => 'Player 2', 'self_target' => false]]], 'mitigationColumns' => $columns, 'mitigationGroups' => $groups, 'mitigationGroupIcons' => $icons, 'synergyWindows' => [], 'deaths' => []];
    }

    public static function compare(): array
    {
        $player = ['job' => 'Paladin', 'name' => 'Fixture Player with a long name', 'role' => 'tank', 'roleOrder' => 0, 'rdps' => 15000, 'potentialRdps' => 16000, 'total' => 1350000, 'potentialTotal' => 1440000, 'color' => '#6090ff', 'activePct' => 99, 'iconFile' => 'paladin', 'dps' => 15000, 'adps' => 14500, 'deaths' => 1];
        $overall = ['partyTotal' => 12000000, 'partyDps' => 120000, 'partyRdps' => 120000, 'players' => [$player], 'closedPos' => []];
        $phase = ['id' => 1, 'name' => 'Phase 1', 'durationSec' => 90, 'startMs' => 100000, 'endMs' => 190000, 'stats' => $overall, 'summary' => $overall, 'players' => [$player], 'series' => [['t' => 0, 'v' => 120000], ['t' => 10, 'v' => 130000], ['t' => 20, 'v' => 110000], ['t' => 30, 'v' => 140000]], 'secDmg' => array_fill(0, 91, 120000), 'enemyCasts' => []];
        $a = ['name' => 'UI fixture', 'code' => 'FixtureA', 'fightId' => 1, 'zoneName' => 'Test zone', 'durationSec' => 90, 'overall' => $overall, 'phases' => [$phase]];

        return ['A' => $a, 'B' => array_replace($a, ['code' => 'FixtureB']), 'phaseIds' => [1], 'advice' => []];
    }

    public static function zones(): array
    {
        return [['name' => 'Test expansion', 'zones' => [['id' => 76, 'name' => 'Test zone', 'encounters' => [['id' => 1085, 'name' => 'Test boss'], ['id' => 1086, 'name' => 'Other boss']]], ['id' => 77, 'name' => 'Other zone', 'encounters' => [['id' => 1087, 'name' => 'Third boss']]]]]];
    }
}
