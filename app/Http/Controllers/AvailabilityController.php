<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Treatment;
use App\Services\Availability\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'treatment_id' => ['required', 'integer', 'exists:treatments,id'],
            'date' => ['required', 'date'],
            // "Some of them will only see their own therapist."
            'therapist_id' => ['nullable', 'integer', 'exists:therapists,id'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);
        $treatment = Treatment::findOrFail($data['treatment_id']);

        $slots = $this->availability->day(
            $branch,
            $treatment,
            CarbonImmutable::parse($data['date'], $branch->timezone),
            $data['therapist_id'] ?? null,
        );

        return response()->json([
            'branch_id' => $branch->id,
            'treatment_id' => $treatment->id,
            'date' => $data['date'],
            'duration_minutes' => $treatment->duration_minutes,
            'slots' => $slots,
        ]);
    }
}
