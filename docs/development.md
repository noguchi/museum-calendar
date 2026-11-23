# 開発の手引き

## 起動

Linux上でDocker EngineとComposeプラグイン（sudoなしで利用可）、Node.js 22.13.0以上（26推奨）、npmを使います。配布には `zip` と `unzip` も必要です。PHP、Composer、WP-CLI、MariaDBはwp-envのコンテナ内で動かします。

```sh
npm ci
npm run setup
npx playwright install chromium
npm run check
```

[確認ページ](http://localhost:8888/?pagename=calendar-demo)と[設定画面](http://localhost:8888/wp-admin/options-general.php?page=museum-calendar)は開発用の8888番、テストは8889番です。開発用ログインは `admin` / `password`。WordPressとDBのポートがホストの全インターフェースで公開されるため、信頼できるネットワークで起動してください。

開発環境は `dev/calendar-copy/calendar.ics`、テストは `tests/fixtures/museum.ics` を読みます。どちらもGoogleに接続しません。開発環境の写しを更新する場合は、公開リポジトリに載せられるカレンダーだけを使い、非公開URLを引数に渡した後はシェルの履歴から消してください。

## 開発コマンド

| コマンド | 用途 |
| --- | --- |
| `npm run setup` / `npm run seed` | 初期構築／確認ページの作成。再実行時も既存のページと接続を維持 |
| `npm run dev` / `npm run stop` | 開発環境の再開／開発・テスト環境の停止。DBは保持 |
| `npm run wp -- <引数>` | 開発環境でWP-CLIを実行 |
| `npm run check` | PHP構文、Composer設定、単体・統合・ブラウザーテストを検証 |
| `npm run test:unit` / `npm run test:integration` / `npm run test:e2e` | PHPUnit／WordPress統合／Playwrightを個別に実行 |
| `npm run package` | 実行用vendorを含む配布ZIPを `dist/` に作成して検査 |
| `npm run check:audit` / `npm run check:updates` | 実行用PHP依存の脆弱性／WordPressの公開版を照会（外部接続） |
| `npm run calendar:copy -- '<URL>'` | 開発用カレンダーの写しを更新 |
| `npm run site` | 開発環境で実サイトのトップページを再現 |
| `npm run site:package` / `npm run site:deploy` | 写しのテーマとページ設定をZIP化／ステージングのテーマを上書き |

## 実サイトのトップページ

Git対象外の `.local/site/theme/otani-museum2026/` にテーマの写しを置き、`.wp-env.override.json` の `themes` に `./.local/site/theme/otani-museum2026` を指定します。`npm run site` でテーマを有効化し、[日本語](http://localhost:8888/)と[英語](http://localhost:8888/en/)の表示を確認します。テスト環境はこのテーマを使いません。

## 更新と配布

- ローカルのWordPress・PHPの更新は、変更用ブランチで `.wp-env.json` の `core` または `phpVersion` を変更し、`npm run setup` と `npm run check` で検証します。開発DBの更新が必要なら `npm run wp -- core update-db` を実行します。本番を更新する前に旧版DBの複製で移行を検証してください。
- PHP依存はコンテナ内のComposerで更新し、対応するlockファイルも含めます。ルートのComposerは開発用、`plugins/museum-calendar/` のComposerは配布用です。
- CIはPRとpushで `check`、`check:audit`、`package` を実行します。mainへのpushで検証が通ると[ステージングへ配備](operations.md#ステージングへの配備)します。プラグインのVersionと一致するタグ（例 `v1.0.0`）をpushすると、配布ZIPがGitHub Releasesに付きます。本番は手動で切り替えます。
