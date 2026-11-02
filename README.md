# EventWatch Events

WordPress のサイトでイベントを公開し、EventWatch にも掲載するためのプラグインです。設計の要点は [docs/design.md](docs/design.md) にあります。

## 必要なもの

- Docker
- Node.js 24.19.0（`.nvmrc` と `.mise.toml` に書いてあります）
- `zip` コマンド（`npm run plugin-zip` で使います）

PHP と Composer は、wp-env のコンテナの中で動かすので、手元に入れなくても構いません。

## 始め方

```bash
npm ci
npm run env start
npm run composer install
```

サイトは http://localhost:8885 で開きます。管理画面は http://localhost:8885/wp-admin/ で、ユーザー名は `admin`、パスワードは `password` です（wp-env の既定値で、手元の環境でだけ使います）。

ポートは `.wp-env.json` で 8885 と 8886 にしています。変えるときは `WP_ENV_PORT` と `WP_ENV_TESTS_PORT` を指定します。

```bash
WP_ENV_PORT=8895 WP_ENV_TESTS_PORT=8896 npm run env start
```

## よく使うコマンド

| コマンド | 内容 |
| --- | --- |
| `npm run lint:js` | JavaScript の lint |
| `npm run test:js` | JavaScript の単体テスト（Vitest） |
| `npm run lint:php` | PHPCS（WordPress の規約と PHP 7.4 以上の互換） |
| `npm run test:php` | PHPUnit（シングルサイト） |
| `npm run test:php:multisite` | PHPUnit（マルチサイト） |
| `npm run build` | ブロックのビルド |
| `npm run plugin-zip` | 配布用の zip（`eventwatch.zip`）を作る |

`lint:php` と `test:php` は、wp-env を起動して `npm run composer install` を流した後に使います。

## ライセンス

GPL-2.0-or-later。[LICENSE](LICENSE) を参照してください。
