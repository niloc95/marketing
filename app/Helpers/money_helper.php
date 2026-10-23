<?php

/**
 * One place that knows how an amount is written in each currency.
 *
 * Until local.webscheduler.app every price was rand and "R" was typed in
 * front of it by hand in some thirty places. Those now call money(), and for
 * ZAR the output is exactly what they printed before (MoneyHelperTest pins
 * it), so the South African site does not change by a character.
 *
 *   money('29.99')            R29.99     the stored string, as stored
 *   money(1500, 'ZAR', 0)     R1 500     formatted, space thousands
 *   money(1234.5, 'GBP', 2)   £1,234.50
 *
 * With $decimals null the amount is printed as given: settings and order rows
 * hold a decimal string such as "29.99", and the views have always shown it
 * verbatim. Pass $decimals to format a number.
 */

if (! function_exists('money_currencies')) {
    /**
     * Symbol and separators per ISO 4217 code. South Africa writes a space
     * between thousands (R1 500); the rest of the launch countries a comma.
     *
     * @return array<string,array{symbol:string,thousands:string,decimal:string}>
     */
    function money_currencies(): array
    {
        return [
            'ZAR' => ['symbol' => 'R',    'thousands' => ' ', 'decimal' => '.'],
            'USD' => ['symbol' => '$',    'thousands' => ',', 'decimal' => '.'],
            'GBP' => ['symbol' => '£',    'thousands' => ',', 'decimal' => '.'],
            'EUR' => ['symbol' => '€',    'thousands' => ',', 'decimal' => '.'],
            'AUD' => ['symbol' => 'A$',   'thousands' => ',', 'decimal' => '.'],
            'NZD' => ['symbol' => 'NZ$',  'thousands' => ',', 'decimal' => '.'],
            'NAD' => ['symbol' => 'N$',   'thousands' => ',', 'decimal' => '.'],
            'BWP' => ['symbol' => 'P',    'thousands' => ',', 'decimal' => '.'],
            'KES' => ['symbol' => 'KSh ', 'thousands' => ',', 'decimal' => '.'],
            'NGN' => ['symbol' => '₦',    'thousands' => ',', 'decimal' => '.'],
        ];
    }
}

if (! function_exists('money')) {
    /**
     * An amount with its currency symbol. Unknown codes print the code itself
     * ("CHF 12.00") rather than guessing a symbol.
     */
    function money(float|int|string $amount, string $currency = 'ZAR', ?int $decimals = null): string
    {
        $currency = strtoupper(trim($currency));
        $format   = money_currencies()[$currency]
            ?? ['symbol' => $currency . ' ', 'thousands' => ',', 'decimal' => '.'];

        $value = $decimals === null
            ? (string) $amount
            : number_format((float) $amount, $decimals, $format['decimal'], $format['thousands']);

        return $format['symbol'] . $value;
    }
}
