<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Lumina Clinics')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
    </style>
    {{-- The Play CDN compiles @apply in this block, so the shared pieces of the
         interface are defined once instead of being copied down every form. --}}
    <style type="text/tailwindcss">
        .input { @apply mt-1 w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 shadow-sm outline-none transition placeholder:text-stone-400 focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10; }
        .input-error { @apply border-rose-400 focus:border-rose-500 focus:ring-rose-500/10; }
        .card { @apply rounded-2xl border border-stone-200 bg-white shadow-sm; }
        .btn-primary { @apply inline-flex items-center justify-center gap-2 rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 focus:outline-none focus:ring-4 focus:ring-teal-600/20 disabled:cursor-not-allowed disabled:bg-stone-300; }
        .btn-ghost { @apply inline-flex items-center justify-center gap-2 rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-700 transition hover:border-stone-400 hover:bg-stone-50; }
        .btn-quiet { @apply inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-stone-500 transition hover:bg-stone-100 hover:text-stone-800; }
        .badge { @apply inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium; }
        .th { @apply px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-stone-500; }
        .td { @apply px-4 py-3 align-middle text-sm; }
    </style>
</head>
<body class="h-full bg-stone-50 text-stone-800 antialiased">

@php($isStaff = session(\App\Http\Middleware\StaffToken::SESSION_KEY) === true)

<header class="sticky top-0 z-30 border-b border-stone-200 bg-white/90 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3">
        <a href="{{ route('book') }}" class="flex items-center gap-2 shrink-0">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-teal-700 text-sm font-bold text-white">L</span>
            <span class="text-base font-semibold tracking-tight">Lumina<span class="hidden sm:inline text-stone-400 font-normal"> Clinics</span></span>
        </a>

        @if ($isStaff)
            {{-- Every staff screen is one click away. Nobody types a URL. --}}
            <nav class="flex flex-1 items-center gap-1 overflow-x-auto text-sm">
                @foreach ([
                    'staff.diary' => 'Diary',
                    'staff.branches.index' => 'Branches',
                    'staff.treatments.index' => 'Treatments',
                    'staff.therapists.index' => 'Therapists',
                ] as $route => $label)
                    @php($on = request()->routeIs(str_replace('.index', '', $route).'*'))
                    <a href="{{ route($route) }}"
                       @class([
                           'rounded-lg px-3 py-1.5 font-medium whitespace-nowrap transition',
                           'bg-teal-50 text-teal-800' => $on,
                           'text-stone-600 hover:bg-stone-100' => ! $on,
                       ])>{{ $label }}</a>
                @endforeach
            </nav>
            <form method="POST" action="{{ route('staff.logout') }}" class="shrink-0">
                @csrf
                <button class="rounded-lg px-3 py-1.5 text-sm font-medium text-stone-500 hover:bg-stone-100 hover:text-stone-800">
                    Sign out
                </button>
            </form>
        @else
            <div class="flex flex-1 justify-end">
                <a href="{{ route('staff.login') }}"
                   class="rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-600 hover:border-stone-400 hover:text-stone-900">
                    Reception sign in
                </a>
            </div>
        @endif
    </div>
</header>

<main class="mx-auto max-w-6xl px-4 py-8">
    @include('partials.flash')
    @yield('content')
</main>

<footer class="mx-auto max-w-6xl px-4 pb-10 pt-4 text-xs text-stone-400">
    Lumina Clinics — phase one. Times shown in the branch's own timezone.
</footer>

@stack('scripts')
</body>
</html>
