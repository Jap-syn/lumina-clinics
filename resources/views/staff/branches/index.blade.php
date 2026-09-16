@extends('layouts.app')
@section('title', 'Branches — Lumina')

@section('content')
<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">Branches</h1>
        <p class="mt-1 text-sm text-stone-500">Opening hours and trading days. Every availability calculation sits inside this window.</p>
    </div>
    <a href="{{ route('staff.branches.create') }}" class="btn-primary">+ Add branch</a>
</div>

<div class="card mt-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-stone-200">
            <thead class="bg-stone-50">
                <tr>
                    <th class="th">Branch</th>
                    <th class="th">Hours</th>
                    <th class="th">Trading days</th>
                    <th class="th">Rooms</th>
                    <th class="th">Therapists</th>
                    <th class="th">Status</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @forelse ($branches as $branch)
                <tr class="{{ $branch->active ? '' : 'bg-stone-50/70 text-stone-400' }}">
                    <td class="td">
                        <div class="font-semibold text-stone-900">{{ $branch->name }}</div>
                        <div class="text-xs text-stone-400">{{ $branch->slug }} · {{ $branch->timezone }}</div>
                    </td>
                    <td class="td tabular-nums">{{ substr($branch->opens_at, 0, 5) }} – {{ substr($branch->closes_at, 0, 5) }}</td>
                    <td class="td">
                        <div class="flex gap-1">
                            @foreach ([1 => 'M', 2 => 'T', 3 => 'W', 4 => 'T', 5 => 'F', 6 => 'S', 7 => 'S'] as $iso => $letter)
                                <span @class([
                                    'grid h-6 w-6 place-items-center rounded text-[11px] font-semibold',
                                    'bg-teal-600 text-white' => in_array($iso, $branch->open_weekdays ?? [], true),
                                    'bg-stone-100 text-stone-400' => ! in_array($iso, $branch->open_weekdays ?? [], true),
                                ])>{{ $letter }}</span>
                            @endforeach
                        </div>
                    </td>
                    <td class="td tabular-nums">{{ $branch->rooms_count }}</td>
                    <td class="td tabular-nums">{{ $branch->therapists_count }}</td>
                    <td class="td">
                        <span class="badge {{ $branch->active ? 'bg-teal-50 text-teal-700' : 'bg-stone-200 text-stone-600' }}">
                            {{ $branch->active ? 'Taking bookings' : 'Not offered' }}
                        </span>
                        @if ($branch->active && ($branch->rooms_count === 0 || $branch->therapists_count === 0))
                            <span class="badge mt-1 block w-fit bg-amber-50 text-amber-700">Needs rooms &amp; staff</span>
                        @endif
                    </td>
                    <td class="td text-right whitespace-nowrap">
                        <a href="{{ route('staff.branches.edit', $branch) }}" class="btn-quiet">Edit</a>
                        <form method="POST" action="{{ route('staff.branches.toggle', $branch) }}" class="inline"
                              onsubmit="return confirm('{{ $branch->active ? 'Stop offering ' . addslashes($branch->name) . '? Bookings already taken there will stand.' : 'Start offering ' . addslashes($branch->name) . ' again?' }}')">
                            @csrf @method('PATCH')
                            <button class="btn-quiet {{ $branch->active ? 'hover:text-rose-700' : 'hover:text-teal-700' }}">
                                {{ $branch->active ? 'Deactivate' : 'Reactivate' }}
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="td text-center text-stone-500">No branches yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="mt-4 text-xs text-stone-500">
    Nothing on this screen deletes. A branch owns rooms, therapists and every booking ever taken at it,
    including consent and payment records — so it is deactivated, never removed.
</p>
@endsection
