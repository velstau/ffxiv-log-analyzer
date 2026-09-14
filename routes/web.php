<?php

use App\Http\Controllers\FFLogsController;
use App\Http\Controllers\IconController;
use App\Http\Controllers\PartySearchController;
use Illuminate\Support\Facades\Route;

// TOPは目次。各ツールはそれぞれの入口ページを持つ。
Route::view('/', 'home')->name('home');

// 軽減（1ログをタイムライン化して軽減率を見る）
Route::get('/mitigation', [FFLogsController::class, 'index'])->name('mitigation.form');
Route::post('/analyze', [FFLogsController::class, 'analyze'])->name('analyze');

// A/B比較（2ログをフェーズ別・分別に火力＆ローテ比較）
Route::get('/compare', [FFLogsController::class, 'compareForm'])->name('compare.form');
Route::post('/compare', [FFLogsController::class, 'compare'])->name('compare');
// グラフ上で選択した任意範囲を再集計（個人別rDPS内訳。AJAX・読み取りのみなのでGET）
Route::get('/compare/range', [FFLogsController::class, 'rangeStats'])->name('compare.range');
Route::get('/image-proxy', [IconController::class, 'proxyImage'])->name('proxy_image');

// PT構成検索（ジョブ構成を指定してランキングから一致ログを探す）
Route::get('/party-search', [PartySearchController::class, 'form'])->name('party_search.form');
Route::post('/party-search', [PartySearchController::class, 'search'])->name('party_search');
// プール取得の進捗確認（「準備中」画面がポーリングする。読み取りのみ）
Route::get('/party-search/status', [PartySearchController::class, 'status'])->name('party_search.status');

// ローカルアイコン配信（静的ファイルが無い場合のみ初回ダウンロードして永続保存。以降はnginxが直接配信）
Route::get('/icons/abilities/{file}', [IconController::class, 'abilityIcon'])->where('file', '[0-9\-]+\.png');
Route::get('/icons/jobs/{file}', [IconController::class, 'jobIcon'])->where('file', '[a-z0-9]+\.png');
