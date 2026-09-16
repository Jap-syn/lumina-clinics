@extends('layouts.app')
@section('title', 'Therapists — Lumina')

@section('content')
<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Therapists</h1>
        <p class="mt-1 text-sm text-stone-500">Who works where, what they are trained on, and how long each of them needs between clients.</p>
    </div>
    <a href="{{ route('staff.therapists.create') }}" class="btn-primary">+ Add therapist</a>
</div>

<div class="card mt-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-stone-200">
            <thead class="bg-stone-50">
                <tr>
                    <th class="th">Therapist</th>
                    <th class="th">Branch</th>
                    <th class="th">Turnaround</th>
                    <th class="th">Trained on</th>
                    <th class="th">Status</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @forelse ($therapists as $therapist)
                <tr class="{{ $therapist->active ? '' : 'bg-stone-50/70 text-stone-400' }}">
                    <td class="td">
                        <div class="font-semibold text-stone-900">{{ $therapist->name }}</div>
                        <div class="text-xs text-stone-400">{{ $therapist->title ?: 'Therapist' }}</div>
                    </td>
                    <td class="td">{{ $therapist->branch?->name ?? '—' }}</td>
                    <td class="td">
                        @if ($therapist->buffer_minutes === 0)
                            <span class="badge bg-teal-50 text-teal-700" title="Can take clients back to back, when a second room is free">Back to back</span>
                        @else
                            <span class="tabular-nums">{{ $therapist->buffer_minutes }} min</span>
                        @endif
                    </td>
                    <td class="td">
                        <div class="flex flex-wrap gap-1">
                            @forelse ($therapist->treatments as $treatment)
                                <span class="badge bg-stone-100 text-stone-600">{{ $treatment->name }}</span>
                            @empty
                                <span class="badge bg-amber-50 text-amber-700">None — never bookable</span>
                            @endforelse
                        </div>
                    </td>
                    <td class="td">
                        <span class="badge {{ $therapist->active ? 'bg-teal-50 text-teal-700' : 'bg-stone-200 text-stone-600' }}">
                            {{ $therapist->active ? 'Working' : 'Not offered' }}
                        </span>
                    </td>
                    <td class="td text-right whitespace-nowrap">
                        <a href="{{ route('staff.therapists.edit', $therapist) }}" class="btn-quiet">Edit</a>
                        <form method="POST" action="{{ route('staff.therapists.toggle', $therapist) }}" class="inline"
                              onsubmit="return confirm('{{ $therapist->active ? 'Stop offering ' . addslashes($therapist->name) . '? Upcoming bookings stay in the diary.' : 'Offer ' . addslashes($therapist->name) . ' again?' }}')">
                            @csrf @method('PATCH')
                            <button class="btn-quiet {{ $therapist->active ? 'hover:text-rose-700' : 'hover:text-teal-700' }}">
                                {{ $therapist->active ? 'Deactivate' : 'Reactivate' }}
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="td text-center text-stone-500">No therapists yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="mt-4 text-xs text-stone-500">
    Changing a turnaround never moves bookings already taken: each booking stores the buffer that applied when it was made.
</p>
@endsection
