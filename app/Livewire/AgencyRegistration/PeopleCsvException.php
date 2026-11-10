<?php

namespace App\Livewire\AgencyRegistration;

use RuntimeException;

/**
 * A CSV that cannot be read as a whole; the message is written for the person uploading it.
 */
final class PeopleCsvException extends RuntimeException {}
