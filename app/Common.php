<?php

/**
 * The goal of this file is to allow developers a location
 * where they can overwrite core procedural functions and
 * replace them with their own. This file is loaded during
 * the bootstrap process and is called during the framework's
 * execution.
 *
 * This can be looked at as a `master helper` file that is
 * loaded early on, and may also contain additional functions
 * that you'd like to use throughout your entire application
 *
 * @see: https://codeigniter.com/user_guide/extending/common.html
 */

if (!function_exists('asset_url')) {
    /**
     * A public asset's path stamped with its modified time, so a browser fetches
     * the new file after a change instead of running the copy it cached.
     */
    function asset_url(string $path): string
    {
        $file = FCPATH . ltrim($path, '/');

        return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
    }
}
