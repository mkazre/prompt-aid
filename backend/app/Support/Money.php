<?php

namespace App\Support;

/**
 * Integer-cents money helpers (no float drift). Every finance calculation
 * converts amounts to cents with Money::cents(), does integer arithmetic,
 * and converts back with Money::toDecimal() for DECIMAL(12,2) columns.
 * Rounding is always half away from zero, to the cent.
 */
final class Money
{
    /** Parse "123.455", 123.45, 12345 (as rands) → cents. Decimal strings are parsed exactly. */
    public static function cents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }
        if (is_int($amount)) {
            return $amount * 100;
        }
        if (is_float($amount)) {
            $amount = sprintf('%.6F', $amount);
        }
        $s = trim((string) $amount);
        if (! preg_match('/^([+-])?(\d*)(?:\.(\d*))?$/', $s, $m)) {
            return (int) round(((float) $s) * 100);
        }
        $neg = ($m[1] ?? '') === '-';
        $whole = (int) (($m[2] ?? '') === '' ? '0' : $m[2]);
        $frac = str_pad($m[3] ?? '', 3, '0');
        $cents = $whole * 100 + (int) substr($frac, 0, 2);
        if ((int) $frac[2] >= 5) {
            $cents++;
        }

        return $neg ? -$cents : $cents;
    }

    /** Cents → "123.45" (string, safe for DECIMAL columns). */
    public static function toDecimal(int $cents): string
    {
        $neg = $cents < 0;
        $abs = abs($cents);

        return ($neg ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function toFloat(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** "R 1 234.50" style label. */
    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-R ' : 'R ').number_format(abs($cents) / 100, 2, '.', ' ');
    }

    /** Percent of an amount in cents; percent may have up to 3 decimals ("17.5"). */
    public static function percentOf(int $cents, string|int|float|null $percent): int
    {
        $milli = self::scaled($percent, 3); // 17.5% → 17500
        if ($milli === 0 || $cents === 0) {
            return 0;
        }

        return self::divRound($cents * $milli, 100000);
    }

    /** round(a * num / den) half away from zero, all integers. */
    public static function mulDiv(int $a, int $num, int $den): int
    {
        return $den === 0 ? 0 : self::divRound($a * $num, $den);
    }

    protected static function divRound(int $n, int $d): int
    {
        $neg = ($n < 0) xor ($d < 0);
        $n = abs($n);
        $d = abs($d);
        $q = intdiv($n, $d);
        if (($n % $d) * 2 >= $d) {
            $q++;
        }

        return $neg ? -$q : $q;
    }

    protected static function scaled(string|int|float|null $v, int $places): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        if (is_float($v)) {
            $v = sprintf('%.6F', $v);
        }
        $s = trim((string) $v);
        if (! preg_match('/^([+-])?(\d*)(?:\.(\d*))?$/', $s, $m)) {
            return (int) round(((float) $s) * (10 ** $places));
        }
        $whole = (int) (($m[2] ?? '') === '' ? '0' : $m[2]);
        $frac = str_pad($m[3] ?? '', $places + 1, '0');
        $out = $whole * (10 ** $places) + (int) substr($frac, 0, $places);
        if ((int) $frac[$places] >= 5) {
            $out++;
        }

        return ($m[1] ?? '') === '-' ? -$out : $out;
    }
}
