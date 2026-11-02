# eventwatch-wordpress

WordPress プラグイン「EventWatch Events」（スラッグ・Text Domain は `eventwatch`）。設計の要点は `docs/design.md`。

## 公開リポジトリ

このリポジトリは public（GPL-2.0-or-later）。非公開リポジトリのファイルパス・基盤の設定・本番のデータを、コード・文書・issue・PR に書かない。EventWatch 側の仕様は、公開の API 文書を参照する。

## 品質チェック

PHP と Composer は wp-env のコンテナの中で動かす。先に `npm run env start` と `npm run composer install` を流す。

| コマンド | 内容 |
| --- | --- |
| `npm run lint:js` | JS の lint |
| `npm run test:js` | JS の単体テスト（Vitest） |
| `npm run lint:php` | PHPCS |
| `npm run test:php` / `npm run test:php:multisite` | PHPUnit |
| `npm run plugin-zip` | 配布用の zip |

## 守ること

- 最小の版は WordPress 6.7・PHP 7.4。上げるときは本体のヘッダーと `readme.txt` と CI の行列を一緒に直す
- 配布物は `package.json` の `files` に書いたものだけ。実行に要るファイルを足したら、ここにも足す
- cron のフックを足したら `Plugin::CRON_HOOKS` にも足す（無効化で外すため）
- コードのコメントは日本語、画面の文言は英語を原文にする
- wp-env のポートは 8885 / 8886。8888 は EventWatch の API サーバーが使う
