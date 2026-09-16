<?php

namespace App\Services\Availability;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Room;
use App\Models\Therapist;
use App\Models\Treatment;
use App\Services\Booking\HoldSweeper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Works out when a treatment can actually happen.
 *
 * A slot is bookable only when BOTH resources are free:
 *
 *   - a room that can host the treatment, free for the treatment plus its
 *     15 minute clean up;
 *   - a therapist qualified for the treatment, free for the treatment plus her
 *     own turnaround.
 *
 * Keeping them separate is what makes the founder's two statements coexist.
 * The room always needs 15 minutes. The senior facialist needs none. So she can
 * take clients back to back, but only when a second room is free to move into -
 * which is exactly how it works on the floor today.
 */
class AvailabilityService
{
    public function __construct(private readonly HoldSweeper $sweeper) {}

    /**
     * Every candidate start time for one day, with who could perform it.
     *
     * @return array<int, array{starts_at: string, ends_at: string, therapist_ids: array<int,int>, rooms_free: int}>
     */
    public function day(
        Branch $branch,
        Treatment $treatment,
        CarbonImmutable $date,
        ?int $therapistId = null,
    ): array {
        // Release anything whose hold ran out before we report what is free.
        $this->sweeper->sweep();

        $tz = $branch->timezone;
        $date = $date->setTimezone($tz)->startOfDay();

        // RULE 13: a deactivated branch or treatment is never offered. Nothing
        // is ever deleted here, so this flag is the only thing standing between
        // a retired treatment and a client booking it.
        if (! $branch->active || ! $treatment->active) {
            return [];
        }

        // RULE 4: the branch has to be open that day.
        if (! $branch->opensOnWeekday((int) $date->isoWeekday())) {
            return [];
        }

        $therapists = $this->eligibleTherapists($branch, $treatment, $therapistId);
        $rooms = $this->eligibleRooms($branch, $treatment);

        if ($therapists->isEmpty() || $rooms->isEmpty()) {
            return [];
        }

        $open = $this->timeOn($date, $branch->opens_at, $tz);
        $close = $this->timeOn($date, $branch->closes_at, $tz);

        $bookings = $this->blockingBookingsBetween(
            $branch,
            $open->subHours(4),
            $close->addHours(4),
        );

        $now = CarbonImmutable::now($tz);
        $step = (int) config('lumina.slot_step_minutes');

        $slots = [];

        // RULE 4: starts land on the half hour grid; the treatment itself must
        // finish by closing time. Clean up may run past close - nobody else is
        // booked after it anyway.
        for ($start = $open; $start->addMinutes($treatment->duration_minutes) <= $close; $start = $start->addMinutes($step)) {
            // RULE 4: nothing in the past.
            if ($start <= $now) {
                continue;
            }

            $free = $this->freeResources($start, $treatment, $therapists, $rooms, $bookings);

            if ($free['therapists']->isEmpty() || $free['rooms']->isEmpty()) {
                continue;
            }

            $slots[] = [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $start->addMinutes($treatment->duration_minutes)->toIso8601String(),
                'therapist_ids' => $free['therapists']->pluck('id')->all(),
                'rooms_free' => $free['rooms']->count(),
            ];
        }

        return $slots;
    }

