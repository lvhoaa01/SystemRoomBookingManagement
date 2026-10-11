<?php

declare(strict_types=1);

use App\Bootstrap\Container;

return static function (Container $container): array {
    // Các tác vụ payment:expire, booking:complete và inventory:reconcile sẽ đăng ký tại đây.
    return [];
};
