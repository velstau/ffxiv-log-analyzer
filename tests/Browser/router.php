<?php

// Development-only fixture server. Never include this file from public/index.php.
if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'testing') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$prefix = '/ff14tools/logs2timeline';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Match nginx's directory redirect when route() emits the base without a slash.
if ($path === $prefix) {
    header('Location: ' . $prefix . '/' . (isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    return;
}
$assets = [];
foreach (['css/logs-ui.css','css/legacy-layout.css','css/timeline.css','js/logs-ui.js','js/timeline.js','js/timeline-share.js','vendor/tabler/1.7.1/tabler.min.css','vendor/tabler/1.7.1/tabler.min.js'] as $asset) {
    $assets[$prefix . '/' . $asset] = [$asset, str_ends_with($asset, '.css') ? 'text/css' : 'text/javascript'];
}
if (preg_match('#^' . preg_quote($prefix, '#') . '/icons/(jobs/[a-z]+|abilities/[0-9\\-]+)\\.png$#', $path)) {
    $asset = substr($path, strlen($prefix) + 1);
    if (is_file($root . '/public/' . $asset)) {
        $assets[$path] = [$asset, 'image/png'];
    }
}

if (isset($assets[$path])) {
    header('Content-Type: ' . $assets[$path][1]);
    readfile($root . '/public/' . $assets[$path][0]);
    return;
}
if ($path === '/favicon.ico') {
    http_response_code(204);
    return;
}

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$fixtureRoot = storage_path('framework/testing/ui-browser');
foreach (['cache', 'sessions', 'private'] as $directory) {
    Illuminate\Support\Facades\File::ensureDirectoryExists($fixtureRoot . '/' . $directory);
}
config(['cache.default' => 'file', 'cache.stores.file.path' => $fixtureRoot . '/cache', 'cache.stores.file.lock_path' => $fixtureRoot . '/cache', 'session.driver' => 'file', 'session.files' => $fixtureRoot . '/sessions', 'filesystems.disks.local.root' => $fixtureRoot . '/private']);
Illuminate\Support\Facades\Http::preventStrayRequests();
require_once $root . '/tests/Fixtures/UiData.php';
config(['services.fflogs.allow_server_credentials' => false]);
$mock = Mockery::mock(App\Services\FFLogsService::class)->makePartial();
$mock->shouldReceive('getZoneTree')->andReturn(Tests\Fixtures\UiData::zones());
$app->instance(App\Services\FFLogsService::class, $mock);
Illuminate\Support\Facades\Route::middleware('web')->get('/__ui/timeline', function (Illuminate\Http\Request $request) {
    $data = Tests\Fixtures\UiData::timeline(request('phase', '1') === '1');
    $data['shareDraft'] = app(App\Services\TimelineShareStore::class)->draft($data, App\Http\Controllers\TimelineShareController::owner($request));
    return redirect()->route('timeline-results.show', $data['shareDraft']);
});
Illuminate\Support\Facades\Route::middleware('web')->get('/__ui/compare', fn() => view('fflogs.compare', Tests\Fixtures\UiData::compare()));

// Exercise the same base path as the nginx deployment, including assets/cookies.
$_SERVER['SCRIPT_NAME'] = $prefix . '/index.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $root . '/public/index.php';
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
