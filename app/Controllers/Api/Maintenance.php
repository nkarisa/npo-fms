<?php

namespace App\Controllers\Api;

use App\Libraries\Maintenance as MaintenanceMode;
use App\Libraries\SettingsAccess;
use App\Repositories\MaintenanceRepository;
use App\Repositories\RuleViolation;

/**
 * Settings → Maintenance: closing the application while it is worked on, and
 * booking the periods that closure is planned for.
 *
 * A role holding settings.maintenance reads and changes this; nobody else reaches
 * it, settings.view or not. Everybody else hears about a closure where it matters
 * to them — the notification when one is booked, the bar across the top of every
 * page, and the maintenance screen itself. That permission is also the key to the
 * door: while the application is closed, its holders are the only people who can
 * sign in or stay signed in (App\Filters\SignedIn, Api\Auth), so it is handed out
 * as carefully as users.manage.
 *
 * Nothing here saves through the settings draft. Closing the application is not a
 * change somebody should be able to leave sitting unsaved in a browser next to an
 * edit to the chart of accounts.
 */
class Maintenance extends BaseApiController
{
    /** How it stands, what is booked, and what has already run. */
    public function index()
    {
        if (!SettingsAccess::of($this->actor())->canSee(MaintenanceMode::SECTION)) {
            return $this->denied($this->actor()['role'] . ' does not look after maintenance. ' . SettingsAccess::refusal(MaintenanceMode::SECTION));
        }

        return $this->json((new MaintenanceRepository())->panel($this->actor()));
    }

    /** Body: {on: bool, message}. Closes the application there and then, or opens it again. */
    public function set()
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }

        $body = $this->request->getJSON(true) ?? [];
        $on = filter_var($body['on'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            (new MaintenanceRepository())->setSwitch($on, (string) ($body['message'] ?? ''), $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json([
            'message' => $on
                ? 'The application is closed. Everyone without ' . MaintenanceMode::PERMISSION . ' is turned away at their next request and cannot sign in again until you open it.'
                : 'The application is open again. Everyone can sign in.',
        ] + (new MaintenanceRepository())->panel($this->actor()));
    }

    /** Body: {starts, ends, reason}. Books a window and tells everybody about it. */
    public function schedule()
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }

        $body = $this->request->getJSON(true) ?? [];

        try {
            $window = (new MaintenanceRepository())->schedule(
                (string) ($body['starts'] ?? ''), (string) ($body['ends'] ?? ''), (string) ($body['reason'] ?? ''), $this->actorId()
            );
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json([
            'message' => 'Maintenance booked for ' . $window['when'] . '. Everyone has been notified, and the application closes itself for ' . $window['lasts'] . ' when it starts.',
        ] + (new MaintenanceRepository())->panel($this->actor()));
    }

    /** Calls a booked window off — or ends one that is running — and tells everybody. */
    public function cancel(string $id)
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }

        try {
            $window = (new MaintenanceRepository())->cancel((int) $id, $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json([
            'message' => 'The maintenance booked for ' . $window['when'] . ' is cancelled. Everyone has been notified.',
        ] + (new MaintenanceRepository())->panel($this->actor()));
    }

    private function cannotManage()
    {
        return $this->can(MaintenanceMode::PERMISSION)
            ? null
            : $this->denied($this->actor()['role'] . ' cannot close the application. ' . SettingsAccess::refusal(MaintenanceMode::SECTION));
    }
}
