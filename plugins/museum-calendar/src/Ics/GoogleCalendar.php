<?php
declare(strict_types=1);

namespace MuseumCalendar\Ics;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * カレンダーIDで特定するGoogleカレンダー。
 *
 * 秘密の部分があれば、iCal形式の非公開URLから読む。これならカレンダーを一般公開しなくても読める。
 * 秘密の部分がなければ公開URLから読む。秘密の部分はパスワードと同じで、どこにも出力しない。
 */
final readonly class GoogleCalendar
{
    private const HOST = 'https://calendar.google.com/calendar/';
    /** Googleが発行するのは16進数32桁。英字と数字なら何でも受け付け、桁数が多い値や大文字の混じる値も使えるようにする。 */
    private const SECRET = '/^[A-Za-z0-9]{1,128}\z/';

    public function __construct(public string $id, #[SensitiveParameter] public ?string $secret = null)
    {
        if (strlen($id) > 254 || !preg_match('/^[A-Za-z0-9._+#-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+\z/', $id)) {
            throw new InvalidArgumentException('invalid_calendar_id');
        }
        if ($secret !== null && !preg_match(self::SECRET, $secret)) {
            throw new InvalidArgumentException('invalid_secret');
        }
    }

    /**
     * カレンダーのiCal形式の非公開URLか公開URLを受け付ける。
     * 例外のメッセージにはURLを含めない。
     */
    public static function fromUrl(#[SensitiveParameter] string $url): self
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'calendar.google.com'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('not_google_calendar');
        }
        if (preg_match('#^/calendar/ical/([^/]+)/(?:public|private-([^/]+))/basic\.ics$#', $parts['path'] ?? '', $match)) {
            return new self(rawurldecode($match[1]), ($match[2] ?? '') === '' ? null : $match[2]);
        }
        throw new InvalidArgumentException('not_calendar_url');
    }

    public function isPrivate(): bool
    {
        return $this->secret !== null;
    }

    /** プラグインが取得するURL。秘密の部分があればそれを含むので、出力しない。 */
    public function feedUrl(): string
    {
        $access = $this->secret === null ? 'public' : 'private-' . $this->secret;
        return self::HOST . 'ical/' . rawurlencode($this->id) . '/' . $access . '/basic.ics';
    }

    /** var_dump() と print_r() に秘密の部分を出さない。 */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'secret' => $this->secret === null ? null : '[hidden]'];
    }
}
