<?php

namespace App\Repositories;

use RuntimeException;

/**
 * A write the books do not allow: a control, a status transition, or an
 * integrity rule the database enforces. The message is written for the user and
 * is returned to them as-is (HTTP 422).
 */
class RuleViolation extends RuntimeException
{
}
