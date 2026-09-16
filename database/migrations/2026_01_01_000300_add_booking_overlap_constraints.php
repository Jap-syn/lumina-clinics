<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The double-booking guard.
 *
 * "Last Christmas two clients turned up for the same 3pm laser slot.
 *  That cannot happen again."
 *
 * Checking availability in PHP before inserting is not enough. Two receptionists
 * on the shared desk (or a receptionist and a client on the website) can both
 * read "3pm is free" in the same millisecond, and both then write. The only
 * place that can arbitrate is the database, so the rule lives here as an
 * exclusion constraint rather than as an if-statement.
 *
 * The range compared is NOT the treatment window. It is the blocked window:
 * start -> room_release_at for a room, start -> therapist_release_at for a
 * therapist. A 60 minute facial at 14:00 blocks its room until 15:15.
 *
 * The WHERE clause means cancelled and expired rows stop blocking, which is how
 * a released hold frees the slot with no scheduled job involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Lets a plain bigint column (room_id) sit in a GiST index next to a
        // range. Ships with Postgres as a standard contrib module.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // RULE 1: one client per room at a time, including the 15 minute clean up.
        DB::statement(<<<'SQL'
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_no_room_overlap
            EXCLUDE USING gist (
                room_id WITH =,
                tstzrange(starts_at, room_release_at, '[)') WITH &&
            )
            WHERE (status IN ('pending_payment', 'confirmed'))
        SQL);

        // RULE 2: a therapist is in one place at a time, plus her own turnaround.
        // The senior facialist's buffer is 0, so she can go straight into her
        // next client - in a different room, because rule 1 still holds.
        DB::statement(<<<'SQL'
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_no_therapist_overlap
            EXCLUDE USING gist (
                therapist_id WITH =,
                tstzrange(starts_at, therapist_release_at, '[)') WITH &&
            )
            WHERE (status IN ('pending_payment', 'confirmed'))
        SQL);

        // RULE 3: a client cannot be in two treatments at once. Catches the
        // double submit that creates two different bookings rather than two
        // payments for one.
        DB::statement(<<<'SQL'
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_no_client_overlap
            EXCLUDE USING gist (
                client_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
            )
            WHERE (status IN ('pending_payment', 'confirmed'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_client_overlap');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_therapist_overlap');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_room_overlap');
    }
};
