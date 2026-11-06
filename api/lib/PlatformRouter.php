<?php
/**
 * 平台识别 + 进程内调用各平台入口。
 *
 * 不依赖 HTTP 自请求：chdir 到入口所在目录后 include 入口文件，
 * 用输出缓冲截获其 JSON；若入口内部调用了 exit（部分平台错误分支会这样），
 * 由 shutdown 钩子接管并输出归一化结果。
 */

declare(strict_types=1);

require_once __DIR__ . '/Normalizer.php';

final class PlatformRouter
{
    /**
     * 平台配置：关键词按顺序匹配，先匹配到先返回。
     * entry 为相对 api/ 的入口文件。
     */
    private const PLATFORMS = [
        'douyin' => [
            'entry'    => 'douyin/douyin.php',
            'keywords' => ['v.douyin.com', 'douyin.com', 'iesdouyin.com'],
            'probe'    => 'https://v.douyin.com/',
        ],
        'kuaishou' => [
            'entry'    => 'kuaishou/ksjx.php',
            'keywords' => ['kuaishou.com', 'gifshow.com', 'chenzhongtech.com', 'kuaishouapp.com'],
            'probe'    => 'https://v.kuaishou.com/',
        ],
        'xiaohongshu' => [
            'entry'    => 'xiaohongshu/xhsjx.php',
            'keywords' => ['xiaohongshu.com', 'xhslink.com', 'xhs.com', 'xhscdn.com'],
            'probe'    => 'https://www.xiaohongshu.com/',
        ],
        'bilibili' => [
            'entry'    => 'bilibili/index.php',
            'keywords' => ['bilibili.com', 'b23.tv', 'bili2233.cn'],
            'probe'    => 'https://www.bilibili.com/',
        ],
        'weibo' => [
            'entry'    => 'weibo.php',
            'keywords' => ['weibo.com', 'weibo.cn', 'weibocdn.com', 't.cn'],
            'probe'    => 'https://m.weibo.cn/',
        ],
        'toutiao' => [
            'entry'    => 'toutiao.php',
            'keywords' => ['toutiao.com', 'toutiaoimg.com', 'toutiaovod.com'],
            'probe'    => 'https://www.toutiao.com/',
        ],
        'ppxia' => [
            'entry'    => 'ppxia.php',
            'keywords' => ['pipix.com'],
            'probe'    => 'https://h5.pipix.com/',
        ],
        'pipigx' => [
            'entry'    => 'pipigx.php',
            'keywords' => ['ippzone.com', 'pipigx.com'],
            'probe'    => 'https://h5.ippzone.com/',
        ],
        'zuiyou' => [
            'entry'    => 'zuiyou.php',
            'keywords' => ['izuiyou.com', 'xiaochuankeji.cn', 'zuiyou.com'],
            'probe'    => 'https://www.izuiyou.com/',
        ],
        'doubao' => [
            'entry'    => 'doubao/video/api.php',
            'keywords' => ['doubao.com'],
            'probe'    => 'https://www.doubao.com/',
        ],
        'jimeng' => [
            'entry'    => 'jimengai/index.php',
            'keywords' => ['jimeng.jianying.com', 'jimeng.com'],
            'probe'    => 'https://jimeng.jianying.com/',
        ],
    ];

    /** 平台中文名（与 index.html 的 PLATFORMS 保持一致）。 */
    private const LABELS = [
        'douyin'      => '抖音',
        'kuaishou'    => '快手',
        'xiaohongshu' => '小红书',
        'bilibili'    => '哔哩哔哩',
        'weibo'       => '微博',
        'toutiao'     => '今日头条',
        'ppxia'       => '皮皮虾',
        'pipigx'      => '皮皮搞笑',
        'zuiyou'      => '最右',
        'doubao'      => '豆包 AI',
        'jimeng'      => '即梦 AI',
    ];

    /**
     * 平台清单（供健康检查等只读用途）。
     *
     * @return array<string, array{entry:string,keywords:array<int,string>,probe:string}>
     */
    public static function platformMap(): array
    {
        return self::PLATFORMS;
    }

