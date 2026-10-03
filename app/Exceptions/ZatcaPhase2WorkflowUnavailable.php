<?php

namespace App\Exceptions;

use RuntimeException;

/** A feature has no reviewed Phase 2 fiscal transaction path yet. */
class ZatcaPhase2WorkflowUnavailable extends RuntimeException
{
}
