<?php

declare(strict_types=1);

use Vortech\Factor\Numbers\RoundingMode;

return [

    /*
    |--------------------------------------------------------------------------
    | Precision
    |--------------------------------------------------------------------------
    |
    | The number of decimal places that functions (sin, ln, exp, roots) and the
    | numeric methods (integration, root finding) are computed to. Plain
    | arithmetic is exact and does not depend on it. Internally the package
    | works with extra guard digits, so the places asked for can be trusted.
    | Rounding the final result to fewer places, for money, is a separate step.
    |
    */

    'precision' => (int) env('FACTOR_PRECISION', 40),

    /*
    |--------------------------------------------------------------------------
    | Rounding
    |--------------------------------------------------------------------------
    |
    | The rounding mode of the Context. One of the RoundingMode values:
    | half-away-from-zero, half-even, truncate, floor, ceiling, away-from-zero.
    |
    */

    'rounding' => env('FACTOR_ROUNDING', RoundingMode::HalfAwayFromZero->value),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Expressions may come from users, so the parser is bounded: the longest
    | accepted text, the deepest nesting, and the iteration cap of the root finders.
    |
    */

    'max_iterations' => 200,

    'max_length' => 10_000,

    'max_depth' => 128,

];
