<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Reader;

use Phox\BrowserAgent\Cdp\CdpException;
use Phox\BrowserAgent\Cdp\CdpSession;

/**
 * Finds the on-screen elements a page script handles drops on and hands them to the reader's
 * world. Handlers set from page script (an `ondrop` property, React's recorded `onDrop` and
 * `onDragOver` props) are visible only in the page's own world, so this runs a read-only lookup
 * there and passes the elements across by backend node id. It changes nothing on the page.
 */
final readonly class DropHandlers
{
    /** Elements handed across per lookup; each costs two calls. */
    private const int MOST = 20;

    private const string GROUP = 'phox-drop-handlers';

    private const string FIND = <<<'JS'
        (() => {
            const found = [];
            const handles = (el) => typeof el.ondrop === 'function' || Object.keys(el).some((key) =>
                key.startsWith('__reactProps$') && (el[key]?.onDrop || el[key]?.onDragOver));
            const walk = (root) => {
                for (const el of root.querySelectorAll('*')) {
                    if (found.length >= %d) {
                        return;
                    }
                    const box = el.getBoundingClientRect();
                    if (box.width >= 80 && box.height >= 60 && box.bottom > 0 && box.top < innerHeight && handles(el)) {
                        found.push(el);
                    }
                    if (el.shadowRoot) {
                        walk(el.shadowRoot);
                    }
                }
            };
            walk(document);
            return found;
        })()
        JS;

    private const string HAND_OVER = 'function () { return pageReader.markDropHandlers([...arguments]); }';

    public function __construct(private CdpSession $cdp) {}

    /**
     * Marks the elements in the reader's world $context and returns how many it found.
     */
    public function mark(int $context): int
    {
        try {
            $found = $this->cdp->send('Runtime.evaluate', ['expression' => sprintf(self::FIND, self::MOST), 'objectGroup' => self::GROUP]);
            $handles = array_values(array_filter(array_map(
                fn (string $id) => $this->inWorld($id, $context),
                $this->elements($found['result']['objectId'] ?? null),
            )));

            return $handles === [] ? 0 : $this->handOver($handles, $context);
        } catch (CdpException) {
            // A page that navigated mid-lookup has no handlers left to mark.
            return 0;
        } finally {
            $this->release();
        }
    }

    /**
     * @return list<string> remote object ids in the page's world
     */
    private function elements(?string $array): array
    {
        if ($array === null) {
            return [];
        }
        $properties = $this->cdp->send('Runtime.getProperties', ['objectId' => $array, 'ownProperties' => true]);
        $ids = [];
        foreach ($properties['result'] ?? [] as $property) {
            if (ctype_digit((string) ($property['name'] ?? '')) && isset($property['value']['objectId'])) {
                $ids[] = (string) $property['value']['objectId'];
            }
        }

        return $ids;
    }

    private function inWorld(string $objectId, int $context): ?string
    {
        $node = $this->cdp->send('DOM.describeNode', ['objectId' => $objectId])['node']['backendNodeId'] ?? null;
        $resolved = $node === null ? [] : $this->cdp->send('DOM.resolveNode', [
            'backendNodeId' => $node, 'executionContextId' => $context, 'objectGroup' => self::GROUP,
        ]);

        return $resolved['object']['objectId'] ?? null;
    }

    /**
     * @param  list<string>  $handles
     */
    private function handOver(array $handles, int $context): int
    {
        $this->cdp->send('Runtime.callFunctionOn', [
            'functionDeclaration' => self::HAND_OVER,
            'executionContextId' => $context,
            'arguments' => array_map(fn (string $id) => ['objectId' => $id], $handles),
            'returnByValue' => true,
        ]);

        return count($handles);
    }

    private function release(): void
    {
        try {
            $this->cdp->send('Runtime.releaseObjectGroup', ['objectGroup' => self::GROUP]);
        } catch (CdpException) {
            // Released with the page when it went.
        }
    }
}
