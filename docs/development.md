# 開発の手引き

プラグインを開発する人向けです。導入して使う手順は [README](../README.md) にあります。

[![Calendar checks](https://github.com/noguchi/museum-calendar/actions/workflows/ci.yml/badge.svg)](https://github.com/noguchi/museum-calendar/actions/workflows/ci.yml)

## 起動

開発とテストはLinux上のDocker（wp-env）で動かします。PHP、Composer、WP-CLI、MariaDBはホストに導入しません。

ホストに必要なもの:

- Docker EngineとDocker Composeプラグイン。`docker info` がsudoなしで通ること。
- Node.js 26推奨（最低22.13.0）とnpm
- `zip` コマンド（配布ZIPを作る場合）

```sh
npm ci
npm run setup
npx playwright install chromium
npm run check
```

- 確認ページ: http://localhost:8888/?pagename=calendar-demo
- カレンダー設定: http://localhost:8888/wp-admin/options-general.php?page=museum-calendar
- 開発専用ログイン: `admin` / `password`
- テスト環境: http://localhost:8889/

Dockerは8888番、8889番、MariaDBのポートをホストの全インターフェースで公開します。信頼できるネットワークでのみ起動してください。
別の端末から開く設定は[運用手順](operations.md)にあります。

開発環境で実際のカレンダーを読むには、`.env.example` を `.env` にコピーし、`MUSEUM_CALENDAR_DEV_CALENDAR_URL` にGoogleカレンダーの公開URLを書きます。
カレンダーが未登録のときだけ、`npm run setup` と `npm run seed` が登録します。
テスト環境は `.env` を読まず、`tests/fixtures/museum.ics` を使います。外部には接続しません。

## 開発コマンド

| コマンド | 内容 |
| --- | --- |
| `npm run setup` | 開発用コンテナを起動し、依存を導入して確認ページを作成。再実行しても既存のページと登録済みのカレンダーを維持 |
| `npm run dev` | 開発用コンテナを起動 |
| `npm run stop` | 開発・テスト用コンテナを停止（DBのボリュームは保持） |
| `npm run seed` | 確認ページを作成。登録済みのカレンダーは維持 |
| `npm run site` | 開発環境を実サイトのトップページの写しにする（下記）。再実行しても既存のページを維持 |
| `npm run site:package` | ステージング用に、写しのテーマとページ設定のZIPをdistに生成 |
| `npm run wp -- plugin list` | 開発用コンテナ内でWP-CLIを実行 |
| `npm run check` | テスト用コンテナで構文、Composer、単体、WordPress統合、ブラウザーテスト |
| `npm run test:unit` | 日付計算・ICS解析テスト |
| `npm run test:integration` | 設定、取得、キャッシュ、失敗時処理、表示のテスト |
| `npm run test:e2e` | PC/スマホの表示と管理画面操作。画像は `test-results` に保存 |
| `npm run package` | vendorを含むプラグインZIPをdistに生成 |
| `npm run check:updates` | WordPress公開最新版を確認（更新はしない） |

## 実サイトのトップページ

カレンダーを実サイトのデザインの中で調整するために、旧古河邸サイトのトップページ（日本語 `/` と英語 `/en/`）の写しを開発環境とステージングに作れます。
写すのは、本日の予定とカレンダーのHTMLとCSSだけです。ヘッダー、ナビ、NEWS、バナー、写真、フッターなど、ほかの要素は持ちません。
テーマは公開リポジトリに含められないので、Git対象外の `.local/site/` に置きます。

| 場所 | 内容 |
| --- | --- |
| `.local/site/theme/otani-museum2026/` | 本番テーマから、本日の予定とカレンダーの表示に要るものだけを残した写し。`front-page.php` は本番と同じ入れ物（`p-topMv__today` と `p-topInfo__calendar`）に2つのショートコードだけを出す。`assets/css/` は本番のまま。メインビジュアルが無いので、`inc/enqueue.php` でその枠の高さだけを外し、本日の予定をページの先頭に出す |
| `.wp-env.override.json` | 上のテーマを開発環境に載せる指定（`"themes": ["./.local/site/theme/otani-museum2026"]`）。Git対象外 |

- `npm run site` は、開発環境を日本語にしてテーマを有効にし、固定ページ `top` と `en` を作って `top` をフロントページにします（`scripts/site-seed.php`）。確認は http://localhost:8888/ と http://localhost:8888/en/ 。
- テーマはコンテナにそのまま載るので、`.local/site/theme` の編集はすぐ表示に反映されます。カレンダーのマークアップは、テーマの `museum-calendar/month.php` と `today.php` で差し替えます（[仕様](specification.md)の「テンプレートの差し替え」）。
- テスト環境（8889）はこの写しを使いません。`.wp-env.override.json` も読みません。
- ステージングへの反映は[運用手順](operations.md#実サイトのトップページの写し)にあります。

## 依存関係と配布

- ルートのComposerはPHPUnitなど開発用。`plugins/museum-calendar/composer.json` はsabre/vobjectなど実行用で、こちらのvendorだけを配布ZIPに含めます。
- 対応バージョンの下限は本番環境の現行版です。変えるときは、プラグインヘッダー、2つの `composer.json`（`require` と `platform`）、両方のcomposer.lock、README、仕様をそろえます。
- GitHub ActionsがPRとmainへの変更を検証します。プラグインのVersionと同じ番号の `v*` タグ（例: `v0.7.0`）をpushすると、`npm run check` の後に配布ZIPを作り、GitHub Releasesに添付します。タグとVersionが一致しない場合は失敗します。mainの検証が通ると、ステージングに配備します（[運用手順](operations.md#ステージングへの配備)）。本番への自動配備は行いません。

## 関連資料

- [運用手順](operations.md)。コンテナの構成、WordPress・PHPの更新、復旧。
- [仕様](specification.md)。プラグインの動作の正本。
- 公式資料: [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) / [sabre/vobject](https://sabre.io/vobject/recurrence/)
