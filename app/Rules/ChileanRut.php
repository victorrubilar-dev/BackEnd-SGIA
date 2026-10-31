<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * RUT chileno con dígito verificador (módulo 11). Acepta puntos y guion.
 */
class ChileanRut implements ValidationRule
{
    public static function normalize(string $rut): string
    {
        $clean = strtoupper(preg_replace('/[^0-9kK]/', '', $rut));

        return strlen($clean) > 1 ? substr($clean, 0, -1).'-'.substr($clean, -1) : $clean;
    }

    public static function isValid(string $rut): bool
    {
        if (! preg_match('/^\d{1,2}(\.?\d{3}){2}-?[\dkK]$|^\d{7,8}-?[\dkK]$/', trim($rut))) {
            return false;
        }

        [$body, $dv] = explode('-', self::normalize($rut));

        $sum = 0;
        $factor = 2;
        foreach (array_reverse(str_split($body)) as $digit) {
            $sum += (int) $digit * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        $expected = 11 - ($sum % 11);
        $expected = match ($expected) {
            11 => '0',
            10 => 'K',
            default => (string) $expected,
        };

        return $dv === $expected;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('El RUT no es válido.');
        }
    }
}
