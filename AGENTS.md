# このリポジトリでの作業

GoogleカレンダーのiCal形式のURL（通常は組織内で共有した非公開カレンダーの「iCal形式の非公開URL」）から予定を読み、月間カレンダーと本日の予定を表示するWordPressプラグイン。
本体は `plugins/museum-calendar`、名前空間は `MuseumCalendar\`。

## 守ること

カレンダーと秘密:

- 受け付けるのはGoogleカレンダーのURLだけ。通常は非公開カレンダーの「iCal形式の非公開URL」で、公開カレンダーの「iCal形式の公開URL」も受け付ける。
- 非公開URLの秘密の部分（`private-` の後）は、DBのオプション `museum_calendar_google_calendar_secret` と、Googleへの取得のリクエストにだけ置く。管理画面（入力欄の値を含む）、公開ページ、通知、エラーメッセージ、保存する例外メッセージ、ログ、テストの出力、画面の画像、CIの成果物に出さない。Gitにも含めない。テストで使うのは作った値だけ。
- カレンダーのURLとIDはどこにも出力しない。管理画面は登録の有無と非公開・公開の別だけを表示し、保存したURLを入力欄に戻さない。
- APIキー、本番DB、ユーザー情報をGitに含めない。上位フォルダーの資料は対象外。
- サンプル予定を本番の開館情報として表示しない。

実装:

- ICS解析はsabre/vobjectを使う。繰り返しやタイムゾーンの処理を独自実装しない。
- 日付判定はAsia/Tokyoを明示し、PHPやホストOSのデフォルトに依存しない。
- 対応するWordPress、PHP、MariaDBは本番環境の現行版以上。古い版への互換コードを足さない。下限を変えるときは、プラグインヘッダー、2つの `composer.json`（`require` と `platform`）、両方の `composer.lock`、README、仕様をそろえる。
- WordPress本体、node_modules、vendorを直接改変しない。
- DB更新を追加するときは、既存データからの更新テストと復旧手順を追加する。

環境:

- 開発とテストはLinux上のDocker（wp-env）が前提。PHP、Composer、WP-CLI、DBはホストに導入せず、`scripts/tasks.mjs` 経由でコンテナ内で実行する。
- 開発環境（8888）もテストも外部に接続しない。
- `setup` と `seed` の再実行で既存のページとDBを削除しない。
- 外部への配備や本番の変更は、ローカル検証とは別の作業として扱う。

配布ZIP:

- テストや開発環境を含めない。
- プラグインの実行用vendor（`plugins/museum-calendar/vendor`）は含める。ルートの開発用vendorは含めない。

進め方:

- コード変更後は変更に応じたテスト、完成前には `npm run check` を実行する。
- 動作の正本は `docs/specification.md`。動作を変えたら仕様とREADMEを合わせて直す。
- READMEは、プラグインを導入してデザインを合わせる人（サイト管理者、Webデザイナー）向けの最低限の手順だけにする。開発者向けの内容は `docs/development.md`、運用と配備は `docs/operations.md` に書く。
- 文書、画面のメッセージ、開発用スクリプトのメッセージ、コード内のコメント、コミットメッセージは日本語。識別子、テスト名、テストの検査メッセージ（PHPUnitとPlaywrightのアサーション、統合テストの `$check`、配備スクリプトの `ok:` の行）、画面に出さない例外のメッセージ（`invalid_feed` などのコードを含む）は英語のまま。

## コマンド

全コマンドの表は `docs/development.md` にある。

| コマンド | 内容 |
| --- | --- |
| `npm run setup` | 開発環境を起動し、依存を導入して確認ページを作る |
| `npm run stop` | 開発環境とテスト環境のコンテナを停止（DBのボリュームは残る） |
| `npm run check` | テスト環境（8889）で構文検査、`composer validate`×2、PHPUnit、統合、Playwright。構文検査は `scripts/lint.php` の `php -l` だけで、スタイルの検査はない |
| `npm run test:unit` | PHPUnit（`tests/unit`）。WordPressを読み込まない |
| `npm run test:integration` | `wp eval-file` で `tests/integration/plugin.php` と `ics.php` を実行 |
| `npm run test:e2e` | Playwright。desktopとmobile（Pixel 7）の2プロジェクト、workers 1 |
| `npm run wp -- <args>` | 開発環境でWP-CLI |
| `npm run package` | `dist/` に配布ZIP。版はプラグインヘッダーから読む |
| `npm run calendar:copy -- '<URL>'` | 開発環境が読む写しを更新。ホストのNode.jsだけで動く |

テストを1件だけ流すときは、テスト環境を起動した後（`npm run test:unit` を一度実行するなど）に次を使う。

```sh
WP_ENV_HOME=$PWD/.cache/wp-env node_modules/.bin/wp-env --config .wp-env.test.json \
  run cli --env-cwd=workspace php vendor/bin/phpunit --filter MonthTest
