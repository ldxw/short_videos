<?php
/**
 * 接口健康检查
 *
 * 返回本机解析入口是否就绪，以及各平台上游客端是否可达。
 * 「可达」只表示该平台域名能正常握手返回，不代表某个具体链接一定能解析成功
 * —— 平台侧反爬、链接失效、内容删除都会让单条解析失败，这与接口本身可用性无关。
 *
 * 用法：
 *   GET /api/health.php            返回全部平台状态
 *   GET /api/health.php?platform=  douyin   只检查指定平台
 *
 * 结果带 60 秒服务端缓存，避免前端每次刷新都向上游发请求。
 */

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

@set_time_limit(30);

require_once __DIR__ . '/lib/PlatformRouter.php';

const HEALTH_CACHE_TTL = 60;
const HEALTH_CONNECT_TIMEOUT = 4;
const HEALTH_TIMEOUT = 6;

/**
 * 单行探测结果的骨架。
 */
function blankRow(string $platform): array
{
    return [
        'platform' => $platform,
        'label'    => PlatformRouter::label($platform),
        'entry'    => PlatformRouter::entryPath($platform) !== null,
        'online'   => false,
        'latency'  => null,
        'status'   => null,
        'reason'   => '',
    ];
}

/**
 * 并发探测一批平台：入口文件是否在位 + 平台域名是否可达。
 *
 * 只用 HEAD 拿状态码，不解析内容；对 403/404 这类「服务活着但不想理你」的响应
 * 也算可达——能拿到 HTTP 响应本身就说明链路是通的。
 *
 * 串行探测 11 个平台，最坏要 11×6 秒，既顶到脚本自身的 30 秒上限，也会让页面首屏
 * 一直等健康数据；curl_multi 之后总耗时接近最慢的那一个平台。
 *
 * @param list<string> $platforms
 * @return list<array> 顺序与入参一致
 */
function probePlatforms(array $platforms): array
{
    $rows    = [];
    $handles = [];

    $map       = PlatformRouter::platformMap();
    $curlReady = function_exists('curl_init');

    foreach ($platforms as $platform) {
        $row = blankRow($platform);

        if (!$row['entry']) {
            $row['reason'] = '入口文件缺失';
        } elseif (!$curlReady) {
            $row['reason'] = '服务器未启用 curl 扩展';
        } else {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $map[$platform]['probe'],
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => HEALTH_CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => HEALTH_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) '
                    . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
                CURLOPT_HTTPHEADER     => ['Accept-Language: zh-CN,zh;q=0.9'],
            ]);
            $handles[$platform] = $ch;
        }

        $rows[$platform] = $row;
    }

    if ($handles !== []) {
        $multi = curl_multi_init();
        foreach ($handles as $ch) {
            curl_multi_add_handle($multi, $ch);
        }

        do {
            $state = curl_multi_exec($multi, $running);
            if ($running) {
                // 等网络活动，不忙轮询
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $state === CURLM_OK);

        foreach ($handles as $platform => $ch) {
            $errno  = curl_errno($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            // 并发下整体耗时没有参考价值，取每个句柄自己的传输时长
            $elapsed = (int)round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            $rows[$platform]['latency'] = $elapsed;
            $rows[$platform]['status']  = $status > 0 ? $status : null;

            if ($errno !== 0) {
                // DNS 失败 / 连接超时 / TLS 失败都属于链路不通
                $rows[$platform]['reason'] = $errno === CURLE_OPERATION_TIMEDOUT ? '连接超时' : '连接失败';
            } elseif ($status >= 500) {
                // 5xx 视为平台侧故障；其余状态码都说明链路可达
                $rows[$platform]['reason'] = '平台返回 ' . $status;
            } elseif ($status === 0) {
                $rows[$platform]['reason'] = '未收到响应';
            } else {
                $rows[$platform]['online'] = true;
            }
        }

        curl_multi_close($multi);
    }

    return array_values($rows);
}

/**
 * 读取缓存：文件缓存，随进程失效自动重建。
 */
function readCache(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['time'])) {
        return null;
    }
    if (time() - (int)$data['time'] > HEALTH_CACHE_TTL) {
        return null;
    }
    return $data;
}

function writeCache(string $file, array $data): void
{
    // 缓存只是加速手段，写不进去不影响结果
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

$wanted = '';
if (isset($_GET['platform']) && is_string($_GET['platform'])) {
    $wanted = trim($_GET['platform']);
}

$all = PlatformRouter::platformMap();
if ($wanted !== '' && !isset($all[$wanted])) {
    echo json_encode([
        'code' => 400,
        'msg'  => '未知平台：' . $wanted,
        'data' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$cacheFile = sys_get_temp_dir() . '/short_videos_health.json';
$cached = readCache($cacheFile);

$list = [];

if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
    foreach ($cached['data'] as $row) {
        if (!is_array($row) || !isset($row['platform'])) {
            continue;
        }
        if ($wanted === '' || $row['platform'] === $wanted) {
            $list[] = $row;
        }
    }
    $fromCache = true;
} else {
    $fromCache = false;
    $targets   = $wanted === '' ? array_keys($all) : [$wanted];
    $list      = probePlatforms($targets);
    if ($wanted === '') {
        writeCache($cacheFile, ['time' => time(), 'data' => $list]);
    }
}

// 叠加最近一次真实解析的结果：域名可达不等于能解析，平台风控会让两者背离
$last = PlatformRouter::lastResults();
foreach ($list as &$row) {
    $rec = $last[$row['platform']] ?? null;
    $row['last'] = $rec === null ? null : [
        'code' => $rec['code'],
        'time' => $rec['time'] > 0 ? date('c', $rec['time']) : null,
        // 200 成功 / 400 链接无效 / 404 内容不存在 / 500 上游失败
        'ok'   => $rec['code'] === 200,
    ];
}
unset($row);

$onlineCount = 0;
foreach ($list as $row) {
    if (!empty($row['online'])) {
        $onlineCount++;
    }
}

// 本机服务自检：入口文件都在、curl 可用，才算「接口就绪」
$missing = [];
foreach (array_keys($all) as $platform) {
    if (PlatformRouter::entryPath($platform) === null) {
        $missing[] = $platform;
    }
}

echo json_encode([
    'code' => 200,
    'msg'  => 'ok',
    'data' => [
        'service'   => empty($missing) && function_exists('curl_init') ? 'ok' : 'degraded',
        'missing'   => $missing,
        'cached'    => $fromCache,
        'checkedAt' => $fromCache ? date('c', (int)$cached['time']) : date('c'),
        'summary'   => ['online' => $onlineCount, 'total' => count($list)],
        'platforms' => $list,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
