<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RULE 12. A phone number is a client's identity here, so these cases are not
 * cosmetic: every pair that normalises to the same string is the same person,
 * and every one that returns null must never reach the database.
 */
class PhoneNumberTest extends TestCase
{
    public static function equivalentThaiNumbers(): array
    {
        return [
            'plain' => ['0855555555'],
            'dashed' => ['085-555-5555'],
            'spaced' => ['085 555 5555'],
            'bracketed' => ['(085) 555-5555'],
            'international' => ['+66855555555'],
            'international spaced' => ['+66 85 555 5555'],
            'double zero prefix' => ['0066855555555'],
            'no plus' => ['66855555555'],
        ];
    }

    /** Every way a receptionist or a client might type one number. */
    #[DataProvider('equivalentThaiNumbers')]
    public function test_the_same_number_written_any_way_normalises_the_same(string $input): void
    {
        $this->assertSame('+66855555555', PhoneNumber::normalise($input));
    }

    public function test_a_thai_landline_is_accepted(): void
    {
        $this->assertSame('+6621234567', PhoneNumber::normalise('02 123 4567'));
    }

    public function test_a_number_from_another_country_is_kept_in_e164(): void
    {
        // Lumina is Thai, but a client on holiday is still a client.
        $this->assertSame('+442071234567', PhoneNumber::normalise('+44 20 7123 4567'));
        $this->assertSame('+6591234567', PhoneNumber::normalise('+65 9123 4567'));
    }

    public static function rejectedNumbers(): array
    {
        return [
            'empty' => [''],
            'letters' => ['not a phone'],
            'too short' => ['0812'],
            'too long for E.164' => ['+6612345678901234567'],
            'thai number of the wrong length' => ['08555555555'],
            'thai mobile prefix that does not exist' => ['0155555555'],
            'leading zero country code' => ['+0855555555'],
        ];
    }

    #[DataProvider('rejectedNumbers')]
    public function test_unusable_numbers_are_refused(string $input): void
    {
        $this->assertNull(PhoneNumber::normalise($input), "[{$input}] should not normalise");
        $this->assertFalse(PhoneNumber::isValid($input));
    }

    public function test_null_is_refused_rather_than_crashing(): void
    {
        $this->assertNull(PhoneNumber::normalise(null));
        $this->assertFalse(PhoneNumber::isValid(null));
    }

    public function test_thai_numbers_are_shown_back_in_the_local_form(): void
    {
        $this->assertSame('08 5555 5555', PhoneNumber::format('+66855555555'));
        $this->assertSame('02 123 4567', PhoneNumber::format('+6621234567'));
        // Anything else is left in E.164 rather than mangled into a Thai shape.
        $this->assertSame('+442071234567', PhoneNumber::format('+442071234567'));
    }
}
