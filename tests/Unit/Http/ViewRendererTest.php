<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Shared\Http\FlashBag;
use App\Shared\Http\SafeHtml;
use App\Shared\Http\ViewRenderer;
use Tests\Support\TestCase;

final class ViewRendererTest extends TestCase
{
    public function test_escapes_strings_by_default_and_allows_explicit_safe_html(): void
    {
        $renderer = new ViewRenderer(dirname(__DIR__, 2) . '/Fixtures/views');

        $escaped = $renderer->render('unsafe', [
            'title' => '<script>alert(1)</script>',
            'description' => 'A & B',
        ]);
        $safe = $renderer->render('unsafe', [
            'title' => new SafeHtml('<strong>An toàn đã kiểm soát</strong>'),
            'description' => 'Mô tả',
        ]);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $escaped);
        self::assertStringContainsString('A &amp; B', $escaped);
        self::assertStringContainsString('<strong>An toàn đã kiểm soát</strong>', $safe);
    }

    public function test_flash_messages_are_consumed_once(): void
    {
        $flash = new FlashBag();
        $flash->add('success', 'Đã lưu');

        self::assertSame(['Đã lưu'], $flash->consume('success'));
        self::assertSame([], $flash->consume('success'));
    }
}
