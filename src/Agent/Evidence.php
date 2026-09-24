<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use Phox\BrowserAgent\Cdp\CdpException;
use Phox\BrowserAgent\Reader\PageReader;
use Phox\BrowserAgent\Reader\ReaderException;

/**
 * What a stopped run points at, taken from the page instead of written by the model: a model
 * asked to describe it would be writing the answer it is checked against.
 */
final readonly class Evidence
{
    /** Enough of the answering element's text to show what was found, bounded against prose. */
    public const int TEXT = 1024;

    public function __construct(
        private PageReader $reader,
        private Faults $faults,
    ) {}

    /**
     * Always carries the document's address, title, first heading, status and type, so a page that
     * failed can be told from one that is an image or a file. The element the last decision named
     * is added when there is one; otherwise the page's text is.
     *
     * @return array<string, mixed>
     */
    public function of(RunState $state): array
    {
        $page = $state->page;
        try {
            $named = $this->reader->identity();
        } catch (CdpException|ReaderException) {
            $named = ['title' => null, 'h1' => null];
        }
        $found = [
            'url' => $page->url,
            'title' => $named['title'] ?? $page->title,
            'h1' => $named['h1'],
            'document_status' => $this->faults->documentStatus(),
            'document_type' => $this->faults->documentType(),
        ];
        $last = $state->decisions === [] ? null : end($state->decisions);
        $chosen = $last === null ? null : $page->action($last->choice);
        if ($chosen?->node === null) {
            return $found + ['text' => mb_substr($page->text, 0, self::TEXT)];
        }

        return $found + ['element' => [
            'label' => $chosen->label,
            'role' => $chosen->role,
            'value' => $chosen->value,
            'text' => mb_substr($this->textOf((int) $chosen->node), 0, self::TEXT),
        ]];
    }

    private function textOf(int $node): string
    {
        try {
            return $this->reader->textOf($node);
        } catch (CdpException|ReaderException) {
            return '';
        }
    }
}
