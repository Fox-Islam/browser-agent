<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use RuntimeException;

/**
 * A decision no longer refers to the current page. Nothing was done; observe and
 * decide again.
 */
final class StalePage extends RuntimeException {}
