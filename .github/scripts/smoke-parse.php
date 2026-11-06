<?php
/**
 * CI 冒烟测试：只验证「进程内调用链路 + 统一契约」没有被改坏，
 * 不发任何外部网络请求，因此不受平台风控影响，CI 里可以稳定通过。
 *
 * 用 php -S 起一个临时服务，逐个断言：
 *   - /api/health.php 返回 code=200，platforms 含 11 个平台
 *   - /api/health.php?platform=douyin 只返回该平台
 *   - /api/health.php?platform=notaplatform 返回 code=400
 *   - /api/parse.php 空参数返回 code=400
 *   - /api/parse.php 非公网地址返回 code=400
 *   - /api/parse.php 不支持的平台返回 code=201
 *   - /api/media.php 缺参数返回 code=400，内网地址返回 403
 *
 * 用法：php .github/scripts/smoke-parse.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$port = (int)(getenv('SMOKE_PORT') ?: 18099);
$base = 'http://127.0.0.1:' . $port;

$cmd = sprintf(
    '%s -S 127.0.0.1:%d -t %s >/dev/null 2>&1',
    escapeshellarg(PHP_BINARY),
    $port,
    escapeshellarg($root)
);
$server = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
if (!is_resource($server)) {
    fwrite(STDERR, "无法启动内置服务器\n");
    exit(1);
}

register_shutdown_function(static function () use ($server): void {
    $status = proc_get_status($server);
    if ($status['running']) {
        proc_terminate($server);
    }
    proc_close($server);
});

/** 等端口可连接，最多 10 秒。 */
function waitServer(string $base): bool
{
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        $fp = @fsockopen('127.0.0.1', (int)parse_url($base, PHP_URL_PORT), $errno, $errstr, 0.3);
        if ($fp !== false) {
            fclose($fp);
            return true;
        }
        usleep(150000);
    }
    return false;
}

if (!waitServer($base)) {
    fwrite(STDERR, "内置服务器启动超时\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function req(string $url, ?string $method = 'GET', ?string $body = null, int $timeout = 10): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    if ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw    = curl_exec($ch);
    $code   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, (string)$raw];
}

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function check(string $name, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "PASS  {$name}\n";
        return;
    }
    $fail++;
    echo "FAIL  {$name}\n";
    echo '      expected: ' . var_export($expected, true) . "\n";
    echo '      actual:   ' . var_export($actual, true) . "\n";
}

// ---- health.php ----
// 冷缓存时 health.php 会真实 HEAD 探测 11 个平台域名（并发，最坏约 6 秒），
// 超时给足；断言只看结构与业务码，不看 online 的取值。
[, $raw] = req($base . '/api/health.php', 'GET', null, 30);
$j = json_decode($raw, true);
check('health 顶层 code=200', $j['code'] ?? null, 200);
check('health platforms 数量=11', count($j['data']['platforms'] ?? []), 11);
check('health service=ok', $j['data']['service'] ?? null, 'ok');
check('health summary.total=11', $j['data']['summary']['total'] ?? null, 11);

$row = $j['data']['platforms'][0] ?? [];
check('health 单行含 platform/label/online', isset($row['platform'], $row['label'], $row['online']), true);

[, $raw] = req($base . '/api/health.php?platform=douyin');
$j = json_decode($raw, true);
check('health 单平台只返回 1 行', count($j['data']['platforms'] ?? []), 1);
check('health 单平台是 douyin', $j['data']['platforms'][0]['platform'] ?? null, 'douyin');

[, $raw] = req($base . '/api/health.php?platform=notaplatform');
$j = json_decode($raw, true);
check('health 未知平台 code=400', $j['code'] ?? null, 400);

// ---- parse.php ----
[, $raw] = req($base . '/api/parse.php');
$j = json_decode($raw, true);
check('parse 缺 url code=400', $j['code'] ?? null, 400);

[, $raw] = req($base . '/api/parse.php?url=' . rawurlencode('http://127.0.0.1/a.mp4'));
$j = json_decode($raw, true);
check('parse 回环地址 code=400', $j['code'] ?? null, 400);

[, $raw] = req($base . '/api/parse.php?url=' . rawurlencode('http://169.254.169.254/latest/meta-data/'));
$j = json_decode($raw, true);
check('parse 云元数据地址 code=400', $j['code'] ?? null, 400);

[, $raw] = req($base . '/api/parse.php?url=' . rawurlencode('https://example.com/video/123'));
$j = json_decode($raw, true);
check('parse 不支持平台 code=201', $j['code'] ?? null, 201);

[, $raw] = req($base . '/api/parse.php?url=' . rawurlencode('就这么一段没有链接的文字'));
$j = json_decode($raw, true);
check('parse 无链接文案 code=400', $j['code'] ?? null, 400);

[, $raw] = req(
    $base . '/api/parse.php',
    'POST',
    json_encode(['url' => 'https://example.com/video/123'], JSON_UNESCAPED_SLASHES)
);
$j = json_decode($raw, true);
check('parse JSON body 可用 code=201', $j['code'] ?? null, 201);

// ---- media.php ----
[$http, $raw] = req($base . '/api/media.php');
$j = json_decode($raw, true);
check('media 缺 url HTTP 400', $http, 400);
check('media 缺 url body code=400', $j['code'] ?? null, 400);

[$http, $raw] = req($base . '/api/media.php?url=' . rawurlencode('http://127.0.0.1/a.mp4'));
check('media 回环地址 HTTP 403', $http, 403);

[$http, $raw] = req($base . '/api/media.php?url=' . rawurlencode('ftp://example.com/a.mp4'));
check('media 非 http 协议 HTTP 400', $http, 400);

[$http, $raw] = req($base . '/api/media.php?url=' . rawurlencode('https://127.0.0.1:8080/a.mp4'));
check('media 非 80/443 端口 HTTP 400', $http, 400);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
