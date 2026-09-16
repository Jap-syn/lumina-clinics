<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\TreatmentRequest;
use App\Models\Treatment;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The treatment menu: duration, price, and whether it needs a consent record.
 *
 * `requires_consent` is the switch behind RULE 11. Turning it on for a
 * treatment means no booking can be taken for it without an ID number and date
 * of birth, so it is presented as the deliberate choice it is - with the
 * standing recommendation that Lumina store as little of that as possible.
 */
class TreatmentController extends Controller
{
    public function index(): View
    {
        return view('staff.treatments.index', [
            'treatments' => Treatment::withCount(['rooms', 'therapists'])->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('staff.treatments.form', [
            'treatment' => new Treatment(['duration_minutes' => 60, 'requires_consent' => false, 'active' => true]),
        ]);
    }

    public function store(TreatmentRequest $request): RedirectResponse
    {
        $treatment = Treatment::create($this->attributes($request));

        return redirect()
            ->route('staff.treatments.index')
            ->with('status', "{$treatment->name} added. Assign it to a room and a therapist before it can be booked.");
    }

    public function edit(Treatment $treatment): View
    {
        return view('staff.treatments.form', ['treatment' => $treatment]);
    }

    public function update(TreatmentRequest $request, Treatment $treatment): RedirectResponse
    {
        $treatment->update($this->attributes($request));

        return redirect()->route('staff.treatments.index')->with('status', "{$treatment->name} updated.");
    }

    public function toggle(Treatment $treatment): RedirectResponse
    {
        $treatment->update(['active' => ! $treatment->active]);

        return redirect()->route('staff.treatments.index')->with(
            'status',
            $treatment->name.($treatment->active ? ' is bookable again.' : ' is no longer offered.')
        );
    }

    /** Baht in the form, satang in the column: money is never a float. */
    private function attributes(TreatmentRequest $request): array
    {
        $data = $request->validated();
        $data['price_minor_units'] = (int) round(((float) $data['price_baht']) * 100);
        unset($data['price_baht']);

        return $data;
    }
}
