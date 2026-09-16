<?php

namespace App\Http\Requests;

use App\Rules\E164Phone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Everything the public booking endpoint will accept, and nothing else.
 *
 * Two things are worth pointing at. The consent pair is all-or-nothing:
 * `required_with` in both directions means a half-filled consent record can
 * never reach RULE 11, which would otherwise see a national_id and no date of
 * birth and store it. And `starts_at` is only shape-checked here - whether the
 * time is on the grid, inside opening hours, in the future and actually free is
 * BookingService's job, because those answers depend on the branch.
 */
class StoreBookingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'treatment_id' => ['required', 'integer', 'exists:treatments,id'],
            'therapist_id' => ['nullable', 'integer', 'exists:therapists,id'],
            'starts_at' => ['required', 'date'],

            'client.name' => ['required', 'string', 'min:2', 'max:120'],
            'client.phone' => ['required', 'string', 'max:40', new E164Phone],
            'client.email' => ['nullable', 'email:rfc', 'max:160'],

            // RULE 11. Present together or not at all.
            'consent.national_id' => ['nullable', 'required_with:consent.date_of_birth', 'string', 'min:6', 'max:40'],
            'consent.date_of_birth' => ['nullable', 'required_with:consent.national_id', 'date', 'before:today', 'after:1900-01-01'],
        ];
    }

    public function attributes(): array
    {
        return [
            'client.name' => 'name',
            'client.phone' => 'phone number',
            'client.email' => 'email address',
            'consent.national_id' => 'ID number',
            'consent.date_of_birth' => 'date of birth',
        ];
    }

    public function messages(): array
    {
        return [
            'consent.national_id.required_with' => 'A consent record needs both the ID number and the date of birth.',
            'consent.date_of_birth.required_with' => 'A consent record needs both the ID number and the date of birth.',
            'consent.date_of_birth.before' => 'The date of birth must be in the past.',
        ];
    }
}
