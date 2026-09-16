<?php

namespace App\Services\Booking;

use App\Exceptions\BookingConflictException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Consent;
use App\Models\Treatment;
use App\Services\Availability\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class BookingService
{
    /** How many times to pick a different room/therapist after losing a race. */
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly HoldSweeper $sweeper,
    ) {}

    /**
     * Take a slot.
     *
     * Members are confirmed straight away. Everyone else gets a hold that
     * expires, so an abandoned checkout cannot sit on a laser slot all afternoon.
     *
     * @param  array{national_id: string, date_of_birth: string}|null  $consent
     *
     * @throws SlotUnavailableException
     * @throws BookingConflictException
     */
    public function hold(
        Branch $branch,
        Treatment $treatment,
        Client $client,
        CarbonImmutable $startsAt,
        ?int $requestedTherapistId = null,
        ?array $consent = null,
        string $via = 'online',
    ): Booking {
        $this->assertSlotIsLegal($branch, $treatment, $startsAt);

        // RULE 11: laser work cannot be booked without its consent record.
        if ($treatment->requires_consent && $consent === null) {
            throw new SlotUnavailableException(
                'This treatment requires a signed consent record before booking.',
                'consent_required',
            );
        }

        $triedRooms = [];
        $triedTherapists = [];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use (
                    $branch, $treatment, $client, $startsAt, $requestedTherapistId,
                    $consent, $via, $triedRooms, $triedTherapists
                ) {
                    // Inside the transaction so the read is as fresh as possible.
                    $this->sweeper->sweep();

                    $free = $this->availability->freeResources(
                        $startsAt,
                        $treatment,
                        $this->availability->eligibleTherapists($branch, $treatment, $requestedTherapistId),
                        $this->availability->eligibleRooms($branch, $treatment),
                        $this->availability->blockingBookingsBetween(
                            $branch,
                            $startsAt->subHours(4),
                            $startsAt->addHours(4),
                        ),
                        $triedTherapists,
                        $triedRooms,
                    );

                    if ($free['therapists']->isEmpty()) {
                        throw new SlotUnavailableException(
                            $requestedTherapistId
                                ? 'That therapist is not free at this time.'
                                : 'No therapist is free at this time.',
                            'no_therapist',
                        );
                    }

                    if ($free['rooms']->isEmpty()) {
                        throw new SlotUnavailableException('No room is free at this time.', 'no_room');
                    }

                    $therapist = $free['therapists']->first();
                    $room = $free['rooms']->first();

                    $endsAt = $startsAt->addMinutes($treatment->duration_minutes);

                    // "Members don't pay it."
                    $depositRequired = ! $client->is_member;

                    $booking = Booking::create([
                        'reference' => Booking::newReference(),
                        'branch_id' => $branch->id,
                        'treatment_id' => $treatment->id,
                        'therapist_id' => $therapist->id,
                        'room_id' => $room->id,
                        'client_id' => $client->id,

                        // RULE 7 in effect: no deposit, no hold - a member's
                        // booking is real immediately.
                        'status' => $depositRequired ? Booking::PENDING_PAYMENT : Booking::CONFIRMED,

                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'room_release_at' => $endsAt->addMinutes($room->cleanup_minutes),
                        'therapist_release_at' => $endsAt->addMinutes($therapist->buffer_minutes),
                        'room_cleanup_minutes' => $room->cleanup_minutes,
                        'therapist_buffer_minutes' => $therapist->buffer_minutes,

                        'deposit_required' => $depositRequired,
                        'deposit_minor_units' => $depositRequired ? (int) config('lumina.deposit_minor_units') : 0,
                        'hold_expires_at' => $depositRequired
                            ? now()->addMinutes((int) config('lumina.hold_minutes'))
                            : null,

                        'created_via' => $via,
                    ]);

                    if ($consent !== null) {
                        Consent::create([
                            'booking_id' => $booking->id,
                            'national_id' => $consent['national_id'],
                            'date_of_birth' => $consent['date_of_birth'],
                            'signed_at' => now(),
                        ]);
                    }

                    return $booking;
                });
            } catch (QueryException $e) {
                // The database refused the write: somebody else took the room or
                // the therapist between our read and our insert. This is the
                // Christmas 3pm laser case, caught by the constraint rather than
                // by luck.
                $conflict = $this->constraintFrom($e);

                if ($conflict === null) {
                    throw $e;
                }

                if ($conflict === 'bookings_no_client_overlap') {
                    // Retrying cannot help: this client is already booked then.
                    throw new SlotUnavailableException(
                        'You already have a booking that overlaps this time.',
                        'client_double_booked',
                    );
                }

                // Exclude whatever we just lost and try the next free resource.
                // With one room left this simply runs out of attempts and the
                // caller is told the slot is gone.
                $fresh = $this->availability->freeResources(
                    $startsAt,
                    $treatment,
                    $this->availability->eligibleTherapists($branch, $treatment, $requestedTherapistId),
                    $this->availability->eligibleRooms($branch, $treatment),
                    $this->availability->blockingBookingsBetween($branch, $startsAt->subHours(4), $startsAt->addHours(4)),
                );

                if ($fresh['rooms']->isEmpty() || $fresh['therapists']->isEmpty()) {
                    throw new BookingConflictException(
                        'That slot was taken a moment before yours. Please pick another time.'
                    );
                }
            }
        }

        throw new BookingConflictException(
            'That slot was taken a moment before yours. Please pick another time.'
        );
    }

    /**
     * RULE 7: the deposit turns a hold into a confirmed appointment.
     *
     * Members never reach here - they are confirmed at creation.
     */
    public function confirmDeposit(Booking $booking): Booking
    {
        $booking->forceFill([
            'status' => Booking::CONFIRMED,
            'deposit_paid_at' => now(),
            'hold_expires_at' => null,
        ])->save();

        return $booking->refresh();
    }

    /**
     * RULE 10: free cancellation up to 24 hours before; after that the deposit
     * is kept. Returns the booking with deposit_forfeited set accordingly.
     */
    public function cancel(Booking $booking, string $reason = 'client_request'): Booking
    {
        if (! $booking->isCancellable()) {
            throw new SlotUnavailableException(
                'This booking can no longer be cancelled.',
                'not_cancellable',
            );
        }

        $refundable = $booking->qualifiesForRefund();

        $booking->forceFill([
            'status' => Booking::CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'deposit_forfeited' => $booking->deposit_paid_at !== null && ! $refundable,
        ])->save();

        return $booking->refresh();
    }

    /** @throws SlotUnavailableException */
    private function assertSlotIsLegal(Branch $branch, Treatment $treatment, CarbonImmutable $startsAt): void
    {
        // RULE 13: deactivated branches and treatments take no new bookings.
        // Appointments already in the diary for them stand.
        if (! $branch->active || ! $treatment->active) {
            throw new SlotUnavailableException(
                'That treatment is not currently offered at this branch.',
                'not_offered',
            );
        }

        // RULE 4: no bookings in the past.
        if ($startsAt <= CarbonImmutable::now()) {
            throw new SlotUnavailableException('That time is in the past.', 'in_the_past');
        }

        $horizon = CarbonImmutable::now()->addDays((int) config('lumina.booking_horizon_days'));

        if ($startsAt > $horizon) {
            throw new SlotUnavailableException('That date is too far ahead.', 'beyond_horizon');
        }

        // RULE 4: on the hour or the half hour.
        if (! $this->availability->isOnGrid($startsAt, $branch)) {
            throw new SlotUnavailableException(
                'Bookings start on the hour or the half hour.',
                'off_grid',
            );
        }

        // RULE 4: inside opening hours, finishing before close.
        if (! $this->availability->isWithinOpeningHours($startsAt, $branch, $treatment)) {
            throw new SlotUnavailableException(
                'That time is outside the branch opening hours.',
                'outside_hours',
            );
        }
    }

    /** Identify which exclusion constraint rejected the write, if any. */
    private function constraintFrom(QueryException $e): ?string
    {
        $message = $e->getMessage();

        foreach ([
            'bookings_no_room_overlap',
            'bookings_no_therapist_overlap',
            'bookings_no_client_overlap',
        ] as $constraint) {
            if (str_contains($message, $constraint)) {
                return $constraint;
            }
        }

        return null;
    }
}
