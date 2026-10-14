<?php

declare(strict_types=1);

namespace Vortech\Factor\Exceptions;

/**
 * The operation is not defined for the given value, such as a division by zero or the logarithm of a negative number.
 */
final class DomainException extends FactorException {}
