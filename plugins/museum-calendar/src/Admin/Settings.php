<?php
declare(strict_types=1);

namespace MuseumCalendar\Admin;

use DateTimeZone;
use InvalidArgumentException;
use MuseumCalendar\Calendar\EventList;
use MuseumCalendar\Calendar\KindTable;
use MuseumCalendar\Calendar\Month;
use MuseumCalendar\Frontend\Language;
use MuseumCalendar\Ics\FeedRepository;
use MuseumCalendar\Ics\GoogleCalendar;
use MuseumCalendar\Ics\Source;
use Throwable;
use WP_Error;

final class Settings
{
    public const OPTION = 'museum_calendar_google_calendar';
    /** iCal形式の非公開URLの秘密の部分。公開カレンダーでは存在しない。設定画面を含め、どこにも出力しない。 */
    public const SECRET_OPTION = 'museum_calendar_google_calendar_secret';
    /** 予定の種類の表の行。サイトが既定の表を使う間は存在しない。 */
    public const KINDS_OPTION = 'museum_calendar_kinds';

    /** saveKinds() のエラーコードごとに出す日本語の通知。 */
    private const KINDS_ERRORS = [
        'duplicate_title' => '予定の種類を保存できませんでした。同じタイトルの行が複数あります。タイトルは1行に1つだけ登録してください。',
        'invalid_kind' => '予定の種類を保存できませんでした。種類は英小文字で始まる英小文字・数字・ハイフン（40文字以内）で入力してください。other は使えません。',
        'invalid_color' => '予定の種類を保存できませんでした。色は #e3f2fd のように、# に続く6桁の16進数で入力してください。',
        'invalid_title' => '予定の種類を保存できませんでした。タイトルと英語での表示には、改行や制御文字を使えません。',
        'too_long' => '予定の種類を保存できませんでした。タイトルと英語での表示は、それぞれ200文字以内で入力してください。',
        'too_many' => '予定の種類を保存できませんでした。登録できる行は100行までです。',
        'invalid_rows' => '予定の種類を保存できませんでした。入力内容を確認してください。',
    ];

    /** 接続中のカレンダー。保存した秘密の部分が正しくなければ、公開URLから取得せず、未接続として扱う。 */
    public static function calendar(): ?GoogleCalendar
    {
        $id = get_option(self::OPTION, '');
        $secret = get_option(self::SECRET_OPTION, null);
        try {
            return is_string($id) && $id !== '' && ($secret === null || is_string($secret))
                ? new GoogleCalendar($id, $secret === '' ? null : $secret)
                : null;
        } catch (InvalidArgumentException $error) {
            return null;
        }
    }

