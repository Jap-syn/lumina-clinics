<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Consent;
use App\Models\Treatment;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->seedClinic();
    }

    private function slot(): string
    {
        return CarbonImmutable::now('Asia/Bangkok')->addWeek()->setTime(14, 0)->toIso8601String();
    }

    public function test_availability_lists_slots_for_a_treatment(): void
    {
        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'signature-facial')->first();

        $response = $this->getJson('/api/availability?'.http_build_query([
            'branch_id' => $branch->id,
            'treatment_id' => $treatment->id,
            'date' => CarbonImmutable::now('Asia/Bangkok')->addWeek()->toDateString(),
        ]));

        $response->assertOk()
            ->assertJsonPath('duration_minutes', 60)
            ->assertJsonStructure(['slots' => [['starts_at', 'ends_at', 'therapist_ids', 'rooms_free']]]);
    }

    public function test_booking_then_paying_confirms_the_appointment(): void
    {
        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'signature-facial')->first();

        $created = $this->postJson('/api/bookings', [
            'branch_id' => $branch->id,
            'treatment_id' => $treatment->id,
            'starts_at' => $this->slot(),
            'client' => ['name' => 'Suda', 'phone' => '0812345678'],
        ])->assertCreated()->json();

        $this->assertSame(Booking::PENDING_PAYMENT, $created['status']);
        $this->assertTrue($created['deposit']['required']);

        $paid = $this->withHeader('Idempotency-Key', 'checkout-1')
            ->postJson("/api/bookings/{$created['reference']}/deposit")
            ->assertOk()
            ->json();

        $this->assertTrue($paid['charged']);
        $this->assertSame(Booking::CONFIRMED, $paid['booking']['status']);
    }

    /** The double-pay fix, end to end over HTTP. */
    public function test_replaying_the_deposit_request_does_not_charge_twice(): void
    {
        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'express-facial')->first();

        $reference = $this->postJson('/api/bookings', [
            'branch_id' => $branch->id,
            'treatment_id' => $treatment->id,
            'starts_at' => $this->slot(),
            'client' => ['name' => 'Anan', 'phone' => '0823456789'],
        ])->json('reference');

        foreach (range(1, 4) as $_) {
            $this->withHeader('Idempotency-Key', 'checkout-same')
                ->postJson("/api/bookings/{$reference}/deposit")
                ->assertOk();
        }

        $this->assertSame(1, $this->gateway->chargeCount());
        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_the_deposit_endpoint_requires_an_idempotency_key(): void
    {
        $reference = $this->postJson('/api/bookings', [
            'branch_id' => Branch::first()->id,
            'treatment_id' => Treatment::where('slug', 'express-facial')->first()->id,
            'starts_at' => $this->slot(),
            'client' => ['name' => 'Ploy', 'phone' => '0834567890'],
        ])->json('reference');

        $this->postJson("/api/bookings/{$reference}/deposit")->assertStatus(400);
    }

    /** RULE 11: laser work cannot be booked without the consent record. */
    public function test_a_laser_booking_without_consent_is_refused(): void
    {
        $this->postJson('/api/bookings', [
            'branch_id' => Branch::first()->id,
            'treatment_id' => Treatment::where('slug', 'laser-hair-removal')->first()->id,
            'starts_at' => $this->slot(),
            'client' => ['name' => 'Kan', 'phone' => '0845678901'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'consent_required');
    }

    /** The ID number is stored, but never in plain text. */
    public function test_consent_identity_data_is_encrypted_at_rest(): void
    {
        $reference = $this->postJson('/api/bookings', [
            'branch_id' => Branch::first()->id,
            'treatment_id' => Treatment::where('slug', 'laser-hair-removal')->first()->id,
            'starts_at' => $this->slot(),
            'client' => ['name' => 'Kan', 'phone' => '0845678901'],
            'consent' => ['national_id' => '1234567890123', 'date_of_birth' => '1990-04-01'],
        ])->assertCreated()->json('reference');

        $booking = Booking::where('reference', $reference)->firstOrFail();
        $raw = DB::table('consents')->where('booking_id', $booking->id)->value('national_id');

        $this->assertStringNotContainsString('1234567890123', $raw, 'The ID number must not be readable in the table.');
        $this->assertSame('1234567890123', Consent::where('booking_id', $booking->id)->first()->national_id);
    }

    public function test_the_same_client_cannot_hold_two_overlapping_bookings(): void
    {
        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'signature-facial')->first();

        $payload = [
            'branch_id' => $branch->id,
            'treatment_id' => $treatment->id,
            'starts_at' => $this->slot(),
            'client' => ['name' => 'Twice', 'phone' => '0856789012'],
        ];

        $this->postJson('/api/bookings', $payload)->assertCreated();
        $this->postJson('/api/bookings', $payload)->assertStatus(422);

        $this->assertSame(1, Booking::blocking()->count());
    }

    public function test_the_reception_diary_is_closed_without_a_token(): void
    {
        $this->getJson('/api/staff/diary?branch_id=1&date=2026-10-01')->assertStatus(401);
    }

    /** Reception can still take a phone booking, and confirm it at the desk. */
    public function test_reception_can_book_on_behalf_of_a_caller(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.config('lumina.staff_token'))
            ->postJson('/api/staff/bookings', [
                'branch_id' => Branch::first()->id,
                'treatment_id' => Treatment::where('slug', 'signature-facial')->first()->id,
                'starts_at' => $this->slot(),
                'client' => ['name' => 'Called In', 'phone' => '0867890123'],
                'deposit_taken' => true,
            ])->assertCreated();

        $this->assertSame(Booking::CONFIRMED, $response->json('status'));
        $this->assertSame('reception', Booking::first()->created_via);
    }

    public function test_a_client_can_cancel_and_see_the_outcome(): void
    {
        $reference = $this->postJson('/api/bookings', [
            'branch_id' => Branch::first()->id,
            'treatment_id' => Treatment::where('slug', 'express-facial')->first()->id,
            'starts_at' => $this->slot(),
            'client' => ['name' => 'Mai', 'phone' => '0878901234'],
        ])->json('reference');

        $this->withHeader('Idempotency-Key', 'k1')->postJson("/api/bookings/{$reference}/deposit")->assertOk();

        $this->postJson("/api/bookings/{$reference}/cancel")
            ->assertOk()
            ->assertJsonPath('deposit_refunded', true)
            ->assertJsonPath('booking.status', Booking::CANCELLED);
    }
}
