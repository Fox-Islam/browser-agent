<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Replay;

use RuntimeException;

/**
 * A replay step could not be carried out on the current page.
 */
final class ReplayException extends RuntimeException {}
