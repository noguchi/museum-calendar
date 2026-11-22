# 開発の手引き

プラグインそのものを開発する人向けです。サイトに導入してデザインを合わせる手順は [README](../README.md) にあります。

[![Calendar checks](https://github.com/noguchi/museum-calendar/actions/workflows/ci.yml/badge.svg)](https://github.com/noguchi/museum-calendar/actions/workflows/ci.yml)

## ステージング

ステージング: https://collaborative-macaw-b043ed.instawp.site

mainへのpushでチェックが通ると、最新のプラグインがステージングに配備されます。配備の仕組みは[運用手順](operations.md#ステージングへの配備)にあります。

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
- 2回目以降の起動は `npm run dev`、停止は `npm run stop`

Dockerは8888番、8889番、MariaDBのポートをホストの全インターフェースで公開します。信頼できるネットワークでのみ起動してください。
別の端末から開く設定は[運用手順](operations.md)にあります。

開発環境は、ステージングのサンプルカレンダーの写し（`dev/calendar-copy/calendar.ics`）を読みます。Googleには接続しません。
開発環境だけに入るプラグイン `dev/calendar-copy` が、仮のカレンダー `museum-dev-copy@group.calendar.google.com` の取得をこのファイルで返します。
カレンダーが未登録のときだけ、`npm run setup` と `npm run seed` がこの仮のカレンダーを公開カレンダーとして登録します。
別のカレンダーを登録済みの開発環境を写しに戻すには、「設定 → Museum Calendar」に仮のカレンダーの公開URL `https://calendar.google.com/calendar/ical/museum-dev-copy%40group.calendar.google.com/public/basic.ics` を保存します。
実際のカレンダーも同じ画面で登録すれば読めますが、一時的な確認にとどめ、本番の非公開URLは登録しません。

写しは `npm run calendar:copy -- '<GoogleカレンダーのURL>'` で更新します。URLは「iCal形式の非公開URL」と「iCal形式の公開URL」のどちらでもかまいません。ホストのNode.jsだけで動き、Dockerは使いません。

- 写しは公開リポジトリに入ります。公開してよい内容のカレンダーだけを写します。
- スクリプトはURL、カレンダーID、秘密の部分を出力しません。取得したデータにカレンダーID、秘密の部分、`ORGANIZER`、`ATTENDEE`、`mailto:` が含まれると書き込みません。
- 引数のURLはシェルの履歴に残ります。非公開URLを渡した後は、履歴からその行を消します。

テスト環境は写しを読まず、`tests/fixtures/museum.ics` を使います。外部には接続しません。

## 開発コマンド

| コマンド | 内容 |
| --- | --- |
| `npm run setup` | 開発用コンテナを起動し、依存を導入して確認ページを作成。再実行しても既存のページと登録済みのカレンダーを維持 |
| `npm run dev` | 開発用コンテナを起動 |
| `npm run stop` | 開発・テスト用コンテナを停止（DBのボリュームは保持） |
| `npm run seed` | 確認ページを作成。登録済みのカレンダーは維持 |
| `npm run site` | 開発環境を実サイトのトップページの写しにする（下記）。再実行しても既存のページを維持 |
| `npm run site:package` | ステージング用に、写しのテーマとページ設定のZIPをdistに生成 |
| `npm run site:deploy` | 写しのテーマをステージングに上書きし、配信されていることを確認（[運用手順](operations.md#実サイトのトップページの写し)） |
| `npm run wp -- plugin list` | 開発用コンテナ内でWP-CLIを実行 |
| `npm run check` | テスト用コンテナで構文、Composer、単体、WordPress統合、ブラウザーテスト |
| `npm run test:unit` | 日付計算、ICS解析、予定の種類の表、サンプルのCSSのテスト |
| `npm run test:integration` | 設定（非公開URLと公開URL、予定の種類の表）、取得、キャッシュ、失敗時処理、表示のテスト |
| `npm run test:e2e` | PC/スマホの表示と管理画面操作。画像は `test-results` に保存 |
| `npm run package` | vendorを含むプラグインZIPをdistに生成 |
| `npm run calendar:copy -- '<URL>'` | 開発環境が読むサンプルカレンダーの写しを更新（上記） |
| `npm run check:updates` | WordPress公開最新版を確認（更新はしない） |

## 実サイトのトップページ

カレンダーを実サイトのデザインの中で調整するために、旧古河邸サイトのトップページ（日本語 `/` と英語 `/en/`）の写しを開発環境とステージングに作れます。
写すのは、本日の予定、カレンダー、言語の切り替え（カレンダーの下の `ENG` / `JPN`）のHTMLとCSSだけです。
テーマは公開リポジトリに含められないので、Git対象外の `.local/site/` に置きます。

- `.local/site/theme/otani-museum2026/`: 本番テーマから、本日の予定とカレンダーの表示に要るものだけを残した写し。`front-page.php` は、本番と同じ入れ物に2つのショートコードと言語の切り替えを出す。
  - `museum-calendar/`: カレンダーと本日の予定のテンプレート。
  - `inc/calendar.php`: 予定のリンク先と、日本語のページで `開館日` と `休館日` の代わりに出す `開館` と `休館`。
  - `assets/css/museum-calendar.css`: 種類ごとの色。
  - `assets/js/museum-calendar.js`: 日付のセルを押すと、その日の予定と説明を `<dialog>` に出す。
- `.wp-env.override.json`: 上のテーマを開発環境に載せる指定（`"themes": ["./.local/site/theme/otani-museum2026"]`）。Git対象外。

- `npm run site` は、開発環境を日本語にしてテーマを有効にし、固定ページ `top` と `en` を作って `top` をフロントページにします（`scripts/site-seed.php`）。確認は http://localhost:8888/ と http://localhost:8888/en/ 。
- テーマはコンテナにそのまま載るので、`.local/site/theme` の編集はすぐ表示に反映されます。カレンダーのマークアップは、テーマの `museum-calendar/month.php` と `today.php` で差し替えます（[仕様](specification.md)の「テンプレートの差し替え」）。
- 言語の切り替えは `node .local/site/lang-switch-check.mjs [サイトのURL]` で確認できます（省略時は開発環境）。`ENG` と `JPN` で `/` と `/en/` を行き来できること、本日の予定とカレンダーの言語が変わることを、PCとスマホの画面幅で確かめます。
- テスト環境（8889）はこの写しを使いません。
- ステージングへの反映は[運用手順](operations.md#実サイトのトップページの写し)にあります。

## 依存関係と配布

- ルートのComposerはPHPUnitなど開発用。`plugins/museum-calendar/composer.json` はsabre/vobjectなど実行用で、こちらのvendorだけを配布ZIPに含めます。
- 対応バージョンの下限は本番環境の現行版です。変えるときは、プラグインヘッダー、2つの `composer.json`（`require` と `platform`）、両方のcomposer.lock、README、仕様をそろえます。
- GitHub ActionsがPRとmainへの変更を検証します。mainの検証が通ると、ステージングに配備します（[運用手順](operations.md#ステージングへの配備)）。プラグインのVersionと同じ番号の `v*` タグ（例: `v1.0.0`）をpushすると、配布ZIPをGitHub Releasesに添付します。タグとVersionが一致しない場合は失敗します。本番への自動配備は行いません。

## 関連資料

- [運用手順](operations.md)。コンテナの構成、WordPress・PHPの更新、復旧。
- [仕様](specification.md)。プラグインの動作の正本。
- 公式資料: [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) / [sabre/vobject](https://sabre.io/vobject/recurrence/)
