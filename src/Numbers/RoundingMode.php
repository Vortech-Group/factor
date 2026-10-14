<?php

declare(strict_types=1);

namespace Vortech\Factor\Numbers;

enum RoundingMode: string
{
    /** Halves go away from zero: 2.5 becomes 3 and -2.5 becomes -3. */
    case HalfAwayFromZero = 'half-away-from-zero';

    /** Halves go to the even neighbour (banker's rounding): 2.5 becomes 2, 3.5 becomes 4. */
    case HalfEven = 'half-even';

    /** Towards zero: the digits are cut off. */
    case Truncate = 'truncate';

    /** Towards negative infinity. */
    case Floor = 'floor';

    /** Towards positive infinity. */
    case Ceiling = 'ceiling';

    /** Away from zero whenever there is a remainder. */
    case AwayFromZero = 'away-from-zero';
}
