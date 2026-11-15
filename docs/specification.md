# Museum Calendar for WordPressの仕様（0.6.0）

## 動作環境

- WordPress 7.1.4以上、PHP 8.5.12以上、MariaDB 10.11.18以上。
- 下限は本番環境の現行版（2026-11-15時点）に合わせている。これより古い版は対象外で、動作を確認しない。
- WordPressとPHPの下限はプラグインヘッダーで宣言する。下限に満たない環境では有効化できない。

## 入力・接続

- WordPress「設定 → Museum Calendar」で1つのICS URLを登録。
- 公開用・非公開用のどちらも、URLへのGETだけで取得できる形式に対応。OAuth/APIキーは不要。
- 変更と手動更新には管理者権限とnonceを要求。
- 保存済みURLは画面に再出力しない。入力欄が空なら維持、接続解除チェックで明示的に消去。
- URLと取得したICSはWordPressのDBにautoload無効で保存。DBバックアップにも含まれるため秘密情報として扱う。
- HTTPS・443番ポート・公開IPv4で解決可能なホストのみ。ユーザー名/パスワード付きURL、内部アドレス、リダイレクトは非対応。
- URLを変更/解除すると前のカレンダーのキャッシュを消去する。ICS設定を保存するとサンプル表示は無効。

## 取得・キャッシュ

- WordPressの安全なHTTP APIで取得。タイムアウト10秒、最大2MiB、最大2,000 VEVENT。
- 初回と15分経過後の表示アクセス時に取得。WP-Cronによる15分間隔の取得も設定。
- WP-Cronはサイトへのアクセスで実行されるため、15分以内の反映を保証するものではない。
- 管理画面の「今すぐ更新」で再取得できる。最終成功・最終試行・汎用エラーを表示。
- 失敗時の自動再試行は最低5分間隔。成功済みの予定を最長24時間保持し、閲覧者に前回データであることと最終取得日時を表示。
- 24時間を超えた予定や初回取得失敗時は、予定を表示せず取得できない旨を案内。休館とは判定しない。
- 空の有効なVCALENDARは正常な結果。全予定が削除された場合、古い予定を残さない。
- パースに失敗した取得データで、直前の正常なキャッシュを上書きしない（取得時は当月を展開して検査）。
- 別の月で展開エラーになった場合はその月の表示不可を案内する。
- 同時取得を抑止するロックを使用。キャッシュ未作成中の同時アクセスには一時的に取得不可の案内が出る場合がある。

## 日時・繰り返し

- sabre/vobject 4.6.1で解析・繰り返し展開。表示は常にAsia/Tokyo。
- UTCとTZID付き時刻を日本時間に変換。浮動時刻はX-WR-TIMEZONE、なければAsia/Tokyoで解釈。
- 終日予定（VALUE=DATE）は時差で日付をずらさない。DTENDは終了日を含まない。
- 終日予定でDTEND/DURATIONがなければ1日。時刻付き予定で終了がなければ開始時刻のみ表示。
- 複数日予定は重なる各日に表示。終了が翌日00:00なら翌日の予定には表示しない。
- DAILY/WEEKLY/MONTHLY/YEARLYのRRULE、COUNT/UNTIL、EXDATE、日時形式のRDATE、RECURRENCE-IDによる例外に対応。
- STATUS:CANCELLEDの予定と中止された繰り返しインスタンスは非表示。
- 負荷制限として1系列10,000回の反復、1か月5,000インスタンスまで。秒/分/時単位の繰り返し、RDATEの期間形式などは非対応。
- ライブラリが扱えない規則・タイムゾーンや上限超過はエラーとして扱う。すべてのiCalendar拡張への対応は保証しない。

## 表示

