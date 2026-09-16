<?php

namespace App\Http\Controllers\Staff;

use App\Exceptions\BookingConflictException;
use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Treatment;
use App\Rules\E164Phone;
use App\Services\Availability\AvailabilityService;
use App\Services\Booking\BookingService;
use App\Services\Booking\HoldSweeper;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The reception diary as a page rather than a JSON endpoint.
 *
 * "The receptionists were in the room. They are worried the system replaces
 * them." This screen is the answer: phone and WhatsApp bookings still come in,
 * and the receptionist takes them here through exactly the same rules as the
 * website - same availability, same overlap constraints, same consent check.
 * The one thing she can do that a client cannot is confirm without a deposit,
 * because she is holding the cash.
 *
 * The JSON API in StaffDiaryController is unchanged and still serves the same
 * data; this controller exists so the diary can be used without a REST client.
 */
class DiaryController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly AvailabilityService $availability,
        private readonly HoldSweeper $sweeper,
    ) {}

    public function index(Request $request): View
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'date' => ['nullable', 'date'],
        ]);

        // RULE 8: every read of the diary is also a chance to release holds that
        // ran out, because nothing runs in the background on this hosting.
        $this->sweeper->sweep();

        $branch = isset($data['branch_id'])
            ? Branch::findOrFail($data['branch_id'])
            : Branch::where('active', true)->orderBy('name')->firstOrFail();

        $day = CarbonImmutable::parse($data['date'] ?? 'today', $branch->timezone);

        $bookings = Booking::query()
            ->with(['treatment', 'therapist', 'room', 'client'])
            ->where('branch_id', $branch->id)
            ->whereBetween('starts_at', [$day->startOfDay(), $day->endOfDay()])
            ->orderBy('starts_at')
            ->get();

        return view('staff.diary', [
            'branches' => Branch::orderBy('name')->get(),
            'branch' => $branch,
            'day' => $day,
            'bookings' => $bookings,
            'treatments' => Treatment::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'treatment_id' => ['required', 'integer', 'exists:treatments,id'],
            'therapist_id' => ['nullable', 'integer', 'exists:therapists,id'],
            'starts_at' => ['required', 'date'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:40', new E164Phone],
            'is_member' => ['boolean'],
            'deposit_taken' => ['boolean'],
            'national_id' => ['nullable', 'required_with:date_of_birth', 'string', 'min:6', 'max:40'],
            'date_of_birth' => ['nullable', 'required_with:national_id', 'date', 'before:today', 'after:1900-01-01'],
        ], [
            'national_id.required_with' => 'A consent record needs both the ID number and the date of birth.',
            'date_of_birth.required_with' => 'A consent record needs both the ID number and the date of birth.',
        ]);

        $branch = Branch::findOrFail($data['branch_id']);
        $treatment = Treatment::findOrFail($data['treatment_id']);

        $client = Client::firstOrCreate(
            ['phone' => PhoneNumber::normalise($data['phone'])],
            ['name' => $data['name'], 'is_member' => $request->boolean('is_member')],
        );

        try {
            $booking = $this->bookings->hold(
                branch: $branch,
                treatment: $treatment,
                client: $client,
                startsAt: CarbonImmutable::parse($data['starts_at'], $branch->timezone),
                requestedTherapistId: $data['therapist_id'] ?? null,
                consent: isset($data['national_id'])
                    ? ['national_id' => $data['national_id'], 'date_of_birth' => $data['date_of_birth']]
                    : null,
                via: 'reception',
            );
        } catch (SlotUnavailableException|BookingConflictException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($request->boolean('deposit_taken') && $booking->status === Booking::PENDING_PAYMENT) {
            $booking = $this->bookings->confirmDeposit($booking);
        }

        return redirect()
            ->route('staff.diary', ['branch_id' => $branch->id, 'date' => $booking->starts_at->setTimezone($branch->timezone)->toDateString()])
            ->with('status', "Booked {$client->name} - reference {$booking->reference}.");
    }

    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        try {
            $this->bookings->cancel($booking, 'reception_cancelled');
        } catch (SlotUnavailableException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Cancelled {$booking->reference}. The slot is free again.");
    }

    /** Slot times for the desk's own picker, same service the website uses. */
    public function slots(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'treatment_id' => ['required', 'integer', 'exists:treatments,id'],
            'date' => ['required', 'date'],
            'therapist_id' => ['nullable', 'integer', 'exists:therapists,id'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);

        return response()->json($this->availability->day(
            $branch,
            Treatment::findOrFail($data['treatment_id']),
            CarbonImmutable::parse($data['date'], $branch->timezone),
            $data['therapist_id'] ?? null,
        ));
    }
}
