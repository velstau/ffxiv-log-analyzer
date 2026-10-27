<?php

namespace App\Support;

/**
 * プレイヤーの実名を「SCH」「SCH(A)」のような別名に置き換える（名前を伏せて表示・共有するため）。
 *
 * 実名は一覧のほか、軽減の使用者・シナジーの付与先・死因などあちこちに入るので、項目を列挙せず
 * 配列全体の値とキーのうち、実名と完全に一致するものを置き換える。
 * 部分一致にしないのは、実名が技名や軽減グループ名（「Tank Role」）と同じときに、技名や列のキー
 * （「Tank Role_0」）まで書き換わって表が壊れるため。解析結果の文字列に実名を埋め込んだ文章はない
 * （2026-10-27 に TimelineBuilder・DeathCheckerBuilder で確認）。埋め込む文章を足すときは、伏せたあとに組み立てる。
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
     * $keep に挙げた最上位のキー（ジョブ・ロールで組んだ軽減列の定義など、実名を含まない構造）はそのまま残す。
     */
    public static function apply(mixed $data, array $alias, bool $markNamed = false, array $keep = []): mixed
    {
        $alias = array_filter($alias, fn($to, $from) => $from !== '' && $from !== $to, ARRAY_FILTER_USE_BOTH);
        if (!$alias) {
            return $data;
        }
        $walk = function ($v) use (&$walk, $markNamed, $alias) {
            if (is_string($v)) {
                return $alias[$v] ?? $v;
            }
            if (!is_array($v)) {
                return $v;
            }
            $out = [];
            foreach ($v as $k => $item) {
                $new = $walk($item);
                $out[is_string($k) ? ($alias[$k] ?? $k) : $k] = $new;
                if ($markNamed && $k === 'name' && is_string($item) && $new !== $item) {
                    $out['anon'] = true;
                }
            }
            return $out;
        };
        if (!is_array($data) || !$keep) {
            return $walk($data);
        }
        $out = [];
        foreach ($data as $k => $v) {
            if (in_array($k, $keep, true)) {
                $out[$k] = $v;
            } else {
                $out[is_string($k) ? ($alias[$k] ?? $k) : $k] = $walk($v);
            }
        }
        return $out;
    }
}
