@extends('layouts.app')
@section('title', 'Treatments — Lumina')

@section('content')
<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Treatments</h1>
        <p class="mt-1 text-sm text-stone-500">The menu, its durations, and which ones need a signed consent record.</p>
    </div>
    <a href="{{ route('staff.treatments.create') }}" class="btn-primary">+ Add treatment</a>
</div>

<div class="card mt-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-stone-200">
            <thead class="bg-stone-50">
                <tr>
                    <th class="th">Treatment</th>
                    <th class="th">Duration</th>
                    <th class="th">Price</th>
                    <th class="th">Consent</th>
                    <th class="th">Rooms</th>
                    <th class="th">Therapists</th>
                    <th class="th">Status</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @forelse ($treatments as $treatment)
                <tr class="{{ $treatment->active ? '' : 'bg-stone-50/70 text-stone-400' }}">
                    <td class="td">
                        <div class="font-semibold text-stone-900">{{ $treatment->name }}</div>
                        <div class="text-xs text-stone-400">{{ $treatment->slug }}</div>
                    </td>
                    <td class="td tabular-nums">{{ $treatment->duration_minutes }} min</td>
                    <td class="td tabular-nums">{{ number_format($treatment->price_minor_units / 100, 2) }} THB</td>
                    <td class="td">
                        @if ($treatment->requires_consent)
                            <span class="badge bg-amber-50 text-amber-800" title="RULE 11: cannot be booked without an ID number and date of birth">Required</span>
                        @else
                            <span class="text-xs text-stone-400">—</span>
                        @endif
                    </td>
                    <td class="td tabular-nums">{{ $treatment->rooms_count }}</td>
                    <td class="td tabular-nums">{{ $treatment->therapists_count }}</td>
                    <td class="td">
                        <span class="badge {{ $treatment->active ? 'bg-teal-50 text-teal-700' : 'bg-stone-200 text-stone-600' }}">
                            {{ $treatment->active ? 'Bookable' : 'Not offered' }}
                        </span>
                        @if ($treatment->active && ($treatment->rooms_count === 0 || $treatment->therapists_count === 0))
                            <span class="badge mt-1 block w-fit bg-amber-50 text-amber-700">Unbookable — no room or staff</span>
                        @endif
                    </td>
                    <td class="td text-right whitespace-nowrap">
                        <a href="{{ route('staff.treatments.edit', $treatment) }}" class="btn-quiet">Edit</a>
                        <form method="POST" action="{{ route('staff.treatments.toggle', $treatment) }}" class="inline"
                              onsubmit="return confirm('{{ $treatment->active ? 'Stop offering ' . addslashes($treatment->name) . '?' : 'Offer ' . addslashes($treatment->name) . ' again?' }}')">
                            @csrf @method('PATCH')
                            <button class="btn-quiet {{ $treatment->active ? 'hover:text-rose-700' : 'hover:text-teal-700' }}">
                                {{ $treatment->active ? 'Deactivate' : 'Reactivate' }}
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="td text-center text-stone-500">No treatments yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