    /**
     * Which therapists and rooms are free for one specific start time.
     *
     * Used both when listing a day and, with a fresh read, at the moment of
     * booking. Anything passed in $exclude is skipped: the booking service uses
     * that to try a different room after losing a race for the first one.
     *
     * @param  Collection<int, Therapist>  $therapists
     * @param  Collection<int, Room>  $rooms
     * @param  Collection<int, Booking>  $bookings
     * @return array{therapists: Collection<int, Therapist>, rooms: Collection<int, Room>}
     */
    public function freeResources(
        CarbonImmutable $start,
        Treatment $treatment,
        Collection $therapists,
        Collection $rooms,
        Collection $bookings,
        array $excludeTherapistIds = [],
        array $excludeRoomIds = [],
    ): array {
        $end = $start->addMinutes($treatment->duration_minutes);

        $freeTherapists = $therapists
            ->reject(fn (Therapist $t) => in_array($t->id, $excludeTherapistIds, true))
            ->filter(function (Therapist $therapist) use ($bookings, $start, $end) {
                // RULE 2: the therapist is blocked for the treatment plus her
                // own turnaround, and the booking we are testing needs the same.
                $needsUntil = $end->addMinutes($therapist->buffer_minutes);

                return ! $bookings
                    ->where('therapist_id', $therapist->id)
                    ->contains(fn (Booking $b) => $this->overlaps(
                        $start, $needsUntil,
                        CarbonImmutable::parse($b->starts_at), CarbonImmutable::parse($b->therapist_release_at),
                    ));
            })
            ->values();

        $freeRooms = $rooms
            ->reject(fn (Room $r) => in_array($r->id, $excludeRoomIds, true))
            ->filter(function (Room $room) use ($bookings, $start, $end) {
                // RULE 1: the room is blocked for the treatment plus clean up.
                $needsUntil = $end->addMinutes($room->cleanup_minutes);

                return ! $bookings
                    ->where('room_id', $room->id)
                    ->contains(fn (Booking $b) => $this->overlaps(
                        $start, $needsUntil,
                        CarbonImmutable::parse($b->starts_at), CarbonImmutable::parse($b->room_release_at),
                    ));
            })
            ->values();

        return ['therapists' => $freeTherapists, 'rooms' => $freeRooms];
    }

    /** Half-open comparison: a treatment may begin exactly when a block ends. */
    private function overlaps(
        CarbonImmutable $aStart,
        CarbonImmutable $aEnd,
        CarbonImmutable $bStart,
        CarbonImmutable $bEnd,
    ): bool {
        return $aStart < $bEnd && $bStart < $aEnd;
    }

    /** @return Collection<int, Therapist> */
    public function eligibleTherapists(Branch $branch, Treatment $treatment, ?int $therapistId = null): Collection
    {
        // RULE 5: the therapist must work at this branch and be trained on this
        // treatment. "Some of them will only see their own therapist" is served
        // by the optional filter.
        return Therapist::query()
            ->where('branch_id', $branch->id)
            ->where('active', true)
            ->when($therapistId, fn ($q) => $q->where('id', $therapistId))
            ->whereHas('treatments', fn ($q) => $q->where('treatments.id', $treatment->id))
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Room> */
    public function eligibleRooms(Branch $branch, Treatment $treatment): Collection
    {
        // RULE 6: the room must be equipped for the treatment. The laser is in
        // one suite; a facial room cannot host it.
        return Room::query()
            ->where('branch_id', $branch->id)
            ->where('active', true)
            ->whereHas('treatments', fn ($q) => $q->where('treatments.id', $treatment->id))
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Booking> */
    public function blockingBookingsBetween(Branch $branch, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Booking::query()
            ->where('branch_id', $branch->id)
            ->blocking()
            ->where('starts_at', '<', $to)
            // Compare on the later of the two release columns so nothing that
            // still blocks is missed at the edges of the window.
            ->where(function ($q) use ($from) {
                $q->where('room_release_at', '>', $from)
                    ->orWhere('therapist_release_at', '>', $from);
            })
            ->get();
    }

    private function timeOn(CarbonImmutable $date, string $time, string $tz): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $date->setTimezone($tz)->setTime($h, $m);
    }

    /** RULE 4 (grid), reused when a caller proposes an arbitrary start time. */
    public function isOnGrid(CarbonImmutable $start, Branch $branch): bool
    {
        $local = $start->setTimezone($branch->timezone);

        return $local->second === 0
            && ($local->minute % (int) config('lumina.slot_step_minutes')) === 0;
    }

    public function isWithinOpeningHours(CarbonImmutable $start, Branch $branch, Treatment $treatment): bool
    {
        $local = $start->setTimezone($branch->timezone);

        if (! $branch->opensOnWeekday((int) $local->isoWeekday())) {
            return false;
        }

        $open = $this->timeOn($local->startOfDay(), $branch->opens_at, $branch->timezone);
        $close = $this->timeOn($local->startOfDay(), $branch->closes_at, $branch->timezone);

        return $local >= $open
            && $local->addMinutes($treatment->duration_minutes) <= $close;
    }
}
