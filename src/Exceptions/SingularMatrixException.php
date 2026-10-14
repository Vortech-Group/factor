<?php

declare(strict_types=1);

namespace Vortech\Factor\Exceptions;

/**
 * The matrix has no inverse, or the system has no unique solution.
 */
final class SingularMatrixException extends FactorException {}
