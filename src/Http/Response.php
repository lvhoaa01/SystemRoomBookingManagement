<?php

declare(strict_types=1);

namespace App\Http;

use JsonException;

final readonly class Response
{
    /** @param array<string, string> $headers */
    private function __construct(
        private string $body,
        private int $status,
        private array $headers,
    ) {
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8', ...$headers]);
    }

    /** @throws JsonException */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8', ...$headers],
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new \InvalidArgumentException('Mã trạng thái redirect không hợp lệ.');
        }
        return new self('', $status, ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->body, $this->status, [...$this->headers, $name => $value]);
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
