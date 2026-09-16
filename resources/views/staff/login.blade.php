@extends('layouts.app')
@section('title', 'Reception sign in — Lumina')

@section('content')
<div class="mx-auto max-w-md">
    <div class="card p-8">
        <h1 class="text-xl font-semibold tracking-tight">Reception sign in</h1>
        <p class="mt-2 text-sm text-stone-500">
            One shared token for the whole reception area, for phase one. Per-person
            accounts with a branch role are the next thing to build — until then,
            treat this token as a door key, not a password.
        </p>

        <form method="POST" action="{{ route('staff.login.store') }}" class="mt-6 space-y-5">
            @csrf
            <x-field name="token" label="Staff token" :required="true" hint="Ask the branch manager. It is the same token the API accepts.">
                <input id="token" name="token" type="password" autocomplete="off" autofocus
                       class="input @error('token') input-error @enderror"
                       placeholder="••••••••••••">
            </x-field>

            <button type="submit" class="btn-primary w-full">Sign in</button>
        </form>
    </div>

    <p class="mt-6 text-center text-sm text-stone-500">
        Booking as a client? <a href="{{ route('book') }}" class="font-semibold text-teal-700 hover:underline">Go to the booking page</a>
    </p>
</div>
@endsection
