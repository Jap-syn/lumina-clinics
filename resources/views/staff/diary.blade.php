@extends('layouts.app')
@section('title', 'Diary — Lumina')

@section('content')
@php
    $tz = $branch->timezone;
    $statusStyles = [
        'confirmed' => 'bg-teal-50 text-teal-700',
        'pending_payment' => 'bg-amber-50 text-amber-800',
        'cancelled' => 'bg-stone-200 text-stone-600',
        'expired' => 'bg-stone-200 text-stone-600',
        'completed' => 'bg-stone-100 text-stone-600',
    ];
@endphp

<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Diary</h1>
        <p class="mt-1 text-sm text-stone-500">
            Six paper diaries as one live view. Phone and WhatsApp bookings go in here, through exactly the same rules as the website.
        </p>
    </div>
    <button type="button" onclick="document.getElementById('take-booking').classList.toggle('hidden')" class="btn-primary">
        + Take a booking
    </button>
</div>

{{-- Branch and day are chosen with controls, not by editing the address bar. --}}
<form method="GET" action="{{ route('staff.diary') }}" class="card mt-6 flex flex-wrap items-end gap-4 p-4">
    <div class="min-w-48 flex-1">
        <label for="branch_id" class="block text-xs font-semibold uppercase tracking-wide text-stone-500">Branch</label>
        <select id="branch_id" name="branch_id" class="input" onchange="this.form.submit()">
            @foreach ($branches as $option)
                <option value="{{ $option->id }}" @selected($option->id === $branch->id)>
                    {{ $option->name }}{{ $option->active ? '' : ' (not offered)' }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="min-w-44">
        <label for="date" class="block text-xs font-semibold uppercase tracking-wide text-stone-500">Day</label>
        <input id="date" name="date" type="date" value="{{ $day->toDateString() }}" class="input" onchange="this.form.submit()">
    </div>
    <div class="flex gap-2 pb-0.5">
        <a href="{{ route('staff.diary', ['branch_id' => $branch->id, 'date' => $day->subDay()->toDateString()]) }}" class="btn-ghost">← Prev</a>
        <a href="{{ route('staff.diary', ['branch_id' => $branch->id, 'date' => now($tz)->toDateString()]) }}" class="btn-ghost">Today</a>
        <a href="{{ route('staff.diary', ['branch_id' => $branch->id, 'date' => $day->addDay()->toDateString()]) }}" class="btn-ghost">Next →</a>
    </div>
</form>

{{-- Reception's own booking form. Same service the public site calls. --}}
<div id="take-booking" class="{{ $errors->any() || old('name') ? '' : 'hidden' }} card mt-6 p-6">
    <h2 class="text-lg font-semibold">Take a booking at the desk</h2>
    <p class="mt-1 text-sm text-stone-500"><span class="text-rose-600">*</span> marks a required field. The same overlap, opening-hours and consent rules apply.</p>

    <form method="POST" action="{{ route('staff.diary.store') }}" class="mt-5 space-y-6">
        @csrf
        <input type="hidden" name="branch_id" value="{{ $branch->id }}">

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <x-field name="name" label="Client name" :required="true">
                <input id="name" name="name" required class="input @error('name') input-error @enderror" value="{{ old('name') }}">
            </x-field>

            <x-field name="phone" label="Phone" :required="true" hint="Any format. Stored as one canonical number so returning clients match.">
                <input id="phone" name="phone" required class="input @error('phone') input-error @enderror"
                       value="{{ old('phone') }}" placeholder="081 234 5678">
            </x-field>

            <x-field name="treatment_id" label="Treatment" :required="true">
                <select id="treatment_id" name="treatment_id" required class="input @error('treatment_id') input-error @enderror">
                    <option value="">Choose…</option>
                    @foreach ($treatments as $treatment)
                        <option value="{{ $treatment->id }}" data-consent="{{ $treatment->requires_consent ? '1' : '0' }}"
                                @selected((int) old('treatment_id') === $treatment->id)>
                            {{ $treatment->name }} · {{ $treatment->duration_minutes }} min
                        </option>
                    @endforeach
                </select>
            </x-field>

            <x-field name="starts_at" label="Start time" :required="true" hint="On the hour or the half hour, inside opening hours.">
                <input id="starts_at" name="starts_at" type="datetime-local" step="1800" required
                       class="input @error('starts_at') input-error @enderror"
                       value="{{ old('starts_at', $day->setTime(14, 0)->format('Y-m-d\TH:i')) }}">
            </x-field>

            <x-field name="therapist_id" label="Requested therapist" hint="Leave empty and the system picks whoever is free.">
                <select id="therapist_id" name="therapist_id" class="input">
                    <option value="">Anyone available</option>
                    @foreach ($branch->therapists()->where('active', true)->orderBy('name')->get() as $therapist)
                        <option value="{{ $therapist->id }}" @selected((int) old('therapist_id') === $therapist->id)>
                            {{ $therapist->name }}@if ($therapist->title) — {{ $therapist->title }}@endif
                        </option>
                    @endforeach
                </select>
            </x-field>
        </div>

        {{-- RULE 11. Shown only when the chosen treatment needs it. --}}
        <div id="consent-block" class="hidden rounded-xl border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-900">This treatment needs a signed consent record</p>
            <p class="mt-1 text-xs text-amber-800">Both fields, or neither. The ID number is encrypted at rest.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <x-field name="national_id" label="ID number" :required="true">
                    <input id="national_id" name="national_id" class="input @error('national_id') input-error @enderror" value="{{ old('national_id') }}">
                </x-field>
                <x-field name="date_of_birth" label="Date of birth" :required="true">
                    <input id="date_of_birth" name="date_of_birth" type="date" class="input @error('date_of_birth') input-error @enderror" value="{{ old('date_of_birth') }}">
                </x-field>
            </div>
        </div>

        <div class="flex flex-wrap gap-6">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_member" value="1" class="h-4 w-4 rounded border-stone-300 text-teal-700" @checked(old('is_member'))>
                Member — no deposit
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="deposit_taken" value="1" class="h-4 w-4 rounded border-stone-300 text-teal-700" @checked(old('deposit_taken'))>
                Deposit taken at the desk — confirm immediately
            </label>
        </div>

        <div class="flex gap-3">
            <button class="btn-primary">Book it</button>
            <button type="button" class="btn-ghost" onclick="document.getElementById('take-booking').classList.add('hidden')">Close</button>
        </div>
    </form>
</div>

<div class="card mt-6 overflow-hidden">
    <div class="flex items-center justify-between border-b border-stone-200 bg-stone-50 px-4 py-3">
        <h2 class="text-sm font-semibold">{{ $branch->name }} — {{ $day->format('l j F Y') }}</h2>
        <span class="text-xs text-stone-500">{{ $bookings->count() }} {{ Str::plural('booking', $bookings->count()) }}</span>
    </div>

    @forelse ($bookings as $booking)
        <div class="flex flex-wrap items-center gap-4 border-b border-stone-100 px-4 py-3 last:border-0">
            <div class="w-24 shrink-0 tabular-nums">
                <div class="text-base font-semibold">{{ $booking->starts_at->setTimezone($tz)->format('H:i') }}</div>
                <div class="text-xs text-stone-400">to {{ $booking->ends_at->setTimezone($tz)->format('H:i') }}</div>
            </div>
            <div class="min-w-48 flex-1">
                <div class="font-medium">{{ $booking->client->name }}
                    @if ($booking->client->is_member)<span class="badge ml-1 bg-violet-50 text-violet-700">Member</span>@endif
                </div>
                <div class="text-xs text-stone-500">{{ $booking->client->formattedPhone() }} · {{ $booking->reference }}</div>
            </div>
            <div class="min-w-40 flex-1 text-sm">
                <div>{{ $booking->treatment->name }}</div>
                <div class="text-xs text-stone-500">{{ $booking->therapist->name }} · {{ $booking->room->name }}</div>
            </div>
            <div class="text-xs text-stone-500">
                <div>Room free {{ $booking->room_release_at->setTimezone($tz)->format('H:i') }}</div>
                <div>{{ $booking->created_via === 'reception' ? 'Taken at the desk' : 'Booked online' }}</div>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge {{ $statusStyles[$booking->status] ?? 'bg-stone-100 text-stone-600' }}">
                    {{ str_replace('_', ' ', $booking->status) }}
                </span>
                @if ($booking->isCancellable())
                    <form method="POST" action="{{ route('staff.diary.cancel', $booking) }}"
                          onsubmit="return confirm('Cancel {{ $booking->reference }} for {{ addslashes($booking->client->name) }}?')">
                        @csrf
                        <button class="btn-quiet hover:text-rose-700">Cancel</button>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <p class="px-4 py-12 text-center text-sm text-stone-500">Nothing booked at {{ $branch->name }} on this day.</p>
    @endforelse
</div>

@push('scripts')
<script>
    // Consent fields appear only for the treatments that need them.
    const treatmentSelect = document.getElementById('treatment_id');
    const consentBlock = document.getElementById('consent-block');
    function syncConsent() {
        if (!treatmentSelect || !consentBlock) return;
        const option = treatmentSelect.selectedOptions[0];
        consentBlock.classList.toggle('hidden', !option || option.dataset.consent !== '1');
    }
    treatmentSelect?.addEventListener('change', syncConsent);
    syncConsent();
</script>
@endpush
@endsection