    /**
     * URLのカレンダーに接続する。「接続を解除する」にチェックがあれば接続を解除する。
     *
     * フォームは保存したURLを表示しないので、URLが空なら接続を変えない。
     *
     * @param array{url?: mixed, disconnect?: mixed} $input
     */
    public static function save(array $input): true|WP_Error
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', '設定を変更する権限がありません。');
        }
        if (!empty($input['disconnect'])) {
            delete_option(self::OPTION);
            delete_option(self::SECRET_OPTION);
            FeedRepository::clear();
            return true;
        }
        $url = is_string($input['url'] ?? null) ? trim($input['url']) : '';
        if ($url !== '') {
            try {
                $calendar = GoogleCalendar::fromUrl($url);
            } catch (InvalidArgumentException $error) {
                return new WP_Error('invalid_url', 'Googleカレンダーの「iCal形式の非公開URL」か「iCal形式の公開URL」を指定してください。');
            }
            $current = self::calendar();
            // 別のカレンダーに変えたときも、同じカレンダーの非公開URLと公開URLを切り替えたときも、取得先が変わる。
            if ($calendar->id !== $current?->id || $calendar->secret !== $current?->secret) {
                self::store($calendar);
                FeedRepository::clear();
            }
        }
        return true;
    }

    private static function store(GoogleCalendar $calendar): void
    {
        update_option(self::OPTION, $calendar->id, false);
        if ($calendar->secret === null) {
            delete_option(self::SECRET_OPTION);
        } else {
            update_option(self::SECRET_OPTION, $calendar->secret, false);
        }
    }

    /**
     * カレンダーを保存し、接続していれば「今すぐ更新」と同じようにすぐ取得する。
     *
     * 取得に失敗しても設定は保存したまま。失敗は設定画面の取得状況に出る。
     */
    public static function saveAndRefresh(array $input): true|WP_Error
    {
        $result = self::save($input);
        if ($result === true && self::calendar() !== null) {
            FeedRepository::refresh(true);
        }
        return $result;
    }

    /** 保存した予定の種類の表。オプションがないか読めなければ既定の表を返し、表が原因で公開ページが失敗しないようにする。 */
    public static function kinds(): KindTable
    {
        $rows = get_option(self::KINDS_OPTION, null);
        if ($rows === null) {
            return KindTable::defaults();
        }
        try {
            return KindTable::fromRows($rows);
        } catch (InvalidArgumentException $error) {
            return KindTable::defaults();
        }
    }

    /**
     * 設定画面のフォームから予定の種類の表を保存する。エラーなら何も変えない。
     *
     * 既定の表と同じ表を保存したときと「既定の表に戻す」のときはオプションを削除し、サイトが再び既定の表に従うようにする。
     *
     * @param array{kinds?: mixed, reset?: mixed} $input
     */
    public static function saveKinds(array $input): true|WP_Error
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', '設定を変更する権限がありません。');
        }
        if (!empty($input['reset'])) {
            delete_option(self::KINDS_OPTION);
            return true;
        }
        $rows = $input['kinds'] ?? [];
        try {
            // フォームの値は行番号をキーにして届く。フォームの順がそのまま表の順になる。
            $table = KindTable::fromRows(is_array($rows) ? array_values($rows) : $rows);
        } catch (InvalidArgumentException $error) {
            $code = array_key_exists($error->getMessage(), self::KINDS_ERRORS) ? $error->getMessage() : 'invalid_rows';
            return new WP_Error($code, self::KINDS_ERRORS[$code]);
        }
        if ($table->rows() === KindTable::defaults()->rows()) {
            delete_option(self::KINDS_OPTION);
        } else {
            update_option(self::KINDS_OPTION, $table->rows(), false);
        }
        return true;
    }

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_options_page('Museum Calendar', 'Museum Calendar', 'manage_options', 'museum-calendar', [self::class, 'page']);
        });
        add_action('admin_post_museum_calendar_save', [self::class, 'handleSave']);
        add_action('admin_post_museum_calendar_refresh', [self::class, 'handleRefresh']);
        add_action('admin_post_museum_calendar_save_kinds', [self::class, 'handleSaveKinds']);
    }

    private static function authorize(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('設定を変更する権限がありません。', '', ['response' => 403]);
        }
        check_admin_referer($action);
    }

    public static function handleSave(): void
    {
        self::authorize('museum_calendar_save');
        $result = self::saveAndRefresh(wp_unslash($_POST));
        wp_safe_redirect(add_query_arg('result', is_wp_error($result) ? 'invalid' : 'saved', admin_url('options-general.php?page=museum-calendar')));
        exit;
    }

    public static function handleSaveKinds(): void
    {
        self::authorize('museum_calendar_save_kinds');
        $result = self::saveKinds(wp_unslash($_POST));
        $code = is_wp_error($result) ? $result->get_error_code() : 'saved';
        wp_safe_redirect(add_query_arg('result', 'kinds_' . $code, admin_url('options-general.php?page=museum-calendar')) . '#museum-calendar-kinds');
        exit;
    }

    public static function handleRefresh(): void
    {
        self::authorize('museum_calendar_refresh');
        FeedRepository::refresh(true);
        wp_safe_redirect(admin_url('options-general.php?page=museum-calendar'));
        exit;
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        // 予定名を読むと取得が走ることがあるので、取得状況を読む前に実行する。
        $calendar = self::calendar();
        $connected = $calendar !== null;
        $untyped = $connected ? self::untypedTitles() : null;
        $state = get_option(FeedRepository::CACHE, []);
        $result = is_string($_GET['result'] ?? null) ? $_GET['result'] : '';
        ?>
        <div class="wrap">
            <h1>Museum Calendar</h1>
            <p>使い方は<a href="https://github.com/noguchi/museum-calendar#readme">README</a>にあります。</p>
            <?php if ($result === 'saved') : ?>
                <div class="notice notice-success"><p>設定を保存しました。</p></div>
            <?php elseif ($result === 'invalid') : ?>
                <div class="notice notice-error"><p>URLを保存できませんでした。Googleカレンダーの「iCal形式の非公開URL」か「iCal形式の公開URL」を指定してください。</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="museum_calendar_save">
                <?php wp_nonce_field('museum_calendar_save'); ?>
                <p><label for="museum-calendar-url"><strong>iCal形式の非公開URL</strong></label></p>
                <?php // 保存したURLは出力しない。入力欄は置き換えるためだけにあり、何を登録しているかは直後の登録状況の行が示す。 ?>
                <p id="museum-calendar-connection">登録状況：<?php echo $calendar === null ? '未登録' : ($calendar->isPrivate() ? '非公開URLを登録済み' : '公開URLを登録済み'); ?></p>
                <input type="password" id="museum-calendar-url" name="url" value="" class="large-text code" autocomplete="new-password" spellcheck="false" aria-describedby="museum-calendar-connection">
                <?php if ($connected) : ?>
                    <p><label><input type="checkbox" name="disconnect" value="1"> 接続を解除する</label></p>
                <?php endif; ?>
                <?php submit_button('設定を保存'); ?>
            </form>
            <h2>取得状況</h2>
            <?php // 最後に取得を試みた時刻は、失敗したときだけ出す。成功したときは最終取得と同じ時刻になるため。 ?>
            <?php if (!empty($state['fetched_at'])) : ?>
                <p>最終取得：<?php echo esc_html(self::date((int) $state['fetched_at'])); ?></p>
            <?php elseif (empty($state['error'])) : ?>
                <p>まだ取得していません。</p>
            <?php endif; ?>
            <?php if (!empty($state['error'])) : ?>
                <p role="alert">取得失敗：<?php echo esc_html(self::date((int) ($state['attempted_at'] ?? 0)) . '　' . $state['error']); ?></p>
            <?php endif; ?>
            <?php if ($connected) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="museum_calendar_refresh">
                    <?php wp_nonce_field('museum_calendar_refresh'); ?>
                    <?php submit_button('今すぐ更新', 'secondary'); ?>
                </form>
            <?php endif; ?>
            <?php self::kindsForm($result); ?>
            <?php // すべての予定名に種類があれば対処することがないので、この節を出さない。 ?>
            <?php if ($connected && $untyped !== []) : ?>
                <h2>種類が未定義の予定名</h2>
                <?php if ($untyped === null) : ?>
                    <p>予定を読み込めないため、種類が未定義の予定名を確認できません。</p>
                <?php else : ?>
                    <ul class="ul-disc">
                        <?php foreach ($untyped as $title) : ?>
                            <li><?php echo esc_html($title); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** 予定の種類の表のフォーム。保存済みの行を並べ、その後に追加用の空欄の行を置く。スクリプトを使わないので、行はタイトルを空にして削除する。 */
    /** @param string $result クエリの result の値。表のフォームの通知は、リダイレクト先になる表の見出しの下に置く。 */
    private static function kindsForm(string $result): void
    {
        $error = str_starts_with($result, 'kinds_') ? (self::KINDS_ERRORS[substr($result, 6)] ?? null) : null;
        $rows = self::kinds()->rows();
        $blank = ['title' => '', 'kind' => '', 'english' => '', 'color' => ''];
        $rows = [...$rows, $blank, $blank, $blank];
        $columns = ['title' => 'タイトル', 'kind' => '種類', 'english' => '英語での表示'];
        ?>
        <h2 id="museum-calendar-kinds">予定の種類と英語での表示</h2>
        <?php // inline を付けて、WordPressが通知をページの先頭に移さないようにする。 ?>
        <?php if ($result === 'kinds_saved') : ?>
            <div class="notice notice-success inline"><p>予定の種類を保存しました。</p></div>
        <?php elseif ($error !== null) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="museum_calendar_save_kinds">
            <?php wp_nonce_field('museum_calendar_save_kinds'); ?>
            <?php // 色の選択は空にできないため、色のない行の色の選択は、色を入れるまで薄く表示する。 ?>
            <style>.museum-calendar-kinds__color { display: flex; gap: 4px; align-items: center; } .museum-calendar-kinds__color input[type="color"] { flex: none; width: 2rem; height: 2rem; padding: 0; } .museum-calendar-kinds__color input[type="color"][data-empty] { opacity: .35; outline: 1px dashed #8c8f94; } .museum-calendar-kinds__color input[type="text"] { min-width: 0; }</style>
            <?php // table-layout: fixed で画面の幅を列に割り振り、スマホで表がはみ出さないようにする。 ?>
            <table class="widefat striped museum-calendar-kinds" style="table-layout: fixed;">
                <thead>
                    <tr>
                        <th scope="col">登録したタイトル</th>
                        <th scope="col">種類（<code>data-kind</code>）</th>
                        <th scope="col">英語での表示</th>
                        <?php // 色の選択と7文字が入れば足りるので、ほかの列より狭くする。 ?>
                        <th scope="col" style="width: 8.5rem;">色</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $index => $row) : ?>
                        <tr>
                            <?php foreach ($columns as $key => $label) : ?>
                                <td><input type="text" name="<?php echo esc_attr("kinds[{$index}][{$key}]"); ?>" value="<?php echo esc_attr($row[$key]); ?>" aria-label="<?php echo esc_attr(($index + 1) . '行目の' . $label); ?>" class="<?php echo $key === 'kind' ? 'widefat code' : 'widefat'; ?>" spellcheck="false"></td>
                            <?php endforeach; ?>
                            <?php // 送信するのは文字の入力欄。色の選択には name がないので、色を空のままにできる。 ?>
                            <td><div class="museum-calendar-kinds__color"><input type="color" value="<?php echo esc_attr($row['color'] === '' ? '#ffffff' : $row['color']); ?>" aria-label="<?php echo esc_attr(($index + 1) . '行目の色を選ぶ'); ?>"<?php echo $row['color'] === '' ? ' data-empty' : ''; ?>><input type="text" name="<?php echo esc_attr("kinds[{$index}][color]"); ?>" value="<?php echo esc_attr($row['color']); ?>" aria-label="<?php echo esc_attr(($index + 1) . '行目の色'); ?>" class="widefat code" placeholder="#e3f2fd" spellcheck="false"></div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php // 行ごとに色の選択と文字の入力欄を同期する。スクリプトがなくても文字の入力欄だけで使える。 ?>
            <script>
                document.querySelector('.museum-calendar-kinds').addEventListener('input', (event) => {
                    const cell = event.target.closest('.museum-calendar-kinds__color');
                    if (!cell) {
                        return;
                    }
                    const [picker, field] = cell.querySelectorAll('input');
                    const color = field.value.trim().toLowerCase();
                    if (event.target === picker) {
                        field.value = picker.value.toLowerCase();
                    } else if (/^#[0-9a-f]{6}$/.test(color)) {
                        picker.value = color;
                    }
                    picker.toggleAttribute('data-empty', field.value.trim() === '');
                });
            </script>
            <p><label><input type="checkbox" name="reset" value="1"> 既定の表に戻す</label></p>
            <?php submit_button('予定の種類を保存', 'primary', 'submit-kinds'); ?>
        </form>
        <?php
    }

    /**
     * 今月から11か月後までの予定のうち、種類が other の予定名。
     * タイトルのない予定は除く。施設が表に登録できるタイトルがないため。
     *
     * @return list<string>|null 予定を読めなければ null。原因には予定のデータが含まれうるので捨てる。
     */
    private static function untypedTitles(): ?array
    {
        try {
            $source = new Source((new FeedRepository())->load()['body']);
            $key = wp_date('Y-m', null, new DateTimeZone('Asia/Tokyo'));
            $kinds = self::kinds();
            $titles = [];
            for ($count = 0; $count < 12 && $key !== null; $count++) {
                $month = new Month($key);
                foreach (EventList::forDisplay($source, $month, $kinds) as $events) {
                    foreach ($events as $event) {
                        $title = KindTable::trimmed($event['title']);
                        if ($event['kind'] === KindTable::OTHER && $title !== Language::Japanese->text('untitled')) {
                            $titles[] = $title;
                        }
                    }
                }
                $key = $month->adjacent(1);
            }
            return array_values(array_unique($titles));
        } catch (Throwable $error) {
            return null;
        }
    }

    private static function date(int $timestamp): string
    {
        return wp_date('Y/m/d H:i', $timestamp, new \DateTimeZone('Asia/Tokyo'));
    }
}
