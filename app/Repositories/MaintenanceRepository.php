<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Maintenance;

/**
 * Closing the application for maintenance, and the periods it is planned for.
 *
 * Two things are held. Whether it is closed right now is one setting on the head
 * office (Maintenance::KEY), carrying the message people are turned away with,
 * when it was closed and by whom — switched by hand, and it stays as it is until
 * somebody switches it back. A maintenance window is a period agreed in advance:
 * everyone is told when it is booked, the application carries it on every page as
 * it approaches, and while it runs the application is closed without anyone
 * having to be at a keyboard at midnight to close it.
 *
 * So the application is closed when either says so, and whichever is closing it
 * is what people are told. A window that is running does not touch the switch:
 * when it ends, the application is open again, and a switch thrown by hand during
 * it still has to be thrown back.
 *
 * Nothing here is deleted. A window is cancelled — what was announced and what
 * happened both stay — and every switch, booking and cancellation is in the
 * settings audit log under Maintenance.
 */
final class MaintenanceRepository extends Repository
{
    /** Kept in the notification bell, so a booking is not just a banner someone missed. */
    private const NOTIFICATION_KIND = 'Maintenance';

    private Lookups $lookups;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
        $this->lookups = new Lookups($this->db);
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * How the application stands, in the shape App\Libraries\Maintenance describes.
     * Read on every page, so it is one query for the setting and one for the windows.
     */
    public function state(): array
    {
        return $this->cached('state', function () {
            $held = $this->held();
            $now = Clock::timestamp();
            $running = null;
            $next = null;

            foreach ($this->rows(
                'SELECT * FROM {maintenance_windows} WHERE cancelled_at IS NULL AND ends_at > ? ORDER BY starts_at LIMIT 2', [$now]
            ) as $row) {
                if ($row['starts_at'] <= $now) {
                    $running ??= $this->shape($row);

                    continue;
                }
                $next ??= $this->shape($row);
            }

            $on = $held['on'] || $running !== null;

            return [
                'on'       => $on,
                // Closed by hand rather than by a window: it does not open by itself.
                'switched' => $held['on'],
                'message'  => !$on ? '' : ($held['on'] ? ($held['message'] ?: Maintenance::DEFAULT_MESSAGE) : $running['reason']),
                'since'    => $held['on'] ? $held['since'] : ($running['starts'] ?? null),
                'by'       => $held['on'] ? $held['by'] : ($running['by'] ?? null),
                // A window says when it opens again; a hand switch does not, because nobody knows.
                'until'    => $held['on'] ? null : ($running['ends'] ?? null),
                'window'   => $running,
                'next'     => $next,
            ];
        });
    }

    /**
     * Everything the Maintenance section shows: how it stands, what is booked, what
     * has been done, and whether this person may change any of it.
     *
     * @param array|null $actor as UserRepository describes one
     */
    public function panel(?array $actor): array
    {
        $state = $this->state();
        $can = in_array(Maintenance::PERMISSION, $actor['permissions'] ?? [], true);

        return [
            'state'     => $state,
            'banner'    => Maintenance::banner(),
            'windows'   => $this->windows(),
            'canManage' => $can,
            'permission' => Maintenance::PERMISSION,
            'role'      => $actor['role'] ?? '',
            'keepers'   => $this->keepers(),
            'limits'    => [
                'minMinutes' => Maintenance::MIN_MINUTES, 'maxHours' => Maintenance::MAX_HOURS,
                'maxReason' => Maintenance::MAX_REASON, 'warnDays' => Maintenance::WARN_DAYS,
            ],
            // The browser fills the form with a window starting at the next round hour.
            'now'       => date('Y-m-d\TH:i', strtotime(Clock::timestamp())),
        ];
    }

    /** Every window, the ones still to come first, then what has already run. */
    public function windows(): array
    {
        return array_map($this->shape(...), $this->rows(
            'SELECT * FROM {maintenance_windows} ORDER BY starts_at DESC LIMIT 40'
        ));
    }

    /** The people who can let everyone back in, for the message that turns them away. */
    public function keepers(): array
    {
        return array_column($this->rows(
            "SELECT DISTINCT u.name FROM {users} u JOIN {user_entity_roles} ur ON ur.user_id = u.id
             JOIN {role_permissions} rp ON rp.role_id = ur.role_id JOIN {permissions} p ON p.id = rp.permission_id
             WHERE u.status = 'active' AND p.key = ? ORDER BY u.name", [Maintenance::PERMISSION]
        ), 'name');
    }

    // ------------------------------------------------------------------
    // Switching it by hand
    // ------------------------------------------------------------------

