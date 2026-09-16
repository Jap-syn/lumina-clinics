<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the requested time cannot be honoured: nothing free, off the
 * grid, outside opening hours, in the past, or lost to a concurrent booking.
 */
class SlotUnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'unavailable')
    {
        parent::__construct($message);
    }
}
