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

    /** The theme whose two colours the organisation chooses for itself. */
    public const CUSTOM = 'custom';

    /** The settings the choice is held under, on the head office entity. */
    public const KEY = 'theme';

    public const CUSTOM_KEY = 'themeCustom';

    /** What the custom theme starts from when it has never been set: the house colours. */
    public const CUSTOM_DEFAULT = ['accent' => '#0F5C4A', 'rail' => '#0D1B18'];

    /**
     * The least contrast each custom colour may have against white, as a WCAG
     * ratio. The accent carries white button text, so it takes the 4.5 that body
     * text needs; the rail carries a whole menu of small, dim labels, so it is held
     * to 7 — the level that keeps the faintest of them readable.
     */
    public const MIN_CONTRAST = ['accent' => 4.5, 'rail' => 7.0];

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
        [
            'key'  => 'custom',
            'name' => 'Custom',
            'note' => 'Your own accent and menu colour. Every other shade is derived from the two, so the shell stays consistent.',
        ],
    ];

    public static function isKnown(?string $key): bool
    {
        return $key !== null && in_array($key, array_column(self::THEMES, 'key'), true);
    }

    /** A colour as the picker sends it: #rgb or #rrggbb, normalised to six lower-case digits. */
    public static function colour(string $hex): ?string
    {
        $hex = strtolower(trim($hex));
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $hex) !== 1) {
            return null;
        }
        if (strlen($hex) === 4) {
            $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }

        return $hex;
    }

    /**
     * How far a colour stands from white, as the WCAG contrast ratio (1 is white
     * itself, 21 is black). The shell writes light text over both custom colours,
     * so this is what decides whether a chosen colour can be read on.
     */
    public static function contrastWithWhite(string $hex): float
    {
        $channel = static function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $rgb = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        $luminance = 0.2126 * $channel((int) $rgb[0]) + 0.7152 * $channel((int) $rgb[1]) + 0.0722 * $channel((int) $rgb[2]);

        return round(1.05 / ($luminance + 0.05), 1);
    }

    /**
     * The two colours as custom properties, for the style attribute on <html>.
     *
     * Only these two are written out. Every other token of the custom theme is
     * derived from them in app.css with color-mix(), so the shades are stated once,
     * in the stylesheet, rather than computed here in PHP and again in the browser
     * for the settings preview.
     */
    public static function customStyle(array $custom): string
    {
        return '--accent: ' . $custom['accent'] . '; --rail-bg: ' . $custom['rail'] . ';';
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

    /** The custom theme's two colours, whether or not it is the theme in force. */
    public static function currentCustom(): array
    {
        try {
            return (new SettingsRepository())->appearance()['custom'];
        } catch (Throwable $e) {
            return self::CUSTOM_DEFAULT;
        }
    }
}
