# Museum Calendar for WordPressの仕様（0.7.0）

## 動作環境

- WordPress 7.1.4以上、PHP 8.5.12以上、MariaDB 10.11.18以上。本番環境の現行版（2026-11-15時点）に合わせた下限で、これより古い版は対象外。
- WordPressとPHPの下限はプラグインヘッダーで宣言する。下限に満たない環境では有効化できない。

## 入力・接続

- WordPress「設定 → Museum Calendar」で、一般公開したGoogleカレンダー1つの公開URLを登録。OAuth/APIキーは不要。
- 受け付けるのは「このカレンダーの公開URL」（`https://calendar.google.com/calendar/embed?src=<カレンダーID>`。ログイン中のブラウザーで表示される `/calendar/u/<番号>/embed` も可）と「iCal形式の公開URL」（`https://calendar.google.com/calendar/ical/<カレンダーID>/public/basic.ics`）。`src` 以外のクエリ（`ctz` など）は無視する。
- 「iCal形式の非公開URL」、`src` が0個または2個以上のURL、その他のURLは保存しない。
- DBにはカレンダーIDだけを `museum_calendar_google_calendar` にautoload無効で保存。取得先は常に `https://calendar.google.com/calendar/ical/<カレンダーID>/public/basic.ics`。リダイレクトは追わない。
- 入力欄は通常のテキスト欄で、保存済みのカレンダーを `https://calendar.google.com/calendar/embed?src=<カレンダーID>` の形で表示する。iCal形式で入力した場合もこの形で表示する。
- 入力欄を空にして保存すると接続を解除し、取得済みの予定を消去する。
- 公開ページ・テンプレート・エラーメッセージには、カレンダーIDとURLを出力しない。
- 一般公開の範囲が時間のみの場合、Googleはタイトルを含めないため、タイトルは表示できない。「すべての予定の詳細を表示」を前提とする。
- 変更と手動更新には管理者権限とnonceを要求。
- カレンダーを変更/解除すると前のカレンダーのキャッシュを消去する。カレンダーを保存するとサンプル表示は無効。

### 0.7.0以前からの更新

- 0.7.0以前は任意のICS URLを `museum_calendar_ics_url` に保存していた。更新後の最初のリクエスト（`init`）でこの値を移行する。
- 値がGoogleカレンダーのiCal形式のURL（公開・非公開、`calendar.google.com` と `www.google.com`）なら、カレンダーIDだけを登録する。非公開URLの秘密の部分は保存しない。
- 新しい版の設定が既にあっても、iCal形式のURLのカレンダーで置き換える。両方があるのは0.7.0に戻して登録し直した場合だけで、そちらが新しい選択のため。
- どの場合も `museum_calendar_ics_url` と取得済みの予定を削除する。Google以外のICS URLだった場合は未登録になる。
- 非公開URLから移行したカレンダーが一般公開されていない場合、取得に失敗し、管理画面に共有設定の確認を案内する。

## 取得・キャッシュ

- WordPressの安全なHTTP APIで取得。タイムアウト10秒、最大2MiB、最大2,000 VEVENT。
- 初回と15分経過後の表示アクセス時に取得。WP-Cronによる15分間隔の取得も設定。WP-Cronはサイトへのアクセスで動くため、15分以内の反映は保証しない。
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

