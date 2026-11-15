# 開発環境の運用

## 環境

開発とテストはLinux上のDocker（wp-env）で動かします。PHP・Composer・WP-CLI・MariaDBはホストに導入せず、
`npm run …`（`scripts/tasks.mjs`）経由でコンテナ内で実行します。ホストで動くのはNode.js（wp-env、Playwright）と `zip` だけです。

| 環境 | ポート | コンテナ名の接頭辞 | 用途 |
| --- | --- | --- | --- |
| 開発 | 8888 | `wp-env-museum-calendar-<ハッシュ>-` | 手動確認。`npm run setup` / `npm run dev` |
| テスト | 8889 | `wp-env-museum-calendar-test-<ハッシュ>-` | 自動テスト。`npm run check` などが起動 |

各環境は `wordpress`（Apache+PHP）、`cli`（WP-CLI・Composer）、`mysql`（MariaDB）の3コンテナで、DBも環境ごとに別です。
稼働状況は `docker ps --filter name=wp-env-museum-calendar`、Apache/PHPの出力は `docker logs <wordpressコンテナ名>` で確認できます。
WordPressのデバッグログはコンテナ内の `wp-content/debug.log` に出力されます。

基準は `.wp-env.json`、テスト構成（`.wp-env.test.json`）はスクリプトが生成します。生成ファイルを直接編集しないでください。
`.cache/wp-env` にはwp-envが生成したDockerfile・docker-compose.yml・WordPress本体を保持します。
DB本体はDockerのボリューム（`wp-env-museum-calendar-…_mysql`）に保持され、`npm run stop` では削除しません。
テストは本番データやGoogle認証情報に依存しません。

### 別の端末から開く

Dockerは8888・8889番とMariaDBのポートをホストの全インターフェース（`0.0.0.0`）で公開します。
開発専用ログインのまま届くため、信頼できるネットワークでのみ起動してください。

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

`afterStart` は、コンテナ内からそのホスト名を解決できるようにするためのものです。これがないとWordPressが自サイトへ接続できず、
WP-Cron（ICSの定期取得）が動きません。ホスト名はマシン固有なので `.wp-env.json` には書かないでください。
テスト環境は別の設定ファイルを使うため、この上書きの影響を受けず `localhost:8889` のままです。

## WordPress・PHPの更新

1. `npm run check:updates` でWordPressの公開版を確認。
2. 変更用ブランチで `.wp-env.json` のcore URLまたはphpVersionを変更。
3. `npm run setup` と `npm run check` を実行。
4. 必要に応じて `npm run wp -- core update-db` で開発DBの更新を実行。
5. 実際に動いた `npm run wp -- core version` とPHPバージョンを記録。

上記はローカルのコンテナの更新です。本番の更新・バックアップ・配備は自動化していません。
**旧版から新版へのDB移行専用テストはまだ未実装**です。初期ひな形の `check` は
対象バージョンでの起動・プラグイン動作を確認するもので、DB移行を保証しません。
本番への更新前には、旧版のDBを複製して新版へ移行する専用検証を追加してください。

## 依存関係

`npm ci` と `composer install` はロックファイルに従います。
PHP依存の更新はcliコンテナ内のComposerで行い、composer.jsonとcomposer.lockを同じ変更に含めます。
`package.json` のoverridesは、wp-env 12.1.0の間接依存に対する修正版の指定です。
対象はisomorphic-git、qs、rimraf。wp-env更新時には不要になった指定を外せるか確認し、
`npm audit` と環境起動・停止・テストを再確認してください。
Renovateの接続は別途必要です。WordPress本体の自動更新PR作成も今回のひな形には含みません。

## 配布

`npm run setup` または `npm run check` で実行用の依存を導入してから、`npm run package` でプラグインフォルダーをZIP化します。
WordPress管理画面の「プラグインをアップロード」から導入できます。
実行用vendorはZIPに含みます。Google認証情報・ICS URL・DB・開発依存は含みません。
本番での表示には管理画面でICS URLを設定してください。実カレンダーとの接続確認は別途必要です。

## ICSの更新

15分間隔のWP-Cronと、キャッシュ期限後の表示アクセスで取得します。低アクセスのサイトで定刻更新が必要ならサーバー側のcronからWP-Cronを実行してください。
管理画面の「今すぐ更新」は即時取得を試みます。上流側のICS生成遅延やページキャッシュの遅延は別途考慮してください。
エラー時は前回データを最長24時間表示します。管理画面でURL・共有設定・形式を確認してください。
接続先の変更/解除でキャッシュは消去されます。プラグインの無効化で定期取得は停止し、設定は保持します。

## 復旧・停止

### Otani Calendarからの名称変更（0.3.0）

導入前にWordPressのDBと独自CSSをバックアップし、旧プラグインを無効化してから新版を有効化します。
ICS URL・キャッシュ・サンプル表示設定は新しいキーへ一度だけコピーし、旧定期取得を解除します。
旧設定は削除せず、新しい設定が存在する場合は上書きしません。ページ本文も書き換えず、旧ショートコードで表示できます。
設定画面は「設定 → Museum Calendar」、新規のショートコードは `[museum_calendar]` です。
旧版へ戻す場合は新版を無効化して旧版を有効化します。旧版には移行時点の設定が残るため、
移行後にURLを変更・接続解除した場合は、旧版を有効化する前にバックアップから整合する設定へ復元してください。
名称変更の引き継ぎ・再実行・新設定の保持はWordPress統合テストで検証します。

### 開発環境

通常は `npm run stop`、再開は `npm run dev` を使います。コンテナを止めてもDBのボリュームは残ります。
コンテナとボリュームを破棄する操作はデータを消すため、自動の開発コマンドには含めていません。
本番DBの移行を伴う更新は、コードの差し戻しだけでは復旧できません。
本番運用を始める段階でDB・ファイルのバックアップと復元試験を別途整備してください。
