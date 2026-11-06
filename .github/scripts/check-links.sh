#!/usr/bin/env bash
# 检查 Markdown 里的相对链接是否指向仓库中真实存在的文件。
# 只查相对路径，不请求外网（外链经常因反爬误报，交由人工判断）。
set -euo pipefail

fail=0
total=0

urldecode() {
    local s="$1"
    # 仓库里只会出现 %20 这类简单转义（如 "api/douyin-cloudflare%20workers/README.md"）
    s="${s//%20/ }"
    printf '%s' "$s"
}

while IFS= read -r md; do
    dir=$(dirname "$md")
    while IFS= read -r link; do
        [ -z "$link" ] && continue
        case "$link" in
            http://*|https://*|mailto:*|\#*) continue ;;
        esac
        path=${link%%#*}
        [ -z "$path" ] && continue
        path=$(urldecode "$path")
        total=$((total + 1))
        if [ ! -e "$dir/$path" ] && [ ! -e "${dir}/${path%/}" ]; then
            echo "BROKEN  $md -> $link"
            fail=$((fail + 1))
        fi
    done < <(grep -oE '\]\([^)]+\)' "$md" | sed 's/^](//; s/)$//' || true)
done < <(find . -type f -name '*.md' -not -path './.git/*')

echo "checked $total relative link(s), $fail broken"
if [ "$fail" -ne 0 ]; then
    exit 1
fi
