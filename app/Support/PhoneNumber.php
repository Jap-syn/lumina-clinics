<?php

namespace App\Support;

/**
 * Phone numbers in E.164, because a phone number IS a client here.
 *
 * `BookingController` matches returning clients with
 * `Client::firstOrCreate(['phone' => ...])` and `clients.phone` is unique, so
 * the stored form has to be canonical. Without that, "085-555-5555",
 * "+66 85 555 5555" and "0855555555" are three different people, the member
 * flag follows only one of them, and the same client can hold three overlapping
 * bookings because RULE 3 compares `client_id`.
 *
 * So every number is normalised to E.164 on the way in: a leading "+" or "00"
 * is an international number as given, a leading single "0" is a national trunk
 * code and takes the default country code, and anything else is assumed to
 * already carry a country code.
 *
 * The home market is checked more closely than the rest: a +66 number must be a
 * real Thai mobile or landline. Elsewhere we accept any well-formed E.164,
 * because guessing at another country's numbering plan is worse than not
 * guessing. A production system would use libphonenumber for the full plan
 * data; this is a deliberate, documented subset - see docs/technical-notes.md.
 */
class PhoneNumber
{
    /** Thailand. Every branch is in Thailand and reception types local numbers. */
    public const DEFAULT_COUNTRY_CODE = '66';

    /**
     * Canonical E.164 ("+66812345678"), or null when the input is not a usable
     * phone number. Never throws: callers validate, they do not rescue.
     */
    public static function normalise(?string $input): ?string
    {
        if (! is_string($input)) {
            return null;
        }

        // People type spaces, dashes, dots and brackets. None of them are data.
        $cleaned = preg_replace('/[\s\-().]/', '', trim($input)) ?? '';

        if ($cleaned === '') {
            return null;
        }

        if (str_starts_with($cleaned, '+')) {
            $digits = substr($cleaned, 1);
        } elseif (str_starts_with($cleaned, '00')) {
            // The other way of writing "+", still common on printed material.
            $digits = substr($cleaned, 2);
        } elseif (str_starts_with($cleaned, '0')) {
            // National trunk prefix: drop exactly one zero, add the country code.
            $digits = self::DEFAULT_COUNTRY_CODE.substr($cleaned, 1);
        } else {
            $digits = $cleaned;
        }

        // E.164: up to 15 digits, country code never starts with zero.
        if (! preg_match('/^[1-9]\d{6,14}$/', $digits)) {
            return null;
        }

        if (str_starts_with($digits, self::DEFAULT_COUNTRY_CODE)
            && ! self::isValidThaiNationalNumber(substr($digits, strlen(self::DEFAULT_COUNTRY_CODE)))) {
            return null;
        }

        return '+'.$digits;
    }

    public static function isValid(?string $input): bool
    {
        return self::normalise($input) !== null;
    }

    /**
     * How the number is shown back to a human. Thai numbers get their familiar
     * grouping; everything else stays in E.164 rather than being mangled.
     */
    public static function format(?string $e164): ?string
    {
        if ($e164 === null || ! str_starts_with($e164, '+'.self::DEFAULT_COUNTRY_CODE)) {
            return $e164;
        }

        $national = substr($e164, 1 + strlen(self::DEFAULT_COUNTRY_CODE));

        // Mobile: 09 1234 5678. Landline: 02 123 4567.
        return strlen($national) === 9
            ? '0'.substr($national, 0, 1).' '.substr($national, 1, 4).' '.substr($national, 5)
            : '0'.substr($national, 0, 1).' '.substr($national, 1, 3).' '.substr($national, 4);
    }

    /**
     * Thai numbering plan, the part of it Lumina will ever see:
     * mobile is 9 digits starting 6, 8 or 9; landline is 8 digits starting 2-7.
     */
    private static function isValidThaiNationalNumber(string $national): bool
    {
        return (bool) preg_match('/^[689]\d{8}$/', $national)
            || (bool) preg_match('/^[2-7]\d{7}$/', $national);
    }
}