    /**
     * Closes the application, or opens it again. Closing it takes a message: it is
     * the only thing everyone turned away is going to read.
     */
    public function setSwitch(bool $on, string $message, int $actorId): array
    {
        $held = $this->held();
        $message = $on ? $this->validMessage($message) : '';

        // Asked to open what was never switched closed. A running window is the
        // usual reason, and it is ended on the window rather than here.
        if (!$on && $held['on'] === false) {
            throw new RuleViolation($this->state()['window'] !== null
                ? 'The application is closed by a scheduled maintenance window, not by hand. End that window to open it again.'
                : 'The application is already open.');
        }
        if ($on === $held['on'] && $message === $held['message']) {
            return $this->state();
        }

        $now = Clock::timestamp();
        $this->transaction(function () use ($on, $message, $held, $now, $actorId) {
            $this->hold([
                'on' => $on, 'message' => $message,
                'since' => $on ? ($held['on'] ? $held['since'] : $now) : null,
                'by' => $on ? ($held['on'] ? $held['byId'] : $actorId) : null,
            ]);
            $this->logChange($on
                ? ($held['on'] ? 'Maintenance message changed to: ' . $message : 'Application closed for maintenance — ' . $message)
                : 'Application opened again after maintenance', $actorId);
        });

        return $this->state();
    }

    // ------------------------------------------------------------------
    // Planning one
    // ------------------------------------------------------------------

    /**
     * Books a window and tells everybody. The times are as the browser sends them
     * ("2026-09-26T18:00") or as the database holds them; both are read the same way.
     */
    public function schedule(string $starts, string $ends, string $reason, int $actorId): array
    {
        $from = $this->validMoment($starts, 'it starts');
        $to = $this->validMoment($ends, 'it ends');
        $reason = $this->validMessage($reason);
        $now = Clock::timestamp();

        if ($from < $now) {
            throw new RuleViolation('A maintenance window is arranged in advance, so it starts in the future. To close the application now, switch it closed instead.');
        }
        if ($to <= $from) {
            throw new RuleViolation('The window ends before it begins. Give the time it ends after the time it starts.');
        }
        if (strtotime($to) - strtotime($from) < Maintenance::MIN_MINUTES * 60) {
            throw new RuleViolation('That is barely a pause. A window runs at least ' . Maintenance::MIN_MINUTES . ' minutes — long enough to be worth telling everybody about.');
        }
        if (strtotime($to) - strtotime($from) > Maintenance::MAX_HOURS * 3600) {
            throw new RuleViolation('That closes the application for ' . Maintenance::lasts($from, $to) . '. A window runs at most ' . Maintenance::MAX_HOURS . ' hours — book the next stretch as a second window.');
        }
        $clash = $this->row(
            'SELECT * FROM {maintenance_windows} WHERE cancelled_at IS NULL AND starts_at < ? AND ends_at > ? ORDER BY starts_at LIMIT 1', [$to, $from]
        );
        if ($clash !== null) {
            throw new RuleViolation('That overlaps the window already booked for ' . Maintenance::period($clash['starts_at'], $clash['ends_at']) . '. Cancel it first, or choose another time.');
        }

        $id = $this->transaction(function () use ($from, $to, $reason, $actorId, $now) {
            $id = $this->insert('maintenance_windows', [
                'starts_at' => $from, 'ends_at' => $to, 'reason' => $reason, 'created_by' => $actorId, 'created_at' => $now,
            ]);
            $this->tellEveryone(
                'Planned maintenance ' . Maintenance::period($from, $to),
                $reason . ' The application is closed to everyone for ' . Maintenance::lasts($from, $to) . ', so finish and save your work before it starts.',
                'alert'
            );
            $this->logChange('Maintenance booked for ' . Maintenance::period($from, $to) . ' — ' . $reason, $actorId);

            return $id;
        });

        return $this->window($id);
    }

    /** Calls a booked window off, and tells everybody it was announced to. */
    public function cancel(int $id, int $actorId): array
    {
        $row = $this->row('SELECT * FROM {maintenance_windows} WHERE id = ?', [$id])
            ?? throw new RuleViolation('That maintenance window is not one this application knows.');
        if ($row['cancelled_at'] !== null) {
            throw new RuleViolation('That window was already cancelled.');
        }
        if ($row['ends_at'] <= Clock::timestamp()) {
            throw new RuleViolation('That window has already run. It stays on the record as it happened.');
        }

        $now = Clock::timestamp();
        $running = $row['starts_at'] <= $now;
        $this->transaction(function () use ($row, $id, $running, $now, $actorId) {
            $this->db->table('maintenance_windows')->where('id', $id)
                ->update(['cancelled_at' => $now, 'cancelled_by' => $actorId, 'updated_at' => $now]);
            $this->tellEveryone(
                ($running ? 'Maintenance ended early' : 'Planned maintenance cancelled') . ' — ' . Maintenance::period($row['starts_at'], $row['ends_at']),
                $running
                    ? 'The maintenance that was under way has been called off and the application is open again.'
                    : 'The maintenance booked for then is no longer going ahead. Nothing is closing.',
                'info'
            );
            $this->logChange(($running ? 'Maintenance ended early, booked for ' : 'Maintenance cancelled, booked for ')
                . Maintenance::period($row['starts_at'], $row['ends_at']), $actorId);
        });

        return $this->window($id);
    }

    // ------------------------------------------------------------------

