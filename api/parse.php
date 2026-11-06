<?php
/**
 * 聚合解析接口（页面专用）
 *
 * 与 api/sv.php 的差异：
 *  - sv.php 依赖 HTTP 自请求 + 需配置本站域名，纯 CLI / 内网 / 反代环境下不可用；
 *  - 本接口直接 require 各平台解析类在进程内调用，无需自请求，也不需要 Host 白名单；
 *  - 输出统一为 docs/视频解析统一JSON格式3.0.md 约定的结构，缺失字段补空值，
 *    并把各平台的“非标准字段”（如 kuaishou 的 author/avatar、bilibili 的 videos）归一化。
 *
 * 用法：
 *   GET  /api/parse.php?url=<分享链接或整段分享文案>
 *   POST /api/parse.php   body: {"url":"..."} 或 url=...
 */

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

@set_time_limit(120);

// 契约要求 publish_time 为东八区，不依赖服务器默认时区
date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/lib/Normalizer.php';
require_once __DIR__ . '/lib/PlatformRouter.php';

$raw = PlatformRouter::readInput();
if ($raw === '') {
    PlatformRouter::fail(400, '请输入视频链接或分享文案');
}

$router = new PlatformRouter();
$result = $router->handle($raw);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
