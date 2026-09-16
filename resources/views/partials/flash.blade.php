@if (session('status'))
    <div class="mb-6 flex items-start gap-3 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-900">
        <span aria-hidden="true">✓</span><p>{{ session('status') }}</p>
    </div>
@endif

@if (session('error'))
    <div class="mb-6 flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
        <span aria-hidden="true">!</span><p>{{ session('error') }}</p>
    </div>
@endif

@if ($errors->any())
    <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
        <p class="font-semibold">{{ $errors->count() }} {{ Str::plural('problem', $errors->count()) }} to fix:</p>
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif
