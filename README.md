# Museum Calendar for WordPress

ミュージアムカレンダープラグイン

[![Calendar checks](https://github.com/noguchi/museum-calendar/actions/workflows/ci.yml/badge.svg)](https://github.com/noguchi/museum-calendar/actions/workflows/ci.yml)

WordPress環境、ICSカレンダープラグイン、自動テストをまとめたリポジトリです。
GoogleカレンダーなどのICS URLを取得し、月間カレンダーに表示します。APIキーは不要です。
英語名は **Museum Calendar for WordPress**、管理画面での略称は **Museum Calendar** です。

## 起動

開発とテストはLinux上のDockerを前提にしています。WordPress・PHP・Composer・WP-CLI・MariaDBはすべてコンテナ内で動かし、ホストには導入しません。

ホスト側に必要なもの:

- Docker EngineとDocker Composeプラグイン。`docker info` がsudoなしで通ること（実行ユーザーを `docker` グループに追加）。
- Node.js 24 LTS推奨（最低22.13.0）とnpm。コンテナの起動・停止を行うwp-envと、ブラウザーテストのPlaywrightを動かします。
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
別の端末のブラウザーから確認する場合の設定は[運用手順](docs/operations.md)を参照してください。
setupを再実行しても既存ページや設定済みICS URLは維持します。

## ICS URLの登録

1. Googleカレンダーの「設定 → カレンダーの統合」でiCal形式のURLを取得。
2. WordPressの「設定 → Museum Calendar」に貼り付け、「設定を保存」。
3. 「今すぐ更新」を押し、最終成功日時を確認。
4. 固定ページに `[museum_calendar]` を置く。確認ページには設定済みです。

公開用URLはカレンダーの一般公開設定が必要です。非公開URLは閲覧用の秘密情報なので、Gitや公開ページに書かないでください。
保存済みURLは設定画面にも再表示しません。空欄で保存すると既存値を維持し、接続解除チェックで消去します。
URLはDBに保存されるため、DBバックアップも適切に保管してください。

通常は15分間隔で更新します。WP-Cronと表示アクセスが更新の契機なので即時反映は保証しません。
取得失敗時は前回データを最長24時間表示し、最終取得日時を案内します。
終日予定、日/週/月/年単位の繰り返し、除外日、日時変更、中止、時差変換に対応します。
表示は日本時間で、タイトルと時間のみ。詳細な上限と制約は [仕様](docs/specification.md) を参照してください。

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
ブラウザーテストの画像は `test-results` に保存されます。

## 依存関係と配布

- `.wp-env.json`: コンテナの構成。WordPress 7.1.4 / PHP 8.5系列 / MariaDB 10.11系列。
- ルートのComposer: PHPUnitなど開発用。
- `plugins/museum-calendar/composer.json`: sabre/vobjectなど実行用。こちらのvendorは配布ZIPに必要。
- 両方のcomposer.lockとpackage-lock.jsonをGit管理。
- vendor、node_modules、実行時データ、秘密情報、生成ZIPはGit対象外。
- `.cache/wp-env` はwp-envが生成するDockerfileとdocker-compose.yml、WordPress本体。`.wp-env.test.json` はスクリプトで生成するテスト設定。

GitHub ActionsでPRとmainへの変更を検証し、`v*` タグでは配布ZIPを生成します。本番への自動配備は行いません。
Renovateは接続後に依存更新のPRを作成します。WordPress本体の更新PRとDB移行テストは未実装です。

## 旧名称からの移行

0.3.0でOtani CalendarからMuseum Calendar for WordPressへ名称を変更しました。
旧プラグインを無効化してから `museum-calendar` をインストール・有効化してください。同時に有効化しないでください。
初回読み込み時に旧ICS設定・キャッシュ・サンプル表示設定を引き継ぎます。新しい設定があれば優先します。
旧 `[otani_calendar]` と `otani_month` の月送りリンクも引き続き使えます。新規ページには `[museum_calendar]` を使用してください。
CSSクラスは `museum-calendar` / `museum-calendar__…` に変更したため、独自CSSは合わせて更新してください。
旧設定は復旧用に保持しますが、移行後の変更は旧設定へ反映しません。詳しくは[運用手順](docs/operations.md)を参照してください。

[運用手順](docs/operations.md) · [仕様](docs/specification.md)

## リポジトリに含めるもの

自作コード、テストと架空のICSデータ、環境設定、依存関係のロックファイル、CI設定、利用手順を管理します。
依存ライブラリの実体、WordPress本体、DB、ICS URL、ログ、画像付きテスト結果、ZIP、個別の作業記録は `.gitignore` で除外します。
実際のカレンダーや非公開URLをテストデータに使用しないでください。

公式資料: [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) / [sabre/vobject](https://sabre.io/vobject/recurrence/)

プラグインのライセンス: GPL-2.0-or-later。依存ライブラリのライセンスはvendor内に含まれます。
