@extends('layouts.app')
@section('title', ($treatment->exists ? 'Edit' : 'New') . ' treatment — Lumina')

@section('content')
<div class="mx-auto max-w-2xl">
    <a href="{{ route('staff.treatments.index') }}" class="btn-quiet mb-4">← Back to treatments</a>

    <div class="card p-8">
        <h1 class="text-xl font-semibold tracking-tight">{{ $treatment->exists ? 'Edit '.$treatment->name : 'New treatment' }}</h1>
        <p class="mt-1 text-sm text-stone-500"><span class="text-rose-600">*</span> marks a required field.</p>

        <form method="POST" action="{{ $treatment->exists ? route('staff.treatments.update', $treatment) : route('staff.treatments.store') }}" class="mt-6 space-y-6">
            @csrf
            @if ($treatment->exists) @method('PUT') @endif

            <div class="grid gap-6 sm:grid-cols-2">
                <x-field name="name" label="Treatment name" :required="true">
                    <input id="name" name="name" required class="input @error('name') input-error @enderror"
                           value="{{ old('name', $treatment->name) }}" placeholder="Signature Facial">
                </x-field>

                <x-field name="slug" label="Slug" :required="true">
                    <input id="slug" name="slug" required class="input @error('slug') input-error @enderror"
                           value="{{ old('slug', $treatment->slug) }}" placeholder="signature-facial">
                </x-field>

                <x-field name="price_baht" label="Price (THB)" :required="true" hint="Stored in satang as a whole number — money never touches a float.">
                    <input id="price_baht" name="price_baht" type="number" min="0" step="0.01" required
                           class="input @error('price_baht') input-error @enderror"
                           value="{{ old('price_baht', $treatment->exists ? number_format($treatment->price_minor_units / 100, 2, '.', '') : '') }}">
                </x-field>

                <x-field name="duration_minutes" label="Duration" :required="true" hint="30, 60 or 90 only, so starts stay on the half-hour grid.">
                    <div class="mt-2 flex gap-2">
                        @foreach ([30, 60, 90] as $minutes)
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" name="duration_minutes" value="{{ $minutes }}" class="peer sr-only" required
                                       @checked((int) old('duration_minutes', $treatment->duration_minutes) === $minutes)>
                                <span class="block rounded-lg border border-stone-300 py-2 text-center text-sm font-medium text-stone-600 transition peer-checked:border-teal-600 peer-checked:bg-teal-600 peer-checked:text-white hover:border-stone-400">
                                    {{ $minutes }} min
                                </span>
                            </label>
                        @endforeach
                    </div>
                </x-field>
            </div>

            <label class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <input type="checkbox" name="requires_consent" value="1" class="mt-0.5 h-4 w-4 rounded border-stone-300 text-amber-700"
                       @checked(old('requires_consent', $treatment->requires_consent))>
                <span class="text-sm">
                    <span class="font-medium text-amber-900">Needs a signed consent record</span>
                    <span class="block text-amber-800">
                        No booking can be taken without an ID number and date of birth. That data is
                        encrypted at rest, and we still recommend Lumina store as little of it as possible —
                        see the proposal.
                    </span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded-xl bg-stone-50 p-4">
                <input type="checkbox" name="active" value="1" class="mt-0.5 h-4 w-4 rounded border-stone-300 text-teal-700"
                       @checked(old('active', $treatment->exists ? $treatment->active : true))>
                <span class="text-sm">
                    <span class="font-medium">On the menu</span>
                    <span class="block text-stone-500">Unticked, it stops being offered. Bookings already taken for it stand.</span>
                </span>
            </label>

            <div class="flex gap-3 pt-2">
                <button class="btn-primary">{{ $treatment->exists ? 'Save changes' : 'Add treatment' }}</button>
                <a href="{{ route('staff.treatments.index') }}" class="btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
