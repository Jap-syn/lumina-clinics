@extends('layouts.app')
@section('title', ($therapist->exists ? 'Edit' : 'New') . ' therapist — Lumina')

@section('content')
<div class="mx-auto max-w-2xl">
    <a href="{{ route('staff.therapists.index') }}" class="btn-quiet mb-4">← Back to therapists</a>

    <div class="card p-8">
        <h1 class="text-xl font-semibold tracking-tight">{{ $therapist->exists ? 'Edit '.$therapist->name : 'New therapist' }}</h1>
        <p class="mt-1 text-sm text-stone-500"><span class="text-rose-600">*</span> marks a required field.</p>

        <form method="POST" action="{{ $therapist->exists ? route('staff.therapists.update', $therapist) : route('staff.therapists.store') }}" class="mt-6 space-y-6">
            @csrf
            @if ($therapist->exists) @method('PUT') @endif

            <div class="grid gap-6 sm:grid-cols-2">
                <x-field name="name" label="Name" :required="true">
                    <input id="name" name="name" required class="input @error('name') input-error @enderror"
                           value="{{ old('name', $therapist->name) }}" placeholder="Nok">
                </x-field>

                <x-field name="title" label="Title" hint="Shown to clients when they pick their own therapist.">
                    <input id="title" name="title" class="input @error('title') input-error @enderror"
                           value="{{ old('title', $therapist->title) }}" placeholder="Senior Facialist">
                </x-field>

                <x-field name="branch_id" label="Branch" :required="true" hint="A therapist works at one branch. She is only ever offered there.">
                    <select id="branch_id" name="branch_id" required class="input @error('branch_id') input-error @enderror">
                        <option value="">Choose a branch…</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) old('branch_id', $therapist->branch_id) === $branch->id)>
                                {{ $branch->name }}{{ $branch->active ? '' : ' (not offered)' }}
                            </option>
                        @endforeach
                    </select>
                </x-field>

                <x-field name="buffer_minutes" label="Her own turnaround (minutes)" :required="true"
                         hint="0 means back to back is fine for her. This is hers alone — the room still needs its own cleanup.">
                    <input id="buffer_minutes" name="buffer_minutes" type="number" min="0" max="120" step="5" required
                           class="input @error('buffer_minutes') input-error @enderror"
                           value="{{ old('buffer_minutes', $therapist->buffer_minutes ?? 15) }}">
                </x-field>
            </div>

            <x-field name="treatments" label="Trained on" :required="true" hint="She is never offered for anything not ticked here.">
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    @foreach ($treatments as $treatment)
                        @php($checked = in_array($treatment->id, old('treatments', $selected), false))
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-stone-200 px-3 py-2 transition hover:border-stone-300 has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50">
                            <input type="checkbox" name="treatments[]" value="{{ $treatment->id }}"
                                   class="h-4 w-4 rounded border-stone-300 text-teal-700" @checked($checked)>
                            <span class="text-sm">
                                <span class="font-medium">{{ $treatment->name }}</span>
                                <span class="block text-xs text-stone-500">
                                    {{ $treatment->duration_minutes }} min
                                    @if ($treatment->requires_consent) · consent required @endif
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </x-field>

            <label class="flex items-start gap-3 rounded-xl bg-stone-50 p-4">
                <input type="checkbox" name="active" value="1" class="mt-0.5 h-4 w-4 rounded border-stone-300 text-teal-700"
                       @checked(old('active', $therapist->exists ? $therapist->active : true))>
                <span class="text-sm">
                    <span class="font-medium">Currently working</span>
                    <span class="block text-stone-500">Unticked, she stops being offered. Her upcoming appointments stay in the diary until reassigned or cancelled.</span>
                </span>
            </label>

            <div class="flex gap-3 pt-2">
                <button class="btn-primary">{{ $therapist->exists ? 'Save changes' : 'Add therapist' }}</button>
                <a href="{{ route('staff.therapists.index') }}" class="btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
