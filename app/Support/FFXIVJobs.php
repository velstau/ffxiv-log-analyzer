<?php

namespace App\Support;

/**
 * FFXIV ジョブの一覧表。ジョブ名・ロール・アイコン・略称はここ1箇所に集約する。
 *
 * キーは FFLogs 内部のジョブ名（report.playerDetails の各プレイヤーの `type` と同じ表記）。
 * `role` は FFLogs の fightRankings が返すロール人数フィールド名
 * （tanks / healers / melee / ranged / casters）とそのまま同じ綴りにしてある。
 * ランキング1件ずつの人数と突き合わせて事前フィルタするため、ここがズレると絞り込みが壊れる。
 *
 * ロール分類は zone 76 (Dancing Mad) のランキング50件で
 * 「このテーブルから算出した人数 == APIが返す tanks/healers/melee/ranged/casters」を
 * 全件突き合わせて確認済み（50/50一致）。
 *
 * 呼び出し側が欲しい形が場所ごとに違うため、投影用のメソッドを用意している。
 *  - {@see all()} / {@see meta()}         … PT構成検索（選択UI・結果表示）
 *  - {@see timelineMeta()}                … 軽減タイムライン（略称・ロール色・並び順）
 *  - {@see abbrByDisplayName()}           … FFLogs の表示名（"Dark Knight"）→ 略称
 *  - {@see typesByAbbr()}                 … 略称 → type（軽減列のジョブ一致チェック）
 */
class FFXIVJobs
{
    /**
     * PT構成検索で選べるジョブ。type => [日本語名, 略称, ロール, アイコンファイル名(拡張子なし)]
     */
    private const JOBS = [
        'Paladin'      => ['ナイト',           'PLD', 'tanks',   'paladin'],
        'Warrior'      => ['戦士',             'WAR', 'tanks',   'warrior'],
        'DarkKnight'   => ['暗黒騎士',         'DRK', 'tanks',   'darkknight'],
        'Gunbreaker'   => ['ガンブレイカー',   'GNB', 'tanks',   'gunbreaker'],

        'WhiteMage'    => ['白魔道士',         'WHM', 'healers', 'whitemage'],
        'Scholar'      => ['学者',             'SCH', 'healers', 'scholar'],
        'Astrologian'  => ['占星術師',         'AST', 'healers', 'astrologian'],
        'Sage'         => ['賢者',             'SGE', 'healers', 'sage'],

        'Monk'         => ['モンク',           'MNK', 'melee',   'monk'],
        'Dragoon'      => ['竜騎士',           'DRG', 'melee',   'dragoon'],
        'Ninja'        => ['忍者',             'NIN', 'melee',   'ninja'],
        'Samurai'      => ['侍',               'SAM', 'melee',   'samurai'],
        'Reaper'       => ['リーパー',         'RPR', 'melee',   'reaper'],
        'Viper'        => ['ヴァイパー',       'VPR', 'melee',   'viper'],

        'Bard'         => ['吟遊詩人',         'BRD', 'ranged',  'bard'],
        'Machinist'    => ['機工士',           'MCH', 'ranged',  'machinist'],
        'Dancer'       => ['踊り子',           'DNC', 'ranged',  'dancer'],

        'BlackMage'    => ['黒魔道士',         'BLM', 'casters', 'blackmage'],
        'Summoner'     => ['召喚士',           'SMN', 'casters', 'summoner'],
        'RedMage'      => ['赤魔道士',         'RDM', 'casters', 'redmage'],
        'Pictomancer'  => ['ピクトマンサー',   'PCT', 'casters', 'pictomancer'],
    ];

    /**
     * レイドには出ないが、ログ上には現れるもの。
     *
     * PT構成検索の候補には出さない（ランキングに載らないため探しても当たらない）が、
     * タイムラインでは名前とアイコンを出す必要がある。
     * リミットブレイクはジョブではないのでロールもアイコンも持たない。
     */
    private const NON_RAID = [
        'BlueMage'     => ['青魔道士',         'BLU', 'casters', 'bluemage'],
        'LimitBreak'   => ['リミットブレイク', 'LB',  null,      null],
    ];

    /** ロール表示名・色。fightRankings のフィールド名と同じキーで並び順も兼ねる。 */
    private const ROLES = [
        'tanks'   => ['タンク',       '#3b82f6'],
        'healers' => ['ヒーラー',     '#22c55e'],
        'melee'   => ['近接DPS',      '#ef4444'],
        'ranged'  => ['遠隔物理DPS',  '#f59e0b'],
        'casters' => ['遠隔魔法DPS',  '#a855f7'],
    ];

    /** タイムライン表示で使う単数形のロール名。ROLES と同じ並び順＝そのまま表示順になる。 */
    private const ROLE_SINGULAR = [
        'tanks'   => 'tank',
        'healers' => 'healer',
        'melee'   => 'melee',
        'ranged'  => 'ranged',
        'casters' => 'caster',
    ];

