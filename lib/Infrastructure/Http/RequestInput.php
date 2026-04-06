<?php

namespace Prospektweb\PropModificator\Infrastructure\Http;

final class RequestInput
{
    /** @param array<string,mixed> $post @param array<string,mixed> $get */
    public function __construct(private array $post = [], private array $get = [])
    {
    }

    public static function fromGlobals(): self
    {
        return new self($_POST, $_GET);
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->get[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function postAll(): array
    {
        return $this->post;
    }

    /** @return array<string,mixed> */
    public function getAll(): array
    {
        return $this->get;
    }
}
