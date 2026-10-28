<?php

namespace App\Exceptions;

use DomainException;

/**
 * The chosen agency principal cannot lead the agency (Agency::principalProblem()). The
 * message is safe to show to staff and holds no member data.
 */
class IneligiblePrincipalException extends DomainException {}
