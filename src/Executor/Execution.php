<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Executor;

final readonly class Execution
{
    private function __construct(
        public ExecutionStatus $status,
        public ?string $reason = null,
    ) {}

    public static function done(): self
    {
        return new self(ExecutionStatus::Done);
    }

    public static function stale(string $reason): self
    {
        return new self(ExecutionStatus::Stale, $reason);
    }

    public static function rejected(string $reason): self
    {
        return new self(ExecutionStatus::Rejected, $reason);
    }

    public static function altered(string $reason): self
    {
        return new self(ExecutionStatus::Altered, $reason);
    }
}
