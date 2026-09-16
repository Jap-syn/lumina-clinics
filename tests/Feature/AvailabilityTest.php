<?php

namespace Tests\Feature;

use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Room;
use App\Models\Therapist;
use App\Models\Treatment;
use App\Services\Availability\AvailabilityService;
use App\Services\Booking\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function nextTuesday(int $hour = 10, int $minute = 0): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Bangkok')
            ->addWeek()
            ->next(CarbonImmutable::TUESDAY)
            ->setTime($hour, $minute);
    }

    private function slotTimes(Branch $branch, Treatment $treatment, CarbonImmutable $day, ?int $therapistId = null): array
    {
        return collect(app(AvailabilityService::class)->day($branch, $treatment, $day, $therapistId))
            ->map(fn ($s) => CarbonImmutable::parse($s['starts_at'])->setTimezone('Asia/Bangkok')->format('H:i'))
            ->all();
    }

    /**
     * RULE 1: a room needs 15 minutes after a client before the next one.
     *
     * A 60 minute facial at 14:00 ends at 15:00, but the room is not free until
     * 15:15 - so 15:00 must not be offered for that room.
     */
    public function test_room_is_blocked_for_fifteen_minutes_after_a_treatment(): void
    {
        $this->seedClinic();

        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'signature-facial')->first();

        // Reduce this branch to a single facial room so the cleanup rule is the
        // only thing that can free or block the slot.
        $branch->rooms()->where('name', 'Treatment Room 2')->delete();

        $start = $this->nextTuesday(14, 0);

        app(BookingService::class)->hold(
            $branch,
            $treatment,
            Client::create(['name' => 'A', 'phone' => '0855555555', 'is_member' => true]),
            $start,
        );

        $times = $this->slotTimes($branch, $treatment, $start);

        $this->assertNotContains('14:00', $times, '14:00 is taken.');
        $this->assertNotContains('14:30', $times, '14:30 overlaps the treatment.');
        $this->assertNotContains('15:00', $times, '15:00 falls inside the 15 minute clean up.');
        $this->assertContains('15:30', $times, '15:30 is the first genuinely free start.');
    }

    /**
     * RULE 2: the senior facialist has no turnaround of her own.
     *
     * With two rooms she can take a client at 15:00 immediately after finishing
     * one at 15:00 - in the other room, because the first still needs cleaning.
     * This is the case that fails if rooms and therapists share one buffer.
     */
    public function test_senior_facialist_can_work_back_to_back_in_a_second_room(): void
    {
        $this->seedClinic();

        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'signature-facial')->first();
        $senior = $branch->therapists()->where('title', 'Senior Facialist')->first();

        $this->assertSame(0, $senior->buffer_minutes);

        $start = $this->nextTuesday(14, 0);

        app(BookingService::class)->hold(
            $branch,
            $treatment,
            Client::create(['name' => 'A', 'phone' => '0866666666', 'is_member' => true]),
            $start,
            requestedTherapistId: $senior->id,
        );

        $times = $this->slotTimes($branch, $treatment, $start, $senior->id);

        $this->assertContains('15:00', $times, 'She is free the moment the treatment ends.');
    }

    /** The same slot is NOT available to a therapist who does need a break. */
    public function test_a_therapist_with_a_buffer_cannot_work_back_to_back(): void
    {
        $this->seedClinic();

        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'signature-facial')->first();
        $regular = $branch->therapists()->where('title', 'Therapist')->first();

        $this->assertSame(15, $regular->buffer_minutes);

        $start = $this->nextTuesday(14, 0);

        app(BookingService::class)->hold(
            $branch,
            $treatment,
            Client::create(['name' => 'A', 'phone' => '0877777777', 'is_member' => true]),
            $start,
            requestedTherapistId: $regular->id,
        );

        $times = $this->slotTimes($branch, $treatment, $start, $regular->id);

        $this->assertNotContains('15:00', $times, 'She needs 15 minutes before the next client.');
        $this->assertContains('15:30', $times);
    }

    /** RULE 4: starts are on the hour and the half hour only. */
    public function test_a_start_time_off_the_grid_is_refused(): void
    {
        $this->seedClinic();

        $this->expectException(SlotUnavailableException::class);

        app(BookingService::class)->hold(
            Branch::first(),
            Treatment::where('slug', 'express-facial')->first(),
            Client::create(['name' => 'A', 'phone' => '0888888888', 'is_member' => true]),
            $this->nextTuesday(14, 20),
        );
    }

    /** RULE 4: a 90 minute treatment must finish before the branch closes. */
    public function test_long_treatments_are_not_offered_near_closing_time(): void
    {
        $this->seedClinic();

        $branch = Branch::first();          // closes 20:00
        $ninety = Treatment::where('slug', 'deep-cleanse-peel')->first();

        $times = $this->slotTimes($branch, $ninety, $this->nextTuesday());

        $this->assertContains('18:30', $times, '18:30 + 90 min ends exactly at close.');
        $this->assertNotContains('19:00', $times, '19:00 + 90 min would run past close.');
    }

    /** RULE 5/6: a laser needs the laser suite and a trained operator. */
    public function test_a_therapist_who_is_not_trained_is_never_offered(): void
    {
        $this->seedClinic();

        $branch = Branch::first();
        $laser = Treatment::where('slug', 'laser-hair-removal')->first();
        $facialOnly = $branch->therapists()->where('title', 'Therapist')->first();

        $slots = app(AvailabilityService::class)->day($branch, $laser, $this->nextTuesday());

        $this->assertNotEmpty($slots);

        foreach ($slots as $slot) {
            $this->assertNotContains(
                $facialOnly->id,
                $slot['therapist_ids'],
                'A facial-only therapist must never be offered for laser work.'
            );
        }
    }

    /** RULE 4: the past is not bookable. */
    public function test_a_time_in_the_past_is_refused(): void
    {
        $this->seedClinic();

        $this->expectException(SlotUnavailableException::class);

        app(BookingService::class)->hold(
            Branch::first(),
            Treatment::where('slug', 'express-facial')->first(),
            Client::create(['name' => 'A', 'phone' => '0899999999', 'is_member' => true]),
            CarbonImmutable::now('Asia/Bangkok')->subDay()->setTime(14, 0),
        );
    }

    /** A closed day offers nothing at all. */
    public function test_a_closed_branch_day_offers_no_slots(): void
    {
        $this->seedClinic();

        $branch = Branch::first();
        $day = $this->nextTuesday();

        // Close on Tuesdays.
        $branch->update(['open_weekdays' => [1, 3, 4, 5, 6, 7]]);

        $this->assertSame([], app(AvailabilityService::class)->day(
            $branch->fresh(),
            Treatment::where('slug', 'express-facial')->first(),
            $day,
        ));
    }
}
