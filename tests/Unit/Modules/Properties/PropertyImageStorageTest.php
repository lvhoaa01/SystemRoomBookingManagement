<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Properties;

use App\Modules\Properties\Application\PropertyImageStorage;
use App\Shared\Security\TokenGenerator;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class PropertyImageStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'property-upload-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
        parent::tearDown();
    }

    public function test_stores_decodable_image_with_random_server_side_name(): void
    {
        $source = $this->directory . DIRECTORY_SEPARATOR . 'source.png';
        file_put_contents($source, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        ));
        $storage = new PropertyImageStorage(
            $this->directory . DIRECTORY_SEPARATOR . 'stored',
            new TokenGenerator(),
            static fn (string $path): bool => is_file($path),
            static fn (string $from, string $to): bool => rename($from, $to),
        );

        $result = $storage->store('CS001', [
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
            'tmp_name' => $source,
            'name' => 'shell.php.png',
            'type' => 'application/x-php',
        ]);

        self::assertSame('image/png', $result['mime']);
        self::assertMatchesRegularExpression('#^/uploads/properties/cs001/[a-f0-9]{64}\.png$#', $result['path']);
        self::assertFileExists(
            $this->directory . DIRECTORY_SEPARATOR . 'stored' . DIRECTORY_SEPARATOR . 'cs001'
            . DIRECTORY_SEPARATOR . basename($result['path']),
        );
    }

    public function test_rejects_php_disguised_with_image_extension(): void
    {
        $source = $this->directory . DIRECTORY_SEPARATOR . 'fake.png';
        file_put_contents($source, '<?php echo "unsafe";');
        $storage = new PropertyImageStorage(
            $this->directory . DIRECTORY_SEPARATOR . 'stored',
            new TokenGenerator(),
            static fn (string $path): bool => is_file($path),
            static fn (string $from, string $to): bool => rename($from, $to),
        );

        $this->expectException(InvalidArgumentException::class);
        $storage->store('CS001', [
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
            'tmp_name' => $source,
            'name' => 'photo.png',
            'type' => 'image/png',
        ]);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
