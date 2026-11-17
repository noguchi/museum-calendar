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
| `npm run wp -- plugin list` | 開発用コンテナ内でWP-CLIを実行 |
| `npm run check` | テスト用コンテナで構文、Composer、単体、WordPress統合、ブラウザーテスト |
| `npm run test:unit` | 日付計算・ICS解析テスト |
| `npm run test:integration` | 設定、取得、キャッシュ、失敗時処理、表示のテスト |
| `npm run test:e2e` | PC/スマホの表示と管理画面操作。画像は `test-results` に保存 |
| `npm run package` | vendorを含むプラグインZIPをdistに生成 |
| `npm run check:updates` | WordPress公開最新版を確認（更新はしない） |

## 依存関係と配布

- ルートのComposerはPHPUnitなど開発用。`plugins/museum-calendar/composer.json` はsabre/vobjectなど実行用で、こちらのvendorだけを配布ZIPに含めます。
- 対応バージョンの下限は本番環境の現行版です。変えるときは、プラグインヘッダー、2つの `composer.json`（`require` と `platform`）、両方のcomposer.lock、README、仕様をそろえます。
- GitHub ActionsがPRとmainへの変更を検証し、`v*` タグで配布ZIPを生成します。本番への自動配備は行いません。

## 関連資料

- [運用手順](operations.md)。コンテナの構成、WordPress・PHPの更新、復旧。
- [仕様](specification.md)。プラグインの動作の正本。
- 公式資料: [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) / [sabre/vobject](https://sabre.io/vobject/recurrence/)
