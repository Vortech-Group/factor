<?php

declare(strict_types=1);

namespace Vortech\Factor\Calculus;

enum IntegrationMethod: string
{
    /** Adaptive composite Gauss-Legendre: fast for smooth functions, never evaluates the end points. The default. */
    case GaussLegendre = 'gauss-legendre';

    /** Romberg: the trapezoid rule with Richardson extrapolation, evaluates the end points. */
    case Romberg = 'romberg';
}
