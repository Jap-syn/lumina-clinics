@extends('layouts.app')
@section('title', 'Book an appointment — Lumina Clinics')

@section('content')
<div class="mx-auto max-w-3xl">
    <div class="text-center">
        <h1 class="text-3xl font-semibold tracking-tight">Book an appointment</h1>
        <p class="mx-auto mt-2 max-w-xl text-stone-500">
            Pick a treatment and a time. You will see only the times we can genuinely staff —
            the room and the therapist both have to be free.
        </p>
    </div>

    {{-- Progress. Purely orientation: the form itself is one page. --}}
    <ol class="mx-auto mt-8 flex max-w-lg items-center gap-2 text-xs font-medium">
        @foreach (['Treatment', 'Time', 'Your details'] as $i => $label)
            <li class="flex flex-1 items-center gap-2" data-step="{{ $i + 1 }}">
                <span class="step-dot grid h-7 w-7 shrink-0 place-items-center rounded-full bg-stone-200 text-stone-500">{{ $i + 1 }}</span>
                <span class="step-label text-stone-400">{{ $label }}</span>
                @unless ($loop->last)<span class="h-px flex-1 bg-stone-200"></span>@endunless
            </li>
        @endforeach
    </ol>

    <div id="alert" class="mt-6 hidden rounded-xl border px-4 py-3 text-sm"></div>

    {{-- ---------------- Step 1 ---------------- --}}
    <section class="card mt-6 p-6">
        <h2 class="text-lg font-semibold">1. What and where</h2>
        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div>
                <label for="branch" class="block text-sm font-medium text-stone-700">Branch <span class="text-rose-600">*</span></label>
                <select id="branch" class="input"><option value="">Loading…</option></select>
            </div>
            <div>
                <label for="treatment" class="block text-sm font-medium text-stone-700">Treatment <span class="text-rose-600">*</span></label>
                <select id="treatment" class="input" disabled><option value="">Choose a branch first</option></select>
            </div>
            <div class="sm:col-span-2">
                <label for="therapist" class="block text-sm font-medium text-stone-700">
                    Therapist <span class="ml-1 text-xs font-normal text-stone-400">optional</span>
                </label>
                <select id="therapist" class="input" disabled><option value="">Anyone available</option></select>
                <p class="mt-1 text-xs text-stone-500">Ask for someone in particular, or leave this and we will assign whoever is free.</p>
            </div>
        </div>
    </section>

    {{-- ---------------- Step 2 ---------------- --}}
    <section class="card mt-6 p-6">
        <h2 class="text-lg font-semibold">2. When</h2>
        <div class="mt-5">
            <label for="date" class="block text-sm font-medium text-stone-700">Date <span class="text-rose-600">*</span></label>
            <input id="date" type="date" class="input sm:max-w-xs" min="{{ now()->addDay()->toDateString() }}" max="{{ now()->addDays(config('lumina.booking_horizon_days'))->toDateString() }}">
        </div>
        <div id="slots" class="mt-5 text-sm text-stone-500">Choose a treatment and a date to see available times.</div>
    </section>

    {{-- ---------------- Step 3 ---------------- --}}
    <section class="card mt-6 p-6">
        <h2 class="text-lg font-semibold">3. Your details</h2>
        <p class="mt-1 text-sm text-stone-500"><span class="text-rose-600">*</span> marks a required field.</p>

        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div>
                <label for="name" class="block text-sm font-medium text-stone-700">Full name <span class="text-rose-600">*</span></label>
                <input id="name" class="input" autocomplete="name" required>
                <p class="field-error mt-1 hidden text-xs font-medium text-rose-600"></p>
            </div>
            <div>
                <label for="phone" class="block text-sm font-medium text-stone-700">Mobile number <span class="text-rose-600">*</span></label>
                <input id="phone" class="input" inputmode="tel" autocomplete="tel" placeholder="081 234 5678" required>
                <p class="mt-1 text-xs text-stone-500">We confirm by WhatsApp. +66 and 0 both work.</p>
                <p class="field-error mt-1 hidden text-xs font-medium text-rose-600"></p>
            </div>
            <div class="sm:col-span-2">
                <label for="email" class="block text-sm font-medium text-stone-700">
                    Email <span class="ml-1 text-xs font-normal text-stone-400">optional</span>
                </label>
                <input id="email" type="email" class="input" autocomplete="email">
                <p class="field-error mt-1 hidden text-xs font-medium text-rose-600"></p>
            </div>
        </div>

        <div id="consent" class="mt-5 hidden rounded-xl border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-900">This treatment needs a consent record</p>
            <p class="mt-1 text-xs text-amber-800">Required by the clinic for laser work. Your ID number is encrypted and is never shown back to staff in the diary.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="national_id" class="block text-sm font-medium text-stone-700">ID number <span class="text-rose-600">*</span></label>
                    <input id="national_id" class="input" inputmode="numeric">
                    <p class="field-error mt-1 hidden text-xs font-medium text-rose-600"></p>
                </div>
                <div>
                    <label for="dob" class="block text-sm font-medium text-stone-700">Date of birth <span class="text-rose-600">*</span></label>
                    <input id="dob" type="date" class="input" max="{{ now()->toDateString() }}">
                    <p class="field-error mt-1 hidden text-xs font-medium text-rose-600"></p>
                </div>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-4">
            <button id="submit" class="btn-primary" disabled>Confirm booking</button>
            <p id="summary" class="text-sm text-stone-500">Pick a time first.</p>
        </div>
    </section>

    {{-- ---------------- Result ---------------- --}}
    <section id="result" class="card mt-6 hidden p-6"></section>