    /** 軽減列のロールグループ名。ロール共通スキル（ランパート等）の列はこの名前で束ねる。 */
    private const ROLE_GROUP = [
        'tanks'   => 'Tank Role',
        'healers' => 'Healer Role',
        'melee'   => 'Melee Role',
        'ranged'  => 'Phys Ranged Role',
        'casters' => 'Caster Role',
    ];

    /** ロールが決まらないもの（リミットブレイク・未知のジョブ）の表示。 */
    private const ROLE_OTHER = 'other';

    private const COLOR_OTHER = '#94a3b8';

    /** 選択可能な全ジョブ。type => ['ja','abbr','role','icon','color'] */
    public static function all(): array
    {
        $out = [];
        foreach (self::JOBS as $type => [$ja, $abbr, $role, $icon]) {
            $out[$type] = [
                'type'  => $type,
                'ja'    => $ja,
                'abbr'  => $abbr,
                'role'  => $role,
                'icon'  => '/icons/jobs/' . $icon . '.png',
                'color' => self::ROLES[$role][1],
            ];
        }

        return $out;
    }

    /** ロール別にまとめたジョブ一覧（ジョブ選択UIの並び用）。 */
    public static function groupedByRole(): array
    {
        $out = [];
        foreach (self::ROLES as $role => [$label, $color]) {
            $out[$role] = ['label' => $label, 'color' => $color, 'jobs' => []];
        }
        foreach (self::all() as $type => $job) {
            $out[$job['role']]['jobs'][$type] = $job;
        }

        return $out;
    }

    public static function exists(string $type): bool
    {
        return isset(self::JOBS[$type]);
    }

    /** 未知のジョブでも表示だけは壊れないようにフォールバックを返す。 */
    public static function meta(string $type): array
    {
        return self::all()[$type] ?? [
            'type' => $type, 'ja' => $type, 'abbr' => $type,
            'role' => null, 'icon' => null, 'color' => self::COLOR_OTHER,
        ];
    }

    /**
     * 軽減タイムライン用のジョブ情報。
     *
     * ロール名は表示用に単数形（tank / healer / …）にし、列の並び順 roleOrder を添える。
     * PT構成検索側（{@see all()}）は FFLogs のフィールド名に合わせた複数形を使うので、
     * 同じ情報でも形が違う点に注意。
     *
     * @return array{abbr:string, role:string, color:string, roleOrder:int, iconFile:string|null}
     */
    public static function timelineMeta(string $type): array
    {
        $job = self::JOBS[$type] ?? self::NON_RAID[$type] ?? null;

        if ($job === null) {
            return [
                'abbr' => $type, 'role' => self::ROLE_OTHER, 'color' => self::COLOR_OTHER,
                'roleOrder' => count(self::ROLES), 'iconFile' => null,
            ];
        }

        [, $abbr, $role, $icon] = $job;

        if ($role === null) {
            return [
                'abbr' => $abbr, 'role' => self::ROLE_OTHER, 'color' => self::COLOR_OTHER,
                'roleOrder' => count(self::ROLES), 'iconFile' => $icon,
            ];
        }

        return [
            'abbr' => $abbr,
            'role' => self::ROLE_SINGULAR[$role],
            'color' => self::ROLES[$role][1],
            'roleOrder' => array_search($role, array_keys(self::ROLES), true),
            'iconFile' => $icon,
        ];
    }

    /**
     * FFLogs が画面表示に使うジョブ名（"Dark Knight"）から略称（"DRK"）を引く表。
     *
     * type は連結表記（"DarkKnight"）なので、大文字の手前に空白を入れて表示名に戻す。
     * リミットブレイクはジョブ列に現れないので含めない。
     *
     * @return array<string, string>
     */
    public static function abbrByDisplayName(): array
    {
        $out = [];
        foreach (self::raidCapableJobs() as $type => [, $abbr]) {
            $out[preg_replace('/(?<!^)([A-Z])/', ' $1', $type)] = $abbr;
        }

        return $out;
    }

    /**
     * 略称（"PLD"）から type（"Paladin"）を引く表。軽減列のジョブ一致チェックに使う。
     *
     * 値が配列なのは、呼び出し側が「その列を撃てるジョブ名の候補」として
     * 他の候補と結合して使うため。
     *
     * @return array<string, list<string>>
     */
    public static function typesByAbbr(): array
    {
        $out = [];
        foreach (self::JOBS as $type => [, $abbr]) {
            $out[$abbr] = [$type];
        }

        return $out;
    }


    /**
     * type（"DarkKnight"）から略称（"DRK"）を引く表。
     *
     * @return array<string, string>
     */
    public static function abbrByType(): array
    {
        $out = [];
        foreach (self::raidCapableJobs() as $type => [, $abbr]) {
            $out[$type] = $abbr;
        }

        return $out;
    }

