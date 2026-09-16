<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingConflictException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Treatment;
use App\Services\Booking\BookingService;
use App\Rules\E164Phone;
use App\Services\Booking\HoldSweeper;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shared diary that replaces the paper one.
 *
 * "The receptionists were in the room. They are worried the system replaces them."
 *
 * This endpoint is the answer to that. Phone and WhatsApp bookings keep coming
 * in, and the receptionist takes them here: same availability logic, same
 * overlap rules, and she can confirm a booking without a deposit when the client
 * is paying at the desk. Six paper diaries become one live view that both
 * receptionists at a busy branch can read at once.
 */
class StaffDiaryController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly HoldSweeper $sweeper,
    ) {}

    /** Everything happening at a branch on a day, in order. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'date' => ['required', 'date'],
        ]);

        $this->sweeper->sweep();

        $branch = Branch::findOrFail($data['branch_id']);
        $day = CarbonImmutable::parse($data['date'], $branch->timezone);

        $bookings = Booking::query()
            ->with(['treatment', 'therapist', 'room', 'client'])
            ->where('branch_id', $branch->id)
            ->whereBetween('starts_at', [$day->startOfDay(), $day->endOfDay()])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Booking $b) => [
                'reference' => $b->reference,
                'status' => $b->status,
                'starts_at' => $b->starts_at->setTimezone($branch->timezone)->format('H:i'),
                'ends_at' => $b->ends_at->setTimezone($branch->timezone)->format('H:i'),
                'room_free_at' => $b->room_release_at->setTimezone($branch->timezone)->format('H:i'),
                'treatment' => $b->treatment->name,
                'therapist' => $b->therapist->name,
                'room' => $b->room->name,
                'client' => $b->client->name,
                'phone' => $b->client->formattedPhone(),
                'is_member' => $b->client->is_member,
                'deposit_paid' => $b->deposit_paid_at !== null,
                'created_via' => $b->created_via,
            ]);

        return response()->json([
            'branch' => $branch->name,
            'date' => $day->toDateString(),
            'bookings' => $bookings,
        ]);
    }

    /**
     * Take a booking on behalf of a client who phoned or sent a WhatsApp.
     *
     * The receptionist may confirm it immediately when the deposit is handled at
     * the desk, which is why deposit_taken exists. Everything else - overlap,
     * opening hours, qualifications - is the same code path as the website.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'treatment_id' => ['required', 'integer', 'exists:treatments,id'],
            'therapist_id' => ['nullable', 'integer', 'exists:therapists,id'],
            'starts_at' => ['required', 'date'],
            'client.name' => ['required', 'string', 'min:2', 'max:120'],
            'client.phone' => ['required', 'string', 'max:40', new E164Phone],
            'client.is_member' => ['nullable', 'boolean'],
            'deposit_taken' => ['nullable', 'boolean'],
            // RULE 11: both halves of a consent record, or neither.
            'consent.national_id' => ['nullable', 'required_with:consent.date_of_birth', 'string', 'min:6', 'max:40'],
            'consent.date_of_birth' => ['nullable', 'required_with:consent.national_id', 'date', 'before:today', 'after:1900-01-01'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);
        $treatment = Treatment::findOrFail($data['treatment_id']);

        $client = Client::firstOrCreate(
            ['phone' => PhoneNumber::normalise($data['client']['phone'])],
            ['name' => $data['client']['name'], 'is_member' => $data['client']['is_member'] ?? false],
        );

        $consent = ! empty($data['consent']['national_id'])
            ? [
                'national_id' => $data['consent']['national_id'],
                'date_of_birth' => $data['consent']['date_of_birth'],
            ]
            : null;

        try {
            $booking = $this->bookings->hold(
                branch: $branch,
                treatment: $treatment,
                client: $client,
                startsAt: CarbonImmutable::parse($data['starts_at'], $branch->timezone),
                requestedTherapistId: $data['therapist_id'] ?? null,
                consent: $consent,
                via: 'reception',
            );
        } catch (SlotUnavailableException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason' => $e->reason], 422);
        } catch (BookingConflictException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason' => 'conflict'], 409);
        }

        if (($data['deposit_taken'] ?? false) && $booking->status === Booking::PENDING_PAYMENT) {
            $booking = $this->bookings->confirmDeposit($booking);
        }

        return response()->json([
            'reference' => $booking->reference,
            'status' => $booking->status,
            'starts_at' => $booking->starts_at->setTimezone($branch->timezone)->toIso8601String(),
            'therapist' => $booking->therapist->name,
            'room' => $booking->room->name,
        ], 201);
    }

    public function cancel(Request $request, string $reference): JsonResponse
    {
        $booking = Booking::where('reference', $reference)->firstOrFail();

        try {
            $cancelled = $this->bookings->cancel($booking, $request->input('reason', 'reception_cancelled'));
        } catch (SlotUnavailableException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'reference' => $cancelled->reference,
            'status' => $cancelled->status,
            'deposit_forfeited' => $cancelled->deposit_forfeited,
        ]);
    }
}
