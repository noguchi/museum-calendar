<?php
declare(strict_types=1);

namespace MuseumCalendar\Admin;

use DateTimeZone;
use MuseumCalendar\Calendar\EventList;
use MuseumCalendar\Calendar\Month;
use MuseumCalendar\Frontend\Language;
use MuseumCalendar\Ics\FeedRepository;
use MuseumCalendar\Ics\Source;
use Throwable;
use WP_Error;

final class Settings
{
    public const OPTION = 'museum_calendar_ics_url';

    public static function url(): string
    {
        return (string) get_option(self::OPTION, '');
    }

    public static function validUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }
        // wp_http_validate_url permits the site's own host. Explicitly reject
        // private addresses as well, including when WordPress itself is local.
        $address = gethostbyname($host);
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        return strlen($url) <= 2048 && !preg_match('/[\x00-\x20\x7f]/', $url)
            && is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['fragment']) && (!isset($parts['port']) || $parts['port'] === 443)
            && wp_http_validate_url($url) !== false;
    }

    /** Empty input preserves the secret. A separate checkbox disconnects it. */
    public static function save(array $input): true|WP_Error
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forbidden', '設定を変更する権限がありません。');
        }
        $url = !empty($input['disconnect']) ? '' : (is_string($input['url'] ?? null) ? trim($input['url']) : '');
        if ($url === '' && empty($input['disconnect'])) {
            return true;
        }
        if ($url !== '' && !self::validUrl($url)) {
            return new WP_Error('invalid_url', '公開インターネット上のHTTPS形式のICS URLを指定してください。');
        }
        if ($url !== self::url()) {
            update_option(self::OPTION, $url, false);
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
        $connected = self::url() !== '';
        $untranslated = $connected ? self::untranslatedTitles() : null;
        $state = get_option(FeedRepository::CACHE, []);
        ?>
        <div class="wrap">
            <h1>Museum Calendar</h1>
            <p>GoogleカレンダーなどのICS URLを登録すると、予定のタイトルと時間をサイトに表示します。</p>
            <p>表示時刻は日本時間。通常15分ごとに更新し、取得失敗時は最長24時間まで前回の予定を表示します。</p>
            <?php if (($_GET['result'] ?? '') === 'saved') : ?>
                <div class="notice notice-success"><p>設定を保存しました。</p></div>
            <?php elseif (($_GET['result'] ?? '') === 'invalid') : ?>
                <div class="notice notice-error"><p>URLを保存できませんでした。公開インターネット上のHTTPS URLを指定してください。</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="museum_calendar_save">
                <?php wp_nonce_field('museum_calendar_save'); ?>
                <p><label for="museum-ics-url"><strong>ICS URL</strong></label></p>
                <input type="password" id="museum-ics-url" name="url" value="" class="large-text" autocomplete="new-password" spellcheck="false">
                <p><?php echo self::url() === '' ? '未設定です。' : 'URLは保存済みです。空欄のまま保存すると変更されません。'; ?></p>
                <p>非公開URLは閲覧用の秘密情報です。保存済みURLは画面に再表示しません。掲載してよい予定だけを含むカレンダーを指定してください。</p>
                <p><label><input type="checkbox" name="disconnect" value="1">接続を解除し、保存済みの予定を消去する</label></p>
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
                <h2>英訳のない予定名</h2>
                <p>今月から12か月分の予定のうち、英訳のない予定名は <code>lang="en"</code> を付けたページで日本語のまま表示されます。</p>
                <?php if ($untranslated === null) : ?>
                    <p>予定を読み込めないため、英訳のない予定名を確認できません。</p>
                <?php elseif ($untranslated === []) : ?>
                    <p>すべての予定名に英訳があります。</p>
                <?php else : ?>
                    <ul class="ul-disc">
                        <?php foreach ($untranslated as $title) : ?>
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
     * The titles, from this month through the following 11 months, that an English page prints in Japanese.
     *
     * @return list<string>|null Null when the events cannot be read. The cause can hold the ICS URL, so it is dropped.
     */
    private static function untranslatedTitles(): ?array
    {
        try {
            $source = new Source((new FeedRepository())->load()['body']);
            $key = wp_date('Y-m', null, new DateTimeZone('Asia/Tokyo'));
            $titles = [];
            for ($count = 0; $count < 12 && $key !== null; $count++) {
                $month = new Month($key);
                foreach (EventList::forDisplay($source, $month) as $events) {
                    foreach ($events as $event) {
                        if (Language::English->title($event['title']) === null) {
                            $titles[] = Language::trimmed($event['title']);
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
