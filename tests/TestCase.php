<?php

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private bool $clinicSeeded = false;

    /** Six branches, rooms, therapists and the treatment menu. */
    protected function seedClinic(): void
    {
        if ($this->clinicSeeded) {
            return;
        }

        $this->seed(DatabaseSeeder::class);
        $this->clinicSeeded = true;
    }
}
