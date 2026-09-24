<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Cdp;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnection as Socket;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Closure;
use Throwable;

use function Amp\async;

/**
 * CDP over amphp's WebSocket client. A background fiber reads every message and completes the
 * waiting call by id, so each call waits on its own deadline and a silent socket fails the call
 * instead of hanging it. Nothing here retries: a repeated Input or Runtime call could resubmit a
 * form on the page.
 */
final class WebSocketConnection implements CdpConnection
{
    /** Chrome sends a large response, a screenshot or a big evaluate result, as one frame. */
    public const int DEFAULT_MESSAGE_LIMIT = 64 * 1024 * 1024;

    /** @var array<int, DeferredFuture<array<string, mixed>>> */
    private array $pending = [];

    private int $nextId = 0;

    /** @var array<string, list<Closure(string, array<string, mixed>): void>> */
    private array $listeners = [];

    private ?string $closedBecause = null;

    private function __construct(
        private readonly Socket $socket,
        private readonly float $timeout,
    ) {}

    /**
     * @param  array<string, string>  $headers  sent with the handshake, for example Authorization
     */
    public static function connect(
        string $url,
        array $headers = [],
        float $timeout = 30.0,
        float $connectTimeout = 10.0,
        int $messageLimit = self::DEFAULT_MESSAGE_LIMIT,
    ): self {
        $connector = new Rfc6455Connector(new Rfc6455ConnectionFactory(
            parserFactory: new Rfc6455ParserFactory(messageSizeLimit: $messageLimit, frameSizeLimit: $messageLimit),
        ));
        try {
            $socket = $connector->connect(new WebsocketHandshake($url, $headers), new TimeoutCancellation($connectTimeout));
        } catch (CancelledException) {
            throw new CdpTimeoutException("Connecting to the browser took longer than {$connectTimeout}s");
        } catch (Throwable $e) {
            // A refused handshake, such as 410 Gone for a hosted session that has ended.
            throw new CdpException("Connecting to the browser failed: {$e->getMessage()}", previous: $e);
        }
        $connection = new self($socket, $timeout);
        async($connection->receive(...))->ignore();

        return $connection;
    }

    public function call(string $method, array $params = [], ?string $sessionId = null, ?float $timeout = null): array
    {
        $this->ensureOpen($method);
        $timeout ??= $this->timeout;
        $id = ++$this->nextId;
        $answer = $this->pending[$id] = new DeferredFuture;
        $message = ['id' => $id, 'method' => $method, 'params' => (object) $params];
        if ($sessionId !== null) {
            $message['sessionId'] = $sessionId;
        }
        try {
            $deadline = new TimeoutCancellation($timeout);
            async(fn () => $this->socket->sendText(json_encode($message, JSON_THROW_ON_ERROR)))->await($deadline);
            $response = $answer->getFuture()->await($deadline);
        } catch (CancelledException) {
            throw new CdpTimeoutException("{$method} got no answer within {$timeout}s");
        } catch (CdpException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CdpException("{$method}: {$e->getMessage()}", previous: $e);
        } finally {
            unset($this->pending[$id]);
        }

        return self::result($method, $response);
    }

    public function listen(string $sessionId, Closure $listener): void
    {
        $this->listeners[$sessionId][] = $listener;
    }

    public function close(): void
    {
        $this->closedBecause ??= 'closed by the caller';
        $this->socket->close();
        $this->failPending();
    }

    public function isClosed(): bool
    {
        return $this->closedBecause !== null;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private static function result(string $method, array $response): array
    {
        if (isset($response['error'])) {
            throw new CdpException("{$method}: {$response['error']['message']}", (int) $response['error']['code']);
        }

        return $response['result'] ?? [];
    }

    private function receive(): void
    {
        try {
            while (($message = $this->socket->receive()) !== null) {
                $this->dispatch(json_decode($message->buffer(), true, flags: JSON_THROW_ON_ERROR));
            }
            $this->closedBecause ??= 'the browser closed the connection: ' . $this->socket->getCloseInfo()->getReason();
        } catch (Throwable $e) {
            $this->closedBecause ??= 'the connection failed: ' . $e->getMessage();
            $this->socket->close();
        }
        $this->failPending();
    }

    /**
     * Events carry no id and go to the listeners of their session. They are handled while a call
     * is waiting, since that is when the event loop runs.
     *
     * @param  array<string, mixed>  $message
     */
    private function dispatch(array $message): void
    {
        if (! isset($message['id'])) {
            foreach ($this->listeners[$message['sessionId'] ?? ''] ?? [] as $listener) {
                $listener((string) ($message['method'] ?? ''), $message['params'] ?? []);
            }

            return;
        }
        $answer = $this->pending[$message['id']] ?? null;
        if ($answer !== null && ! $answer->isComplete()) {
            $answer->complete($message);
        }
    }

    private function failPending(): void
    {
        foreach ($this->pending as $answer) {
            if (! $answer->isComplete()) {
                $answer->error(new CdpException("CDP connection closed: {$this->closedBecause}"));
            }
        }
        $this->pending = [];
    }

    private function ensureOpen(string $method): void
    {
        if ($this->closedBecause !== null) {
            throw new CdpException("{$method}: CDP connection closed: {$this->closedBecause}");
        }
    }
}
