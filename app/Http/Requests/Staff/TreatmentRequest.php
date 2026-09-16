<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Treatments are 30, 60, or 90 minutes. Bookings are on the hour and the half
 * hour." Those two sentences are one constraint: a duration that is not a
 * multiple of the 30 minute grid would push every following start off it, so
 * the duration list is closed rather than a free integer.
 *
 * Price is entered in baht, the unit the founder thinks in, and stored in
 * satang as an integer - money never touches a float.
 */
class TreatmentRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('treatment')?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => [
                'required', 'string', 'min:2', 'max:120', 'alpha_dash',
                Rule::unique('treatments', 'slug')->ignore($id),
            ],
            'duration_minutes' => ['required', 'integer', Rule::in([30, 60, 90])],
            'price_baht' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'requires_consent' => ['boolean'],
            'active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'requires_consent' => $this->boolean('requires_consent'),
            'active' => $this->boolean('active'),
        ]);
    }

    public function messages(): array
    {
        return [
            'duration_minutes.in' => 'Treatments are 30, 60 or 90 minutes, so that starts stay on the half-hour grid.',
        ];
    }
}
