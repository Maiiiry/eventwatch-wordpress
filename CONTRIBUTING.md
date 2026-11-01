# 開発に参加するには

環境の作り方は [README](README.md) を参照してください。

## ブランチとコミット

- `main` へ直接コミットせず、ブランチを切って Pull Request を出します
- ブランチ名は `feature/<issue の番号>-<簡潔な説明>` です
- コミットメッセージは `feat:`・`fix:`・`docs:`・`test:`・`chore:` などの接頭辞を付けて書きます

## Pull Request の前に流すもの

```bash
npm run lint:js
npm run lint:php
npm run test:php
npm run test:php:multisite
npm run plugin-zip
```

CI は同じものに加えて、WordPress と PHP の最小の版でのテストと、Plugin Check を流します。

## 書くときの決まり

- PHP の名前空間は `EventWatch\`、グローバルに出す関数・option・transient の接頭辞は `eventwatch_` です
- 実行するファイルの先頭で `ABSPATH` を確かめます
- 画面の文言は英語を原文にして、テキストドメイン `eventwatch` で翻訳できる形にします
- このリポジトリは公開されています。API キーやパスワードなどの秘密情報と、非公開のリポジトリのパス・基盤の設定を、コード・issue・Pull Request に書かないでください
