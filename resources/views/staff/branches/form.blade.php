@extends('layouts.app')
@section('title', ($branch->exists ? 'Edit' : 'New') . ' branch — Lumina')

@section('content')
<div class="mx-auto max-w-2xl">
    <a href="{{ route('staff.branches.index') }}" class="btn-quiet mb-4">← Back to branches</a>

    <div class="card p-8">
        <h1 class="text-xl font-semibold tracking-tight">{{ $branch->exists ? 'Edit '.$branch->name : 'New branch' }}</h1>
        <p class="mt-1 text-sm text-stone-500"><span class="text-rose-600">*</span> marks a required field.</p>

        <form method="POST" action="{{ $branch->exists ? route('staff.branches.update', $branch) : route('staff.branches.store') }}" class="mt-6 space-y-6">
            @csrf
            @if ($branch->exists) @method('PUT') @endif

            <div class="grid gap-6 sm:grid-cols-2">
                <x-field name="name" label="Branch name" :required="true">
                    <input id="name" name="name" class="input @error('name') input-error @enderror"
                           value="{{ old('name', $branch->name) }}" placeholder="Lumina Sukhumvit" required>
                </x-field>

                <x-field name="slug" label="Slug" :required="true" hint="Lower case, letters, numbers and dashes. Used in links.">
                    <input id="slug" name="slug" class="input @error('slug') input-error @enderror"
                           value="{{ old('slug', $branch->slug) }}" placeholder="sukhumvit" required>
                </x-field>

                <x-field name="opens_at" label="Opens" :required="true">
                    <input id="opens_at" name="opens_at" type="time" step="60" required
                           class="input @error('opens_at') input-error @enderror"
                           value="{{ old('opens_at', substr((string) $branch->opens_at, 0, 5)) }}">
                </x-field>

                <x-field name="closes_at" label="Closes" :required="true" hint="A treatment must finish by this time; cleanup may run past it.">
                    <input id="closes_at" name="closes_at" type="time" step="60" required
                           class="input @error('closes_at') input-error @enderror"
                           value="{{ old('closes_at', substr((string) $branch->closes_at, 0, 5)) }}">
                </x-field>

                <x-field name="timezone" label="Timezone" :required="true" hint="Phuket and Bangkok are the same today, but the column is per branch.">
                    <select id="timezone" name="timezone" class="input @error('timezone') input-error @enderror" required>
                        @foreach (['Asia/Bangkok', 'Asia/Singapore', 'Asia/Kuala_Lumpur', 'Asia/Yangon', 'UTC'] as $tz)
                            <option value="{{ $tz }}" @selected(old('timezone', $branch->timezone) === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field name="phone" label="Branch phone" hint="Any format: 02 123 4567, 081 234 5678 or +66 81 234 5678.">
                    <input id="phone" name="phone" class="input @error('phone') input-error @enderror"
                           value="{{ old('phone', $branch->phone) }}" placeholder="02 123 4567">
                </x-field>
            </div>

            <x-field name="open_weekdays" label="Trading days" :required="true" hint="A day left unticked offers no slots at all, for anyone.">
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $iso => $label)
                        @php($checked = in_array($iso, old('open_weekdays', $branch->open_weekdays ?? []), false))
                        <label class="cursor-pointer">
                            <input type="checkbox" name="open_weekdays[]" value="{{ $iso }}" class="peer sr-only" @checked($checked)>
                            <span class="block rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-600 transition peer-checked:border-teal-600 peer-checked:bg-teal-600 peer-checked:text-white hover:border-stone-400">
                                {{ $label }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </x-field>

            <label class="flex items-start gap-3 rounded-xl bg-stone-50 p-4">
                <input type="checkbox" name="active" value="1" class="mt-0.5 h-4 w-4 rounded border-stone-300 text-teal-700"
                       @checked(old('active', $branch->exists ? $branch->active : true))>
                <span class="text-sm">
                    <span class="font-medium">Taking bookings</span>
                    <span class="block text-stone-500">Unticked, the branch stops being offered. Appointments already in its diary still stand.</span>
                </span>
            </label>

            <div class="flex gap-3 pt-2">
                <button class="btn-primary">{{ $branch->exists ? 'Save changes' : 'Add branch' }}</button>
                <a href="{{ route('staff.branches.index') }}" class="btn-ghost">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
