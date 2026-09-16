<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\BranchRequest;
use App\Models\Booking;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Branches: the trading window everything else is measured against.
 *
 * Nothing here deletes. A branch owns rooms, therapists and every booking ever
 * taken at it; removing the row would take consent and payment history with it.
 * Deactivating stops it being offered and leaves the record intact.
 */
class BranchController extends Controller
{
    public function index(): View
    {
        return view('staff.branches.index', [
            'branches' => Branch::withCount(['rooms', 'therapists'])->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('staff.branches.form', ['branch' => new Branch([
            'timezone' => 'Asia/Bangkok',
            'opens_at' => '10:00',
            'closes_at' => '20:00',
            'open_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'active' => true,
        ])]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $branch = Branch::create($request->validated());

        return redirect()
            ->route('staff.branches.index')
            ->with('status', "{$branch->name} added. It needs rooms and therapists before it can take bookings.");
    }

    public function edit(Branch $branch): View
    {
        return view('staff.branches.form', ['branch' => $branch]);
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $branch->update($request->validated());

        return redirect()->route('staff.branches.index')->with('status', "{$branch->name} updated.");
    }

    /**
     * Deactivate or reactivate. The upcoming-booking count is reported rather
     * than blocked on: those appointments stand, they simply stop being offered
     * to new clients, and reception may well be deactivating the branch because
     * of them.
     */
    public function toggle(Branch $branch): RedirectResponse
    {
        $branch->update(['active' => ! $branch->active]);

        if ($branch->active) {
            return redirect()->route('staff.branches.index')->with('status', "{$branch->name} is taking bookings again.");
        }

        $upcoming = Booking::where('branch_id', $branch->id)
            ->blocking()
            ->where('starts_at', '>', now())
            ->count();

        return redirect()->route('staff.branches.index')->with(
            'status',
            "{$branch->name} is no longer offered."
                .($upcoming > 0 ? " {$upcoming} booking(s) already taken there still stand." : '')
        );
    }
}