    /** 平台中文名。 */
    public static function label(string $platform): string
    {
        return self::LABELS[$platform] ?? $platform;
    }

    /** 入口文件的绝对路径；不存在时返回 null。 */
    public static function entryPath(string $platform): ?string
    {
        if (!isset(self::PLATFORMS[$platform])) {
            return null;
        }
        $path = self::API_DIR . '/' . self::PLATFORMS[$platform]['entry'];
        return is_file($path) ? $path : null;
    }

    /** 最近一次真实解析结果的记录文件。 */
    private static function lastResultFile(): string
    {
        return sys_get_temp_dir() . '/short_videos_last_result.json';
    }

    /**
     * 记录某平台最近一次真实解析的业务码。
     *
     * 健康检查里「域名可达」只能说明链路通，真正决定能不能用的是解析结果：
     * 平台反爬、风控升级都会让域名照常返回 200 而解析持续失败。
     */
    public static function recordResult(string $platform, int $code): void
    {
        if ($platform === '') {
            return;
        }

        $file = self::lastResultFile();
        $map  = self::lastResults();

        $map[$platform] = ['code' => $code, 'time' => time()];

        // 只保留已知平台，避免异常输入把文件撑大
        $map = array_intersect_key($map, self::PLATFORMS);

        @file_put_contents(
            $file,
            json_encode($map, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /**
     * 各平台最近一次解析记录。
     *
     * @return array<string, array{code:int,time:int}>
     */
    public static function lastResults(): array
    {
        $file = self::lastResultFile();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $platform => $row) {
            if (!is_string($platform) || !is_array($row) || !isset($row['code'])) {
                continue;
            }
            $out[$platform] = [
                'code' => (int)$row['code'],
                'time' => (int)($row['time'] ?? 0),
            ];
        }
        return $out;
    }

    private const API_DIR = __DIR__ . '/..';

    /** @var array{platform:string,entry:string}|null 正在执行、尚未收尾的上下文 */
    private static $pending = null;

    /**
     * 读取 url 参数：GET/POST/JSON body 皆可，兼容未编码的整段分享文案。
     */
    public static function readInput(): string
    {
        $input = '';

        if (isset($_GET['url']) && is_string($_GET['url'])) {
            $input = $_GET['url'];
        } elseif (isset($_POST['url']) && is_string($_POST['url'])) {
            $input = $_POST['url'];
        } else {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $json = json_decode($raw, true);
                if (is_array($json) && isset($json['url']) && is_string($json['url'])) {
                    $input = $json['url'];
                } elseif (strpos($raw, 'url=') !== false) {
                    parse_str($raw, $parsed);
                    $input = isset($parsed['url']) && is_string($parsed['url']) ? $parsed['url'] : '';
                } else {
                    $input = $raw;
                }
            }
        }

        // 兼容 GBK/UTF-8 混杂的分享文案：非法 UTF-8 时按 GB18030 转码
        if ($input !== '' && !preg_match('//u', $input)) {
            $converted = @iconv('GB18030', 'UTF-8//IGNORE', $input);
            if (is_string($converted) && $converted !== '') {
                $input = $converted;
            }
        }

        return trim($input);
    }

    /**
     * 从任意文本里取第一个 http(s) 链接。
     */
    public static function extractUrl(string $text): ?string
    {
        if (preg_match('/https?:\/\/[^\s"\'<>（）()【】]+/iu', $text, $m)) {
            return rtrim($m[0], '\'"，。；,.;：:！!？?）)】]');
        }
        return null;
    }

    /**
     * 匹配平台；返回平台标识或 null。
     */
    public static function detectPlatform(string $text): ?string
    {
        $lower = strtolower($text);
        foreach (self::PLATFORMS as $name => $conf) {
            foreach ($conf['keywords'] as $kw) {
                if (strpos($lower, $kw) !== false) {
                    return $name;
                }
            }
        }
        return null;
    }

    /**
     * 处理一次解析请求，返回统一结构。
     */
    public function handle(string $input): array
    {
        $url = self::extractUrl($input);
        if ($url === null) {
            return $this->fail(400, '没有识别到有效链接，请粘贴包含 http(s) 链接的分享文案');
        }

        if (!self::isAllowedUrl($url)) {
            return $this->fail(400, '该链接不可解析：仅支持公网 http(s) 地址');
        }

        $platform = self::detectPlatform($url);
        if ($platform === null) {
            return $this->fail(201, '暂不支持该平台的链接');
        }

        $raw = $this->runEntry($platform, $url);
        if ($raw === null) {
            self::recordResult($platform, 500);
            return $this->fail(500, '解析接口没有返回有效数据');
        }

        $result = Normalizer::normalize($raw, $platform);
        self::recordResult($platform, (int)($result['code'] ?? 500));

        return $result;
    }

    /**
     * 直接输出错误 JSON 并结束。
     */
    public static function fail(int $code, string $msg): array
    {
        return [
            'code'           => $code,
            'msg'            => $msg,
            'data'           => [],
            'platform'       => '',
            'platform_label' => '',
        ];
    }

    /**
     * include 入口并收集其 JSON 输出。
     */
    private function runEntry(string $platform, string $url): ?array
    {
        $entry = self::API_DIR . '/' . self::PLATFORMS[$platform]['entry'];
        if (!is_file($entry)) {
            return null;
        }

        $cwd = getcwd();
        self::$pending = ['platform' => $platform];
        register_shutdown_function([self::class, 'finalizeOnShutdown']);

        // 入口文件内使用相对路径 require（如 require_once 'DouyinParser.php'），
        // 需要把工作目录切到入口所在目录。
        @chdir(dirname($entry));

        // 部分入口从 REQUEST_URI / QUERY_STRING 里截取 url，模拟一个带参请求
        $_SERVER['REQUEST_URI'] = '/?url=' . rawurlencode($url);
        $_SERVER['QUERY_STRING'] = 'url=' . rawurlencode($url);
        $_GET['url'] = $url;
        $_REQUEST['url'] = $url;
        // 仅 POST 请求才填充 $_POST，避免误导按请求方法分支的入口
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($_POST['url'])) {
            $_POST['url'] = $url;
        }

        ob_start();
        $buffer = '';
        try {
            include $entry;
            $buffer = (string)ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > 0) {
                $buffer = (string)ob_get_clean();
                break;
            }
            error_log('[short_videos] ' . $platform . ' 解析异常: ' . $e->getMessage());
        } finally {
            if ($cwd !== false) {
                @chdir($cwd);
            }
            self::$pending = null;
        }

        return $this->decode($buffer);
    }

    /**
     * 入口内部 exit 时接管输出。
     */
    public static function finalizeOnShutdown(): void
    {
        if (self::$pending === null) {
            return;
        }

        $platform = self::$pending['platform'];
        self::$pending = null;

        $buffer = '';
        while (ob_get_level() > 0) {
            $chunk = ob_get_clean();
            $buffer = (string)$chunk . $buffer;
        }

        $raw = self::decodeStatic($buffer);
        $result = $raw === null
            ? self::fail(500, '解析失败，接口未返回有效数据')
            : Normalizer::normalize($raw, $platform);

        self::recordResult($platform, (int)($result['code'] ?? 500));

        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decode(string $buffer): ?array
    {
        return self::decodeStatic($buffer);
    }

    /**
     * 容忍入口在 JSON 前后输出警告/提示文本，取最后一个完整 JSON 对象。
     */
    private static function decodeStatic(string $buffer): ?array
    {
        $buffer = trim($buffer);
        if ($buffer === '') {
            return null;
        }

        $decoded = json_decode($buffer, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 截取首个 { 到最后一个 }，跳过前后杂讯（如 PHP 警告）
        $start = strpos($buffer, '{');
        $end = strrpos($buffer, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $slice = substr($buffer, $start, $end - $start + 1);
            $decoded = json_decode($slice, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * 仅允许公网 http(s)：拒绝私网、回环、链路本地（含云元数据地址）。
     */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool)filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        $ip = gethostbyname($host);
        if ($ip === $host) {
            // 解析失败：交给解析器按原样处理（部分短链需要平台侧改写 host）
            return true;
        }
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
