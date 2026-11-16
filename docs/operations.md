# 開発環境の運用

## 環境

| 環境 | ポート | コンテナ名の接頭辞 | 用途 |
| --- | --- | --- | --- |
| 開発 | 8888 | `wp-env-museum-calendar-<ハッシュ>-` | 手動確認。`npm run setup` / `npm run dev` |
| テスト | 8889 | `wp-env-museum-calendar-test-<ハッシュ>-` | 自動テスト。`npm run check` などが起動 |

- 各環境は `wordpress`（Apache+PHP）、`cli`（WP-CLI・Composer）、`mysql`（MariaDB）の3コンテナで、DBも環境ごとに別です。
- 稼働状況は `docker ps --filter name=wp-env-museum-calendar`、Apache/PHPの出力は `docker logs <wordpressコンテナ名>`、WordPressのデバッグログはコンテナ内の `wp-content/debug.log` で確認します。
- 基準は `.wp-env.json` です。`.wp-env.test.json` と `.cache/wp-env` は生成物なので編集しません。
- DBはDockerのボリューム（`wp-env-museum-calendar-…_mysql`）にあり、`npm run stop` では消えません。

### 別の端末から開く

WordPressはリンクやCSSのURLをサイトURLから生成するため、`localhost` 以外の名前で開くにはサイトURLの変更が必要です。
Git対象外の `.wp-env.override.json` に次のように書き、`npm run dev` で反映します（ポートは自動で付きます）。

```json
{
  "config": {
    "WP_SITEURL": "http://<ホスト名>",
    "WP_HOME": "http://<ホスト名>"
  },
  "lifecycleScripts": {
    "afterStart": "docker exec -u root \"$(docker ps -q --filter 'name=^wp-env-museum-calendar-[0-9a-f]+-wordpress-1$')\" sh -c \"grep -q ' <ホスト名>$' /etc/hosts || echo '127.0.0.1 <ホスト名>' >> /etc/hosts\""
  }
}
```

`afterStart` は、コンテナ内からそのホスト名を解決できるようにします。これがないとWordPressが自サイトへ接続できず、WP-Cron（ICSの定期取得）が動きません。
テスト環境はこの上書きの影響を受けず、`localhost:8889` のままです。

## WordPress・PHPの更新

1. `npm run check:updates` でWordPressの公開版を確認。
2. 変更用ブランチで `.wp-env.json` のcore URLまたはphpVersionを変更。
3. `npm run setup` と `npm run check` を実行。
4. 必要に応じて `npm run wp -- core update-db` で開発DBを更新。

これはローカルのコンテナの更新です。本番の更新、バックアップ、配備は自動化していません。
**旧版から新版へのDB移行テストは未実装**です。本番を更新する前に、旧版のDBを複製して新版へ移行する検証を追加してください。

## 依存関係

- PHP依存の更新はcliコンテナ内のComposerで行い、composer.jsonとcomposer.lockを同じ変更に含めます。
- `package.json` のoverrides（isomorphic-git、qs、rimraf）は、wp-env 12.1.0の間接依存に対する修正版の指定です。wp-envを更新するときに、外せるかを確認します。
- `renovate.json` を置いていますが、Renovateの接続は別途必要です。

## ICSの定期取得

- WP-Cronはサイトへのアクセスで動きます。アクセスの少ないサイトで定刻に更新するには、サーバー側のcronからWP-Cronを実行します。
- プラグインを無効化すると定期取得は止まり、設定は残ります。

## 復旧・停止

- 停止は `npm run stop`、再開は `npm run dev` です。
- コンテナとボリュームを破棄する操作はデータを消すため、開発コマンドには含めていません。
- 本番DBの移行を伴う更新は、コードの差し戻しだけでは復旧できません。本番運用の前に、DBとファイルのバックアップと復元試験を整備してください。
