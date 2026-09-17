<?php

namespace App\Libraries;

use App\Repositories\SettingsRepository;
use Throwable;

/**
 * The interface theme: the accent, the brand colour and the navigation rail.
 *
 * One theme is held for the organisation rather than one per person, for the
 * same reason the reporting locale is held centrally: finance staff read each
 * other's screens over a shoulder and in screenshots attached to approvals, and
 * a colour that means "this is our ledger" only works if everyone sees it. It is
 * therefore an organisation setting, changed by the role that holds
 * settings.manage (the Finance Manager) and written to the audit log like any
 * other.
 *
 * Only the theme's key lives here. The colours themselves are in app.css, as
 * [data-theme="..."] blocks of custom properties, so there is one place a
 * palette is stated and the settings picker can paint a swatch from the very
 * tokens the shell will use.
 */
class Theme
{
    public const DEFAULT = 'evergreen';

    /** The setting the key is held under, on the head office entity. */
    public const KEY = 'theme';

    /** @var list<array{key: string, name: string, note: string}> */
    public const THEMES = [
        [
            'key'  => 'evergreen',
            'name' => 'Evergreen',
            'note' => 'The house palette — deep green accent on a warm paper ground.',
        ],
        [
            'key'  => 'deep-blue',
            'name' => 'Deep blue',
            'note' => 'A navy accent. Reads as conventional finance software and prints legibly in greyscale.',
        ],
        [
            'key'  => 'indigo',
            'name' => 'Indigo',
            'note' => 'A violet accent with a near-black rail. The highest contrast of the five.',
        ],
        [
            'key'  => 'burgundy',
            'name' => 'Burgundy',
            'note' => 'A dark red accent. Sits closest to the urgent colour, so exceptions stand out least.',
        ],
        [
            'key'  => 'graphite',
            'name' => 'Graphite',
            'note' => 'Neutral slate. Leaves urgent, warning and settled amounts as the only colour on a page.',
        ],
    ];

    public static function isKnown(?string $key): bool
    {
        return $key !== null && in_array($key, array_column(self::THEMES, 'key'), true);
    }

    public static function name(string $key): string
    {
        foreach (self::THEMES as $theme) {
            if ($theme['key'] === $key) {
                return $theme['name'];
            }
        }

        return $key;
    }

    /**
     * The theme in force, for the page shell and the API alike.
     *
     * The shell asks for this on every page, before anything else has touched the
     * database, so a theme that cannot be read — an unmigrated database, a
     * connection that is not up yet — falls back to the default rather than taking
     * the whole page down with it.
     */
    public static function current(): string
    {
        try {
            return (new SettingsRepository())->theme();
        } catch (Throwable $e) {
            return self::DEFAULT;
        }
    }
}
