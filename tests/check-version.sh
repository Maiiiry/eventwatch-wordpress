#!/usr/bin/env bash
# 版の番号が、本体のヘッダー・readme.txt の Stable tag・package.json でそろっているかを見る。
# 引数にタグ（例: v1.2.3）を渡すと、タグの番号ともそろっているかを見る。
# リポジトリのルートで流す。
set -euo pipefail

header=$(sed -n 's/^ \* Version:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p' eventwatch.php)
stable=$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p' readme.txt)
package=$(node -p 'require("./package.json").version')

echo "eventwatch.php Version: ${header:-(none)}"
echo "readme.txt Stable tag: ${stable:-(none)}"
echo "package.json version: ${package:-(none)}"

status=0
if [ -z "$header" ] || [ -z "$stable" ] || [ -z "$package" ]; then
	echo 'A version is missing.' >&2
	status=1
elif [ "$header" != "$stable" ] || [ "$header" != "$package" ]; then
	echo 'The versions do not match.' >&2
	status=1
fi

if [ $# -gt 0 ]; then
	tag="${1#v}"
	echo "tag: $1"
	if [ "$tag" != "$header" ]; then
		echo 'The tag does not match the plugin version.' >&2
		status=1
	fi
fi

exit "$status"
