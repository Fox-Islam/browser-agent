<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use Phox\BrowserAgent\Cdp\CdpException;
use Phox\BrowserAgent\Cdp\CdpSession;

/**
 * The page's console errors and warnings, uncaught exceptions and failed requests, collected from
 * CDP events as they arrive, with no round trip per step.
 */
final class Faults
{
    /** A page that fails one request per image would otherwise report its whole gallery. */
    public const int KEPT = 25;

    private const int TEXT_LENGTH = 200;

    private const int FAILURE_LENGTH = 80;

    /** @var array{console: list<array<string, mixed>>, exceptions: list<array<string, mixed>>, requests: list<array<string, mixed>>} */
    private array $faults = ['console' => [], 'exceptions' => [], 'requests' => []];

    private ?int $documentStatus = null;

    private ?string $documentType = null;

    public function watch(CdpSession $cdp): void
    {
        $cdp->listen($this->note(...));
        foreach (['Log', 'Runtime', 'Network', 'Page'] as $domain) {
            try {
                $cdp->send("{$domain}.enable");
            } catch (CdpException) {
                // A target that will not report a domain has nothing of it to report.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function note(string $method, array $params): void
    {
        match ($method) {
            'Log.entryAdded' => $this->logEntry($params['entry'] ?? []),
            'Runtime.exceptionThrown' => $this->exception($params['exceptionDetails'] ?? []),
            'Network.responseReceived' => $this->response($params),
            'Network.loadingFailed' => $this->add('requests', ['url' => null, 'status' => null, 'failed' => mb_substr((string) ($params['errorText'] ?? ''), 0, self::FAILURE_LENGTH)]),
            default => null,
        };
    }

    /**
     * What went wrong, deduplicated and capped, with the document's own status and type.
     *
     * @return array<string, mixed>
     */
    public function diagnosis(): array
    {
        $out = array_filter(array_map(fn (array $kind) => array_slice($kind, 0, self::KEPT), $this->faults));

        return $out + array_filter(['document_status' => $this->documentStatus, 'document_type' => $this->documentType], fn ($v) => $v !== null);
    }

    public function documentStatus(): ?int
    {
        return $this->documentStatus;
    }

    public function documentType(): ?string
    {
        return $this->documentType;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function logEntry(array $entry): void
    {
        if (in_array($entry['level'] ?? null, ['error', 'warning'], true)) {
            $this->add('console', ['level' => $entry['level'], 'text' => mb_substr((string) ($entry['text'] ?? ''), 0, self::TEXT_LENGTH), 'url' => $entry['url'] ?? null]);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function exception(array $details): void
    {
        $thrown = $details['exception']['description'] ?? $details['text'] ?? '';
        $this->add('exceptions', ['text' => mb_substr((string) $thrown, 0, self::TEXT_LENGTH), 'url' => $details['url'] ?? null]);
    }

    /**
     * The first document response is the page's own status and type; later ones are its frames.
     *
     * @param  array<string, mixed>  $params
     */
    private function response(array $params): void
    {
        $response = $params['response'] ?? [];
        $status = (int) ($response['status'] ?? 0);
        if (($params['type'] ?? null) === 'Document' && ($response['url'] ?? '') !== '') {
            $this->documentStatus ??= $status;
            $this->documentType ??= explode(';', (string) ($response['mimeType'] ?? ''))[0] ?: null;
        }
        if ($status >= 400) {
            $this->add('requests', ['url' => mb_substr((string) ($response['url'] ?? ''), 0, self::TEXT_LENGTH), 'status' => $status]);
        }
    }

    /**
     * @param  'console'|'exceptions'|'requests'  $kind
     * @param  array<string, mixed>  $fault
     */
    private function add(string $kind, array $fault): void
    {
        if (count($this->faults[$kind]) < self::KEPT && ! in_array($fault, $this->faults[$kind], true)) {
            $this->faults[$kind][] = $fault;
        }
    }
}
