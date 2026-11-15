# 開発の手引き

このリポジトリでプラグインを開発するエンジニア向けの手順です。
プラグインを導入して使う手順は [README](../README.md) を参照してください。

[![Calendar checks](https://github.com/noguchi/museum-calendar/actions/workflows/ci.yml/badge.svg)](https://github.com/noguchi/museum-calendar/actions/workflows/ci.yml)

WordPress環境、ICSカレンダープラグイン、自動テストを1つのリポジトリにまとめています。

## 起動

開発とテストはLinux上のDockerを前提にしています。WordPress・PHP・Composer・WP-CLI・MariaDBはすべてコンテナ内で動かし、ホストには導入しません。

ホスト側に必要なもの:

- Docker EngineとDocker Composeプラグイン。`docker info` がsudoなしで通ること（実行ユーザーを `docker` グループに追加）。
- Node.js 26推奨（最低22.13.0）とnpm。CIも26を使います。コンテナの起動・停止を行うwp-envと、ブラウザーテストのPlaywrightを動かします。
- `zip` コマンド（配布ZIPを作る場合）。

初回はコンテナ画像と依存の取得にネット接続が必要です。

```sh
npm ci
npm run setup
npx playwright install chromium
npm run check
```

`npm run setup` は開発用コンテナを起動し、コンテナ内のComposerで依存を導入して確認ページを作ります。
`npm run check` はテスト用コンテナを別に起動して検証します。
ブラウザー用ライブラリが不足する場合は `npx playwright install --with-deps chromium`（sudoが必要）。

- 確認ページ: http://localhost:8888/?pagename=calendar-demo
- ICS設定: http://localhost:8888/wp-admin/options-general.php?page=museum-calendar
- 開発専用ログイン: `admin` / `password`
- 独立したテスト環境: http://localhost:8889/

Dockerは8888・8889番とMariaDBのポートをホストの全インターフェースで公開します。同じネットワークの端末から開発専用ログインで入れるため、信頼できるネットワークでのみ起動してください。
別の端末のブラウザーから確認する場合の設定は[運用手順](operations.md)を参照してください。
setupを再実行しても既存ページや設定済みICS URLは維持します。

開発環境で実際のカレンダーを読み込むには、`.env.example` を `.env` にコピーしてICS URLを書きます。
ICS URLが未登録のときだけ `npm run setup` と `npm run seed` が登録します。`.env` はGit対象外です。
テスト環境（8889）は `.env` を読まず、単体・統合・E2Eのすべてでモックのデータを使います。外部のカレンダーには接続しません。

## 開発コマンド

| コマンド | 内容 |
| --- | --- |
| `npm run dev` | 開発用コンテナを起動 |
| `npm run stop` | 開発・テスト用コンテナを停止（DBのボリュームは保持） |
| `npm run seed` | 確認ページを作成。設定済みICS接続は維持 |
| `npm run wp -- plugin list` | 開発用コンテナ内でWP-CLIを実行 |
| `npm run check` | テスト用コンテナで構文、Composer、単体、WordPress統合、ブラウザーテスト |
| `npm run test:unit` | 日付計算・ICS解析テスト |
| `npm run test:integration` | 設定、取得、キャッシュ、失敗時処理、表示のテスト |
| `npm run test:e2e` | PC/スマホの表示と管理画面操作 |
| `npm run package` | vendorを含むプラグインZIPをdistに生成（zipコマンドが必要） |
| `npm run check:updates` | WordPress公開最新版を確認（更新はしない） |

## テスト

テスト用WordPressは8889ポートの別DBで動作し、開発用DBや実カレンダーを使いません。
`tests/fixtures/museum.ics` とテスト専用プラグインでHTTP応答を置き換えるため、Google認証や外部の予定変更に依存しません。
テスト専用プラグインは開発用サイトと配布ZIPには入りません。
テンプレートの差し替えは、テスト専用テーマ `tests/support/override-theme` で検証します。統合テストの間だけ有効にし、終了時に元のテーマへ戻します。このテーマも開発用サイトと配布ZIPには入りません。
ブラウザーテストの画像は `test-results` に保存されます。

## 依存関係と配布

- `.wp-env.json`: コンテナの構成。WordPress 7.1.4 / PHP 8.5系列 / MariaDB 10.11系列。
- 対応バージョンの下限は本番環境の現行版に合わせています（WordPress 7.1.4、PHP 8.5.12、MariaDB 10.11.18）。下限を変えるときは、プラグインヘッダー、2つの `composer.json`（`require` と `platform`）、両方のcomposer.lock、README、仕様をそろえて更新します。
- ルートのComposer: PHPUnitなど開発用。
- `plugins/museum-calendar/composer.json`: sabre/vobjectなど実行用。こちらのvendorは配布ZIPに必要。
- 両方のcomposer.lockとpackage-lock.jsonをGit管理。
- vendor、node_modules、実行時データ、秘密情報、生成ZIPはGit対象外。
- `.cache/wp-env` はwp-envが生成するDockerfileとdocker-compose.yml、WordPress本体。`.wp-env.test.json` はスクリプトで生成するテスト設定。

GitHub ActionsでPRとmainへの変更を検証し、`v*` タグでは配布ZIPを生成します。本番への自動配備は行いません。
Renovateは接続後に依存更新のPRを作成します。WordPress本体の更新PRとDB移行テストは未実装です。

## リポジトリに含めるもの

自作コード、テストと架空のICSデータ、環境設定、依存関係のロックファイル、CI設定、利用手順を管理します。
依存ライブラリの実体、WordPress本体、DB、ICS URL、ログ、画像付きテスト結果、ZIP、個別の作業記録は `.gitignore` で除外します。
実際のカレンダーや非公開URLをテストデータに使用しないでください。

## 関連資料

- [運用手順](operations.md)。コンテナの構成、WordPress・PHPの更新、配布、復旧。
- [仕様](specification.md)。プラグインの動作の正本。
- 公式資料: [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) / [sabre/vobject](https://sabre.io/vobject/recurrence/)
