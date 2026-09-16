<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Treatment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogueController extends Controller
{
    public function branches(): JsonResponse
    {
        return response()->json(
            Branch::where('active', true)->orderBy('name')
                ->get(['id', 'name', 'slug', 'timezone', 'opens_at', 'closes_at', 'open_weekdays'])
        );
    }

    /** Treatments actually performable at a branch: a room and a therapist must exist. */
    public function treatments(Request $request): JsonResponse
    {
        $branchId = $request->integer('branch_id');

        $treatments = Treatment::query()
            ->where('active', true)
            ->when($branchId, function ($q) use ($branchId) {
                $q->whereHas('rooms', fn ($r) => $r->where('branch_id', $branchId))
                    ->whereHas('therapists', fn ($t) => $t->where('branch_id', $branchId));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'duration_minutes', 'price_minor_units', 'requires_consent']);

        return response()->json($treatments);
    }

    public function therapists(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'treatment_id' => ['nullable', 'integer', 'exists:treatments,id'],
        ]);

        $therapists = \App\Models\Therapist::query()
            ->where('branch_id', $data['branch_id'])
            ->where('active', true)
            ->when($data['treatment_id'] ?? null, fn ($q, $id) => $q->whereHas('treatments', fn ($t) => $t->where('treatments.id', $id)))
            ->orderBy('name')
            ->get(['id', 'name', 'title']);

        return response()->json($therapists);
    }
}
