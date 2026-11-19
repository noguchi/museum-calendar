<?php
declare(strict_types=1);

namespace MuseumCalendar\Admin;

use DateTimeZone;
use InvalidArgumentException;
use MuseumCalendar\Calendar\EventList;
use MuseumCalendar\Calendar\Kind;
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
    /** Up to 0.7.0 the plugin stored an iCal URL, often the secret one, under this name. */
    public const LEGACY_OPTION = 'museum_calendar_ics_url';

    public static function calendar(): ?GoogleCalendar
    {
        $id = get_option(self::OPTION, '');
        try {
            return is_string($id) && $id !== '' ? new GoogleCalendar($id) : null;
        } catch (InvalidArgumentException $error) {
            return null;
        }
    }

    /**
     * Keeps the calendar ID of a Google iCal URL saved by 0.7.0 or earlier and deletes the URL, including any secret token.
     * The legacy option outlives this update only when the site went back to 0.7.0, so its calendar is the newer choice.
     */
    public static function migrate(): void
    {
        $legacy = get_option(self::LEGACY_OPTION, null);
        if ($legacy === null) {
            return;
        }
        if (is_string($legacy)
            && preg_match('#^https://(?:calendar|www)\.google\.com/calendar/ical/([^/?\#]+)/(?:public|private-[^/?\#]+)/basic\.ics$#', $legacy, $match)) {
            try {
                update_option(self::OPTION, (new GoogleCalendar(rawurldecode($match[1])))->id, false);
            } catch (InvalidArgumentException $error) {
                // Not a Google calendar ID. The administrator enters the public URL again.
            }
        }
        delete_option(self::LEGACY_OPTION);
        FeedRepository::clear();
    }

    /** An empty URL disconnects the calendar and deletes its saved events. */
    public static function save(array $input): true|WP_Error
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', '設定を変更する権限がありません。');
        }
        $url = is_string($input['url'] ?? null) ? trim($input['url']) : '';
        try {
            $id = $url === '' ? '' : GoogleCalendar::fromUrl($url)->id;
        } catch (InvalidArgumentException $error) {
            return new WP_Error('invalid_url', 'Googleカレンダーの公開URLを指定してください。');
        }
        if ($id !== (self::calendar()?->id ?? '')) {
            update_option(self::OPTION, $id, false);
            FeedRepository::clear();
        }
        update_option('museum_calendar_demo', false);
        return true;
    }

    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_options_page('Museum Calendar', 'Museum Calendar', 'manage_options', 'museum-calendar', [self::class, 'page']);
        });
        add_action('admin_post_museum_calendar_save', [self::class, 'handleSave']);
        add_action('admin_post_museum_calendar_refresh', [self::class, 'handleRefresh']);
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
        $result = self::save(wp_unslash($_POST));
        wp_safe_redirect(add_query_arg('result', is_wp_error($result) ? 'invalid' : 'saved', admin_url('options-general.php?page=museum-calendar')));
        exit;
    }

    public static function handleRefresh(): void
    {
        self::authorize('museum_calendar_refresh');
        try {
            (new FeedRepository())->load(true);
        } catch (Throwable $error) {
            // Show the generic persisted status on the settings page.
        }
        wp_safe_redirect(admin_url('options-general.php?page=museum-calendar'));
        exit;
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        // Reading the titles can fetch the feed, so it runs before the fetch status is read.
        $connected = self::calendar() !== null;
        $untyped = $connected ? self::untypedTitles() : null;
        $state = get_option(FeedRepository::CACHE, []);
        ?>
        <div class="wrap">
            <h1>Museum Calendar</h1>
            <p>一般公開したGoogleカレンダーの予定のタイトルと時間をサイトに表示します。</p>
            <p>表示時刻は日本時間。通常15分ごとに更新し、取得失敗時は最長24時間まで前回の予定を表示します。</p>
            <?php if (($_GET['result'] ?? '') === 'saved') : ?>
                <div class="notice notice-success"><p>設定を保存しました。</p></div>
            <?php elseif (($_GET['result'] ?? '') === 'invalid') : ?>
                <div class="notice notice-error"><p>URLを保存できませんでした。Googleカレンダーの「このカレンダーの公開URL」を指定してください。</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="museum_calendar_save">
                <?php wp_nonce_field('museum_calendar_save'); ?>
                <p><label for="museum-calendar-url"><strong>Googleカレンダーの公開URL</strong></label></p>
                <input type="text" id="museum-calendar-url" name="url" value="<?php echo esc_attr(self::calendar()?->publicUrl() ?? ''); ?>" class="large-text code" spellcheck="false">
                <p>Googleカレンダーの「設定と共有 → 予定のアクセス権限」で「一般公開して誰でも利用できるようにする」をオンにし、「すべての予定の詳細を表示」を選びます。「カレンダーの統合」にある「このカレンダーの公開URL」を貼り付けてください。</p>
                <p>公開URLは誰でも開けます。掲載してよい予定だけを含むカレンダーを指定してください。空欄にして保存すると接続を解除し、保存済みの予定を消去します。</p>
                <?php submit_button('設定を保存'); ?>
            </form>
            <h2>取得状況</h2>
            <p>最終成功：<?php echo esc_html(self::date((int) ($state['fetched_at'] ?? 0))); ?></p>
            <p>最終試行：<?php echo esc_html(self::date((int) ($state['attempted_at'] ?? 0))); ?></p>
            <?php if (!empty($state['error'])) : ?><p role="alert"><?php echo esc_html($state['error']); ?></p><?php endif; ?>
            <?php if ($connected) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="museum_calendar_refresh">
                    <?php wp_nonce_field('museum_calendar_refresh'); ?>
                    <?php submit_button('今すぐ更新', 'secondary'); ?>
                </form>
                <h2>種類が未定義の予定名</h2>
                <p>今月から12か月分の予定のうち、種類が未定義の予定名にはテーマの色が付かず、<code>lang="en"</code> を付けたページでは日本語のまま表示されます。</p>
                <?php if ($untyped === null) : ?>
                    <p>予定を読み込めないため、種類が未定義の予定名を確認できません。</p>
                <?php elseif ($untyped === []) : ?>
                    <p>すべての予定名に種類があります。</p>
                <?php else : ?>
                    <ul class="ul-disc">
                        <?php foreach ($untyped as $title) : ?>
                            <li><?php echo esc_html($title); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
            <p>固定ページに <code>[museum_calendar]</code> を置いて表示します。</p>
        </div>
        <?php
    }

    /**
     * The titles, from this month through the following 11 months, whose kind is other.
     * An event without a title is left out. The facility has no title to register for it.
     *
     * @return list<string>|null Null when the events cannot be read. The cause can hold event data, so it is dropped.
     */
    private static function untypedTitles(): ?array
    {
        try {
            $source = new Source((new FeedRepository())->load()['body']);
            $key = wp_date('Y-m', null, new DateTimeZone('Asia/Tokyo'));
            $titles = [];
            for ($count = 0; $count < 12 && $key !== null; $count++) {
                $month = new Month($key);
                foreach (EventList::forDisplay($source, $month) as $events) {
                    foreach ($events as $event) {
                        $title = Kind::trimmed($event['title']);
                        if (Kind::fromTitle($title) === Kind::Other && $title !== Language::Japanese->text('untitled')) {
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
        return $timestamp ? wp_date('Y/m/d H:i:s', $timestamp, new \DateTimeZone('Asia/Tokyo')) . '（日本時間）' : '未取得';
    }
}
