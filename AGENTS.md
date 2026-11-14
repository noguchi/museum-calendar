# このリポジトリでの作業

- 仕様の正本は `docs/specification.md`。現在は読み取り専用のICS連携に対応。
- WordPress本体・node_modules・vendorを直接改変しない。
- プラグインは `plugins/otani-calendar`。配布ZIPにテストや開発環境を含めない。
- プラグインの実行用vendorは配布ZIPに含める。ルートの開発用vendorは含めない。
- ICS URLは秘密情報になりうる。HTML・ログ・エラーメッセージに出力しない。
- ICS解析はsabre/vobjectを使用。繰り返しやタイムゾーンの処理を独自実装しない。
- PHP/Composerは `scripts/tasks.mjs` 経由でコンテナ内実行を基本とする。
- コード変更後は変更に応じたテスト、完成前には `npm run check` を実行する。
- 日付判定はAsia/Tokyoを明示し、PHP・ホストOSのデフォルトに依存しない。
- サンプル予定を本番の開館情報として表示しない。
- APIキー・本番DB・ユーザー情報をGitに含めない。上位フォルダーの資料は対象外。
- DB更新を追加するときは既存データからの更新テストと復旧手順を追加する。
- `setup` と `seed` の再実行で既存ページ・DBを削除しない。
- 外部への配備や本番の変更はローカル検証とは別の作業として扱う。