    /** The held switch, as it is stored. An instance that has never used it is open. */
    private function held(): array
    {
        $row = $this->db->table('settings')->select('value')
            ->where('entity_id', $this->headOfficeId())->where('key', Maintenance::KEY)->get()->getRowArray();
        $held = $row === null ? null : json_decode((string) $row['value'], true);
        $held = is_array($held) ? $held : [];

        return [
            'on'      => (bool) ($held['on'] ?? false),
            'message' => (string) ($held['message'] ?? ''),
            'since'   => $held['since'] ?? null,
            'by'      => isset($held['by']) ? $this->lookups->shortName((int) $held['by'], '') : null,
            'byId'    => $held['by'] ?? null,
        ];
    }

    /**
     * Writes the switch. Written rather than updated blind: an instance installed
     * before maintenance mode existed has no row to update.
     */
    private function hold(array $switch): void
    {
        $entityId = $this->headOfficeId();
        $now = Clock::timestamp();
        // Through the builder rather than raw SQL: "key" is a reserved word, and the
        // builder quotes it for whichever database is behind this.
        $held = $this->db->table('settings')->select('id')->where('entity_id', $entityId)->where('key', Maintenance::KEY)->get()->getRowArray();
        $value = (string) json_encode($switch);

        if ($held === null) {
            $this->insert('settings', [
                'entity_id' => $entityId, 'key' => Maintenance::KEY, 'kind' => Maintenance::KIND, 'value' => $value,
                'label' => 'Maintenance mode', 'created_at' => $now,
                'note' => 'Whether the application is closed to everyone but the people who can close it. Set in Settings → Maintenance.',
            ]);

            return;
        }

        $this->db->table('settings')->where('id', $held['id'])->update(['value' => $value, 'updated_at' => $now]);
    }

    /** One window as the screen reads it. */
    private function shape(array $row): array
    {
        $now = Clock::timestamp();
        $state = match (true) {
            $row['cancelled_at'] !== null => 'Cancelled',
            $row['ends_at'] <= $now       => 'Finished',
            $row['starts_at'] <= $now     => 'Under way',
            default                       => 'Scheduled',
        };

        return [
            'id'       => (int) $row['id'],
            'starts'   => $row['starts_at'],
            'ends'     => $row['ends_at'],
            'reason'   => (string) $row['reason'],
            'state'    => $state,
            'when'     => Maintenance::period($row['starts_at'], $row['ends_at']),
            'lasts'    => Maintenance::lasts($row['starts_at'], $row['ends_at']),
            'relative' => Maintenance::relative($row['starts_at']),
            'days'     => max(0, (int) ceil((strtotime($row['starts_at']) - strtotime($now)) / 86400)),
            'by'       => $this->lookups->shortName((int) $row['created_by'], ''),
            'cancelledBy' => $row['cancelled_by'] === null ? null : $this->lookups->shortName((int) $row['cancelled_by'], ''),
        ];
    }

    private function window(int $id): array
    {
        self::forget();

        return $this->shape($this->row('SELECT * FROM {maintenance_windows} WHERE id = ?', [$id]));
    }

    /**
     * A notification for everyone who can sign in. It belongs to no entity's books —
     * the application is one application whichever entity someone is working in — so
     * it is written without one and read in every scope (App\Libraries\EntityScope).
     */
    private function tellEveryone(string $title, string $body, string $tone): void
    {
        $now = Clock::timestamp();
        foreach ($this->rows(
            "SELECT DISTINCT u.id FROM {users} u JOIN {user_entity_roles} ur ON ur.user_id = u.id WHERE u.status IN ('active', 'invited')"
        ) as $user) {
            $this->insert('notifications', [
                'user_id' => (int) $user['id'], 'entity_id' => null, 'kind' => self::NOTIFICATION_KIND, 'tone' => $tone,
                'title' => $title, 'body' => $body, 'created_at' => $now,
            ]);
        }
    }

    private function validMessage(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message));
        if (mb_strlen($message) < Maintenance::MIN_REASON || mb_strlen($message) > Maintenance::MAX_REASON) {
            throw new RuleViolation('Say what the maintenance is for, in ' . Maintenance::MIN_REASON . ' to ' . Maintenance::MAX_REASON
                . ' characters — "Upgrading the database; payments and approvals will be unavailable." It is the only thing everyone else is told.');
        }

        return $message;
    }

    /** "2026-09-26T18:00", "2026-09-26 18:00" or "2026-09-26 18:00:00" → as the database holds it. */
    private function validMoment(string $value, string $which): string
    {
        $value = trim(str_replace('T', ' ', $value));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value) !== 1 || strtotime($value) === false) {
            throw new RuleViolation('Give the date and time ' . $which . '.');
        }

        return date('Y-m-d H:i:s', strtotime($value));
    }

    private function headOfficeId(): int
    {
        return $this->lookups->headOfficeId();
    }

    private function logChange(string $what, int $actorId): void
    {
        $this->audit('settings:maintenance', null, null, $what, $actorId, 'settings.changed', $this->headOfficeId());
    }
}
