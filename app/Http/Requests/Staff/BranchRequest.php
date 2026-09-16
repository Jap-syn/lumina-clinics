<?php

namespace App\Http\Requests\Staff;

use App\Rules\E164Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A branch defines the trading window every availability calculation sits
 * inside, so these fields are not cosmetic: get `closes_at` wrong and RULE 4
 * silently offers slots that cannot be staffed.
 */
class BranchRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('branch')?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => [
                'required', 'string', 'min:2', 'max:120', 'alpha_dash',
                Rule::unique('branches', 'slug')->ignore($id),
            ],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'opens_at' => ['required', 'date_format:H:i'],
            // A branch that closes before it opens would offer an empty day
            // forever, with no error anywhere. Catch it here.
            'closes_at' => ['required', 'date_format:H:i', 'after:opens_at'],
            'open_weekdays' => ['required', 'array', 'min:1'],
            'open_weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'phone' => ['nullable', 'string', 'max:40', new E164Phone],
            'active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'active' => $this->boolean('active'),
            'open_weekdays' => array_map('intval', (array) $this->input('open_weekdays', [])),
        ]);
    }

    public function messages(): array
    {
        return [
            'closes_at.after' => 'Closing time must be later than opening time.',
            'open_weekdays.required' => 'Choose at least one trading day.',
        ];
    }
}
