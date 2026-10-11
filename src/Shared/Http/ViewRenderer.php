<?php

declare(strict_types=1);

namespace App\Shared\Http;

use RuntimeException;

final readonly class ViewRenderer
{
    public function __construct(private string $viewsPath)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = [], ?string $layout = null): string
    {
        $content = $this->renderFile($view, $data);
        if ($layout === null) {
            return $content;
        }

        return $this->renderFile($layout, [
            ...$data,
            'content' => new SafeHtml($content),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $view, array $data): string
    {
        if (preg_match('/^[A-Za-z0-9_\/-]+$/', $view) !== 1 || str_contains($view, '..')) {
            throw new RuntimeException('Tên view không hợp lệ.');
        }

        $path = rtrim($this->viewsPath, '/\\') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $view) . '.php';
        if (!is_file($path)) {
            throw new RuntimeException("Không tìm thấy view: {$view}");
        }

        $escaped = [];
        foreach ($data as $key => $value) {
            $escaped[$key] = $this->escapeValue($value);
        }

        extract($escaped, EXTR_SKIP);
        ob_start();
        try {
            require $path;
            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }

    private function escapeValue(mixed $value): mixed
    {
        if ($value instanceof SafeHtml) {
            return $value->value;
        }
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->escapeValue($item), $value);
        }
        return $value;
    }
}
