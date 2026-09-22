<?php

namespace App\Controllers;

use App\Libraries\Brand;
use App\Libraries\InstallState;
use App\Libraries\Theme;

/**
 * The installer's one page. Everything it does is asked for from
 * App\Controllers\Api\Install; this only draws the shell it happens in.
 *
 * App\Filters\Installed has already decided whether this address exists: on an
 * installed instance it never reaches here.
 *
 * The page is deliberately not branded. Brand::current() would fall back to the
 * name the application ships with, and calling an instance that has no
 * organisation yet by that name would be telling the person installing it
 * something untrue. It takes its own name here, and the organisation's own name
 * and colours apply from the first sign-in.
 */
class Install extends BaseController
{
    public function index()
    {
        // Asking for the installer from anywhere but the server itself needs the
        // setup key, so write it now: whoever deployed the instance can read it off
        // the server, and nobody who merely found the address can.
        if (InstallState::tokenRequired($this->request)) {
            InstallState::token();
        }

        return view('install', [
            'title' => 'Install',
            'name'  => Brand::DEFAULT_TAGLINE,
            'theme' => Theme::DEFAULT,
        ]);
    }
}
