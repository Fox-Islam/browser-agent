<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use RuntimeException;

/**
 * The text helper answered with no usable value. Nothing was typed, so the decision can be taken
 * again.
 */
final class UnusableValue extends RuntimeException {}
