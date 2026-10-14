<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

/**
 * When a decision is sent a screenshot of the page. Off sends none; Always sends one with every
 * decision; Auto sends one only where {@see Looking} finds the text cannot settle the decision.
 */
enum ImageMode: string
{
    case Off = 'off';
    case Always = 'always';
    case Auto = 'auto';
}