npx playwright test -g '<テスト名>' --project=desktop
```

- `.wp-env.test.json` は `tasks.mjs` が `.wp-env.json` から毎回生成する。直接編集しない。
- ポート8888と8889は固定。別のwp-envプロジェクトが同じポートを使っていると起動に失敗する。

## 構成

表示までのデータの流れ:

1. `Admin\Settings` がURLからカレンダーIDを `museum_calendar_google_calendar` に、非公開URLの秘密の部分を `museum_calendar_google_calendar_secret` に保存する（公開URLなら後者は削除）。URLの検証は `Ics\GoogleCalendar::fromUrl()`。取得先URL（`feedUrl()`）もこのクラスがIDと秘密の部分から組み立てる。設定画面の保存は `Settings::saveAndRefresh()` で、`save()` に続けて「今すぐ更新」と同じ取得を行う。入力欄が空なら接続を変えず、「接続を解除する」のチェックで両方のオプションと取得済みの予定を削除する。
2. `Ics\FeedRepository` がICSを取得してオプション `museum_calendar_feed` にキャッシュする。15分間隔、ロック、失敗時は最後に成功した古いデータを期限なく返す（`stale`）。保存前に `Ics\Parser::forMonth()` で当月を展開して検査し、壊れたデータで正常なキャッシュを上書きしない。
3. `Ics\Source`（`Calendar\EventSource` の実装）が `Parser::forMonth()` で月ごとに展開する。`Parser::description()` が説明欄をプレーンテキストにし、URLだけの最初の行を `url` として取り出す。
4. `Calendar\EventList::forDisplay()` が `classify()` でタイトルから `kind` と `color` を付け、`closed` のある日の `open` を除く。本日の開館状況（`$status`）は `EventList::status()`。
5. `Frontend\Renderer` が `templates/month.php` と `today.php` を描画する。テーマの `museum-calendar/*.php` があればそちらを使う（`locate_template`）。
6. `Frontend\Language` が `lang="en"` の文言とロケールの切り替えを持つ。予定のタイトルから種類（`kind`）と英語を決める表は `Calendar\KindTable`。管理画面で編集でき、保存した表は `Settings::kinds()` がオプション `museum_calendar_kinds` から読む（ないか読めなければ `KindTable::DEFAULTS`）。表は `museum-calendar.php` が `Renderer` に渡し、`EventList::forDisplay()`/`classify()` と `Language::event()` に引数で届く。既定の行を変えるときは `DEFAULTS`。`Language::event()` が付けるのは `label` と `label_lang` だけ。

予定の形は全段で共通で、日付（`YYYY-MM-DD`）をキーにした配列の中に `title`、`time`、`all_day`、`start`、`end`、`description`、`url` を持つ（定義は `Calendar\EventSource` のdocblock）。
`kind` と `color`（表の行の背景色、なければ空文字）は4で、`label` と `label_lang` は6で加わる。
キーを足すときは、`Calendar\EventSource` のdocblockと仕様の「テンプレートの差し替え」をそろえる。

`plugins/museum-calendar/samples/*.css` は、利用者が追加CSSに写して使うデザインのサンプルで、プラグインは読み込まない。
既定のテンプレートのマークアップ、`assets/calendar.css`、CSS変数、予定の種類を変えたら、サンプルも合わせる。
`default.css` の値と `calendar.css` の既定値の一致は `SampleStylesTest` が検査する。

ショートコードの登録、Cronの設定、エラー時の案内文の出し分けは `museum-calendar.php` にまとまっている。
プラグインの削除時は `uninstall.php` が全オプションとCronの予定を消す。プラグインを読み込まずに動くので、オプション名は定数でなく文字列で書く。オプションを足したら `uninstall.php` にも足す（統合テストが `Settings` と `FeedRepository` の定数と突き合わせる）。
訪問者が `?museum_month=` で開ける月は翌々月まで（`Calendar\Month::lastNavigable()`）。ショートコードの `month` 属性はこの制限を受けない。
カレンダー未設定なら、ショートコードは接続が設定されていないことだけを表示する。

## 開発環境の仕組み

- 開発環境は、ステージングのサンプルカレンダーの写し（`dev/calendar-copy/calendar.ics`）を読む。`dev/calendar-copy` プラグインが、仮のカレンダー `museum-dev-copy@group.calendar.google.com` への取得をこのファイルで返す。`seed` はカレンダーが未登録のときだけこの仮のカレンダーを（公開カレンダーとして）登録する。
- 写しは公開リポジトリに入る。`npm run calendar:copy` は非公開URLも公開URLも受け付けるが、写すのは公開してよい内容のカレンダーだけ。URLと秘密の部分は出力せず、写しに含まれていれば書き込まない。URLはシェルの履歴に残るので、非公開URLを渡した後は履歴から消す。
- Git対象外の `.wp-env.override.json` があると、開発環境は `.local/site/` にある実サイトのテーマの写しを使う（`npm run site`）。このテーマは `museum-calendar/*.php` でテンプレートを差し替えるので、8888の表示はプラグインの既定のテンプレートと異なることがある。テスト環境はこの指定を読まない。

## テスト環境の仕組み

- テスト環境には `tests/support/ics-fixture` プラグインが入る。`pre_http_request` で決まったGoogleカレンダーのURL（`museum-fixture` の公開URLと、作った秘密の部分の非公開URL）への取得を `tests/fixtures/museum.ics` に差し替える。`seed` はこのカレンダーを公開カレンダーとして登録する。
- テーマは `tests/support/override-theme`。テンプレート差し替えの確認に使う。
- `ics-fixture` は `?museum_fixture_today=open|closed|none` のとき、そのリクエストだけ保存済みの予定（`option_museum_calendar_feed` フィルター）を本日の予定だけのICSに差し替える。取得し直さず、DBにも書かない。Playwrightは日付を選べないため、`seed` がテスト環境だけに作る `calendar-today` ページと組み合わせて開館状況を確認する。
- 単体テストの `tests/bootstrap.php` はプラグインのvendorを読むだけで、WordPressの関数は使えない。`apply_filters` やオプションを通る動作は統合テストに書く。
- 統合テストはPHPUnitではない。`$check(条件, メッセージ)` を並べたスクリプトで、失敗すると例外で止まる。`pre_http_request` を自前でモックし、オプションを退避して終了時に復元する。

## 版と配備

- 版を上げるときは、プラグインヘッダーの `Version`、`wp_register_style` の版、`package.json`、`package-lock.json`、仕様の見出し、`docs/development.md` のタグの例をそろえる。
- mainへのpushで「Calendar checks」が通ると、GitHub Actionsがプラグインをステージングに自動で配備する。mainへのpushはステージングの変更を伴う。
- `Version` と同じ番号の `v*` タグをpushすると、配布ZIPをGitHub Releasesに添付する。本番への自動配備はない。
- 配備、予定の登録、復旧の手順は `docs/operations.md`。