    /**
     * 略称（"DRK"）から所属するロール列名（"Tank Role"）を引く表。
     *
     * @return array<string, string>
     */
    public static function roleGroupByAbbr(): array
    {
        $out = [];
        foreach (self::raidCapableJobs() as [, $abbr, $role]) {
            $out[$abbr] = self::ROLE_GROUP[$role];
        }

        return $out;
    }

    /**
     * ロール列名（"Tank Role"）から、そのロールのジョブ略称一覧を引く表。
     *
     * @return array<string, list<string>>
     */
    public static function abbrsByRoleGroup(): array
    {
        $out = array_fill_keys(array_values(self::ROLE_GROUP), []);
        foreach (self::raidCapableJobs() as [, $abbr, $role]) {
            $out[self::ROLE_GROUP[$role]][] = $abbr;
        }

        return $out;
    }

    /**
     * ロール列名から、そのロールに属するジョブ名を type と略称の両方で引く表。
     *
     * 軽減列のグループ名がロール単位のとき、発生源のジョブがどちらの表記で来ても
     * 一致判定できるようにするため。
     *
     * @return array<string, list<string>>
     */
    public static function membersByRoleGroup(): array
    {
        $out = array_fill_keys(array_values(self::ROLE_GROUP), []);
        foreach (self::raidCapableJobs() as $type => [, $abbr, $role]) {
            $out[self::ROLE_GROUP[$role]][] = $type;
        }
        foreach (self::abbrsByRoleGroup() as $group => $abbrs) {
            $out[$group] = array_merge($out[$group], $abbrs);
        }

        return $out;
    }

    /**
     * ロールを持つジョブ（＝リミットブレイクを除く全ジョブ）。青魔道士も含む。
     *
     * @return array<string, array{0:string,1:string,2:string,3:string}>
     */
    private static function raidCapableJobs(): array
    {
        return array_filter(self::JOBS + self::NON_RAID, static fn($job) => $job[2] !== null);
    }


    /**
     * 略称 => 表示順。ロール順（タンク→ヒーラー→近接→遠隔→キャス）に並べ、
     * 同じロール内はこの表の定義順にする。
     *
     * 値を10刻みにしてあるのは、表示順の中に別種のもの（薬など）を割り込ませるため。
     *
     * @return array<string, int>
     */
    public static function displayOrderByAbbr(): array
    {
        $out = [];
        $roleIndex = array_flip(array_keys(self::ROLES));

        foreach (self::raidCapableJobs() as [, $abbr, $role]) {
            $base = ($roleIndex[$role] + 1) * 10;
            $out[$abbr] = $base + count(array_filter(
                $out,
                static fn($v) => intdiv($v, 10) === intdiv($base, 10),
            ));
        }

        return $out;
    }

    /** 略称 => アイコンファイル名（拡張子なし）。 @return array<string, string> */
    public static function iconFileByAbbr(): array
    {
        $out = [];
        foreach (self::raidCapableJobs() as [, $abbr, , $icon]) {
            $out[$abbr] = $icon;
        }

        return $out;
    }

    /**
     * 略称 => tank / healer / dps の3分類。
     *
     * ロールごとの細かい区別（近接・遠隔・キャス）が要らない場面
     * （死因チェッカーの配色など）で使う。未知のジョブは dps 扱い。
     *
     * @return array<string, string>
     */
    public static function broadRoleByAbbr(): array
    {
        $out = [];
        foreach (self::raidCapableJobs() as [, $abbr, $role]) {
            $out[$abbr] = match ($role) {
                'tanks' => 'tank',
                'healers' => 'healer',
                default => 'dps',
            };
        }

        return $out;
    }

    /**
     * ジョブ配列を fightRankings のロール人数フィールドと同じ形に集計する。
     * 未知のジョブが混ざっていたら null（＝この構成では人数での事前フィルタをかけない）。
     */
    public static function roleCounts(array $types): ?array
    {
        $counts = array_fill_keys(array_keys(self::ROLES), 0);
        foreach ($types as $t) {
            if (!isset(self::JOBS[$t])) {
                return null;
            }
            $counts[self::JOBS[$t][2]]++;
        }

        return $counts;
    }

    /** ロール順 → 略称順でジョブを並べ替える（構成表示を常に同じ順にするため）。 */
    public static function sortTypes(array $types): array
    {
        $order = array_flip(array_keys(self::ROLES));
        usort($types, function ($a, $b) use ($order) {
            $ma = self::meta($a);
            $mb = self::meta($b);
            $ra = $order[$ma['role']] ?? 99;
            $rb = $order[$mb['role']] ?? 99;

            return $ra <=> $rb ?: strcmp($ma['abbr'], $mb['abbr']);
        });

        return $types;
    }
}
