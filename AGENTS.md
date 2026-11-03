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
| `npm run i18n:check` | ja の翻訳がいまのソースにそろっているか（先に `npm run build`） |
| `npm run plugin-zip` | 配布用の zip |

文言を足したら、`npm run build` と `npm run i18n` のあと `languages/eventwatch-ja.po` に訳を足し、もう一度 `npm run i18n` で MO と JSON を作り直す。

## 守ること

- 最小の版は WordPress 6.7・PHP 7.4。上げるときは本体のヘッダーと `readme.txt` と CI の行列を一緒に直す
- 配布物は `package.json` の `files` に書いたものだけ。実行に要るファイルを足したら、ここにも足す
- `templates/` の `$args` はテーマが上書きで使う契約なので、キーを消す・意味を変えるときは readme も直す
- 詳細のページの既定の並びは `Event_Detail::default_content()` の 1 か所。ブロックテーマのテンプレートと `templates/single-event.php` が共有する
- cron のフックを足したら `Plugin::CRON_HOOKS` にも足す（無効化で外すため）
- EventWatch の API は `Api_Client` を通して呼ぶ。管理画面の操作と WP-Cron からだけ呼び、サイトの表示の流れでは呼ばない。キーの平文を画面・ログ・エラーに出さない
- 送り直しの判断は `Api_Client::is_temporary()` で分ける。同じ要求の中で送り直すのは `Api_Client` の 503 の 1 回だけで、呼び出し側で回数を足さない
- イベントの送信は `Sync_Queue` を通す。フックでは印だけを積み、送るときに投稿を読み直す。送る欄を足すときは `Sync_Payload::build()` に足す（送った内容のハッシュにも入る）。同期のメタは `Sync_Queue::meta_keys()` に足す（複製の選択と削除で消すため）
- アイキャッチ画像は `Sync_Image` を通す。送る ID を決めるのは `resolve()` の 1 か所で、上げられなくても本文の同期は止めない。投入は保存の時点では行わず、cron で行う。`imageUploadId` だけは、止める理由を見た後で上げるので `build()` の外の `Sync_Payload::with_image()` で足す
- 状態の取り込みは `Sync_Pull` を通す。応答の投稿を手元に書くのは `Sync_Queue::store_remote()` の 1 か所で、状態の語と文は `Sync_Rest::status()` の 1 か所（編集画面と一覧の列が読む）
- 開発では定数 `EVENTWATCH_API_URL` で API の URL を変えられる。wp-env では `WP_HOME` が固定なので、URL の変化は `eventwatch_site_url` を書き換えて確かめる
- コードのコメントは日本語、画面の文言は英語を原文にする
- wp-env のポートは 8885 / 8886。8888 は EventWatch の API サーバーが使う
