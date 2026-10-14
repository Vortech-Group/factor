# Changelog

All notable changes to `laravel-factor` will be documented in this file

## 0.1.0 - 2026-10-14

### Added

- `Number`: an exact rational number on bcmath. `+ - * /` and integer powers never round; `0.1 + 0.2` is exactly `0.3`. Six rounding modes (`RoundingMode`), `toDecimal()`, `round()`, `floor()`, `ceil()`
- `Functions`: sqrt, exp, ln, sin, cos, tan, asin, acos, atan, sinh, cosh, tanh, pow, pi and e to any number of decimal places, validated against published constants to 50 places
- Expressions: a parser (`Expression::parse('3x^2 - sin(2x)/5')`) and a fluent builder, both producing a canonical simplified tree, with `toString()`, `toLatex()`, exact `evaluate()`, `with()` substitution and `toClosure()`. The parser never evaluates or runs input and its length and nesting are limited
- Symbolic differentiation: `derivative()` (any order), `gradient()` and partial derivatives over sum, product, power and chain rules and the elementary functions
- Numeric integration: adaptive Gauss-Legendre (default) and Romberg, with an error estimate, the evaluation count and a `converged` flag
- Equation solving: polynomials in closed form up to degree 2 (exact rationals and exact radicals, complex pairs), rational roots recognised and divided out for higher degrees, the rest by Durand-Kerner; `solve()` returns a `Solution` of `Root`s (`nth(1)`, `roots()`, `real()`, `values()`); `root()` by Newton with the exact derivative or by Brent's method inside an interval; exact linear systems; an exact `Matrix`
- `Number::allocate()` and `Number::split()`: split an amount by ratio into parts of a given scale that add up exactly (largest remainder method); an amount with more decimals than the scale is refused
- Interpolation: `Factor::interpolate()` with linear, polynomial (Newton) and natural cubic spline methods, exact and without extrapolation
- Operations chain: `Factor::derivative('x^3')->integrate(0, 1)`, `->solve()` and `->root()` work on every `Expression`
- The `Factor` entry point, the `Factor` facade, the service provider and `config/factor.php` (precision, rounding, limits)
- The public API is documented and the implementation classes are marked `@internal`
- PHP 8.3+ with Laravel 12 and 13
