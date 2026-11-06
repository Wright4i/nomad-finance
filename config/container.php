<?php

declare(strict_types=1);

use App\Auth\AuthService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Money\CurrencyCatalog;

return [
    'settings' => require __DIR__ . '/settings.php',
    PDO::class => static function (Psr\Container\ContainerInterface $container): PDO {
        $settings = $container->get('settings');

        return Database::connect($settings['db'], $settings['migrations']);
    },
    AuthService::class => static function (Psr\Container\ContainerInterface $container): AuthService {
        $settings = $container->get('settings');

        return new AuthService(
            $container->get(PDO::class),
            $container->get(CurrencyCatalog::class),
            $container->get(DemoLibrary::class),
            ($settings['allowNewUser'] ?? false) === true,
        );
    },
];
