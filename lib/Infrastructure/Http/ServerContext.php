<?php

namespace Prospektweb\PropModificator\Infrastructure\Http;

final class ServerContext
{
    /** @param array<string,mixed> $server */
    public function __construct(private array $server = [])
    {
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER);
    }

    public function requestMethod(): string
    {
        return strtoupper((string)($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function requestUri(): string
    {
        return (string)($this->server['REQUEST_URI'] ?? '/');
    }

    public function documentRoot(): string
    {
        return (string)($this->server['DOCUMENT_ROOT'] ?? '');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }
}
