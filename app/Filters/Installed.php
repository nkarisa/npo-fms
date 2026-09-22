<?php

namespace App\Filters;

use App\Libraries\InstallState;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Keeps an instance that has not been installed at the installer, and an instance
 * that has been installed away from it.
 *
 * Runs before every other filter (Config\Filters), because until there is a
 * database there is nothing for the rest of them to read: a sign-in cannot be
 * checked against a schema that does not exist yet, and a page that asks for the
 * ledger would only fail with a connection error that says nothing about what to
 * do. So an uninstalled instance answers every address with the installer instead.
 *
 * Once installed, the installer is gone: /install and /api/install are answered
 * exactly as an address that was never there. It writes the database credentials
 * and makes a user holding every permission, so it must not linger behind a
 * "you cannot do that" — it should not appear to exist at all.
 *
 * Whether the instance is installed is a file, not a row (App\Libraries\InstallState),
 * so this costs one filesystem check on a request that has nothing else to do.
 */
class Installed implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $path = self::path($request);

        // Static files are served whatever state the instance is in: the installer
        // needs the same stylesheet and scripts every other page does.
        if (str_starts_with($path, 'assets/')) {
            return null;
        }

        $installer = $path === 'install' || $path === 'api/install' || str_starts_with($path, 'api/install/');

        // The installer's own session, part way through writing: once the seed has
        // run, the database holds an organisation and would read as installed — which
        // would close the installer under it before finish() could.
        if ($installer && InstallState::writing()) {
            return null;
        }

        if (InstallState::installed()) {
            if (!$installer) {
                return null;
            }

            // Installed: there is no installer here.
            if (self::isApi($path)) {
                return service('response')->setStatusCode(404)->setJSON([
                    'error' => 'This instance is already installed.',
                ]);
            }

            throw PageNotFoundException::forPageNotFound();
        }

        if ($installer) {
            return null;
        }

        // Not installed: nothing else can be answered yet.
        if (self::isApi($path)) {
            return service('response')->setStatusCode(503)->setJSON([
                'error'   => 'This instance has not been installed yet.',
                'install' => '/install',
            ]);
        }

        // Relative, so it stays on whatever host and port the page was asked for.
        return service('response')->setStatusCode(302)->setHeader('Location', '/install');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private static function isApi(string $path): bool
    {
        return $path === 'api' || str_starts_with($path, 'api/');
    }

    /** The routed path ("api/install/migrate"), without the base URL or index.php. */
    private static function path(RequestInterface $request): string
    {
        return trim($request instanceof IncomingRequest ? $request->getPath() : $request->getUri()->getPath(), '/');
    }
}
