<?php

namespace App\Support;

/**
 * FFLogs の damage イベントを「実被弾 / 生ダメージ / 軽減率」に分解する。
 *
 * 軽減タイムラインと被弾内訳モーダルの両方がこの分解に依存しているので、
 * 計算の根拠（各フィールドの意味と按分の理由）は of() の docblock に置いてある。
 */
class DamageBreakdown
{
    /**
     * 被弾イベントを「実被弾 / 生ダメージ / 軽減率」に分解する。
     *
     * FFLogs の damage イベントの各フィールドの意味：
     *   amount            … 実際にHPが減った量。死亡時は残HPで頭打ちになる
     *   overkill          … 残HPを超えて切り捨てられた分（死亡時のみ）
     *   absorbed          … バリアが吸った分（HPには入らない）
     *   unmitigatedAmount … 軽減前のダメージ。バリアが吸った分も含む
     *   multiplier        … 軽減倍率。unmitigatedAmount × multiplier = amount + absorbed + overkill
     *
     * amount をそのまま被弾量として使うと、バリア吸収分とオーバーキル分が欠けるため、
     * 軽減率が「軽減 + バリア + オーバーキル切り捨て」の混合値に化ける
     * （例: 実軽減15%の一撃が -56% と表示される）。
     * ここでは実被弾 = amount + overkill（＝バリアを抜けてHPに向かった量。FFLogs の
     * calculateddamage の表示値と一致する）に揃え、生ダメージもそれに対応する値へ換算する。
     *
     * 返り値の関係（この順に一直線でつながる）：
     *   unmit_full  −軽減−→  total  −バリア吸収−→  effective（＝HPに向かった量）
     *   unmit_full - (unmit_full × 軽減率) - absorbed = effective
     *
     * base は「バリアが無かったものとして按分し直した生ダメージ」で、base → effective の比が
     * 軽減率そのものになる。Base Dmg 列と軽減率の表示にはこちらを使う。
     *
     * @return array{effective:int, hp_lost:int, absorbed:int, overkill:int, base:int, rate:int, total:int, unmit_full:int}
     */
    public static function of(array $event): array
    {
        $hpLost   = (int) ($event['amount'] ?? 0);
        $absorbed = (int) ($event['absorbed'] ?? 0);
        $overkill = (int) ($event['overkill'] ?? 0);

        $effective = $hpLost + $overkill;               // 実被弾（HPに向かった量）
        $total     = $effective + $absorbed;            // 軽減後の総被弾（バリアが吸う前）

        $unmit = $event['unmitigatedAmount'] ?? null;
        $mult  = $event['multiplier'] ?? null;

        if ($unmit !== null && $total > 0) {
            // unmitigatedAmount はバリア吸収分を含むので、実被弾の比率で按分して除く。
            // multiplier は小数2桁に丸められているため、こちらの方が誤差が出ない。
            $base = (int) round($unmit * $effective / $total);
        } elseif ($mult !== null && $mult > 0) {
            $base = (int) round($effective / $mult);
        } else {
            // DoT tick 等、生ダメージ情報が無いイベントは軽減率を出さない
            $base = $effective;
        }

        // ダメージ上昇デバフ等で base < effective になった場合は軽減率0扱いにする
        if ($base < $effective) {
            $base = $effective;
        }

        // 素のダメージ（軽減前・バリア吸収前）。バリアが吸った分もここには含まれる。
        if ($unmit !== null) {
            $unmitFull = (int) $unmit;
        } elseif ($mult !== null && $mult > 0) {
            $unmitFull = (int) round($total / $mult);
        } else {
            $unmitFull = $total;
        }
        if ($unmitFull < $total) {
            $unmitFull = $total;
        }

        return [
            'effective'  => $effective,
            'total'      => $total,
            'unmit_full' => $unmitFull,
            'hp_lost'   => $hpLost,
            'absorbed'  => $absorbed,
            'overkill'  => $overkill,
            'base'      => $base,
            'rate'      => $base > $effective
                ? (int) round((1 - $effective / $base) * 100)
                : 0,
        ];
    }
}