- `[museum_calendar]` で当月を表示。前月・翌月リンク、スマホでの表内横スクロール。
- 月間カレンダーの最初の列は、WordPressの「設定 → 一般 → 週の始まり」で選んだ曜日。7つの曜日すべてに対応。保存されている値が0〜6の整数でない場合は月曜始まり。
- 週の始まりを変えるショートコードの属性やプラグインの設定はない。`[museum_calendar_today]` の表示は週の始まりで変わらない。日付の表記は日付形式に従う。
- 月間カレンダーの見出し、前月と翌月のリンク、曜日の見出しは、WordPressの「設定 → 一般 → サイトの言語」で選んだ言語で出力。
- 見出しの年月は、WordPress本体が月別アーカイブに使う形式（`_x('F Y', 'monthly archives date format')`）で出力。日本語のサイトでは `2026年11月`、英語のサイトでは `November 2026`。
- 前月と翌月のリンクは、移動先の月の名前（`wp_date()` の `F`）を出力。年は出力しない。前月には `«`、翌月には `»` を添える。日本語のサイトでは `« 10月` と `12月 »`、英語のサイトでは `« October` と `December »`。
- 2つのリンクを囲む `nav` の `aria-label` は、WordPress本体の文言 `Previous and next months` の翻訳。日本語のサイトでは `前と次の月`。
- 曜日の見出しは、WordPress本体が持つ曜日の省略名（`WP_Locale::get_weekday_abbrev()`）。日本語のサイトでは `日`〜`土`、英語のサイトでは `Sun`〜`Sat`。
- 見出し、リンク、曜日の表記を変えるショートコードの属性やプラグインの設定はない。プラグインは翻訳ファイルを持たず、WordPress本体の翻訳だけを使う。
- URLのmuseum_monthで月送り。`[museum_calendar month="2026-11"]` は固定月表示。
- `[museum_calendar_today]` で本日の日付と当日の予定を表示。属性なし。予定がなければ「本日の予定はありません」。
- 本日の日付の表記は、WordPressの「設定 → 一般 → 日付形式」で選んだ形式。保存されている値が文字列でない場合と空の場合は `Y年n月j日`。
- 曜日は、日付形式が曜日（`D` または `l`）を含むときだけ表示。曜日と月の名前はサイトの言語で出力。例: 日本語のサイトで `n月j日(D)` なら `11月16日(月)`。
- 日付形式を変えるショートコードの属性やプラグインの設定はない。
- ここまでに挙げた箇所を除くプラグインの文言は、サイトの言語にかかわらず日本語。例: 「この月の予定はありません」などの案内、`section` と横スクロール領域の `aria-label`、時間の「終日」、管理画面の設定ページ。
- 本日の表示はリクエストごとに生成する。WordPress外のページキャッシュを使うと、日付が変わっても前日の内容が残る場合がある。
- 表示範囲1900-01〜2100-12。不正な年月は案内を表示。
- 公開するのはタイトルと時間だけ。説明、参加者、添付、元のICS URLは出力しない。
- 終日を先、時刻付きは開始順に並べる。タイトルと時間をHTMLエスケープ。
- 空の月は「この月の予定はありません」。開館/休館の意味づけは行わない。
- テーマ向けの目印として、月間カレンダーのルートに `id="museum-calendar"`、本日のセルに `aria-current="date"`、各予定の `li` にタイトルを入れた `data-title` を出力。
- 曜日見出しの `th` と日付のあるセルの `td` に、曜日の番号（1＝月〜7＝日）を入れた `data-weekday` を出力。月の外のセルには出力しない。
- 週の始まりによって曜日の列の位置が変わる。曜日ごとの装飾は `td:nth-child(7)` のような列の番号ではなく、`td[data-weekday="7"]` のように指定する。
- 前月と翌月のリンクは `#museum-calendar` 付きで、月送りの後もカレンダーの位置に戻る。同じページに置く月間カレンダーは1つを想定。
- スタイルはハンドル `museum-calendar` で登録する。テンプレートから `do_shortcode()` で呼ぶテーマは `wp_enqueue_style('museum-calendar')` でheadに読み込める。
- 色・寸法、表示する予定、HTMLはテーマから変更できる。方法は「カスタマイズ」を参照。この節のマークアップと並び順は、テーマが変更していない既定の表示のもの。
- ICS未設定かつ開発環境でサンプルオプションが有効な場合のみ、明示したサンプルを表示。

## カスタマイズ

テーマから、色・寸法、表示する予定、HTMLを変更できる。プラグインのファイルは編集しない。何も指定しなければ既定の表示のまま。

### CSS変数

