# Museum Calendar for WordPress

Googleカレンダーの予定を、WordPressのページに月間カレンダーと本日の開館状況として表示します。[表示サンプル](https://collaborative-macaw-b043ed.instawp.site)

## インストール

WordPress 7.1.4以上、PHP 8.5.12以上、MariaDB 10.11.18以上が必要です。

1. [Releases](https://github.com/noguchi/museum-calendar/releases) から `museum-calendar-<版>.zip` をダウンロードし、WordPressの「プラグインのアップロード」でインストールして有効化します。
2. サイト掲載用のGoogleカレンダーを作り、予定を入力する職員と共有します。カレンダーは非公開のまま使えます。
3. Googleカレンダーの「設定と共有 → カレンダーの統合」から「iCal形式の非公開URL」をコピーし、WordPressの「設定 → Museum Calendar」に貼り付けて保存します。「取得状況」で最終取得日時を確認します。公開カレンダーを使う場合は「すべての予定の詳細を表示」を選び、「iCal形式の公開URL」を登録します。

## ページに表示する

固定ページや投稿にショートコードを置きます。

| ショートコード | 表示内容 |
| --- | --- |
| `[museum_calendar]` | 月間カレンダー。1ページにつき1つ |
| `[museum_calendar_today]` | 本日の日付と開館状況 |

英語のページでは、それぞれに `lang="en"` を付けます。予定名の英訳と種類ごとの色は「設定 → Museum Calendar」で編集できます。Googleカレンダーの変更をすぐに反映したいときは、同じ画面の「今すぐ更新」を押します。[予定の登録手順](docs/operations.md#予定の登録)

## デザインをサイトに合わせる

- 色や寸法を変えるには、プラグインの [samples/](plugins/museum-calendar/samples/) からCSSを1つ選び、中身をテーマのCSSか追加CSSにコピーして編集します。黒地にするには `monochrome.css`、明るい多色の配色にするには `pop.css` を使います。すでにサンプルを貼っている場合は、その部分を丸ごと差し替えます。
- HTMLを変えるには、[templates/](plugins/museum-calendar/templates/) の `month.php` または `today.php` をテーマの `museum-calendar/` にコピーして編集します。予定名や説明は `esc_html()`、属性は `esc_attr()`、リンク先は `esc_url()` で出力します。[テンプレートに渡る値](docs/specification.md#テンプレートの差し替え)

[仕様](docs/specification.md) ｜ [運用・復旧](docs/operations.md) ｜ [開発の手引き](docs/development.md)

ライセンスはGPL-2.0-or-laterです。
