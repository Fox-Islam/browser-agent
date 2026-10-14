<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Reader;

use Phox\BrowserAgent\Cdp\CdpException;
use Phox\BrowserAgent\Cdp\CdpSession;

/**
 * The visible part of the page as a JPEG. On a hosted browser each capture is a round trip that
 * carries the image back, so it is taken only for a decision that is sent one.
 */
final readonly class ScreenCapture
{
    private const int QUALITY = 72;

    public function __construct(private CdpSession $cdp) {}

    /**
     * JPEG bytes, or null when the browser could not take one. A decision without its screenshot
     * is still a decision, so a failed capture is not an error.
     */
    public function jpeg(): ?string
    {
        try {
            $data = $this->cdp->send('Page.captureScreenshot', ['format' => 'jpeg', 'quality' => self::QUALITY])['data'] ?? null;
        } catch (CdpException) {
            return null;
        }
        $bytes = is_string($data) ? base64_decode($data, true) : false;

        return $bytes === false || $bytes === '' ? null : $bytes;
    }
}