- 色と寸法はCSSカスタムプロパティで変更する。テーマのCSSで `:root` などに宣言する。
- プラグインのCSSは変数を宣言しない。未指定の変数には表の既定値を使う。

| 変数 | 既定値 | 用途 |
| --- | --- | --- |
| `--museum-calendar-text` | `#29251f` | 月間カレンダーと本日の予定の文字色 |
| `--museum-calendar-line` | `#d6d0c4` | 罫線と枠線 |
| `--museum-calendar-accent` | `#635440` | 前月・翌月リンクの文字色、本日のセルの枠 |
| `--museum-calendar-surface` | `#f4f1eb` | 曜日見出しの背景 |
| `--museum-calendar-notice-background` | `#f8f1dc` | サンプル表示と前回データの案内の背景 |
| `--museum-calendar-today-background` | `#fbf6e6` | 本日のセルの背景 |
| `--museum-calendar-focus` | `#3069a1` | キーボードフォーカスの枠 |
| `--museum-calendar-max-width` | `960px` | 月間カレンダーと本日の予定の最大幅 |
| `--museum-calendar-min-width` | `540px` | 月間の表の最小幅。これより狭い画面では表内を横スクロール |
| `--museum-calendar-cell-height` | `6.5rem` | 日付セルの高さ |
| `--museum-calendar-event-background` | `--museum-calendar-surface` の値 | 月間カレンダーの予定の背景 |
| `--museum-calendar-event-accent` | `--museum-calendar-accent` の値 | 月間カレンダーの予定の左線 |

```css
:root {
  --museum-calendar-accent: #1d4f7a;
  --museum-calendar-cell-height: 5rem;
}
```

### 予定の変更

- `museum_calendar_events` フィルターで、表示する前の予定を変更できる。月間カレンダーと本日の予定の両方に適用する。
- 第1引数は、日付（`YYYY-MM-DD`）をキーにした予定のリスト。第2引数は対象の月（`YYYY-MM`）。本日の予定でも、その月の全日分を渡す。

```php
add_filter('museum_calendar_events', function (array $events, string $month): array {
    foreach ($events as $date => $day) {
        $events[$date] = array_filter($day, fn (array $event): bool => $event['title'] !== '館内整理');
    }
    return $events;
}, 10, 2);
```

予定1件は次のキーを持つ配列。

| キー | 型 | 内容 |
| --- | --- | --- |
| `title` | string | タイトル |
| `time` | string | 表示用の時間。終日は `終日`、時刻付きは `10:30–16:30`、終了のない予定は `10:30` |
| `all_day` | bool | 終日予定ならtrue |
| `start` | string | その日の開始時刻（`H:i`）。前日から続く日は `00:00`。終日予定は空文字 |
| `end` | string | その日の終了時刻（`H:i`）。翌日へ続く日は `24:00`。終日予定と終了のない予定は空文字 |

- 戻り値では `title` だけが必須。`time`、`start`、`end` を省略すると空文字、`all_day` を省略するとfalseになる。
- 独自のキーを追加できる。追加したキーはそのままテンプレートに渡る。
- 1日の中の並び順は戻り値の順。配列のキーは無視する。
- 予定が0件になった日は、予定のない日として表示する。
- 戻り値の形式が正しくない場合は予定を表示せず、「この月の予定を表示できません」「本日の予定を表示できません」と案内する。予定のない月や日としては表示しない。
- 正しくない形式: 全体が配列でない、キーが `YYYY-MM-DD` でない、1日分が配列でない、予定が配列でない、`title` が文字列でない、`time`・`start`・`end` が文字列でない、`all_day` が真偽値でない。
- 既定のテンプレートは、フィルターが返したタイトルと時間もHTMLエスケープする。

### テンプレートの差し替え

- テーマに `museum-calendar/month.php`（月間カレンダー）または `museum-calendar/today.php`（本日の予定）を置くと、プラグインのテンプレートの代わりに使う。子テーマ、親テーマの順に探す。
- 元にするファイルは、プラグインの `templates/month.php` と `templates/today.php`。
- 外側の `section` 要素、サンプル表示、前回データの案内はプラグインが出力する。テンプレートが出力するのはその内側だけで、これらを消すことはできない。

