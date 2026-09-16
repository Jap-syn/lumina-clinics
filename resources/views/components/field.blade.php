{{--
  One form field, so that "required" is marked the same way everywhere and an
  error always appears against the field that caused it rather than only at the
  top of the page.
--}}
@props(['name', 'label', 'required' => false, 'hint' => null])
<div>
    <label for="{{ $name }}" class="block text-sm font-medium text-stone-700">
        {{ $label }}
        @if ($required)
            <span class="text-rose-600" title="Required">*</span>
        @else
            <span class="ml-1 text-xs font-normal text-stone-400">optional</span>
        @endif
    </label>
    {{ $slot }}
    @if ($hint)<p class="mt-1 text-xs text-stone-500">{{ $hint }}</p>@endif
    @error($name)<p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>@enderror
</div>
