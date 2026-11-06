<?php
/**
 * 统一响应归一化：把各平台解析结果映射到《视频解析统一 JSON 格式 3.0》。
 *
 * 各平台返回差异见仓库 README 与 docs/：
 *  - douyin / kuaishou / xiaohongshu / jimengai：已较接近统一契约；
 *  - bilibili：data 为 {title, cover, auther, avatar, url, videos[]}；
 *  - weibo_v：data 为 {author, avatar, time, title, cover, url}，url 为代理地址；
 *  - toutiao：data 为 {itemId, videoType, author, avatar, title, cover, url, music}；
 *  - ppxia：data 为 {author, avatar, title, cover, url, imgurl[]}；
 *  - pipigx：data 为 {title, cover, video}；
 *  - zuiyou：data 为 {author, avatar, title, cover, url}。
 */

declare(strict_types=1);

final class Normalizer
{
    /** 平台名 -> 展示名 */
    public const PLATFORM_LABELS = [
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

    /** 平台默认代理 Referer */
    private const PLATFORM_REFERER = [
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

    public static function platformLabel(string $platform): string
    {
        return self::PLATFORM_LABELS[$platform] ?? $platform;
    }

    public static function refererFor(string $platform): string
    {
        return self::PLATFORM_REFERER[$platform] ?? 'https://www.douyin.com/';
    }

    /**
     * 归一化单平台结果。
     *
     * @param array  $raw      解析器返回的原始数组（含 code/msg/data）
     * @param string $platform 平台标识
     * @return array{code:int,msg:string,data:array,platform:string,platform_label:string}
     */
    public static function normalize(array $raw, string $platform): array
    {
        $code = (int)($raw['code'] ?? 500);
        $msg  = (string)($raw['msg'] ?? ('解析' . ($code === 200 ? '成功' : '失败')));
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];

        if ($code !== 200 || $data === []) {
            return [
                'code'           => self::errorCode($code === 200 && $data === [] ? 404 : $code),
                'msg'            => $msg !== '' ? $msg : '解析失败，未取到有效内容',
                'data'           => [],
                'platform'       => $platform,
                'platform_label' => self::platformLabel($platform),
            ];
        }

        $data = self::shape($data, $platform);

        return [
            'code'           => 200,
            'msg'            => $msg !== '' ? $msg : '解析成功',
            'data'           => $data,
            'platform'       => $platform,
            'platform_label' => self::platformLabel($platform),
        ];
    }

    /**
     * 把各平台自有的错误码收敛到契约里的 400/404/500。
     * 例如 B 站返回 -1（链接不对）、201（参数为空）。
     */
    private static function errorCode(int $code): int
    {
        if (in_array($code, [200, 400, 401, 404, 429, 500, 504], true)) {
            return $code;
        }
        if ($code === 201 || $code === 422 || $code < 0) {
            return 400;
        }
        return 500;
    }

    /**
     * 按契约整理 data 字段；已有字段保留，缺失字段补空值。
     */
    private static function shape(array $d, string $platform): array
    {
        // ---------- 作者 ----------
        $author = $d['author'] ?? ($d['auther'] ?? '');
        if (!is_array($author)) {
            $author = [
                'name'   => self::str($author),
                'id'     => self::str($d['userID'] ?? ($d['uid'] ?? '')),
                'avatar' => self::str($d['avatar'] ?? ''),
            ];
        } else {
            $author = [
                'name'   => self::str($author['name'] ?? ($author['nickname'] ?? '')),
                'id'     => self::str($author['id'] ?? ($author['uid'] ?? '')),
                'avatar' => self::str($author['avatar'] ?? ($author['avatar_url'] ?? '')),
            ];
        }
        if ($author['avatar'] === '' && isset($d['avatar'])) {
            $author['avatar'] = self::str($d['avatar']);
        }

        // ---------- 文本 ----------
        $title = self::str($d['title'] ?? ($d['desc'] ?? ''));
        $desc  = self::str($d['desc'] ?? $title);
        if ($title === '') {
            $title = $desc;
        }

        // ---------- 主链接 ----------
        // 豆包等接口返回的字段名不固定，按常见别名依次取值
        $url = null;
        foreach (['url', 'video', 'video_url', 'play_url', 'play_addr', 'mp4'] as $alias) {
            $url = self::nullableStr($d[$alias] ?? null);
            if ($url !== null) {
                break;
            }
        }
        $cover = self::str($d['cover'] ?? null);
        if ($cover === '') {
            foreach (['cover_url', 'poster', 'thumbnail', 'pic'] as $alias) {
                $cover = self::str($d[$alias] ?? null);
                if ($cover !== '') {
                    break;
                }
            }
        }

        // ---------- 图片 ----------
        $images = [];
        if (is_array($d['images'] ?? null)) {
            $images = array_values(array_filter(array_map([self::class, 'str'], $d['images'])));
        } elseif (is_array($d['imgurl'] ?? null)) {
            $images = array_values(array_filter(array_map([self::class, 'str'], $d['imgurl'])));
        }

        // ---------- 实况 ----------
        $livePhoto = [];
        if (is_array($d['live_photo'] ?? null)) {
            foreach ($d['live_photo'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $img = self::str($item['image'] ?? '');
                $vid = self::str($item['video'] ?? '');
                if ($img !== '' || $vid !== '') {
                    $livePhoto[] = ['image' => $img, 'video' => $vid];
                }
            }
        }

        // ---------- 类型 ----------
        $type = self::str($d['type'] ?? '');
        if (!in_array($type, ['video', 'image', 'live', 'music'], true)) {
            if ($images !== [] || $livePhoto !== []) {
                $type = 'image';
            } elseif ($url !== null) {
                $type = 'video';
            } else {
                $type = 'unknown';
            }
        }
        if ($type === 'image' && $livePhoto !== []) {
            $type = 'live';
        }
        if ($type === 'live' && $livePhoto === []) {
            $type = $images !== [] ? 'image' : 'video';
        }
        if ($type === 'unknown' && $url !== null) {
            $type = 'video';
        }

        // ---------- 画质 / 时长 / 大小 ----------
        $duration = $d['duration'] ?? null;
        $duration = is_numeric($duration) ? (float)$duration : null;
        if ($duration !== null && $duration <= 0) {
            $duration = null;
        }

        $size = (int)($d['size'] ?? 0);
        if ($size < 0) {
            $size = 0;
        }

        // ---------- 发布时间 ----------
        // 快手等平台给的是毫秒时间戳，统一折算成秒
        $createTime = (int)($d['create_time'] ?? 0);
        if ($createTime <= 0 && isset($d['time']) && is_numeric($d['time'])) {
            $createTime = (int)$d['time'];
        }
        if ($createTime > 100000000000) {
            $createTime = (int)round($createTime / 1000);
        }
        if ($createTime <= 0 || $createTime > 4102444800) {
            // 0 或明显不合理（超过 2100 年）时按未知处理
            $createTime = 0;
        }

        $publishTime = self::str($d['publish_time'] ?? '');
        if (!self::isValidDateTime($publishTime)) {
            // 契约要求东八区，不依赖服务器默认时区
            $publishTime = $createTime > 0
                ? (new \DateTime('@' . $createTime))
                    ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
                    ->format('Y-m-d H:i:s')
                : '';
        }

        // ---------- 音乐 ----------
        $music = $d['music'] ?? [];
        if (is_string($music)) {
            $music = ['title' => '', 'author' => '', 'url' => self::str($music), 'cover' => ''];
        }
        if (!is_array($music)) {
            $music = [];
        }

        // ---------- 备选直链 ----------
        $backup = self::normalizeBackup($d['video_backup'] ?? [], $platform);

        // ---------- B 站分P ----------
        $parts = [];
        if (is_array($d['videos'] ?? null)) {
            foreach ($d['videos'] as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $purl = self::str($item['url'] ?? '');
                if ($purl === '') {
                    continue;
                }
                $parts[] = [
                    'index'    => (int)($item['index'] ?? ($i + 1)),
                    'title'    => self::str($item['title'] ?? ('P' . ($i + 1))),
                    'duration' => (int)($item['duration'] ?? 0),
                    'url'      => $purl,
                ];
            }
        }

        // ---------- extra ----------
        $extra = is_array($d['extra'] ?? null) ? $d['extra'] : [];
        if ($platform === 'bilibili' && $parts !== []) {
            $extra['parts'] = $parts;
            $extra['total_videos'] = count($parts);
        }
        if ($type === 'video' && $url === null && $parts !== []) {
            $url = $parts[0]['url'];
        }

        return [
            'type'         => $type,
            'title'        => $title,
            'desc'         => $desc,
            'author'       => $author,
            'cover'        => $cover,
            'url'          => $url,
            'quality'      => self::str($d['quality'] ?? ''),
            'duration'     => $duration,
            'size'         => $size,
            'size_label'   => self::str($d['size_label'] ?? ($size > 0 ? self::sizeLabel($size) : '')),
            'create_time'  => $createTime,
            'publish_time' => $publishTime,
            'images'       => $images,
            'live_photo'   => $livePhoto,
            'video_backup' => $backup,
            'music'        => [
                'title'  => self::str($music['title'] ?? ($music['name'] ?? '')),
                'author' => self::str($music['author'] ?? ($music['artist'] ?? '')),
                'url'    => self::str($music['url'] ?? ''),
                'cover'  => self::str($music['cover'] ?? ''),
            ],
            'extra'        => (object)$extra,
        ];
    }

    /**
     * 备选直链统一为 [{label, quality, url, ...}]；纯字符串数组转成对象数组。
     */
    private static function normalizeBackup($backup, string $platform): array
    {
        if (!is_array($backup)) {
            return [];
        }

        $out = [];
        foreach ($backup as $item) {
            if (is_string($item)) {
                $u = trim($item);
                if ($u !== '') {
                    $out[] = ['label' => '', 'quality' => '', 'url' => $u];
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $u = self::str($item['url'] ?? '');
            if ($u === '') {
                continue;
            }
            $entry = [
                'label'   => self::str($item['label'] ?? ''),
                'quality' => self::str($item['quality'] ?? ''),
                'url'     => $u,
            ];
            foreach (['width', 'height', 'bit_rate', 'size', 'fps'] as $k) {
                if (isset($item[$k]) && is_numeric($item[$k])) {
                    $entry[$k] = (int)$item[$k];
                }
            }
            $out[] = $entry;
        }

        // 去重（按 URL），并去掉与主链重复的项由前端处理
        $seen = [];
        $uniq = [];
        foreach ($out as $item) {
            if (isset($seen[$item['url']])) {
                continue;
            }
            $seen[$item['url']] = true;
            $uniq[] = $item;
        }

        return $uniq;
    }

    public static function sizeLabel(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float)$bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return round($v, 2) . $units[$i];
    }

    /**
     * 校验 Y-m-d H:i:s 格式且真实存在（拒绝 56779-03-24 这类脏值）。
     */
    private static function isValidDateTime(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
        if ($dt === false) {
            return false;
        }
        // createFromFormat 会宽容溢出值（如 2 月 30 日），回格式化比对
        return $dt->format('Y-m-d H:i:s') === $value;
    }

    private static function str($v): string
    {
        if (is_scalar($v)) {
            return trim((string)$v);
        }
        return '';
    }

    private static function nullableStr($v): ?string
    {
        $s = self::str($v);
        return $s === '' ? null : $s;
    }
}
