<?php

namespace App\Support;

/**
 * プレイヤーの実名を「SCH」「SCH(A)」のような別名に置き換える（名前を伏せて表示・共有するため）。
 *
 * 実名は一覧のほか、軽減の使用者・シナジーの付与先・死因などあちこちに入るので、項目を列挙せず
 * 配列全体（値とキー）を置き換える。文章に埋め込まれた名前も残さないよう、部分一致で置き換える。
 */
class PlayerAliases
{
    /**
     * 実名 => ジョブ略称 から 実名 => 別名 を作る。
     *
     * 同じジョブが複数いるときの番号は実名順で決める（並びが場所ごとに違っても同じ人は同じ番号）。
     * $label があれば「SCH(A)」「SCH(A1)」、なければ「SCH」「SCH1」。
     *
     * @param  array<string,string|null>  $roster
     * @return array<string,string>
     */
    public static function make(array $roster, string $label = ''): array
    {
        ksort($roster, SORT_STRING);
        $byJob = [];
        foreach ($roster as $name => $job) {
            $byJob[$job ?: 'PT'][] = (string) $name;
        }
        $alias = [];
        foreach ($byJob as $job => $names) {
            foreach ($names as $i => $name) {
                $n = count($names) > 1 ? (string) ($i + 1) : '';
                $alias[$name] = $label === '' ? "{$job}{$n}" : "{$job}({$label}{$n})";
            }
        }
        return $alias;
    }

    /**
     * $data の中の実名をすべて別名に置き換える。
     *
     * $markNamed のときは、'name' が置き換わった配列に anon => true を付ける
     * （DPS比較のアドバイス文で「SCH（SCH(A)）」と重ねないための印）。
     */
    public static function apply(mixed $data, array $alias, bool $markNamed = false): mixed
    {
        $alias = array_filter($alias, fn($to, $from) => $from !== '' && $from !== $to, ARRAY_FILTER_USE_BOTH);
        if (!$alias) {
            return $data;
        }
        $swap = fn(string $s) => strtr($s, $alias); // 長い名前から先に置き換わり、置き換え後の文字列は再度置き換えない
        $walk = function ($v) use (&$walk, $swap, $markNamed) {
            if (is_string($v)) {
                return $swap($v);
            }
            if (!is_array($v)) {
                return $v;
            }
            $out = [];
            foreach ($v as $k => $item) {
                $new = $walk($item);
                $out[is_string($k) ? $swap($k) : $k] = $new;
                if ($markNamed && $k === 'name' && is_string($item) && $new !== $item) {
                    $out['anon'] = true;
                }
            }
            return $out;
        };
        return $walk($data);
    }
}
