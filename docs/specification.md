# Museum Calendar for WordPressの仕様（1.0.0）

## 動作環境

- WordPress 7.1.4以上、PHP 8.5.12以上、MariaDB 10.11.18以上。本番環境の現行版（2026-11-15時点）に合わせた下限で、これより古い版は対象外。
- WordPressとPHPの下限はプラグインヘッダーで宣言する。下限に満たない環境では有効化できない。

## 入力・接続

- WordPress「設定 → Museum Calendar」で、Googleカレンダー1つを登録する。OAuth/APIキーは不要。
- 通常は、一般公開しないカレンダーを組織内で共有し、その「iCal形式の非公開URL」（`https://calendar.google.com/calendar/ical/<カレンダーID>/private-<秘密の部分>/basic.ics`）を登録する。カレンダーは非公開のままでよい。
- 一般公開したカレンダーの「iCal形式の公開URL」（`https://calendar.google.com/calendar/ical/<カレンダーID>/public/basic.ics`）も受け付ける。
- この2つの形式以外のURLは保存しない。「このカレンダーの公開URL」（`/calendar/embed?src=…`）、ホストが `https://calendar.google.com` でないもの、ポートやユーザー情報の付いたもの、パスが余分なもの、`basic.ics` 以外の形式が該当する。前後の空白は除く。
- 秘密の部分は英数字1〜128文字（Googleが発行するのは16進数32桁）。空のものと、これ以外の文字を含むものは保存しない。
- 保存できなかったときは「URLを保存できませんでした。Googleカレンダーの「iCal形式の非公開URL」か「iCal形式の公開URL」を指定してください。」と表示し、接続は変えない。

### 保存する値

