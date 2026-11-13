<?php

namespace App\Services\Agencies;

use RuntimeException;

/**
 * Adding or removing people cannot go ahead now; the message is for the principal.
 */
final class RosterChangeException extends RuntimeException {}