```html
<section class="museum-calendar" id="museum-calendar" aria-label="月間カレンダー">
  <p class="museum-calendar__demo">…</p>                  <!-- サンプル表示のときだけ -->
  <p class="museum-calendar__notice" role="status">…</p>  <!-- 前回取得した予定を表示しているときだけ -->
  <!-- month.php の出力 -->
</section>

<section class="museum-calendar-today" aria-label="本日の予定">
  <p class="museum-calendar-today__demo">…</p>
  <p class="museum-calendar-today__notice" role="status">…</p>
  <!-- today.php の出力 -->
</section>
```

- テンプレートに渡すのは次の変数だけ。プラグインのクラスやICS URLは渡さない。
- 変数の値はエスケープしていない。テンプレートで `esc_html()`、`esc_attr()`、`esc_url()` を使って出力する。
- テンプレートで例外が起きた場合は、その出力を捨てて表示できない旨を案内する。

`month.php` の変数:

| 変数 | 型 | 内容 |
| --- | --- | --- |
| `$month` | string | 表示する月（`YYYY-MM`） |
| `$label` | string | 見出し用の年月。WordPressの月別アーカイブの形式とサイトの言語で整形済み。例: 日本語のサイトで `2026年11月` |
| `$weekdays` | array | 曜日の表示名。WordPressの曜日の省略名で、サイトの言語に従う。例: 日本語のサイトで `月`。キーは1（月）〜7（日）。表の列の順に並ぶ。日曜始まりならキーの順は7、1、2、3、4、5、6 |
| `$weeks` | array | 週のリスト。1週は7つのセルで、`$weekdays` と同じ列の順に並ぶ。月の外のセルはnull |
| `$previous` | array または null | 前月。`month`（`YYYY-MM`）、`url`、`label` を持つ。`label` はその月の名前で、サイトの言語に従う。例: 日本語のサイトで `10月`。固定月表示のときと表示範囲の外はnull |
| `$next` | array または null | 翌月。形式は `$previous` と同じ |
| `$empty` | bool | その月に予定が1件もなければtrue |

`$weeks` のセルは次のキーを持つ配列。

| キー | 型 | 内容 |
| --- | --- | --- |
| `date` | string | 日付（`YYYY-MM-DD`） |
| `day` | int | 日 |
| `weekday` | int | その日付の曜日。1（月）〜7（日）。列の位置ではない |
| `today` | bool | 本日ならtrue |
| `events` | array | その日の予定のリスト。予定がなければ空の配列。1件の形式は「予定の変更」の表と同じ |

`today.php` の変数:

| 変数 | 型 | 内容 |
| --- | --- | --- |
| `$today` | DateTimeImmutable | 本日。タイムゾーンはAsia/Tokyo |
| `$date` | string | 本日の日付。WordPressの日付形式で整形済み。例: `11月16日(月)` |
| `$weekday` | string | 本日の曜日の表示名。WordPressの曜日の省略名で、サイトの言語に従う。例: 日本語のサイトで `日`。既定のテンプレートは出力しないが、テーマのテンプレートで引き続き使える |
| `$events` | array | 本日の予定のリスト。予定がなければ空の配列 |

- `$previous['url']` と `$next['url']` は `#museum-calendar` 付きで、月送りの後もカレンダーの位置に戻る。

### 既定のCSSを使わない

- テーマのCSSだけで表示する場合は、`wp_deregister_style('museum-calendar')` を呼ぶ。既定のCSSを読み込まなくなり、ショートコードはそのまま表示する。
- プラグインは `init` でスタイルを登録する。`wp_enqueue_scripts` など、それより後のアクションで呼ぶ。

```php
add_action('wp_enqueue_scripts', function (): void {
    wp_deregister_style('museum-calendar');
});
```

## 今回の対象外

- 本日のOPEN/CLOSED判定、通常開館と臨時変更の優先順位。
- Googleへの書き込み、予約、複数のICS URLの合成、実サイトのテーマ調整。
- WordPress外のページキャッシュの自動消去。キャッシュ製品を使う場合は予定ページの更新設定も確認する。