</div>

@push('scripts')
<script>
(() => {
    const $ = id => document.getElementById(id);
    const state = { slot: null, treatment: null };

    const alertBox = $('alert');
    const show = (kind, message) => {
        alertBox.className = 'mt-6 rounded-xl border px-4 py-3 text-sm ' + (kind === 'error'
            ? 'border-rose-200 bg-rose-50 text-rose-900'
            : 'border-teal-200 bg-teal-50 text-teal-900');
        alertBox.textContent = message;
    };
    const hideAlert = () => alertBox.classList.add('hidden');

    const clearFieldErrors = () => document.querySelectorAll('.field-error').forEach(p => {
        p.textContent = ''; p.classList.add('hidden');
        p.previousElementSibling?.classList?.remove('input-error');
    });

    // Laravel returns { errors: { "client.phone": ["..."] } }; put each message
    // against its own field rather than dumping one generic failure at the top.
    const fieldFor = key => ({
        'client.name': 'name', 'client.phone': 'phone', 'client.email': 'email',
        'consent.national_id': 'national_id', 'consent.date_of_birth': 'dob',
    })[key];

    const applyErrors = errors => {
        clearFieldErrors();
        let unmatched = [];
        for (const [key, messages] of Object.entries(errors || {})) {
            const input = $(fieldFor(key) || '');
            if (input) {
                input.classList.add('input-error');
                const p = input.parentElement.querySelector('.field-error');
                p.textContent = messages[0];
                p.classList.remove('hidden');
            } else {
                unmatched.push(messages[0]);
            }
        }
        return unmatched;
    };

    const markStep = n => {
        document.querySelectorAll('[data-step]').forEach(li => {
            const done = Number(li.dataset.step) <= n;
            li.querySelector('.step-dot').className = 'step-dot grid h-7 w-7 shrink-0 place-items-center rounded-full '
                + (done ? 'bg-teal-700 text-white' : 'bg-stone-200 text-stone-500');
            li.querySelector('.step-label').className = 'step-label ' + (done ? 'text-stone-700' : 'text-stone-400');
        });
    };

    const get = async (url) => {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Request failed');
        return response.json();
    };

    // ---- catalogue ----
    (async () => {
        const branches = await get('/api/branches');
        $('branch').innerHTML = '<option value="">Choose a branch…</option>'
            + branches.map(b => `<option value="${b.id}">${b.name}</option>`).join('');
    })();

    $('branch').addEventListener('change', async e => {
        const id = e.target.value;
        $('treatment').disabled = !id;
        $('therapist').disabled = true;
        $('therapist').innerHTML = '<option value="">Anyone available</option>';
        if (!id) return;
        const treatments = await get(`/api/treatments?branch_id=${id}`);
        $('treatment').innerHTML = '<option value="">Choose a treatment…</option>'
            + treatments.map(t => `<option value="${t.id}" data-consent="${t.requires_consent ? 1 : 0}" data-duration="${t.duration_minutes}" data-name="${t.name}" data-price="${t.price_minor_units}">${t.name} · ${t.duration_minutes} min · ${(t.price_minor_units / 100).toLocaleString()} THB</option>`).join('');
        markStep(1);
    });

    $('treatment').addEventListener('change', async e => {
        const option = e.target.selectedOptions[0];
        state.treatment = option?.value ? option : null;
        $('consent').classList.toggle('hidden', option?.dataset.consent !== '1');
        if (state.treatment) {
            const therapists = await get(`/api/therapists?branch_id=${$('branch').value}&treatment_id=${state.treatment.value}`);
            $('therapist').disabled = false;
            $('therapist').innerHTML = '<option value="">Anyone available</option>'
                + therapists.map(t => `<option value="${t.id}">${t.name}${t.title ? ' — ' + t.title : ''}</option>`).join('');
        }
        loadSlots();
    });

    $('therapist').addEventListener('change', loadSlots);
    $('date').addEventListener('change', loadSlots);

    async function loadSlots() {
        state.slot = null;
        syncSubmit();
        const branch = $('branch').value, treatment = $('treatment').value, date = $('date').value;
        if (!branch || !treatment || !date) {
            $('slots').innerHTML = '<p class="text-sm text-stone-500">Choose a treatment and a date to see available times.</p>';
            return;
        }
        $('slots').innerHTML = '<p class="text-sm text-stone-500">Checking what is genuinely free…</p>';
        const therapist = $('therapist').value;
        const slots = await get(`/api/availability?branch_id=${branch}&treatment_id=${treatment}&date=${date}${therapist ? '&therapist_id=' + therapist : ''}`);

        if (!slots.slots.length) {
            $('slots').innerHTML = '<p class="rounded-lg bg-stone-100 px-4 py-6 text-center text-sm text-stone-600">Nothing free that day. Try another date, or leave the therapist as “anyone available”.</p>';
            return;
        }

        markStep(2);
        $('slots').innerHTML = '<div class="flex flex-wrap gap-2">' + slots.slots.map(s => {
            const time = new Date(s.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
            return `<button type="button" data-iso="${s.starts_at}" class="slot rounded-lg border border-stone-300 px-4 py-2 text-sm font-medium tabular-nums transition hover:border-teal-600 hover:bg-teal-50">${time}</button>`;
        }).join('') + '</div>';

        document.querySelectorAll('.slot').forEach(button => button.addEventListener('click', () => {
            document.querySelectorAll('.slot').forEach(b => b.className = 'slot rounded-lg border border-stone-300 px-4 py-2 text-sm font-medium tabular-nums transition hover:border-teal-600 hover:bg-teal-50');
            button.className = 'slot rounded-lg border border-teal-600 bg-teal-600 px-4 py-2 text-sm font-medium tabular-nums text-white';
            state.slot = button.dataset.iso;
            markStep(3);
            syncSubmit();
        }));
    }

    function syncSubmit() {
        $('submit').disabled = !state.slot;
        $('summary').textContent = state.slot
            ? new Date(state.slot).toLocaleString([], { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', hour12: false })
            : 'Pick a time first.';
    }

    $('submit').addEventListener('click', async () => {
        hideAlert(); clearFieldErrors();
        $('submit').disabled = true; $('submit').textContent = 'Booking…';

        const payload = {
            branch_id: Number($('branch').value),
            treatment_id: Number($('treatment').value),
            therapist_id: $('therapist').value ? Number($('therapist').value) : null,
            starts_at: state.slot,
            client: { name: $('name').value, phone: $('phone').value, email: $('email').value || null },
        };
        if (!$('consent').classList.contains('hidden')) {
            payload.consent = { national_id: $('national_id').value, date_of_birth: $('dob').value };
        }

        const response = await fetch('/api/bookings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(payload),
        });
        const body = await response.json();

        $('submit').disabled = false; $('submit').textContent = 'Confirm booking';

        if (response.status === 422 && body.errors) {
            const unmatched = applyErrors(body.errors);
            alertBox.classList.remove('hidden');
            show('error', unmatched[0] || 'Please check the highlighted fields.');
            return;
        }
        if (!response.ok) {
            alertBox.classList.remove('hidden');
            show('error', body.error || 'Something went wrong. Please try again.');
            if (response.status === 409) loadSlots();
            return;
        }

        renderResult(body);
    });

    function renderResult(booking) {
        hideAlert();
        const deposit = booking.deposit.required && !booking.deposit.paid;
        $('result').classList.remove('hidden');
        $('result').innerHTML = `
            <div class="flex items-start gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-teal-600 text-white">✓</span>
                <div class="flex-1">
                    <h2 class="text-lg font-semibold">${deposit ? 'Slot held for you' : 'You are booked in'}</h2>
                    <p class="mt-1 text-sm text-stone-600">
                        ${booking.treatment} with ${booking.therapist} at ${booking.branch},
                        ${new Date(booking.starts_at).toLocaleString([], { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', hour12: false })}.
                    </p>
                    <p class="mt-3 text-sm">Reference <span class="rounded bg-stone-100 px-2 py-1 font-mono font-semibold">${booking.reference}</span></p>
                    <p class="mt-3 text-xs text-stone-500">Free cancellation until ${new Date(booking.free_cancellation_until).toLocaleString([], { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', hour12: false })}.</p>
                    ${deposit ? `
                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                            <p class="text-sm text-amber-900">
                                We are holding this slot until
                                <strong>${new Date(booking.hold_expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false })}</strong>
                                while you pay the ${(booking.deposit.amount_minor_units / 100).toLocaleString()} THB deposit.
                                After that it goes back to whoever wants it.
                            </p>
                            <button id="pay" class="btn-primary mt-3">Pay the deposit</button>
                        </div>` : `
                        <p class="mt-4 rounded-xl bg-teal-50 px-4 py-3 text-sm text-teal-900">
                            No deposit — members do not pay one. See you then.
                        </p>`}
                </div>
            </div>`;
        $('result').scrollIntoView({ behavior: 'smooth' });

        $('pay')?.addEventListener('click', async () => {
            const button = $('pay');
            button.disabled = true; button.textContent = 'Paying…';
            // The key is generated once and reused on retry: pressing this
            // twice must never charge twice. See DepositService.
            const key = (crypto.randomUUID?.() ?? String(Date.now()));
            const response = await fetch(`/api/bookings/${booking.reference}/deposit`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Idempotency-Key': key },
            });
            const body = await response.json();
            button.disabled = false; button.textContent = 'Pay the deposit';
            if (!response.ok) { alertBox.classList.remove('hidden'); show('error', body.error || 'The payment did not go through.'); return; }
            renderResult(body.booking);
        });
    }
})();
</script>
@endpush
@endsection