- カレンダーIDを `museum_calendar_google_calendar` に、非公開URLの秘密の部分を `museum_calendar_google_calendar_secret` に、どちらもautoload無効で保存する。公開URLを保存したときは `museum_calendar_google_calendar_secret` を削除する。
- 秘密の部分は平文で `wp_options` に入る。DBのバックアップやエクスポートを読める人は、予定をすべて読める。
- 保存した秘密の部分が読めない（形式の誤りなど）ときは未登録として扱い、公開URLでの取得に切り替えない。
- 取得先は、カレンダーIDと秘密の部分から組み立てた非公開URL。秘密の部分がなければ公開URL。リダイレクトは追わない。
- [予定の種類](#予定の種類)の表を既定から変えたときは、その表を `museum_calendar_kinds` にautoload無効で保存する。
- プラグインを無効化すると、WP-Cronの定期取得（`museum_calendar_refresh_feed`）を止める。オプションは残し、再び有効化すると同じ設定で動く。
- 管理画面でプラグインを削除すると、`uninstall.php` がプラグインの保存するオプション（`museum_calendar_google_calendar`、`museum_calendar_google_calendar_secret`、`museum_calendar_kinds`、`museum_calendar_feed`、取得のロック `museum_calendar_feed_lock`）をすべて削除し、定期取得の予定を消す。ほかのオプションは消さない。

### 設定画面

- 入力欄の名前は「iCal形式の非公開URL」。`<input type="password" autocomplete="new-password" spellcheck="false">` で、常に空で表示する。保存したURL（非公開、公開とも）は画面に戻さない。
- 入力欄の上に、登録状況を1行で表示する。「登録状況：未登録」「登録状況：非公開URLを登録済み」「登録状況：公開URLを登録済み」のどれか。カレンダーIDは出さない。
- 入力欄にURLを入れて保存すると、そのカレンダーに接続する。入力欄を空のまま保存すると、接続は変えない。
- どちらの保存でも、カレンダーを登録していれば、続けて「今すぐ更新」と同じ取得を行う（[取得・キャッシュ](#取得キャッシュ)）。取得に失敗しても設定は保存し、失敗は取得状況に表示する。保存できなかったときは取得しない。
- カレンダーを登録しているときだけ「接続を解除する」のチェックボックスを出す。チェックして保存すると、入力欄の内容にかかわらず接続を解除し、2つのオプションと取得済みの予定を削除する。取得は行わない。

### 秘密の部分とURLの扱い

- 秘密の部分を置くのは、DBのオプションと、Googleへの取得のリクエストだけ。
- 設定画面（入力欄の値を含む）、公開ページ、テンプレート、通知、エラーメッセージ、取得状況に保存するエラー、ログには、秘密の部分、非公開URL、カレンダーID、公開URLを出力しない。取得の失敗は汎用の文言だけを保存する（[取得・キャッシュ](#取得キャッシュ)）。
- PHPのスタックトレースとデバッグ出力（`var_dump()`、`print_r()`）にも秘密の部分を出さない（`#[\SensitiveParameter]` と `__debugInfo()`）。
- 公開カレンダーで、一般公開の範囲が時間のみの場合、Googleはタイトルと説明を含めないため、どちらも表示できない。「すべての予定の詳細を表示」を前提とする。非公開URLでは、カレンダーの予定の詳細がすべて含まれる。
- 変更と手動更新には管理者権限とnonceを要求。
- カレンダーを変更/解除すると前のカレンダーのキャッシュを消去する。同じカレンダーの非公開URLと公開URLの切り替え、秘密の部分の変更（Googleでリセットした後の登録し直し）も変更として扱う。同じURLを保存し直したときはキャッシュを残す。

## 取得・キャッシュ

- WordPressの安全なHTTP APIで取得。タイムアウト10秒、最大2MiB、最大2,000 VEVENT。
- 初回と15分経過後の表示アクセス時に取得。WP-Cronによる15分間隔の取得も設定。WP-Cronはサイトへのアクセスで動くため、15分以内の反映は保証しない。
- 管理画面の「今すぐ更新」で再取得できる。取得状況には、最後に成功した取得の日時を「最終取得」として分まで表示する（日本時間）。直近の取得に失敗しているときだけ、その日時と汎用エラーを「取得失敗」として続けて表示する。一度も取得していなければ「まだ取得していません。」。
- 汎用エラーは原因によらず「更新に失敗しました。Googleカレンダーで非公開URLをリセットした場合やカレンダーを削除した場合は、新しい「iCal形式の非公開URL」を登録し直してください。公開URLで登録している場合は、一般公開と「すべての予定の詳細を表示」の設定を確認してください。」。HTTP APIのエラーと例外のメッセージは、取得先URLや予定のデータを含みうるため保存しない。
- 失敗時の自動再試行は最低5分間隔。最後に取得に成功した予定を期限なく保持して表示し続け、閲覧者に前回データであることと最終取得日時を表示。
- 一度も取得に成功していないとき（初回取得の失敗、カレンダーの変更直後の失敗）は、予定を表示せず取得できない旨を案内。
- 空の有効なVCALENDARは正常な結果。全予定が削除された場合、古い予定を残さない。
- キャッシュ（`museum_calendar_feed`）に保存するのは取得したICSそのものと、取得先URLのSHA-256（取得先の変更の検出用。URLそのものは保存しない）。予定のキーを変えてもDBの更新は不要で、保存済みのキャッシュのまま表示できる。
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
- 月送りで表示できるのは1900-01から翌々月（日本時間の当月の2か月後）まで。翌々月には翌月リンクを出さない。`museum_month` が翌々月より後の月なら、カレンダーを出さず「この月の予定はまだ表示できません。」と案内する。
- `[museum_calendar month="2026-11"]` は固定月表示。表示範囲は1900-01〜2100-12で、翌々月より後の月も表示する。不正な年月は案内を表示。
- `[museum_calendar_today]` で本日の日付、[開館状況](#休館の優先と本日の開館状況)、当日の予定を表示。属性は `lang` だけ。
- 公開するのはタイトル、時間、説明、予定のURL。場所、参加者、主催者、添付、カレンダーのURLとIDは出力せず、テンプレートにも渡さない。
- 既定のテンプレートはタイトルと時間をHTMLエスケープして出力し、説明は出力しない。説明はテーマのテンプレートが出す（[予定の説明](#予定の説明)）。
- 予定のURL（[予定のURL](#予定のurl)）があれば、既定のテンプレートは予定の名前をそのURLへのリンクにする。`target` は付けない。
- 終日を先、時刻付きは開始順に並べる。
- 予定のない月は「この月の予定はありません」。本日の予定は、開館状況も予定もない日だけ「本日の予定はありません」。
- 開館と休館の扱いは[休館の優先と本日の開館状況](#休館の優先と本日の開館状況)の規則だけ。予定のない日や取得できない日を休館とは表示しない。
- 本日の表示はリクエストごとに生成する。WordPress外のページキャッシュを使うと、前日の内容が残る場合がある。
- カレンダー未設定のときは「カレンダーの接続が設定されていません。」だけを表示する。
- 管理画面の設定ページは常に日本語。使い方の説明は画面に置かず、READMEへのリンクだけを出す。上から、[カレンダーのURL](#設定画面)、取得状況、[予定の種類と英語での表示](#表の編集)、[種類が未定義の予定名](#種類が未定義の予定名)の順に並ぶ。

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
- 表の行に色があれば、その行の予定の `li` と本日の開館状況の `p` に `style="--museum-calendar-kind-color: #rrggbb"`。色のない行と表にないタイトルでは `style` を出さない。
- 曜日見出しの `th` と日付のある `td` に、曜日の番号（1＝月〜7＝日）を入れた `data-weekday`。月の外のセルにはない。週の始まりで列の位置が変わるため、曜日ごとの装飾は `td[data-weekday="7"]` のように指定する。
- 前月と翌月のリンクは `#museum-calendar` 付きで、月送りの後もカレンダーの位置に戻る。同じページに置く月間カレンダーは1つを想定。
- スタイルはハンドル `museum-calendar` で登録する。テンプレートから `do_shortcode()` で呼ぶテーマは `wp_enqueue_style('museum-calendar')` でheadに読み込める。

## 英語での表示

- `[museum_calendar lang="en"]` と `[museum_calendar_today lang="en"]` は、見出しと案内を英語で表示する。予定名には登録済みの英訳を使う。`month` と併用できる。
- 指定できる値は `en` だけ。それ以外（`ja` を含む）は、予定を表示せず「言語の指定が正しくありません。」と案内する。`lang=""` は省略と同じ。
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
- 予定のタイトルは、[予定の種類](#予定の種類)の表の「英語での表示」に置き換える。機械翻訳は行わない。
- 表にないタイトルと、表の「英語での表示」が空欄の行のタイトルは、登録したタイトルのまま表示する。既定のテンプレートは、そのタイトルの要素に `lang="ja"` を付ける。本日の開館状況の `strong` も同じ。
- `data-title` と `data-kind` は、`lang="en"` でも変わらない。`li[data-kind="tea"]` のようなCSSの指定は、両方の言語のページに効く。

次の例は、最後の1行を除いて、色のない予定の出力。

```html
<li data-title="お茶室営業日" data-kind="tea"><strong>Tea Room Open</strong><span>12:00–16:00</span></li>
<li data-title="臨時休館" data-kind="other"><strong lang="ja">臨時休館</strong><span>All day</span></li>
<li data-title="コンサート" data-kind="concert"><a href="https://example.com/concert"><strong>Concert</strong></a><span>13:00–14:00</span></li>
<li data-title="ガイドツアー開催日" data-kind="tour" style="--museum-calendar-kind-color: #e3f2fd"><strong>Guided Tour</strong><span>11:00–12:00</span></li>  <!-- 既定の表の色 -->
```

## 予定の種類

プラグインは、予定のタイトルから種類（`kind`）、英語での表示、色（`color`）を1つの表で決める。
表は管理画面の「設定 → Museum Calendar」の「予定の種類と英語での表示」で編集する。次の8行は既定の表で、表を保存したことのないサイトはこの表を使う。

| 登録したタイトル | `kind` | 英語での表示 | 色 |
| --- | --- | --- | --- |
| `開館日` | `open` | `OPEN` | `#ffebee` |
| `休館日` | `closed` | `CLOSED` | `#f5f5f5` |
| `お茶室営業日` | `tea` | `Tea Room Open` | `#e8f5e9` |
| `ガイドツアー開催日` | `tour` | `Guided Tour` | `#e3f2fd` |
| `結婚式` | `wedding` | `Wedding` | `#fff0fc` |
| `夜間特別開館` | `evening` | `Special Evening Opening` | `#fff9f4` |
| `コンサート` | `concert` | `Concert` | `#f5f5f5` |
| `イベント` | `event` | `Event` | `#f5f5f5` |

- 前後の空白（全角を含む）を除いた全体が表の行と一致したときだけ、その行の種類にする。部分一致では決めない。大文字と小文字を区別する。
- 表にないタイトルの `kind` は `other`。英語での表示はなく、登録したタイトルのまま表示する。タイトルのない予定も `other`。
- 複数のタイトルに同じ `kind` を付けられる（例: `臨時休館` にも `closed`）。`open` と `closed` は[本日の開館状況と休館の優先](#休館の優先と本日の開館状況)に使う。ほかの `kind`（例: `workshop`）は表示とテーマの指定にだけ使う。
- `color` は行の色（`#rrggbb`、小文字）で、予定の背景色そのもの。色のない行と表にないタイトルでは空文字。GoogleカレンダーのICSは予定の色を含まないため、色はこの表だけで決める。
- プラグインのCSSは、表の色を月間カレンダーの予定と本日の開館状況の背景に使い、そこから左線と文字色を作る（[CSS変数](#css変数)）。本日の予定の一覧には色を付けない。
- テーマのCSSで `--museum-calendar-event-background` か `--museum-calendar-event-accent` を指定すると、表の色より優先する。種類ごとの指定は `[data-kind="tour"]` のように書く。

```css
.museum-calendar li[data-kind="tour"] { --museum-calendar-event-accent: #2f6f4f; }
```

### 表の編集

- 表は1つのフォームで、保存済みの行のあとに空欄の行が3行ある。空欄の行に入力すると行を追加する。URLのフォームとは別に保存し、互いの設定と取得済みの予定を変えない。表の保存では取得しない。
- 登録したタイトル: 前後の空白を除いて保存する。空欄の行は、ほかの欄の内容にかかわらず保存しない（行の削除）。200文字まで。改行と制御文字は使えない。同じタイトルの行は2つ置けない。
- `kind`: 必須。英小文字で始まり、英小文字・数字・ハイフンが続く40文字以内。`other` は表にないタイトル用なので使えない。
- 英語での表示: 前後の空白を除いて保存する。空欄でもよい。200文字まで。改行と制御文字は使えない。
- 色: 予定の背景色。空欄（色なし）か、`#` に続く6桁の16進数（例: `#e3f2fd`）。前後の空白を除き、小文字にして保存する。大文字でも入力できる。3桁の形式、色の名前、`rgb()` などは使えない。
  - 文字の入力欄の左に、ブラウザー標準の色の選択（`<input type="color">`）を置き、JavaScriptで文字の入力欄と同期する。送信するのは文字の入力欄だけで、JavaScriptがなくても入力できる。
  - 色の選択は空にできないため、色のない行では白を薄く、点線で囲んで表示する。
- 行は100行まで。
- 1か所でも誤りがあれば何も保存せず、誤りの内容を表の見出しの下に表示する。色の誤りは「色は #e3f2fd のように、# に続く6桁の16進数で入力してください。」と案内する。入力した内容は画面に残らず、保存済みの表を表示する。保存できたときの案内も同じ場所に表示する。
- 保存した表が既定の表と同じなら、`museum_calendar_kinds` を削除する。「既定の表に戻す」をチェックして保存すると、入力した行は使わず `museum_calendar_kinds` を削除する。すべての行を消して保存すると空の表を保存し、すべてのタイトルが `other` になる。
- 保存済みの値が読めない（形式の誤りなど）ときは、既定の表を使う。公開ページはエラーにしない。
- 変更と保存には管理者権限とnonceを要求する。

### 種類が未定義の予定名

- カレンダーを登録していると、管理画面の「設定 → Museum Calendar」に、保存した表で `kind` が `other` になるタイトルを重複なしで一覧にする。表に行を足すと、そのタイトルは一覧から消える。タイトルのない予定は含めない。該当するタイトルがなければ、この節を表示しない。
- 対象は当月（Asia/Tokyo）から12か月分のタイトル。
- 予定を読み込めないときは、その旨だけを表示する。エラーの詳細は表示しない。
- 設定ページを開いたときにもICSを取得する場合がある。取得の間隔は、ページの表示のときと同じ規則に従う。

## 休館の優先と本日の開館状況

- 同じ日に `closed` の予定があれば、その日の `open` の予定を表示しない。月間カレンダーと本日の予定の両方に適用する。`kind` を決めた後に行う。
  - 通常の開館を繰り返し予定で登録し、臨時休館はその日に `休館日` を足すだけで済む。
- 本日の開館状況は、上の規則を適用した後の本日の予定から決める。
  - `closed` の予定があれば `closed`。
  - なければ、`open` の予定があれば `open`。複数あれば並び順で最初の1件。
  - どちらもなければ、なし。休館とは表示しない。
- 判定は日単位（Asia/Tokyo）。閉館時刻を過ぎても `open` のまま。
- 予定を取得できないときは、取得できない旨だけを案内し、開館状況は出さない。前回取得した予定を表示しているときは、その予定から決める。
- 月間カレンダーには開館状況を出さない。
- `today.php` に変数 `$status` を渡す。

| `$status` | 内容 |
| --- | --- |
| null | 開館状況なし |
| 配列 | `kind`（`open` または `closed`）、`color`（その予定の `color`。色がなければ空文字）、`label`（その予定の表示する文言。`lang="en"` では英語）、`label_lang`（その予定の `label_lang`。`lang="en"` で `label` が英語での表示のない日本語のタイトルなら `ja`、それ以外は空文字）、`hours`（`open` で時刻付きの予定なら `10:30–16:30` の形式。それ以外は空文字） |

- 既定の `today.php` は、日付の次に開館状況を出し、予定の一覧からは `open` と `closed` の予定を除く。`$events` には含めたまま渡す。
- 次の例は、最後の1行を除いて、色のない予定の出力。

```html
<p class="museum-calendar-today__status" data-kind="open"><strong>開館日</strong><span>10:30–16:30</span></p>
<p class="museum-calendar-today__status" data-kind="closed"><strong>休館日</strong></p>
<p class="museum-calendar-today__status" data-kind="open"><strong>OPEN</strong><span>10:30–16:30</span></p>  <!-- lang="en" -->
<p class="museum-calendar-today__status" data-kind="closed"><strong>CLOSED</strong></p>  <!-- lang="en" -->
<p class="museum-calendar-today__status" data-kind="closed"><strong lang="ja">臨時休館</strong></p>  <!-- lang="en" で、英語での表示が空欄の closed の行 -->
<p class="museum-calendar-today__status" data-kind="closed" style="--museum-calendar-kind-color: #f5f5f5"><strong>休館日</strong></p>  <!-- 既定の表の色 -->
```

## 予定の説明

- 各予定にキー `description`（string）を持たせる。ICSのDESCRIPTIONをプレーンテキストにしたもの。説明のない予定は空文字。
- 変換は次の順に行う。
  1. 文字参照（`&lt;`、`&amp;`、`&nbsp;` など）を元の文字に戻す。1回だけ。
  2. `script` と `style` の要素を中身ごと除く。
  3. `<br>`（`<br/>`、`<br />`、大文字を含む）と、段落・`div`・リスト項目の終わり（`</p>`、`</div>`、`</li>`）を改行にする。
  4. ほかのタグを除く。タグの中の文字は残す。
  5. 改行をLFにそろえ、前後の空白（全角を含む）を除く。途中の改行と空白は残す。
  6. URLだけの行を取り出す（[予定のURL](#予定のurl)）。
  7. 500文字を超える分は切り捨て、末尾に `…` を付ける。文字数は変換後の文字で数える。
- 文字参照を先に戻すため、`&lt;script&gt;` のようにエスケープしたタグも除かれる。タグに見える文字列（`a<b` など）も失われる。`<` の後に空白があれば（`1 < 2`）残る。
- `<a>` はタグを除き、リンクの文字だけを残す。`href` は使わない。
- 文中のURLはただの文字として残す。リンクにはしない。
- 翻訳しない。`lang="en"` でも登録した説明のまま。
- 改行を見せるには、テーマのテンプレートでCSSの `white-space: pre-line` などを使う。
- カレンダー自体が非公開でも、説明は公開情報として扱う。サイトに載せる予定だけを入れた専用のカレンダーを使う。

## 予定のURL

- 各予定にキー `url`（string）を持たせる。URLのない予定は空文字。
- 説明をプレーンテキストにした後、500文字で切る前に、前後の空白（全角を含む）を除くとURLだけになる最初の行を `url` にし、その行を説明から除く。除いた結果、説明の先頭と末尾に残る空行と空白は除く。途中の空行は残す。
- 500文字より後の行でも取り出す。説明がURLだけの予定は、説明が空文字になる。
- Googleカレンダーは入力したURLを `<a href="…">…</a>` で保存する。タグを除いた後のリンクの文字で判定する。
- URLとして扱う条件: `http://` または `https://` で始まる（大文字と小文字を区別しない）、ホストがある、空白と制御文字を含まない。満たさない行（`javascript:`、`ftp:`、`https:///a` など）は説明に文字として残し、後の行を探す。
- 2行目以降のURLだけの行と、文中のURLは説明に残す。
- 繰り返し予定の各回は、元の予定のURLを持つ。説明を変えた回（RECURRENCE-ID）は、その回の説明から決める。
- リンクにするのは月間カレンダーと本日の予定の一覧の名前。本日の開館状況（`開館日`・`休館日`、`lang="en"` では `OPEN`・`CLOSED`）はリンクにしない。

## カスタマイズ

テーマから、色・寸法、表示する予定、HTMLを変更できる。プラグインのファイルは編集しない。

### CSS変数

テーマのCSSで `:root` などに宣言する。プラグインのCSSは変数を宣言せず、未指定の変数には表の既定値を使う。

| 変数 | 既定値 | 用途 |
| --- | --- | --- |
| `--museum-calendar-text` | `#29251f` | 月間カレンダーと本日の予定の文字色 |
| `--museum-calendar-line` | `#d6d0c4` | 罫線と枠線 |
| `--museum-calendar-accent` | `#635440` | 前月・翌月リンクと予定の名前のリンクの文字色、本日のセルの枠、開館状況の左線 |
| `--museum-calendar-surface` | `#f4f1eb` | 曜日見出しと開館状況の背景。月間カレンダーの予定の背景の既定値 |
| `--museum-calendar-notice-background` | `#f8f1dc` | 前回データの案内の背景 |
| `--museum-calendar-today-background` | `#fbf6e6` | 本日のセルの背景 |
| `--museum-calendar-focus` | `#3069a1` | キーボードフォーカスの枠 |
| `--museum-calendar-max-width` | `960px` | 月間カレンダーと本日の予定の最大幅 |
| `--museum-calendar-min-width` | `540px` | 月間の表の最小幅。これより狭い画面では表内を横スクロール |
| `--museum-calendar-cell-height` | `6.5rem` | 日付セルの高さ |
| `--museum-calendar-event-background` | `--museum-calendar-kind-color` の値。なければ `--museum-calendar-surface` の値 | 月間カレンダーの予定の背景 |
| `--museum-calendar-event-accent` | `--museum-calendar-kind-accent` の値。なければ `--museum-calendar-accent` の値 | 月間カレンダーの予定の左線 |

[予定の種類](#予定の種類)の表の色には、次の3つの変数を使う。

| 変数 | 値 | 用途 |
| --- | --- | --- |
| `--museum-calendar-kind-color` | 既定のテンプレートが、色のある行の予定の `li` と開館状況の `p` に `style` で出す | 月間カレンダーの予定と開館状況の背景 |
| `--museum-calendar-kind-accent` | `oklab(from var(--museum-calendar-kind-color) calc(l * .76) calc(a * clamp(1, .15 / hypot(a, b), 6.5)) calc(b * clamp(1, .15 / hypot(a, b), 6.5)))` | 月間カレンダーの予定と開館状況の左線 |
| `--museum-calendar-kind-text` | `oklab(from <背景> clamp(0, calc((.6 - l) * 1000), 1) 0 0)` | 月間カレンダーの予定と開館状況の文字、予定の名前のリンク |

- 左線は背景と同じ色相で、明度を0.76倍にし、彩度を最大6.5倍（上限0.15、元より下げない）にした色。灰色の背景の左線は灰色になる。
- 文字は、背景のOKLabの明度が0.6より小さければ白、それ以外は黒。背景は、予定では `--museum-calendar-event-background` があればその値、なければ `--museum-calendar-kind-color`。開館状況では `--museum-calendar-kind-color`。
- プラグインのCSSは、`.museum-calendar li` と `.museum-calendar-today__status` に `--museum-calendar-kind-accent` と `--museum-calendar-kind-text` を宣言する。`--museum-calendar-kind-color` がない要素では、この2つは無効な値になり、`var()` は次の候補に進む。文字色は継承した色のまま。
- 相対色（`oklab(from …)`）に対応しないブラウザーでは、この2つを宣言しない。表の色は背景だけに効き、左線と文字色は色のない予定と同じになる。
- 色の優先順位は、月間カレンダーの予定では `--museum-calendar-event-background`（`-accent`）、表の色とそこから作った値、`--museum-calendar-surface`（`--museum-calendar-accent`）、既定値の順。開館状況では、表の色とそこから作った値、`--museum-calendar-surface`（`--museum-calendar-accent`）、既定値の順。
- テーマのCSSで `--museum-calendar-kind-color` を指定しても、表の色と同じように背景、左線、文字色を決める。

### サンプルのCSS

配布ZIPの `samples/` に、既定のテンプレート向けのCSSを3つ含める。プラグインはこれらを読み込まない。利用者が中身をテーマのCSSか追加CSSに写して使う。

| ファイル | 内容 |
| --- | --- |
| `default.css` | 最初の表の変数を既定値のまま `:root` に宣言する。写しただけでは表示は変わらない。既定値が別の変数に従う `--museum-calendar-event-background` と `--museum-calendar-event-accent`、表の色の3つの変数は宣言しない。表の色は、写した後も効く |
| `monochrome.css` | 黒地に白の文字。本日のセルは白地に反転。予定の種類は濃淡と枠線で区別する。表の色は使わない |
| `pop.css` | 曜日（`data-weekday`）と予定の種類（`data-kind`）ごとに色を付ける。表の色は使わない |

- `monochrome.css` と `pop.css` は、変数の宣言を除くすべての規則を `:root` で始める。既定のCSSより詳細度が高く、どちらが先に読み込まれても上書きできる。
- `monochrome.css` は `concert` と `event` に固有の装飾を付けない。

### テンプレートの差し替え

- テーマに `museum-calendar/month.php`（月間カレンダー）または `museum-calendar/today.php`（本日の予定）を置くと、プラグインのテンプレートの代わりに使う。子テーマ、親テーマの順に探す。
- 元にするファイルは、プラグインの `templates/month.php` と `templates/today.php`。
- 外側の `section` と前回データの案内はプラグインが出力する。テンプレートが出力するのはその内側だけ。

```html
<section class="museum-calendar" id="museum-calendar" aria-label="月間カレンダー">
  <p class="museum-calendar__notice" role="status">…</p>  <!-- 前回取得した予定を表示しているときだけ -->
  <!-- month.php の出力 -->
</section>

<section class="museum-calendar-today" aria-label="本日の予定">
  <p class="museum-calendar-today__notice" role="status">…</p>
  <!-- today.php の出力 -->
</section>
```

- テンプレートに渡すのは次の変数だけ。プラグインのクラスやカレンダーのURLは渡さない。
- 変数の値はエスケープしていない。テンプレートで `esc_html()`、`esc_attr()`、`esc_url()` を使って出力する。予定の `description` は `esc_html()`、`url` は `esc_url()` で出す。
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
| `$next` | array または null | 翌月。形式は `$previous` と同じ。`$previous` がnullになる場合に加えて、翌月が翌々月より後のときもnull |
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
| `events` | array | その日の予定のリスト。1件の形式は下の表 |

予定1件は次のキーを持つ配列。

| キー | 型 | 内容 |
| --- | --- | --- |
| `title` | string | 登録したタイトル。`lang` で変わらない |
| `label` | string | 表示するタイトル。`lang="en"` では英訳。`lang` なしと英訳のないタイトルでは `title` と同じ |
| `label_lang` | string | `lang="en"` の表示で `label` が英訳のないタイトルのときは `ja`。それ以外は空文字 |
| `kind` | string | [予定の種類](#予定の種類)。表にないタイトルは `other`。`lang` で変わらない |
| `color` | string | [予定の種類](#予定の種類)の表の行の色（`#rrggbb`、小文字）。予定の背景色。色のない行と表にないタイトルは空文字。`lang` で変わらない |
| `time` | string | 表示用の時間。終日は `終日`（`lang="en"` では `All day`）、時刻付きは `10:30–16:30`、終了のない予定は `10:30` |
| `all_day` | bool | 終日予定ならtrue |
| `start` | string | その日の開始時刻（`H:i`）。前日から続く日は `00:00`。終日予定は空文字 |
| `end` | string | その日の終了時刻（`H:i`）。翌日へ続く日は `24:00`。終日予定と終了のない予定は空文字 |
| `description` | string | [予定の説明](#予定の説明)。プレーンテキストで改行を含む場合がある。説明のない予定は空文字 |
| `url` | string | [予定のURL](#予定のurl)。`http` または `https` の絶対URL。URLのない予定は空文字 |

- `closed` のある日の `open` の予定は含まない（[休館の優先と本日の開館状況](#休館の優先と本日の開館状況)）。

`today.php` の変数:

| 変数 | 型 | 内容 |
| --- | --- | --- |
| `$today` | DateTimeImmutable | 本日。タイムゾーンはAsia/Tokyo |
| `$date` | string | 本日の日付。WordPressの日付形式で整形済み。`lang="en"` では `D, M j` の形式 |
| `$weekday` | string | 本日の曜日の省略名。サイトの言語。既定のテンプレートは出力しない |
| `$status` | array または null | [本日の開館状況](#休館の優先と本日の開館状況)。`kind`、`color`、`label`、`label_lang`、`hours` を持つ。なければnull |
| `$events` | array | 本日の予定のリスト。`open` と `closed` の予定も含む。1件の形式は `month.php` の `events` と同じ |
| `$lang` | string | ショートコードの言語。`lang` なしは `ja`、`lang="en"` は `en` |
| `$text` | array | 固定の文言。`$lang` の言語。`empty`（予定のない日の案内）を持つ |

既定のテンプレートは、予定を次のように出力する。

```php
<li data-title="<?php echo esc_attr($event['title']); ?>" data-kind="<?php echo esc_attr($event['kind']); ?>"<?php if ($event['color'] !== '') : ?> style="--museum-calendar-kind-color: <?php echo esc_attr($event['color']); ?>"<?php endif; ?>><strong<?php if ($event['label_lang'] !== '') : ?> lang="<?php echo esc_attr($event['label_lang']); ?>"<?php endif; ?>><?php echo esc_html($event['label']); ?></strong><span><?php echo esc_html($event['time']); ?></span></li>
```

- 開館状況の `strong` にも、`$status['label_lang']` が空でなければ同じように `lang` を付ける。開館状況の `p` には、`$status['color']` が空でなければ同じように `style` を付ける。
- 表の色を使うテーマのテンプレートは、`--museum-calendar-kind-color` を同じように出力する。出力しなければ、表の色は表示に効かない。

### 既定のCSSを使わない

`wp_deregister_style('museum-calendar')` を呼ぶと既定のCSSを読み込まなくなる。プラグインは `init` でスタイルを登録するため、`wp_enqueue_scripts` など、それより後のアクションで呼ぶ。

```php
add_action('wp_enqueue_scripts', function (): void {
    wp_deregister_style('museum-calendar');
});
```

## 対象外

- 時刻による開館状況の切り替え（閉館時刻を過ぎたら `closed` にするなど）。
- 週・日表示、ページを再読み込みしない月送り、「カレンダーに追加」と購読リンク、OAuthによる接続、祝日名の表示。
- Googleへの書き込み、予約、複数のカレンダーの合成、実サイトのテーマ調整。
- WordPress外のページキャッシュの自動消去。キャッシュ製品を使う場合は予定ページの更新設定も確認する。
