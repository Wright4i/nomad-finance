<?php

declare(strict_types=1);

use App\Database\Database;
use App\ExchangeRate\FailingRateFeed;
use App\ExchangeRate\FileRateFeed;
use App\ExchangeRate\OpenExchangeRateFeed;
use App\ExchangeRate\RateFeed;

return [
    'settings' => require __DIR__ . '/settings.php',
    RateFeed::class => static function (): RateFeed {
        $setting = getenv('FINANCE_RATE_FEED');
        if (!is_string($setting) || $setting === '' || $setting === 'open') {
            return new OpenExchangeRateFeed();
        }
        if ($setting === 'off') {
            return new FailingRateFeed();
        }

        return new FileRateFeed($setting);
    },
    PDO::class => static function (Psr\Container\ContainerInterface $container): PDO {
        $settings = $container->get('settings');

        return Database::connect($settings['db'], $settings['migrations']);
    },
];
