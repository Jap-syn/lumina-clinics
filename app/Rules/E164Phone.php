<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * RULE 12: a phone number must be reachable and must normalise to E.164.
 *
 * Applied everywhere a number is accepted. See App\Support\PhoneNumber for why
 * this matters more than ordinary field validation: the number is the client's
 * identity, so a number that cannot be normalised cannot be matched.
 */
class E164Phone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! PhoneNumber::isValid(is_string($value) ? $value : null)) {
            $fail('The :attribute must be a reachable phone number, for example 081 234 5678 or +66 81 234 5678.');
        }
    }
}
