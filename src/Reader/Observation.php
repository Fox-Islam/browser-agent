<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Reader;

/**
 * The page as the page reader saw it at one instant. The page key and guards are opaque and
 * compared for equality only; a document-mode observation has neither.
 */
final readonly class Observation
{
    /**
     * @param  list<Action>  $actions
     * @param  array<int, mixed>  $guards
     * @param  list<array{level: int, text: string}>  $outline
     * @param  array{outline: int, text: int, actions: int}|null  $truncated  what document mode dropped to fit its budget
     */
    public function __construct(
        public string $url,
        public string $title,
        public int $viewportWidth,
        public int $viewportHeight,
        public int $scrollY,
        public int $scrollHeight,
        public string $text,
        public array $actions,
        public int $omittedActions,
        public mixed $pageKey,
        public array $guards,
        public string $fingerprint = '',
        public ReadingMode $mode = ReadingMode::Viewport,
        public array $outline = [],
        public ?array $truncated = null,
    ) {}

    /**
     * @param  array<string, mixed>  $observation
     */
    public static function fromArray(array $observation): self
    {
        return new self(
            url: $observation['url'],
            title: $observation['title'],
            viewportWidth: $observation['viewport']['w'],
            viewportHeight: $observation['viewport']['h'],
            scrollY: $observation['scroll']['y'],
            scrollHeight: $observation['scroll']['height'],
            text: $observation['text'],
            actions: array_map(Action::fromArray(...), $observation['actions']),
            omittedActions: $observation['omitted_actions'],
            pageKey: $observation['page_key'] ?? null,
            guards: $observation['guards'] ?? [],
            fingerprint: self::fingerprintOf($observation),
            mode: ReadingMode::from($observation['mode'] ?? 'viewport'),
            outline: $observation['outline'] ?? [],
            truncated: $observation['truncated'] ?? null,
        );
    }

    public function action(string $id): ?Action
    {
        foreach ($this->actions as $action) {
            if ($action->id === $id) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Whether a user could act on anything. An unhydrated page carries the wait entry, so
     * having actions is no evidence on its own.
     */
    public function isOperable(): bool
    {
        foreach ($this->actions as $action) {
            if ($action->operatesControl()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same observation with $actions in place of its own.
     *
     * @param  list<Action>  $actions
     */
    public function withActions(array $actions): self
    {
        return new self(
            $this->url, $this->title, $this->viewportWidth, $this->viewportHeight, $this->scrollY, $this->scrollHeight,
            $this->text, $actions, $this->omittedActions, $this->pageKey, $this->guards, $this->fingerprint,
            $this->mode, $this->outline, $this->truncated,
        );
    }

    public function expandedCount(): int
    {
        return count(array_filter($this->actions, fn (Action $a) => $a->expanded === 'true'));
    }

    public function guard(int $node): mixed
    {
        return $this->guards[$node] ?? null;
    }

    /**
     * The page's semantic state: address, text, controls and scroll position. Equal fingerprints
     * mean the page a decision was taken about is the current page. Control geometry
     * is left out: an animation moves it without changing what the page offers, and the executor
     * reads geometry again before any input.
     *
     * @param  array<string, mixed>  $observation
     */
    private static function fingerprintOf(array $observation): string
    {
        $actions = array_map(function (array $action): array {
            unset($action['rect']);

            return $action;
        }, $observation['actions']);
        $content = [$observation['url'], $observation['text'], $actions, $observation['scroll']];

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
    }
}
