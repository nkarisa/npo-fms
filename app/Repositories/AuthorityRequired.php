<?php

namespace App\Repositories;

/**
 * An approval that escalates to an authority outside the system (the Board
 * Treasurer, a board minute). The in-system approver may proceed once they
 * record the reference for that authority; the response says so (HTTP 422 with
 * `needsAuthority`).
 */
final class AuthorityRequired extends RuleViolation
{
}
