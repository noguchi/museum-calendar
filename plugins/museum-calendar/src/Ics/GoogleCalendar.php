<?php
declare(strict_types=1);

namespace MuseumCalendar\Ics;

use InvalidArgumentException;

/** A Google Calendar that is shared with the public, identified by its calendar ID. */
final readonly class GoogleCalendar
{
    private const HOST = 'https://calendar.google.com/calendar/';

    public function __construct(public string $id)
    {
        if (strlen($id) > 254 || !preg_match('/^[A-Za-z0-9._+#-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+\z/', $id)) {
            throw new InvalidArgumentException('invalid_calendar_id');
        }
    }

    /** Accepts the public URL of the calendar or its public address in iCal format. */
    public static function fromUrl(string $url): self
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'calendar.google.com'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('not_google_calendar');
        }
        $path = $parts['path'] ?? '';
        // A signed-in browser shows the public URL as /calendar/u/<account>/embed.
        if (preg_match('#^/calendar/(?:u/\d+/)?embed$#', $path)) {
            $sources = [];
            foreach (explode('&', $parts['query'] ?? '') as $pair) {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                if (rawurldecode($key) === 'src') {
                    $sources[] = rawurldecode($value);
                }
            }
            if (count($sources) !== 1) {
                throw new InvalidArgumentException('not_one_calendar');
            }
            return new self($sources[0]);
        }
        if (preg_match('#^/calendar/ical/([^/]+)/public/basic\.ics$#', $path, $match)) {
            return new self(rawurldecode($match[1]));
        }
        throw new InvalidArgumentException('not_public_url');
    }

    public function publicUrl(): string
    {
        return self::HOST . 'embed?src=' . rawurlencode($this->id);
    }

    public function feedUrl(): string
    {
        return self::HOST . 'ical/' . rawurlencode($this->id) . '/public/basic.ics';
    }
}