- `[museum_calendar]` で当月を表示。前月・翌月リンク、スマホでの表内横スクロール。URLの `museum_month` で月送り。
- `[museum_calendar month="2026-11"]` は固定月表示。表示範囲は1900-01〜2100-12。不正な年月は案内を表示。
- `[museum_calendar_today]` で本日の日付、[開館状況](#休館の優先と本日の開館状況)、当日の予定を表示。属性は `lang` だけ。
- 公開するのはタイトルと時間だけ。説明、参加者、添付、カレンダーのURLとIDは出力しない。タイトルと時間をHTMLエスケープ。
- 終日を先、時刻付きは開始順に並べる。
- 予定のない月は「この月の予定はありません」。本日の予定は、開館状況も予定もない日だけ「本日の予定はありません」。
- 開館と休館の扱いは[休館の優先と本日の開館状況](#休館の優先と本日の開館状況)の規則だけ。予定のない日や取得できない日を休館とは表示しない。
- 本日の表示はリクエストごとに生成する。WordPress外のページキャッシュを使うと、前日の内容が残る場合がある。
- カレンダー未設定かつ開発環境でサンプルオプションが有効な場合のみ、明示したサンプルを表示。
- 管理画面の設定ページは常に日本語。

### WordPressの設定に従う箇所

「設定 → 一般」の3つの設定に従う。これらを変えるプラグインの設定やショートコードの属性はない（`lang="en"` を除く）。

| 設定 | 従う箇所 |
| --- | --- |
| 週の始まり | 月間カレンダーの最初の列。値が0〜6の整数でなければ月曜始まり |
| サイトの言語 | 見出しの年月、前月と翌月のリンク、曜日の見出し |
| 日付形式 | 本日の日付。値が文字列でない場合と空の場合は `Y年n月j日` |

- 見出しの年月は、WordPress本体が月別アーカイブに使う形式（`_x('F Y', 'monthly archives date format')`）。日本語のサイトでは `2026年11月`。
- 前月と翌月のリンクは、移動先の月の名前（`wp_date()` の `F`）に `«` と `»` を添える。年は出力しない。日本語のサイトでは `« 10月` と `12月 »`。
- リンクを囲む `nav` の `aria-label` は、WordPress本体の文言 `Previous and next months` の翻訳。
- 曜日の見出しは、WordPress本体の曜日の省略名（`WP_Locale::get_weekday_abbrev()`）。
- 本日の日付の曜日は、日付形式が `D` または `l` を含むときだけ出る。例: `n月j日(D)` なら `11月16日(月)`。
- プラグインは翻訳ファイルを持たない。上記以外の文言は、`lang` を省略すると、サイトの言語にかかわらず日本語。

### テーマ向けのマークアップ

- 月間カレンダーのルートに `id="museum-calendar"`、本日のセルに `aria-current="date"`。
- 各予定の `li` に、カレンダーに登録したタイトルを入れた `data-title` と、[予定の種類](#予定の種類)を入れた `data-kind`。表示の言語で変わらない。
- 曜日見出しの `th` と日付のある `td` に、曜日の番号（1＝月〜7＝日）を入れた `data-weekday`。月の外のセルにはない。週の始まりで列の位置が変わるため、曜日ごとの装飾は `td[data-weekday="7"]` のように指定する。
- 前月と翌月のリンクは `#museum-calendar` 付きで、月送りの後もカレンダーの位置に戻る。同じページに置く月間カレンダーは1つを想定。
- スタイルはハンドル `museum-calendar` で登録する。テンプレートから `do_shortcode()` で呼ぶテーマは `wp_enqueue_style('museum-calendar')` でheadに読み込める。

## 英語での表示

- `[museum_calendar lang="en"]` と `[museum_calendar_today lang="en"]` は、ショートコードが出力する文言をすべて英語にする。`month` と併用できる。
- 指定できる値は `en` だけ。それ以外（`ja` を含む）は、予定を表示せず「言語の指定が正しくありません。」と案内する。`lang=""` は省略と同じ。
- `lang` を省略したショートコードの文言は、0.7.0と同じく日本語。
- サイトの言語は変更しない。出力する間だけWordPressのロケールを `en_US` に切り替え、終えると戻す。英語の言語パックは不要。
- 外側の `section` に `lang="en"` を付ける。`section` を出力しない案内では、案内の `p` に付ける。

| 箇所 | `lang` なし（日本語のサイト） | `lang="en"` |
| --- | --- | --- |
| 月間カレンダーの見出し | `2026年11月` | `November 2026` |
| 前月と翌月のリンク | `« 10月`、`12月 »` | `« October`、`December »` |
| 曜日の見出し | `日`〜`土` | `Sun`〜`Sat` |
| 本日の日付 | 日付形式の設定に従う | 固定の形式 `D, M j`。例: `Mon, Nov 16` |
| 終日予定の時間 | `終日` | `All day` |
| タイトルのない予定 | `（無題）` | `(Untitled)` |

- 案内と `aria-label` も英語にする。文言は `plugins/museum-calendar/src/Frontend/Language.php` の `TEXT`。
- 時刻付きの予定の時間（`10:30–16:30`）は、どちらの言語でも同じ。
- 予定のタイトルは、[予定の種類](#予定の種類)の表の英語に置き換える。機械翻訳は行わない。
- 表にないタイトルは、登録したタイトルのまま表示する。既定のテンプレートは、そのタイトルの要素に `lang="ja"` を付ける。
- `data-title`、`data-kind` と、`museum_calendar_events` フィルターが受け取る `title` は、`lang="en"` でも変わらない。`li[data-kind="tea"]` のようなCSSの指定は、両方の言語のページに効く。

```html
<li data-title="お茶室営業日" data-kind="tea"><strong>Tea Room Open</strong><span>12:00–16:00</span></li>
<li data-title="臨時休館" data-kind="other"><strong lang="ja">臨時休館</strong><span>All day</span></li>
```

## 予定の種類

プラグインは、予定のタイトルから種類（`kind`）を決める。表は1つで、英語での表示も同じ表に持つ。

| 登録したタイトル | `kind` | 英語での表示 |
| --- | --- | --- |
| `OPEN` | `open` | `OPEN` |
| `CLOSE` | `closed` | `CLOSED` |
| `お茶室営業日` | `tea` | `Tea Room Open` |
| `ガイドツアー開催日` | `tour` | `Guided Tour` |
| `結婚式` | `wedding` | `Wedding` |
| `夜間特別開館` | `evening` | `Special Evening Opening` |
| `コンサート` | `concert` | `Concert` |
| `イベント` | `event` | `Event` |

- 前後の空白（全角を含む）を除いた全体が表の行と一致したときだけ、その行の種類にする。部分一致では決めない。大文字と小文字を区別する。
- 表にないタイトルの `kind` は `other`。英語での表示はなく、登録したタイトルのまま表示する。タイトルのない予定も `other`。
- `kind` は `museum_calendar_events` フィルターを適用した直後に、そのタイトルから決めて各予定に追加する。フィルターが入れた値は上書きする。言語によらない処理で、`lang="en"` でも同じ値。
- 既定のテンプレートは、各予定の `li` に `data-kind` を出す。`data-title` も残す。
- プラグインのCSSは種類ごとの色を付けない。テーマが `[data-kind="tour"]` のように指定する。

```css
.museum-calendar li[data-kind="tour"] { --museum-calendar-event-accent: #2f6f4f; }
```

- 種類を足すには、`plugins/museum-calendar/src/Calendar/Kind.php` の表に行を足し、新しい版を配布する。管理画面から登録する機能はない。

## 休館の優先と本日の開館状況

- 同じ日に `closed` の予定があれば、その日の `open` の予定を表示しない。月間カレンダーと本日の予定の両方に適用する。`kind` を決めた後、つまりフィルターを適用した後に行う。
  - 通常の開館を繰り返し予定で登録し、臨時休館はその日に `CLOSE` を足すだけで済む。
  - `closed` の予定は残るため、この規則で予定のない日にはならない。
- 本日の開館状況は、上の規則を適用した後の本日の予定から決める。
  - `closed` の予定があれば `closed`。
  - なければ、`open` の予定があれば `open`。複数あれば並び順で最初の1件。
  - どちらもなければ、なし。休館とは表示しない。
- 判定は日単位（Asia/Tokyo）。閉館時刻を過ぎても `open` のまま。
- 予定を取得できないときは、今までどおり取得できない旨を案内する。開館状況は出さない。前回取得した予定を表示しているときは、その予定から決める。
- 月間カレンダーには開館状況を出さない。
- `today.php` に変数 `$status` を渡す。

| `$status` | 内容 |
| --- | --- |
| null | 開館状況なし |
| 配列 | `kind`（`open` または `closed`）、`label`（その予定の表示する文言。`lang="en"` では英語）、`hours`（`open` で時刻付きの予定なら `10:30–16:30` の形式。それ以外は空文字） |

- 既定の `today.php` は、日付の次に開館状況を出し、予定の一覧からは `open` と `closed` の予定を除く。`$events` には含めたまま渡す。
- 「本日の予定はありません」は、開館状況も一覧に出す予定もないときだけ出す。開館状況だけの日は開館状況だけを出す。

```html
<p class="museum-calendar-today__status" data-kind="open"><strong>OPEN</strong><span>10:30–16:30</span></p>
<p class="museum-calendar-today__status" data-kind="closed"><strong>CLOSED</strong></p>
```

- サンプル表示でも同じ規則。サンプルの予定は `OPEN`、`CLOSE`、`ガイドツアー開催日`、`お茶室営業日` で、サンプルであることの明示は今までどおり。

### 種類が未定義の予定名

- カレンダーを登録していると、管理画面の「設定 → Museum Calendar」に、`kind` が `other` のタイトルを重複なしで一覧にする。タイトルのない予定は含めない。なければ「すべての予定名に種類があります。」と表示する。
- 対象は当月（Asia/Tokyo）から12か月分で、`museum_calendar_events` フィルターを適用した後のタイトル。
- 予定を読み込めないときは、その旨だけを表示する。エラーの詳細は表示しない。
- 設定ページを開いたときにもICSを取得する場合がある。取得の間隔は、ページの表示のときと同じ規則に従う。

## カスタマイズ

テーマから、色・寸法、表示する予定、HTMLを変更できる。プラグインのファイルは編集しない。

### CSS変数

テーマのCSSで `:root` などに宣言する。プラグインのCSSは変数を宣言せず、未指定の変数には表の既定値を使う。

| 変数 | 既定値 | 用途 |
| --- | --- | --- |
| `--museum-calendar-text` | `#29251f` | 月間カレンダーと本日の予定の文字色 |
| `--museum-calendar-line` | `#d6d0c4` | 罫線と枠線 |
| `--museum-calendar-accent` | `#635440` | 前月・翌月リンクの文字色、本日のセルの枠、開館状況の左線 |
| `--museum-calendar-surface` | `#f4f1eb` | 曜日見出しと開館状況の背景 |
| `--museum-calendar-notice-background` | `#f8f1dc` | サンプル表示と前回データの案内の背景 |
| `--museum-calendar-today-background` | `#fbf6e6` | 本日のセルの背景 |
| `--museum-calendar-focus` | `#3069a1` | キーボードフォーカスの枠 |
| `--museum-calendar-max-width` | `960px` | 月間カレンダーと本日の予定の最大幅 |
| `--museum-calendar-min-width` | `540px` | 月間の表の最小幅。これより狭い画面では表内を横スクロール |
| `--museum-calendar-cell-height` | `6.5rem` | 日付セルの高さ |
| `--museum-calendar-event-background` | `--museum-calendar-surface` の値 | 月間カレンダーの予定の背景 |
| `--museum-calendar-event-accent` | `--museum-calendar-accent` の値 | 月間カレンダーの予定の左線 |

### 予定の変更

`museum_calendar_events` フィルターで、表示する前の予定を変更できる。月間カレンダーと本日の予定の両方に適用する。
第1引数は、日付（`YYYY-MM-DD`）をキーにした予定のリスト。第2引数は対象の月（`YYYY-MM`）。本日の予定でも、その月の全日分を渡す。

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

- 戻り値では `title` だけが必須。`time`、`start`、`end` を省略すると空文字、`all_day` を省略するとfalse。
- 独自のキーを追加できる。追加したキーはそのままテンプレートに渡る。
- 1日の中の並び順は戻り値の順。配列のキーは無視する。予定が0件になった日は、予定のない日として表示する。
- 戻り値の形式が正しくない場合は、予定のない月や日としてではなく、「この月の予定を表示できません」「本日の予定を表示できません」と案内する。
- 正しくない形式: 全体が配列でない、キーが `YYYY-MM-DD` でない、1日分が配列でない、予定が配列でない、`title` が文字列でない、`time`・`start`・`end` が文字列でない、`all_day` が真偽値でない。
- フィルターが受け取る `title` と `time` は、`lang="en"` でも日本語の表示のときと同じ値。終日予定の `time` は `終日`。

フィルターを適用した後で、プラグインが各予定にキーを3つ追加する。フィルターが入れた値は上書きする。

| キー | 型 | 内容 |
| --- | --- | --- |
| `label` | string | 表示するタイトル。`lang="en"` では英訳。`lang` なしと英訳のないタイトルでは `title` と同じ |
| `label_lang` | string | `lang="en"` の表示で `label` が英訳のないタイトルのときは `ja`。それ以外は空文字 |
| `kind` | string | [予定の種類](#予定の種類)。表にないタイトルは `other`。`lang` で変わらない |

- `label` と `kind` は、フィルターを適用した後の `title` から決まる。
- `lang="en"` では、フィルターを適用した後の `time` が `終日` と一致する予定の `time` を `All day` に置き換える。それ以外の `time` は変更しない。
- `kind` を追加した後に、`closed` のある日の `open` の予定を除く（[休館の優先と本日の開館状況](#休館の優先と本日の開館状況)）。フィルターで `CLOSE` を足すと、その日の `OPEN` は表示されない。

### テンプレートの差し替え

- テーマに `museum-calendar/month.php`（月間カレンダー）または `museum-calendar/today.php`（本日の予定）を置くと、プラグインのテンプレートの代わりに使う。子テーマ、親テーマの順に探す。
- 元にするファイルは、プラグインの `templates/month.php` と `templates/today.php`。
- 外側の `section`、サンプル表示、前回データの案内はプラグインが出力する。テンプレートが出力するのはその内側だけ。

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

- テンプレートに渡すのは次の変数だけ。プラグインのクラスやカレンダーのURLは渡さない。
- 変数の値はエスケープしていない。テンプレートで `esc_html()`、`esc_attr()`、`esc_url()` を使って出力する。
- テンプレートで例外が起きた場合は、その出力を捨てて表示できない旨を案内する。
- `lang="en"` に対応するテンプレートは、タイトルに `$event['label']` を出力し、固定の文言に `$text` を使う。`$event['title']` や日本語の文言をそのまま出力すると、その部分が日本語のまま残る。
- 以下の表で「サイトの言語」と書いた値は、`lang="en"` では英語になる。

`month.php` の変数:

| 変数 | 型 | 内容 |
| --- | --- | --- |
| `$month` | string | 表示する月（`YYYY-MM`） |
| `$label` | string | 見出し用の年月。サイトの言語で整形済み。例: `2026年11月` |
| `$weekdays` | array | 曜日の省略名。サイトの言語。キーは1（月）〜7（日）で、表の列の順に並ぶ。日曜始まりならキーの順は7、1、2、3、4、5、6 |
| `$weeks` | array | 週のリスト。1週は7つのセルで、`$weekdays` と同じ列の順に並ぶ。月の外のセルはnull |
| `$previous` | array または null | 前月。`month`（`YYYY-MM`）、`url`（`#museum-calendar` 付き）、`label`（月の名前。サイトの言語。例: `10月`）を持つ。固定月表示のときと表示範囲の外はnull |
| `$next` | array または null | 翌月。形式は `$previous` と同じ |
| `$empty` | bool | その月に予定が1件もなければtrue |
| `$lang` | string | ショートコードの言語。`lang` なしは `ja`、`lang="en"` は `en` |
| `$text` | array | 固定の文言。`$lang` の言語。`scroll_label`（横スクロール領域の `aria-label`）と `empty`（予定のない月の案内）を持つ |

`$weeks` のセルは次のキーを持つ配列。

| キー | 型 | 内容 |
| --- | --- | --- |
| `date` | string | 日付（`YYYY-MM-DD`） |
| `day` | int | 日 |
| `weekday` | int | その日付の曜日。1（月）〜7（日）。列の位置ではない |
| `today` | bool | 本日ならtrue |
| `events` | array | その日の予定のリスト。1件は「予定の変更」の2つの表のキーを持つ |

`today.php` の変数:

| 変数 | 型 | 内容 |
| --- | --- | --- |
| `$today` | DateTimeImmutable | 本日。タイムゾーンはAsia/Tokyo |
| `$date` | string | 本日の日付。WordPressの日付形式で整形済み。`lang="en"` では `D, M j` の形式 |
| `$weekday` | string | 本日の曜日の省略名。サイトの言語。既定のテンプレートは出力しない |
| `$status` | array または null | [本日の開館状況](#休館の優先と本日の開館状況)。`kind`、`label`、`hours` を持つ。なければnull |
| `$events` | array | 本日の予定のリスト。`open` と `closed` の予定も含む。1件の形式は `month.php` の `events` と同じ |
| `$lang` | string | ショートコードの言語。`lang` なしは `ja`、`lang="en"` は `en` |
| `$text` | array | 固定の文言。`$lang` の言語。`empty`（予定のない日の案内）を持つ |

既定のテンプレートは、予定を次のように出力する。

```php
<li data-title="<?php echo esc_attr($event['title']); ?>" data-kind="<?php echo esc_attr($event['kind']); ?>"><strong<?php if ($event['label_lang'] !== '') : ?> lang="<?php echo esc_attr($event['label_lang']); ?>"<?php endif; ?>><?php echo esc_html($event['label']); ?></strong><span><?php echo esc_html($event['time']); ?></span></li>
```

### 既定のCSSを使わない

`wp_deregister_style('museum-calendar')` を呼ぶと既定のCSSを読み込まなくなる。プラグインは `init` でスタイルを登録するため、`wp_enqueue_scripts` など、それより後のアクションで呼ぶ。

```php
add_action('wp_enqueue_scripts', function (): void {
    wp_deregister_style('museum-calendar');
});
```

## 今回の対象外

- 時刻による開館状況の切り替え（閉館時刻を過ぎたら `closed` にするなど）。
- 週・日表示、ページを再読み込みしない月送り、「カレンダーに追加」と購読リンク、OAuthによる非公開カレンダー、祝日名の表示。
- Googleへの書き込み、予約、複数のカレンダーの合成、実サイトのテーマ調整。
- WordPress外のページキャッシュの自動消去。キャッシュ製品を使う場合は予定ページの更新設定も確認する。
