<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Reader;

enum ReadingMode: string
{
    /** What is on screen, for deciding and acting. */
    case Viewport = 'viewport';

    /** The whole rendered page, to give a model as context. Nothing is acted on from it. */
    case Document = 'document';
}
