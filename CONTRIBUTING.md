# 贡献指南

感谢你愿意为这个项目出力。下面是在本仓库里改动代码、提交 PR 需要知道的全部约定。

## 开发环境

| 项目 | 要求 |
|------|------|
| PHP | 8.0+，需启用 `curl`、`json`，页面与归一化逻辑建议同时启用 `mbstring` |
| 依赖 | 无 Composer、无构建步骤 |
| 本地跑起来 | `php -S 127.0.0.1:8099 -t .`，浏览器打开 <http://127.0.0.1:8099/> |

注意 `php -S` 是单进程：一个卡住的解析请求会阻塞整个服务，联调时一次只发一个请求并加 `curl --max-time`。需要并发时用 Nginx / Apache + PHP-FPM。

## 目录职责

```
api/<平台>/            单平台解析：入口文件 + Parser 类
api/sv.php            聚合入口，HTTP 自请求转发（需 Web 服务器支持并发）
api/parse.php         聚合入口，进程内 include 调用（无自请求，CLI 可用）
api/lib/PlatformRouter.php  平台识别、进程内调用、公网 URL 校验、解析结果留痕
api/lib/Normalizer.php      各平台返回 → 统一 JSON 3.0
api/media.php         媒体代理：Referer、Range、DNS 固定
api/health.php        健康检查：域名可达性 + 最近解析业务码
index.html            解析页面，原生 HTML/CSS/JS，无构建
docs/                 统一 JSON 契约，中英各一份
```

新增一个平台时，需要同时改三处：

1. `api/<平台>/` 下放入口与 Parser，返回 `{code, msg, data}`；
2. `api/lib/PlatformRouter.php` 的 `PLATFORMS`（关键词、入口路径、probe 域名）与 `LABELS`；
3. `index.html` 里的平台清单常量（若有展示需求），以及 README 的支持平台表。

字段与统一契约对不上时，在 `Normalizer::shape()` 里加分支处理，**不要**改契约本身；确需扩展契约时，同步更新 `docs/` 两份文档并在 PR 里说明兼容性。

## 代码风格

- 遵循 `.editorconfig`：UTF-8、LF、PHP 4 空格、前端 2 空格、文件末尾留空行。
- PHP 文件统一 `declare(strict_types=1);`，类名 StudlyCase，方法 camelCase。
- 注释只写「为什么这么做」，不复述代码在做什么。平台特例（风控、字段错拼、时间戳单位）必须写清来源。
- 不引入 Composer 依赖，不引入前端构建链。一个页面文件能跑就不要再拆一层。

## 安全基线

改动以下位置时请在 PR 描述里单独说明：

- `api/media.php` 的 `$allowedSuffixes` 与公网 IP 校验：任何放宽都要给出理由；
- `PlatformRouter::isAllowedUrl()`：它挡的是内网探测与云元数据访问；
- 拼接外部输入到 `curl` 选项或 shell 命令的地方。

## 提交信息

格式固定为 Conventional Commits：

```
type(scope): 摘要
```

- `type`：`feat` / `fix` / `refactor` / `perf` / `docs` / `style` / `test` / `chore` / `build` / `ci`
- `scope`：小写单词，如 `ui` / `parse` / `normalizer` / `docs`
- 摘要：中文祈使语气，≤72 字符，结尾不加句号
- 正文：与摘要之间空一行，用 `- ` 分条，每行 ≤100 字符，说明改了什么与为什么

示例：

```
fix(media): 修复 Range 响应头未透传导致进度条无法拖动

- $sendHeaders 闭包漏捕获 $forwardHeaders，Content-Range 被丢弃
- 补齐 Content-Length / Content-Range / Accept-Ranges 三个头
```

## 提交前自查

```bash
# 语法检查（CI 也会跑）
find . -name '*.php' -not -path './.git/*' -print0 | while IFS= read -r -d '' f; do php -l "$f"; done

# 冒烟测试：只测本机链路与契约，不发解析请求
php .github/scripts/smoke-parse.php
```

- [ ] 受影响的请求实际跑过一次，贴出返回的 `code`
- [ ] 页面改动看过桌面与移动两档视口
- [ ] 没有提交 `.idea/`、编辑器缓存、抓包凭据或任何 Cookie
- [ ] 没有把测试用的临时脚本、截图、日志一起提交

## PR 流程

1. 从 `main` 切分支：`feat/xxx`、`fix/xxx`、`docs/xxx`；
2. 一个 PR 只做一件事，涉及多个平台时按平台拆；
3. 用仓库的 PR 模板填写，验证方式必须是真的跑过的命令；
4. CI 的 `PHP 语法检查` 与 `接口链路冒烟` 都要绿；
5. 等待 review，不要自行合并。

## 平台风控与「解析失败」

同一个接口在你的机器上失败、在维护者机器上成功，最常见的原因是出口 IP 被平台反爬。提交 issue 前请：

- 用官方演示站 <https://api.bugpk.com/> 试同一条链接；
- 说明是哪个出口 IP 环境（住宅 / 云服务器 / 特定地区）；
- 附带原始响应体，而不只是「返回 500」。

## 许可证

提交的代码默认以本仓库的 MIT 许可证发布。
