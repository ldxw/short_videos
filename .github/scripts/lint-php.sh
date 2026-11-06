#!/usr/bin/env bash
# 对仓库内所有 .php 文件跑 php -l。
# 路径里含空格的目录（如 "api/douyin-cloudflare workers/"）也要覆盖，用 -print0 处理。
set -euo pipefail

fail=0
count=0

while IFS= read -r -d '' file; do
    count=$((count + 1))
    if ! output=$(php -l "$file" 2>&1); then
        echo "FAIL  $file"
        echo "$output" | sed 's/^/      /'
        fail=$((fail + 1))
    fi
done < <(find . -type f -name '*.php' -not -path './.git/*' -print0)

echo "checked $count file(s), $fail failure(s)"

if [ "$fail" -ne 0 ]; then
    exit 1
fi
