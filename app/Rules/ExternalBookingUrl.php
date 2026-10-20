<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ExternalBookingUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Enter a valid HTTPS booking page URL.');

            return;
        }

        $value = trim($value);
        $parts = parse_url($value);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($value === '' || strlen($value) > 2000 || filter_var($value, FILTER_VALIDATE_URL) === false || $scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || preg_match('/[<>&"\'\s]/', $value)) {
            $fail('Enter a valid HTTPS booking page URL.');

            return;
        }

        if (in_array($host, ['localhost', 'localhost.localdomain', '127.0.0.1', '::1', '0.0.0.0'], true) || str_ends_with($host, '.localhost')) {
            $fail('Enter a public HTTPS booking page URL.');

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $fail('Enter a public HTTPS booking page URL.');
        }
    }
}
