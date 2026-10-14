<?php

declare(strict_types=1);

namespace Vortech\Factor\Interpolation;

enum InterpolationMethod: string
{
    /** Straight lines between the points. */
    case Linear = 'linear';

    /** The one polynomial of degree n-1 through all n points (Newton's divided differences). */
    case Polynomial = 'polynomial';

    /** Natural cubic spline: smooth (continuous second derivative), straight at both ends. */
    case CubicSpline = 'cubic-spline';
}
