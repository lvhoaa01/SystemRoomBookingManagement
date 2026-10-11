<?php

declare(strict_types=1);

namespace App\Config;

use DateTimeZone;
use RuntimeException;

final readonly class AppConfig
{
    public function __construct(
        public string $environment,
        public bool $debug,
        public string $url,
        public DateTimeZone $businessTimezone,
        public string $sessionName = 'accommodation_booking',
        public int $sessionLifetimeSeconds = 7200,
    ) {
        if (in_array(strtolower($this->environment), ['production', 'prod'], true) && $this->debug) {
            throw new RuntimeException('Không được bật APP_DEBUG trong môi trường production.');
        }

        if (filter_var($this->url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('APP_URL không hợp lệ.');
        }

        if ($this->sessionLifetimeSeconds < 300) {
            throw new RuntimeException('Thời gian sống session phải từ 300 giây.');
        }
    }

    public static function fromEnv(): self
    {
        $timezone = Env::require('APP_TIMEZONE');

        try {
            $businessTimezone = new DateTimeZone($timezone);
        } catch (\Exception) {
            throw new RuntimeException('APP_TIMEZONE không hợp lệ.');
        }

        return new self(
            Env::require('APP_ENV'),
            Env::bool('APP_DEBUG'),
            Env::require('APP_URL'),
            $businessTimezone,
            Env::get('SESSION_NAME', 'accommodation_booking') ?? 'accommodation_booking',
            (int) (Env::get('SESSION_LIFETIME_SECONDS', '7200') ?? '7200'),
        );
    }

    public function usesSecureCookies(): bool
    {
        return strtolower((string) parse_url($this->url, PHP_URL_SCHEME)) === 'https';
    }
}
