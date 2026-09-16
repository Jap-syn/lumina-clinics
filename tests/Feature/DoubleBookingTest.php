<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Treatment;
use App\Services\Booking\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Last Christmas two clients turned up for the same 3pm laser slot.
 *  That cannot happen again."
 *
 * These tests use DatabaseMigrations rather than RefreshDatabase on purpose.
 * RefreshDatabase wraps each test in a transaction, which would hide every write
 * from the second connection and make a concurrency test meaningless.
 */
class DoubleBookingTest extends TestCase
{
    use DatabaseMigrations;

    private function laserSlot(): CarbonImmutable
    {
        // 3pm, the slot from the story, on a day comfortably in the future.
        return CarbonImmutable::now('Asia/Bangkok')
            ->addWeek()
            ->setTime(15, 0);
    }

    /**
     * Two writers, no coordination, one slot.
     *
     * Both transactions are open at the same time and both insert the same room
     * at the same time. Exactly one may survive.
     */
    public function test_two_simultaneous_connections_cannot_book_the_same_room(): void
    {
        $this->seedClinic();

        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'laser-hair-removal')->first();
        $room = $branch->rooms()->where('name', 'Laser Suite')->first();
        $therapistA = $branch->therapists()->where('title', 'Laser Technician')->first();
        $therapistB = $branch->therapists()->where('title', 'Senior Facialist')->first();

        $clientA = Client::create(['name' => 'Client A', 'phone' => '0811111111']);
        $clientB = Client::create(['name' => 'Client B', 'phone' => '0822222222']);

        $start = $this->laserSlot();
        $end = $start->addMinutes($treatment->duration_minutes);

        // A second, genuinely independent PDO handle on the same database.
        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

        $row = fn (Client $client, $therapist) => [
            'reference' => Booking::newReference(),
            'branch_id' => $branch->id,
            'treatment_id' => $treatment->id,
            'therapist_id' => $therapist->id,
            'room_id' => $room->id,
            'client_id' => $client->id,
            'status' => Booking::CONFIRMED,
            'starts_at' => $start,
            'ends_at' => $end,
            'room_release_at' => $end->addMinutes(15),
            'therapist_release_at' => $end->addMinutes($therapist->buffer_minutes),
            'room_cleanup_minutes' => 15,
            'therapist_buffer_minutes' => $therapist->buffer_minutes,
            'deposit_required' => true,
            'deposit_minor_units' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $first = DB::connection('pgsql');
        $second = DB::connection('pgsql_second');

        $first->beginTransaction();
        $first->table('bookings')->insert($row($clientA, $therapistA));

        // The second writer has already decided 3pm is free - it reads here,
        // while the first writer's row is still uncommitted and therefore
        // invisible to it. This is the state both receptionists were in.
        $second->beginTransaction();

        $this->assertSame(
            0,
            $second->table('bookings')->where('room_id', $room->id)->count(),
            'The second writer must still believe the slot is free.'
        );

        // An exclusion constraint does NOT reject a conflict with an uncommitted
        // row: it blocks the second writer until the first transaction ends, and
        // only then raises 23P01. So the first must commit before the second
        // writes. Doing it the other way round parks this single process on a
        // lock that only it could release - the suite hangs rather than fails.
        $first->commit();

        $rejected = false;

        try {
            $second->table('bookings')->insert($row($clientB, $therapistB));
            $second->commit();
        } catch (\Illuminate\Database\QueryException $e) {
            $rejected = true;
            $second->rollBack();
            $this->assertStringContainsString('bookings_no_room_overlap', $e->getMessage());
        }

        $this->assertTrue($rejected, 'The second booking should have been refused by the database.');
        $this->assertSame(1, Booking::where('room_id', $room->id)->blocking()->count());
    }

    /**
     * The same race, but through the real application path, with real parallel
     * processes rather than an interleaving we arranged by hand.
     *
     * Every child passes its own availability check - they all read before any
     * of them writes - so the only thing standing between Lumina and a repeat of
     * last Christmas is the constraint.
     */
    public function test_twenty_parallel_processes_produce_exactly_one_booking(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to fork parallel bookers.');
        }

        $this->seedClinic();

        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'laser-resurfacing')->first();
        $start = $this->laserSlot();

        // One client per process, created up front so the client-overlap rule is
        // not what produces the result.
        $clientIds = [];
        for ($i = 0; $i < 20; $i++) {
            $clientIds[] = Client::create([
                'name' => "Racer {$i}",
                'phone' => '0900000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ])->id;
        }

        $children = [];

        foreach ($clientIds as $clientId) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                // Child. The inherited PDO socket must not be shared.
                DB::purge('pgsql');
                DB::reconnect('pgsql');

                $code = 1;

                try {
                    app(BookingService::class)->hold(
                        branch: $branch->fresh(),
                        treatment: $treatment->fresh(),
                        client: Client::find($clientId),
                        startsAt: $start,
                        // RULE 11: laser work is refused without one, so every
                        // child must carry it or they all lose for the wrong reason.
                        consent: ['national_id' => '1234567890123', 'date_of_birth' => '1990-01-01'],
                    );
                    $code = 0;
                } catch (\Throwable $e) {
                    $code = 1;
                }

                exit($code);
            }

            $children[] = $pid;
        }

        $succeeded = 0;

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);

            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                $succeeded++;
            }
        }

        $booked = Booking::where('treatment_id', $treatment->id)->blocking()->count();

        $this->assertSame(1, $booked, "Expected exactly 1 booking to survive, found {$booked}.");
        $this->assertSame(1, $succeeded, "Expected exactly 1 process to report success, {$succeeded} did.");
    }

    /** A cancelled booking hands its slot back. */
    public function test_cancelling_releases_the_slot_for_someone_else(): void
    {
        $this->seedClinic();

        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'laser-hair-removal')->first();
        $start = $this->laserSlot();

        $first = Client::create(['name' => 'First', 'phone' => '0833333333', 'is_member' => true]);
        $second = Client::create(['name' => 'Second', 'phone' => '0844444444', 'is_member' => true]);

        $service = app(BookingService::class);

        // RULE 11: laser hair removal cannot be booked without a consent record.
        $consent = ['national_id' => '1234567890123', 'date_of_birth' => '1990-01-01'];

        $booking = $service->hold($branch, $treatment, $first, $start, consent: $consent);
        $service->cancel($booking);

        // Must not throw: the laser suite is free again.
        $replacement = $service->hold($branch, $treatment, $second, $start, consent: $consent);

        $this->assertSame(Booking::CONFIRMED, $replacement->status);
        $this->assertSame(1, Booking::blocking()->count());
    }
}
