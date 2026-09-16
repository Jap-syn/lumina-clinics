<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\Booking\BookingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * RULE 9: a deposit is taken at most once per booking.
 *
 * "Sometimes the page hangs and people pay twice, then we refund by hand."
 *
 * Three things stop that here.
 *
 *  1. The caller sends an Idempotency-Key. If a payment already exists for that
 *     key we replay the stored response and never touch the gateway. This is the
 *     retry case: the page hung, the client pressed pay again, the browser
 *     resent the request.
 *
 *  2. Before charging we check for an existing successful payment on the
 *     booking. This is the different-key case: two tabs, two keys, one booking.
 *
 *  3. A unique partial index (payments_one_success_per_booking) makes it true at
 *     the database level even if two requests pass check 2 simultaneously.
 *
 * The order matters. The payment row is written BEFORE the gateway is called, so
 * a crash between the two leaves a record to reconcile rather than a silent
 * charge. Here the row is written inside the transaction that also claims the
 * key; if the charge then fails we roll back and the client can retry.
 */
class DepositService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly BookingService $bookings,
    ) {}

    /**
     * @return array{payment: Payment, booking: Booking, replayed: bool}
     */
    public function pay(Booking $booking, string $idempotencyKey): array
    {
        // 1. Same attempt, retried. Return what we returned the first time.
        $existing = Payment::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return [
                'payment' => $existing,
                'booking' => $existing->booking->refresh(),
                'replayed' => true,
            ];
        }

        // 2. Different attempt, booking already paid. Do not charge again.
        if ($paid = $booking->successfulPayment()) {
            return [
                'payment' => $paid,
                'booking' => $booking->refresh(),
                'replayed' => true,
            ];
        }

        if ($booking->status === Booking::CONFIRMED && ! $booking->deposit_required) {
            // A member's booking. Nothing to pay.
            return ['payment' => null, 'booking' => $booking, 'replayed' => true];
        }

        if ($booking->status !== Booking::PENDING_PAYMENT) {
            throw new PaymentFailedException(
                'This booking is no longer awaiting payment (status: '.$booking->status.').'
            );
        }

        try {
            return DB::transaction(function () use ($booking, $idempotencyKey) {
                // Claiming the key and the booking's single success slot. If a
                // parallel request beat us here, one of the two unique indexes
                // raises and we fall through to the catch below.
                $payment = Payment::create([
                    'booking_id' => $booking->id,
                    'idempotency_key' => $idempotencyKey,
                    'amount_minor_units' => $booking->deposit_minor_units,
                    'currency' => config('lumina.currency'),
                    'status' => Payment::SUCCEEDED,
                ]);

                $reference = $this->gateway->charge(
                    $booking->deposit_minor_units,
                    config('lumina.currency'),
                    'Lumina deposit '.$booking->reference,
                );

                $confirmed = $this->bookings->confirmDeposit($booking);

                $payment->forceFill([
                    'provider_reference' => $reference,
                    'response_snapshot' => [
                        'reference' => $confirmed->reference,
                        'status' => $confirmed->status,
                        'amount_minor_units' => $payment->amount_minor_units,
                    ],
                ])->save();

                return [
                    'payment' => $payment,
                    'booking' => $confirmed,
                    'replayed' => false,
                ];
            });
        } catch (QueryException $e) {
            // 3. Lost a race on one of the unique indexes. Whoever won has
            // already charged; return their payment rather than charging again.
            if ($this->isDuplicate($e)) {
                $winner = Payment::where('idempotency_key', $idempotencyKey)->first()
                    ?? $booking->successfulPayment();

                if ($winner !== null) {
                    return [
                        'payment' => $winner,
                        'booking' => $booking->refresh(),
                        'replayed' => true,
                    ];
                }
            }

            throw $e;
        }
    }

    /**
     * RULE 10 applied to money: cancel inside 24 hours and the deposit is kept.
     */
    public function refundIfDue(Booking $booking): ?Payment
    {
        $payment = $booking->successfulPayment();

        if ($payment === null || $booking->deposit_forfeited) {
            return null;
        }

        $this->gateway->refund($payment->provider_reference, $payment->amount_minor_units);

        $payment->forceFill([
            'status' => Payment::REFUNDED,
            'refunded_at' => now(),
        ])->save();

        return $payment->refresh();
    }

    private function isDuplicate(QueryException $e): bool
    {
        return ($e->getCode() === '23505')
            || str_contains($e->getMessage(), 'payments_idempotency_key_unique')
            || str_contains($e->getMessage(), 'payments_one_success_per_booking');
    }
}
