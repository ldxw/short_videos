<?php
/**
 * 媒体代理 / 下载转发
 *
 * 用途：解析出来的直链常带 Referer 校验，浏览器直接播放或下载会 403；
 * 本接口带平台 Referer 转发，并支持 Range（拖动进度条）与下载文件名。
 *
 * 用法：
 *   GET /api/media.php?url=<原始直链>&platform=douyin&download=1&name=标题.mp4
 *   GET /api/media.php?url=<原始直链>&download=0          （播放/预览，支持 Range）
 *
 * 安全：
 *   - 仅 http/https，端口限定 80/443；
 *   - 目标域名必须解析到公网 IP（拒绝内网、回环、169.254 云元数据）；
 *   - 域名后缀白名单（$allowedSuffixes）；
 *   - 校验后固定 DNS（CURLOPT_RESOLVE），防 DNS 重绑定。
 */

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Content-Type');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges, Content-Disposition');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * 允许代理的目标域名后缀（后缀匹配）。留空 = 不限制（仍强制公网校验）。
 * 公开部署建议保留默认清单，只追加自己需要的 CDN。
 */
$allowedSuffixes = [
    // 抖音 / 字节
    'douyinvod.com', 'douyinpic.com', 'douyinstatic.com', 'douyin.com', 'iesdouyin.com',
    'byteimg.com', 'bytecdn.cn', 'bytedance.com', 'snssdk.com', 'amemv.com', 'pstatp.com',
    'ixigua.com', 'toutiaoimg.com', 'toutiaovod.com', 'toutiao.com', 'zjcdn.com', 'pddpic.com',
    // 快手
    'kwimgs.com', 'yximgs.com', 'kwaicdn.com', 'gifshow.com', 'kuaishou.com', 'chenzhongtech.com',
    // 小红书
    'xhscdn.com', 'xiaohongshu.com', 'xhslink.com',
    // B 站
    'bilivideo.com', 'bilivideo.cn', 'hdslb.com', 'bilibili.com', 'akamaized.net',
    // 微博
    'weibocdn.com', 'sinaimg.cn', 'weibo.com', 'weibo.cn', 'sinajs.cn',
    // 皮皮虾 / 皮皮搞笑 / 最右
    'pipix.com', 'ippzone.com', 'izuiyou.com', 'xiaochuankeji.cn', 'zuiyou.com',
    // 豆包 / 即梦
    'doubao.com', 'bytecdntp.com', 'jianying.com', 'jimeng.com', 'capcut.com',
    // 仓库自带微博中转代理
    '168299.xyz',
    // 微信生态常见 CDN
    'qpic.cn', 'gtimg.cn', 'weixin.qq.com',
];

$platformReferers = [
    'douyin'      => 'https://www.douyin.com/',
    'kuaishou'    => 'https://www.kuaishou.com/',
    'xiaohongshu' => 'https://www.xiaohongshu.com/',
    'bilibili'    => 'https://www.bilibili.com/',
    'weibo'       => 'https://weibo.com/',
    'toutiao'     => 'https://www.toutiao.com/',
    'ppxia'       => 'https://h5.pipix.com/',
    'pipigx'      => 'https://h5.pipigx.com/',
    'zuiyou'      => 'https://share.xiaochuankeji.cn/',
    'doubao'      => 'https://www.doubao.com/',
    'jimeng'      => 'https://jimeng.jianying.com/',
];

function bail(int $httpCode, string $msg): void
{
    if (headers_sent()) {
        exit;
    }
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $httpCode, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitizeFilename(string $name, string $contentType, string $url): string
{
    $name = trim($name);
    if ($name === '') {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $name = basename($path);
    }
    $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/u', '', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    $name = trim($name);

    if ($name === '') {
        $name = 'media-' . substr(md5($url), 0, 8) . '.' . extensionFromType($contentType);
    } elseif (pathinfo($name, PATHINFO_EXTENSION) === '') {
        $name .= '.' . extensionFromType($contentType);
    }

    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 80, 'UTF-8');
    } else {
        $name = substr($name, 0, 80);
    }

    return $name;
}

function extensionFromType(string $contentType): string
{
    $map = [
        'video/mp4'       => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm'      => 'webm',
        'video/x-m4v'     => 'm4v',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
        'audio/mpeg'      => 'mp3',
        'audio/mp4'       => 'm4a',
        'audio/aac'       => 'aac',
    ];
    foreach ($map as $type => $ext) {
        if (stripos($contentType, $type) !== false) {
            return $ext;
        }
    }
    return 'bin';
}

$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
if ($url === '') {
    bail(400, '缺少 url 参数');
}

