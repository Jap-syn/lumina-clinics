<?php

namespace App\Exceptions;

use RuntimeException;

/** The database refused the write because the slot was taken mid-flight. */
class BookingConflictException extends RuntimeException {}
