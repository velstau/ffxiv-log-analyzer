<?php

namespace App\Support;

/**
 * 軽減タイムライン／比較画面でジョブを表示するための情報（略称・ロール色・アイコン）。
 *
 * 実体は {@see FFXIVJobs} が持つ。ここは呼び出し側の見た目を変えずに残している薄い入口で、
 * ジョブを増やすときに直すのは FFXIVJobs だけでよい。
 */
class TimelineJobMeta
{
    /**
     * @return array{abbr:string, role:string, color:string, roleOrder:int, iconFile:string|null}
     */
    public static function of(string $type): array
    {
        return FFXIVJobs::timelineMeta($type);
    }
}
