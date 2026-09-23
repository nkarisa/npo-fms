<?php

namespace App\Filters;

use App\Libraries\Clock;
use App\Libraries\EntityScope;
use App\Libraries\Maintenance;
use App\Libraries\SignIn;
use App\Repositories\UserRepository;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Keeps everything but the sign-in screens behind a completed sign-in, and the
 * whole application behind whoever holds the keys while it is closed for
 * maintenance.
 *
 * Applied to every route except those listed in Config\Filters (the sign-in pages
 * and /api/auth/*). A page request without a session is sent to /login and
 * brought back afterwards; an API request is answered 401, which the page scripts
 * turn into the same trip. A session part-way through (password given, second
 * factor not yet) counts as not signed in, and so does one whose user has since
 * been suspended or lost every role.
 *
 * While the application is closed for maintenance (App\Libraries\Maintenance),
 * only a holder of settings.maintenance gets past here — whoever closed it, and
 * whoever else can open it again. A session already open is not ended: everyone
 * else is shown the maintenance page instead of the application, and is back
 * where they left off the moment it opens. The check is on the person signed in
 * rather than on anyone they are acting as, so "Act as" on a training instance
 * cannot be used to walk through a closed door.
 *
 * In the consolidated view (App\Libraries\EntityScope), which reads every
 * entity's books and belongs to none of them, the API refuses writes other than
 * to the person's own account.
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
        $actor = (new UserRepository())->actorById((int) SignIn::userId());
        if ($actor === null) {
            SignIn::end();

            return self::refuse($request, $api, 'Your account is no longer active. Ask whoever manages users.');
        }
        if (!Maintenance::admits($actor)) {
            return self::closed($api);
        }
        $write = !in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($api && $write && !self::fromOwnPages($request)) {
            return service('response')->setStatusCode(403)->setJSON(['error' => 'This request did not come from the application\'s own pages, so it was not acted on.']);
        }
        // The consolidated view reads every entity's books and belongs to none of
        // them, so nothing is recorded from it — only the person's own account.
        if ($api && $write && EntityScope::consolidated() && !self::personal(self::path($request))) {
            return service('response')->setStatusCode(409)->setJSON([
                'error' => 'You are viewing the consolidated books, which are read only. Choose an entity at the top of the page to make changes there.',
                'consolidated' => true,
            ]);
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

    /** A change to the person's own account, which belongs to no entity. */
    private static function personal(string $path): bool
    {
        foreach (['api/me', 'api/account', 'api/auth', 'api/notifications'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    public static function fromOwnPages(RequestInterface $request): bool
    {
        return $request->getHeaderLine('X-Requested-With') !== '';
    }

    /**
     * The application is closed and this is not one of the people who can close
     * it. A page gets the maintenance screen; a request from a page already open
     * gets 503 and a header its scripts turn into a trip to that screen.
     */
    private static function closed(bool $api)
    {
        $state = Maintenance::state();
        $response = service('response')->setStatusCode(503)->setHeader('X-Maintenance', '1')->setHeader('Cache-Control', 'no-store');

        if ($api) {
            return $response->setJSON(['error' => Maintenance::refusal(), 'maintenance' => true]);
        }
        if ($state['until'] !== null) {
            // Whole seconds until the window ends, for anything that reads it as a
            // machine. Measured on the application's own clock, like the window itself.
            $response->setHeader('Retry-After', (string) max(60, strtotime($state['until']) - strtotime(Clock::timestamp())));
        }

        return $response->setBody(view('maintenance', ['state' => $state]));
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
