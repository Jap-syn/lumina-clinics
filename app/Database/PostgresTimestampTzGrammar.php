<?php

namespace App\Database;

use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Sends and reads timestamps with their UTC offset attached.
 *
 * Every business timestamp in this schema is `timestamptz`, and the application
 * deliberately reasons in branch-local time: a 14:00 start at Sukhumvit is a
 * CarbonImmutable carrying +07:00. Laravel's default date format is
 * 'Y-m-d H:i:s', which formats that value as the bare string
 * "2026-09-23 14:00:00" - the offset is dropped, not applied. Postgres then
 * reads it in the session timezone, so the booking lands somewhere other than
 * where it belongs, and every comparison made the same way misses it.
 *
 * Appending 'P' keeps the offset on the wire. The format is used both for query
 * bindings (Connection::prepareBindings) and, through
 * HasAttributes::getDateFormat, for every Eloquent date attribute - so one
 * change covers writes and comparisons alike, on any server timezone.
 *
 * This is the code half of the rule in docs/technical-notes.md: storage and
 * comparison are absolute; never hand the database a wall clock.
 */
class PostgresTimestampTzGrammar extends PostgresGrammar
{
    public function getDateFormat()
    {
        return 'Y-m-d H:i:sP';
    }
}
