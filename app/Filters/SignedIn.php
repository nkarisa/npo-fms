<?php

namespace App\Filters;

use App\Libraries\SignIn;
use App\Repositories\UserRepository;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Keeps everything but the sign-in screens behind a completed sign-in.
 *
 * Applied to every route except those listed in Config\Filters (the sign-in pages
 * and /api/auth/*). A page request without a session is sent to /login and
 * brought back afterwards; an API request is answered 401, which the page scripts
 * turn into the same trip. A session part-way through (password given, second
 * factor not yet) counts as not signed in, and so does one whose user has since
 * been suspended or lost every role.
 *
 * Writes to the API must also carry the X-Requested-With header the page scripts
 * send. A form on another site can make the browser post with the session cookie,
 * but cannot add a header without the browser asking this server first — which it
 * never agrees to — so the header shows the request came from the application's
 * own pages.
 */
class SignedIn implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $api = self::isApi($request);

        if (SignIn::expired()) {
            return self::refuse($request, $api, 'You were signed out after a while without activity. Sign in again to carry on.');
        }
        if (!SignIn::signedIn()) {
            return self::refuse($request, $api, 'Sign in to carry on.');
        }
        // Suspended, or every role taken away, since signing in: the session goes too.
        if ((new UserRepository())->actorById((int) SignIn::userId()) === null) {
            SignIn::end();

            return self::refuse($request, $api, 'Your account is no longer active. Ask whoever manages users.');
        }
        if ($api && !in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true) && !self::fromOwnPages($request)) {
            return service('response')->setStatusCode(403)->setJSON(['error' => 'This request did not come from the application\'s own pages, so it was not acted on.']);
        }

        SignIn::touch();

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nothing to add: responses are already sent no-store unless a controller says otherwise.
        return null;
    }

    public static function isApi(RequestInterface $request): bool
    {
        $path = self::path($request);

        return $path === 'api' || str_starts_with($path, 'api/');
    }

    /** The routed path ("api/journals"), without the base URL or index.php. */
    private static function path(RequestInterface $request): string
    {
        return trim($request instanceof IncomingRequest ? $request->getPath() : $request->getUri()->getPath(), '/');
    }

    public static function fromOwnPages(RequestInterface $request): bool
    {
        return $request->getHeaderLine('X-Requested-With') !== '';
    }

    private static function refuse(RequestInterface $request, bool $api, string $why)
    {
        if ($api) {
            return service('response')->setStatusCode(401)->setJSON(['error' => $why, 'signIn' => '/login']);
        }

        $path = '/' . self::path($request);
        $query = $request->getUri()->getQuery();
        $next = $path === '/' ? '' : '?next=' . rawurlencode($path . ($query !== '' ? '?' . $query : ''));

        // Relative, so it stays on whatever host and port the page was asked for.
        return service('response')->setStatusCode(302)->setHeader('Location', '/login' . $next);
    }
}
