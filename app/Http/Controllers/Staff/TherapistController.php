<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\TherapistRequest;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Therapist;
use App\Models\Treatment;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Therapists, their per-person turnaround, and what they are trained on.
 *
 * Two rules are configured from this screen. RULE 2: `buffer_minutes` is hers
 * alone and is independent of the room's cleanup - that separation is what lets
 * the senior facialist work back to back while the room she just left is still
 * being turned around. RULE 5: the treatment list decides what she is ever
 * offered for.
 *
 * Changing a buffer does not move bookings already taken: each booking stores
 * the buffer used at the time, so tomorrow's edit cannot silently reschedule
 * today's diary.
 */
class TherapistController extends Controller
{
    public function index(): View
    {
        return view('staff.therapists.index', [
            'therapists' => Therapist::with(['branch', 'treatments'])
                ->orderBy('branch_id')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('staff.therapists.form', [
            'therapist' => new Therapist(['buffer_minutes' => 15, 'active' => true]),
            'branches' => Branch::orderBy('name')->get(),
            'treatments' => Treatment::orderBy('name')->get(),
            'selected' => [],
        ]);
    }

    public function store(TherapistRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $therapist = Therapist::create(collect($data)->except('treatments')->all());
        $therapist->treatments()->sync($data['treatments']);

        return redirect()->route('staff.therapists.index')->with('status', "{$therapist->name} added.");
    }

    public function edit(Therapist $therapist): View
    {
        return view('staff.therapists.form', [
            'therapist' => $therapist,
            'branches' => Branch::orderBy('name')->get(),
            'treatments' => Treatment::orderBy('name')->get(),
            'selected' => $therapist->treatments->pluck('id')->all(),
        ]);
    }

    public function update(TherapistRequest $request, Therapist $therapist): RedirectResponse
    {
        $data = $request->validated();
        $therapist->update(collect($data)->except('treatments')->all());
        $therapist->treatments()->sync($data['treatments']);

        return redirect()->route('staff.therapists.index')->with('status', "{$therapist->name} updated.");
    }

    public function toggle(Therapist $therapist): RedirectResponse
    {
        $therapist->update(['active' => ! $therapist->active]);

        if ($therapist->active) {
            return redirect()->route('staff.therapists.index')->with('status', "{$therapist->name} is bookable again.");
        }

        $upcoming = Booking::where('therapist_id', $therapist->id)
            ->blocking()
            ->where('starts_at', '>', now())
            ->count();

        return redirect()->route('staff.therapists.index')->with(
            'status',
            "{$therapist->name} is no longer offered."
                .($upcoming > 0 ? " She still has {$upcoming} upcoming booking(s) - reassign or cancel them from the diary." : '')
        );
    }
}
