<?php

namespace App\Services\Booking;

use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * RULE 8: an unpaid hold releases its slot once it expires - with no cron job.
 *
 * "Our IT guy says nothing can run in the background on it. No scheduled jobs."
 *
 * The obvious design for holds is a scheduled task that sweeps expired rows
 * every minute. Lumina's shared hosting cannot run one, so the sweep happens on
 * the request path instead: any request that is about to read or write
 * availability first flips expired holds to 'expired'.
 *
 * That status is outside the exclusion constraints' WHERE clause, so the moment
 * a hold is swept the room and the therapist are free again.
 *
 * The cost is one indexed UPDATE per availability check. The partial index
 * bookings_live_holds_idx means it touches only rows that are actually holds,
 * so in the normal case it matches nothing and returns immediately.
 */
class HoldSweeper
{
    /** @return int number of holds released */
    public function sweep(): int
    {
        return DB::table('bookings')
            ->where('status', Booking::PENDING_PAYMENT)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->update([
                'status' => Booking::EXPIRED,
                'updated_at' => now(),
            ]);
    }
}