$platform = isset($_GET['platform']) ? strtolower(trim((string)$_GET['platform'])) : '';
$download = isset($_GET['download']) && in_array((string)$_GET['download'], ['1', 'true', 'yes'], true);
$name = isset($_GET['name']) ? (string)$_GET['name'] : '';

$parts = parse_url($url);
if (!is_array($parts)) {
    bail(400, 'url 格式不正确');
}

$scheme = strtolower($parts['scheme'] ?? '');
if (!in_array($scheme, ['http', 'https'], true)) {
    bail(400, '仅支持 http/https 地址');
}

$host = strtolower($parts['host'] ?? '');
if ($host === '' || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
    bail(400, '目标域名不合法');
}

$port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
if (!in_array($port, [80, 443], true)) {
    bail(400, '仅支持 80/443 端口');
}

if ($allowedSuffixes !== []) {
    $ok = false;
    foreach ($allowedSuffixes as $suffix) {
        $suffix = strtolower($suffix);
        if ($host === $suffix || substr($host, -(strlen($suffix) + 1)) === '.' . $suffix) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        bail(403, '该域名不在代理白名单内');
    }
}

$ip = gethostbyname($host);
if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
    bail(502, '目标域名解析失败');
}
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    bail(403, '拒绝代理内网 / 保留地址');
}

$referer = $platformReferers[$platform] ?? 'https://www.douyin.com/';

$requestHeaders = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
    'Accept: video/mp4,video/*;q=0.9,image/avif,image/webp,image/*,*/*;q=0.8',
    'Accept-Language: zh-CN,zh;q=0.9',
    'Referer: ' . $referer,
    'Connection: keep-alive',
];
$clientRange = isset($_SERVER['HTTP_RANGE']) ? trim((string)$_SERVER['HTTP_RANGE']) : '';
if ($clientRange !== '') {
    $requestHeaders[] = 'Range: ' . $clientRange;
}

$isHead = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');

$responseHeaders = [];
$headersSent = false;
$statusCode = 200;
$forwardHeaders = ['content-type', 'content-length', 'content-range', 'accept-ranges', 'last-modified', 'etag'];

/**
 * 真正的上游响应头解析完成后才下发；body 的第一个数据块到达时调用。
 */
$sendHeaders = function () use (&$responseHeaders, &$headersSent, &$statusCode, $forwardHeaders, $download, $name, $url) {
    if ($headersSent) {
        return;
    }
    $headersSent = true;

    if (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (headers_sent()) {
        return;
    }

    $upstreamCode = $statusCode;
    if ($upstreamCode >= 400) {
        http_response_code($upstreamCode);
    } else {
        http_response_code($upstreamCode === 206 ? 206 : 200);
    }

    $contentType = $responseHeaders['content-type'] ?? 'application/octet-stream';
    header('Content-Type: ' . $contentType);

    foreach ($forwardHeaders as $key) {
        if ($key === 'content-type' || !isset($responseHeaders[$key])) {
            continue;
        }
        header($key . ': ' . $responseHeaders[$key]);
    }

    if ($download) {
        $filename = sanitizeFilename($name, $contentType, $url);
        header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    }
};

$ch = curl_init($url);
if ($ch === false) {
    bail(500, 'cURL 初始化失败');
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 600,
    CURLOPT_LOW_SPEED_LIMIT => 1024,
    CURLOPT_LOW_SPEED_TIME  => 30,
    CURLOPT_HTTPHEADER     => $requestHeaders,
    CURLOPT_RESOLVE        => ["{$host}:{$port}:{$ip}"],
    CURLOPT_NOBODY         => $isHead,
    CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$responseHeaders, &$statusCode) {
        $len = strlen($header);
        if (stripos($header, 'HTTP/') === 0) {
            // 重定向后保留最后一跳的响应头；状态码直接从状态行解析
            $responseHeaders = [];
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#i', $header, $m)) {
                $statusCode = (int)$m[1];
            }
            return $len;
        }
        $pair = explode(':', $header, 2);
        if (count($pair) === 2) {
            $responseHeaders[strtolower(trim($pair[0]))] = trim($pair[1]);
        }
        return $len;
    },
    CURLOPT_WRITEFUNCTION  => function ($curl, $data) use ($sendHeaders) {
        $sendHeaders();
        echo $data;
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
        return strlen($data);
    },
]);

$ok = curl_exec($ch);
$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
$finalCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if (!$headersSent) {
    $statusCode = $finalCode > 0 ? $finalCode : 502;
    $sendHeaders();
}

if ($curlErrno !== 0 && !$headersSent) {
    bail(502, '转发失败: ' . $curlError);
}

exit;
