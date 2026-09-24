<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use RuntimeException;

/**
 * A model call failed or answered with something unusable. Nothing was done to the page.
 */
final class ModelException extends RuntimeException {}
