# このリポジトリでの作業

- 仕様の正本は `docs/specification.md`。現在は一般公開したGoogleカレンダーの読み取りに対応。
- WordPress本体・node_modules・vendorを直接改変しない。
- プラグインは `plugins/museum-calendar`。配布ZIPにテストや開発環境を含めない。
- プラグインの実行用vendorは配布ZIPに含める。ルートの開発用vendorは含めない。
- 受け付けるのはGoogleカレンダーの公開URLだけ。非公開URLを保存しない。カレンダーのURLとIDは管理画面の入力欄以外（公開ページ・ログ・エラーメッセージ）に出力しない。
- ICS解析はsabre/vobjectを使用。繰り返しやタイムゾーンの処理を独自実装しない。
- 開発・テストはLinux上のDocker（wp-env）が前提。PHP・Composer・WP-CLI・DBはホストに導入せず、`scripts/tasks.mjs` 経由でコンテナ内で実行する。
- 実際のカレンダーを読むのは開発環境（8888）だけ。URLはGit対象外の `.env` に置く。テストは常にモックのICSを使い、外部に接続しない。
- 対応するWordPress・PHP・MariaDBは本番環境の現行版以上。古い版への互換コードを足さない。下限はプラグインヘッダー・composer.json・README・仕様でそろえる。
- コード変更後は変更に応じたテスト、完成前には `npm run check` を実行する。
- 日付判定はAsia/Tokyoを明示し、PHP・ホストOSのデフォルトに依存しない。
- サンプル予定を本番の開館情報として表示しない。
- APIキー・本番DB・ユーザー情報をGitに含めない。上位フォルダーの資料は対象外。
- DB更新を追加するときは既存データからの更新テストと復旧手順を追加する。
- `setup` と `seed` の再実行で既存ページ・DBを削除しない。
- 外部への配備や本番の変更はローカル検証とは別の作業として扱う。
