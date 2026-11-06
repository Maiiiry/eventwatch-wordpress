#!/usr/bin/env bash
# 公式ディレクトリのプレビューの blueprint を、手元のビルドで開く。
# blueprint はディレクトリからプラグインを入れる手順を持つので、公開前はそのままでは開けない。
# その手順を外した写しを作り、配布用の zip を展開したプラグインを載せて有効にする。
# リポジトリのルートで、`npm run build` と `npm run plugin-zip` の後に流す。
# 引数はポート（既定 9400）と、サイトの言語（例: ja。スクリーンショットを撮るとき。既定は英語）。
set -euo pipefail

port="${1:-9400}"
language="${2:-}"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

unzip -q eventwatch.zip -d "$work"

node -e '
const fs = require("fs");
const blueprint = JSON.parse(fs.readFileSync(".wordpress-org/blueprints/blueprint.json", "utf8"));
const language = process.argv[2];
blueprint.steps = blueprint.steps.flatMap((step) =>
	step.step === "installPlugin"
		? [
				{ step: "activatePlugin", pluginPath: "eventwatch/eventwatch.php" },
				...(language ? [{ step: "setSiteLanguage", language }] : []),
			]
		: [step]
);
fs.writeFileSync(process.argv[1], JSON.stringify(blueprint, null, "\t"));
' "$work/blueprint.json" "$language"

npx -y @wp-playground/cli@3.1.60 server \
	--port="$port" \
	--mount-before-install="$work/eventwatch:/wordpress/wp-content/plugins/eventwatch" \
	--blueprint="$work/blueprint.json"
