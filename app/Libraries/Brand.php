<?php

namespace App\Libraries;

use App\Repositories\SettingsRepository;
use Throwable;

/**
 * What the application calls itself: the name in the sidebar and the browser tab,
 * the line under it, and the logo.
 *
 * This is deliberately separate from the organisation's registered name. The
 * registered name is what prints on a statement and belongs to the legal entity;
 * this is the name on the screen the staff work in. They are usually related and
 * occasionally not — a shared installation renamed for the office using it, a
 * trust whose registered name is far too long for a 236-pixel sidebar.
 *
 * The logo is an uploaded file rather than a setting saved with the draft: a file
 * cannot sit in a browser draft waiting for Save, the same reason the M-Pesa
 * credentials save as they are entered.
 */
class Brand
{
    public const NAME_KEY = 'appName';

    public const TAGLINE_KEY = 'appTagline';

    public const LOGO_KEY = 'appLogo';

    public const DEFAULT_NAME = 'ELOG';

    public const DEFAULT_TAGLINE = 'Finance Suite';

    public const MAX_NAME = 40;

    public const MAX_TAGLINE = 60;

    /** A logo is a raster image the browser can draw anywhere: no SVG, which is a script host as much as a picture. */
    public const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public const MAX_LOGO_BYTES = 512000;

    /** Where an uploaded logo is kept, under writable/uploads. */
    public const STORAGE_DIR = 'branding';

    /**
     * The initials drawn in place of a logo, from the name the application carries.
     * "ELOG" gives EL, "Coast Finance" gives CF.
     */
    public static function mark(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $letters = count($words) > 1
            ? mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1)
            : mb_substr($name, 0, 2);

        return mb_strtoupper($letters) ?: '··';
    }

    /**
     * The brand as the shell draws it: name, tagline, the initials mark, and the
     * logo's address when one has been uploaded.
     *
     * Read on every page before anything else has touched the database, so a brand
     * that cannot be read falls back to the default rather than taking the page
     * down with it.
     */
    public static function current(): array
    {
        try {
            $held = (new SettingsRepository())->appearance();
        } catch (Throwable $e) {
            $held = ['appName' => self::DEFAULT_NAME, 'appTagline' => self::DEFAULT_TAGLINE, 'logo' => ''];
        }

        return [
            'name'    => $held['appName'],
            'tagline' => $held['appTagline'],
            'mark'    => self::mark($held['appName']),
            // The stored key is random per upload, so a new logo is a new address
            // and no browser serves the old one from its cache.
            'logo'    => $held['logo'] === '' ? '' : '/api/settings/logo?v=' . substr(sha1($held['logo']), 0, 10),
        ];
    }
}
