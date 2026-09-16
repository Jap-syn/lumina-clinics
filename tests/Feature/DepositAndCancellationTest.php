<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Treatment;
use App\Services\Availability\AvailabilityService;
use App\Services\Booking\BookingService;
use App\Services\Booking\HoldSweeper;
use App\Services\Payments\DepositService;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositAndCancellationTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    private function futureSlot(int $hour = 14): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Bangkok')->addWeek()->setTime($hour, 0);
    }

    private function book(bool $member = false, ?CarbonImmutable $at = null): Booking
    {
        $this->seedClinic();

        return app(BookingService::class)->hold(
            Branch::first(),
            Treatment::where('slug', 'signature-facial')->first(),
            Client::create([
                'name' => 'Test',
                'phone' => '09'.random_int(10000000, 99999999),
                'is_member' => $member,
            ]),
            $at ?? $this->futureSlot(),
        );
    }

    /** RULE 7: a non-member holds the slot but is not confirmed until paid. */
    public function test_a_non_member_booking_starts_as_a_hold(): void
    {
        $booking = $this->book();

        $this->assertSame(Booking::PENDING_PAYMENT, $booking->status);
        $this->assertTrue($booking->deposit_required);
        $this->assertSame(30000, $booking->deposit_minor_units);
        $this->assertNotNull($booking->hold_expires_at);
    }

    /** RULE 7: "Members don't pay it." */
    public function test_a_member_is_confirmed_without_a_deposit(): void
    {
        $booking = $this->book(member: true);

        $this->assertSame(Booking::CONFIRMED, $booking->status);
        $this->assertFalse($booking->deposit_required);
        $this->assertSame(0, $booking->deposit_minor_units);
        $this->assertNull($booking->hold_expires_at);
    }

    /**
     * RULE 9: "Sometimes the page hangs and people pay twice."
     *
     * The same idempotency key twice charges once.
     */
    public function test_retrying_a_payment_with_the_same_key_charges_once(): void
    {
        $booking = $this->book();
        $deposits = app(DepositService::class);

        $first = $deposits->pay($booking, 'key-abc-123');
        $second = $deposits->pay($booking->fresh(), 'key-abc-123');

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed'], 'The second call must replay, not charge.');

        $this->assertSame(1, $this->gateway->chargeCount(), 'The gateway must be called exactly once.');
        $this->assertSame(1, Payment::count());
        $this->assertSame(Booking::CONFIRMED, $booking->fresh()->status);
    }

    /**
     * Two tabs, two different keys, one booking. Still one charge - this is the
     * case a naive idempotency key alone would miss.
     */
    public function test_two_different_keys_on_one_booking_still_charge_once(): void
    {
        $booking = $this->book();
        $deposits = app(DepositService::class);

        $deposits->pay($booking, 'key-tab-one');
        $result = $deposits->pay($booking->fresh(), 'key-tab-two');

        $this->assertTrue($result['replayed']);
        $this->assertSame(1, $this->gateway->chargeCount());
        $this->assertSame(1, Payment::where('status', Payment::SUCCEEDED)->count());
    }

    /** A gateway failure leaves no payment behind and the hold still stands. */
    public function test_a_failed_charge_rolls_back_and_can_be_retried(): void
    {
        $booking = $this->book();
        $deposits = app(DepositService::class);

        $this->gateway->failNext = true;

        try {
            $deposits->pay($booking, 'key-fails');
            $this->fail('The gateway failure should have propagated.');
        } catch (\App\Services\Payments\PaymentFailedException) {
            // expected
        }

        $this->assertSame(0, Payment::count(), 'A failed charge must not leave a payment row.');
        $this->assertSame(Booking::PENDING_PAYMENT, $booking->fresh()->status);

        // The client tries again and it works.
        $retry = $deposits->pay($booking->fresh(), 'key-works');

        $this->assertFalse($retry['replayed']);
        $this->assertSame(Booking::CONFIRMED, $booking->fresh()->status);
    }

    /**
     * RULE 8: holds expire with no scheduled job.
     *
     * Nothing runs in the background. The sweep happens on the next read.
     */
    public function test_an_expired_hold_releases_the_slot_without_a_scheduled_job(): void
    {
        $slot = $this->futureSlot();
        $booking = $this->book(at: $slot);

        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'signature-facial')->first();

        // Only one facial room, so the hold is the only thing blocking.
        $branch->rooms()->where('name', 'Treatment Room 2')->delete();

        $before = collect(app(AvailabilityService::class)->day($branch, $treatment, $slot))
            ->pluck('starts_at')
            ->map(fn ($t) => CarbonImmutable::parse($t)->setTimezone('Asia/Bangkok')->format('H:i'));

        $this->assertNotContains('14:00', $before, 'The live hold blocks the slot.');

        // Time passes. No queue worker, no cron - just a later request.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(
            (int) config('lumina.hold_minutes') + 1
        ));

        $after = collect(app(AvailabilityService::class)->day($branch, $treatment, $slot))
            ->pluck('starts_at')
            ->map(fn ($t) => CarbonImmutable::parse($t)->setTimezone('Asia/Bangkok')->format('H:i'));

        $this->assertContains('14:00', $after, 'The expired hold must release the slot.');
        $this->assertSame(Booking::EXPIRED, $booking->fresh()->status);

        CarbonImmutable::setTestNow();
    }

    /** A paid booking is never swept, however long it sits. */
    public function test_a_confirmed_booking_is_never_swept(): void
    {
        $booking = $this->book();

        app(DepositService::class)->pay($booking, 'key-paid');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(2));

        app(HoldSweeper::class)->sweep();

        $this->assertSame(Booking::CONFIRMED, $booking->fresh()->status);

        CarbonImmutable::setTestNow();
    }

    /** RULE 10: cancelling more than 24 hours ahead refunds the deposit. */
    public function test_cancelling_early_refunds_the_deposit(): void
    {
        $booking = $this->book(at: CarbonImmutable::now('Asia/Bangkok')->addDays(5)->setTime(14, 0));

        app(DepositService::class)->pay($booking, 'key-early');

        $cancelled = app(BookingService::class)->cancel($booking->fresh());
        $refund = app(DepositService::class)->refundIfDue($cancelled);

        $this->assertSame(Booking::CANCELLED, $cancelled->status);
        $this->assertFalse($cancelled->deposit_forfeited);
        $this->assertNotNull($refund);
        $this->assertSame(Payment::REFUNDED, $refund->status);
        $this->assertCount(1, $this->gateway->refunds);
    }

    /** RULE 10: inside 24 hours the deposit is kept. */
    public function test_cancelling_late_forfeits_the_deposit(): void
    {
        $slot = CarbonImmutable::now('Asia/Bangkok')->addDays(5)->setTime(14, 0);
        $booking = $this->book(at: $slot);

        app(DepositService::class)->pay($booking, 'key-late');

        // Move to 12 hours before the appointment.
        CarbonImmutable::setTestNow($slot->subHours(12));

        $cancelled = app(BookingService::class)->cancel($booking->fresh());
        $refund = app(DepositService::class)->refundIfDue($cancelled);

        $this->assertTrue($cancelled->deposit_forfeited);
        $this->assertNull($refund, 'No refund is due inside the 24 hour window.');
        $this->assertCount(0, $this->gateway->refunds);

        CarbonImmutable::setTestNow();
    }

    /** Exactly on the boundary is still free - the client gets the benefit. */
    public function test_cancelling_exactly_24_hours_ahead_is_free(): void
    {
        $slot = CarbonImmutable::now('Asia/Bangkok')->addDays(5)->setTime(14, 0);
        $booking = $this->book(at: $slot);

        app(DepositService::class)->pay($booking, 'key-boundary');

        CarbonImmutable::setTestNow($slot->subHours(24));

        $cancelled = app(BookingService::class)->cancel($booking->fresh());

        $this->assertFalse($cancelled->deposit_forfeited);

        CarbonImmutable::setTestNow();
    }
}
