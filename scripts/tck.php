<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$queued = getenv('A2A_QUEUE') === '1';
$store = sys_get_temp_dir().'/a2a-laravel-tck-'.($queued ? 'queued' : 'sync').'.json';
@unlink($store);

$env = [
    'PHP_CLI_SERVER_WORKERS=16',
    'A2A_QUEUE='.($queued ? '1' : '0'),
    'A2A_STORE=file',
    'A2A_FILE_STORE='.escapeshellarg($store),
    'A2A_BASE_URL=http://127.0.0.1:9999',
    'APP_KEY=base64:'.base64_encode(str_repeat('a', 32)),
];

$log = sys_get_temp_dir().'/a2a-laravel-tck-server.log';
$router = $root.'/vendor/orchestra/testbench-core/laravel/public/index.php';
$serve = implode(' ', [
    ...$env,
    'TESTBENCH_WORKING_PATH='.escapeshellarg($root),
    'php -S 127.0.0.1:9999',
    escapeshellarg($router),
]);
$pid = (int) trim((string) shell_exec($serve.' > '.escapeshellarg($log).' 2>&1 & echo $!'));

if ($pid < 1) {
    fwrite(STDERR, "Failed to start the workbench server.\n");
    exit(1);
}

$ready = false;

for ($i = 0; $i < 40; $i++) {
    $card = @file_get_contents('http://127.0.0.1:9999/.well-known/agent-card.json');

    if (is_string($card) && str_contains($card, 'TCK Laravel Agent')) {
        $ready = true;
        break;
    }

    usleep(250_000);
}

if (! $ready) {
    fwrite(STDERR, "Workbench server failed to start.\n");
    fwrite(STDERR, (string) @file_get_contents($log));
    posix_kill($pid, SIGTERM);
    shell_exec('lsof -tiTCP:9999 -sTCP:LISTEN 2>/dev/null | xargs kill -9 2>/dev/null');

    exit(1);
}

$tck = getenv('A2A_TCK_DIR') ?: '/tmp/a2a-tck';

if (! is_dir($tck)) {
    passthru('git clone --depth 1 https://github.com/a2aproject/a2a-tck.git '.escapeshellarg($tck), $clone);

    if ($clone !== 0) {
        posix_kill($pid, SIGTERM);

        exit($clone);
    }
}

fwrite(STDOUT, $queued ? "Running A2A TCK (queued)\n" : "Running A2A TCK (sync)\n");

$prepare = 'cd '.escapeshellarg($tck).' && uv venv --allow-existing && uv pip install -e .';
passthru($prepare, $prepareStatus);

if ($prepareStatus !== 0) {
    posix_kill($pid, SIGTERM);
    shell_exec('lsof -tiTCP:9999 -sTCP:LISTEN 2>/dev/null | xargs kill -9 2>/dev/null');

    exit($prepareStatus);
}

$run = 'cd '.escapeshellarg($tck).' && uv run ./run_tck.py --sut-host http://127.0.0.1:9999 --transport jsonrpc,http_json --level ';
passthru($run.'must', $mustStatus);
passthru($run.'should', $shouldStatus);

posix_kill($pid, SIGTERM);
shell_exec('lsof -tiTCP:9999 -sTCP:LISTEN 2>/dev/null | xargs kill -9 2>/dev/null');

exit($mustStatus !== 0 ? $mustStatus : $shouldStatus);
