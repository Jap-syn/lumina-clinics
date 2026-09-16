<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RULE 2 and RULE 5 are both configured here.
 *
 * `buffer_minutes` is the therapist's own turnaround, and it is per person on
 * purpose: "My senior facialist doesn't need a break between clients" is a 0,
 * everyone else is 15. It is independent of the room's cleanup, which is what
 * lets her take a client back to back in a second room.
 *
 * `treatments` is her qualification list. An empty one would make her
 * unbookable everywhere with no visible reason, so at least one is required.
 */
class TherapistRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'title' => ['nullable', 'string', 'max:120'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'treatments' => ['required', 'array', 'min:1'],
            'treatments.*' => ['integer', 'distinct', 'exists:treatments,id'],
            'active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'active' => $this->boolean('active'),
            'treatments' => array_map('intval', (array) $this->input('treatments', [])),
        ]);
    }

    public function messages(): array
    {
        return [
            'treatments.required' => 'Choose at least one treatment she is trained on, or she can never be booked.',
            'buffer_minutes.max' => 'A turnaround longer than two hours is almost certainly a typo.',
        ];
    }
}
