<?php

namespace Prospektweb\PropModificator\Infrastructure\Http;

final class SessionStorage
{
    /** @param array<string,mixed>|null $session */
    public function __construct(private ?array &$session = null)
    {
        if ($this->session === null) {
            $this->session = &$_SESSION;
        }
    }

    public static function fromGlobals(): self
    {
        return new self($_SESSION);
    }

    public function has(string $key): bool
    {
        return isset($this->session[$key]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->session[$key] = $value;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        if (!isset($this->session[$key])) {
            return $default;
        }

        $value = $this->session[$key];
        unset($this->session[$key]);

        return $value;
    }
}
