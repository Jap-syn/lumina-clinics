<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingConflictException;
use App\Exceptions\SlotUnavailableException;
use App\Http\Requests\StoreBookingRequest;
use App\Support\PhoneNumber;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Treatment;
use App\Services\Booking\BookingService;
use App\Services\Payments\DepositService;
use App\Services\Payments\PaymentFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly DepositService $deposits,
    ) {}

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $branch = Branch::findOrFail($data['branch_id']);
        $treatment = Treatment::findOrFail($data['treatment_id']);

        // Returning clients are matched on phone, which is how the receptionists
        // already identify people. Membership therefore follows the client, and
        // a member is never asked for a deposit.
        // Matched on the canonical number, not on whatever was typed - see
        // App\Support\PhoneNumber. Looking up the raw string would miss the
        // existing row and then collide with the unique index.
        $client = Client::firstOrCreate(
            ['phone' => PhoneNumber::normalise($data['client']['phone'])],
            [
                'name' => $data['client']['name'],
                'email' => $data['client']['email'] ?? null,
            ],
        );

        $consent = null;

        if (! empty($data['consent']['national_id']) && ! empty($data['consent']['date_of_birth'])) {
            $consent = [
                'national_id' => $data['consent']['national_id'],
                'date_of_birth' => $data['consent']['date_of_birth'],
            ];
        }

        try {
            $booking = $this->bookings->hold(
                branch: $branch,
                treatment: $treatment,
                client: $client,
                startsAt: CarbonImmutable::parse($data['starts_at'])->setTimezone($branch->timezone),
                requestedTherapistId: $data['therapist_id'] ?? null,
                consent: $consent,
                via: 'online',
            );
        } catch (SlotUnavailableException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'reason' => $e->reason,
            ], 422);
        } catch (BookingConflictException $e) {
            // 409: the request was valid, it just lost the race.
            return response()->json(['error' => $e->getMessage(), 'reason' => 'conflict'], 409);
        }

        return response()->json($this->present($booking), 201);
    }

    public function show(string $reference): JsonResponse
    {
        $booking = Booking::where('reference', $reference)->firstOrFail();

        return response()->json($this->present($booking));
    }

    /**
     * Pay the deposit.
     *
     * Requires an Idempotency-Key header. Sending the same key twice returns the
     * first result and does not charge again - see DepositService.
     */
    public function pay(Request $request, string $reference): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (blank($key)) {
            return response()->json([
                'error' => 'An Idempotency-Key header is required so a retry cannot charge twice.',
            ], 400);
        }

        $booking = Booking::where('reference', $reference)->firstOrFail();

        try {
            $result = $this->deposits->pay($booking, $key);
        } catch (PaymentFailedException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'booking' => $this->present($result['booking']),
            'charged' => ! $result['replayed'],
            'payment_reference' => $result['payment']?->provider_reference,
        ]);
    }

    public function cancel(Request $request, string $reference): JsonResponse
    {
        $booking = Booking::where('reference', $reference)->firstOrFail();

        try {
            $cancelled = $this->bookings->cancel($booking, $request->input('reason', 'client_request'));
        } catch (SlotUnavailableException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        $refund = $this->deposits->refundIfDue($cancelled);

        return response()->json([
            'booking' => $this->present($cancelled),
            'deposit_refunded' => $refund !== null,
            'deposit_forfeited' => $cancelled->deposit_forfeited,
        ]);
    }

    private function present(Booking $booking): array
    {
        $booking->loadMissing(['treatment', 'therapist', 'room', 'branch', 'client']);

        return [
            'reference' => $booking->reference,
            'status' => $booking->status,
            'branch' => $booking->branch->name,
            'treatment' => $booking->treatment->name,
            'therapist' => $booking->therapist->name,
            'room' => $booking->room->name,
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'deposit' => [
                'required' => $booking->deposit_required,
                'amount_minor_units' => $booking->deposit_minor_units,
                'currency' => config('lumina.currency'),
                'paid' => $booking->deposit_paid_at !== null,
                'forfeited' => $booking->deposit_forfeited,
            ],
            'hold_expires_at' => $booking->hold_expires_at?->toIso8601String(),
            'free_cancellation_until' => $booking->starts_at
                ->copy()
                ->subHours((int) config('lumina.free_cancellation_hours'))
                ->toIso8601String(),
        ];
    }
}
