<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Executor;

enum ExecutionStatus: string
{
    case Done = 'done';

    /** The page changed since the observation; observe again instead of acting. */
    case Stale = 'stale';

    /** The value was refused before anything was done to the page. */
    case Rejected = 'rejected';

    /** The value was set, and the page cleared or changed it; input and change have fired. */
    case Altered = 'altered';
}
