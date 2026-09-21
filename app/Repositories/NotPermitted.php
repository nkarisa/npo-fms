<?php

namespace App\Repositories;

use RuntimeException;

/**
 * A change the person asking holds no permission for. Unlike a RuleViolation, it
 * says nothing about the change itself: the message names the permission it needs
 * and is returned to them as-is (HTTP 403).
 */
class NotPermitted extends RuntimeException
{
}
