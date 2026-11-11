<?php

declare(strict_types=1);

use App\Auth\Actor;
use App\Auth\AuthService;
use App\Bill\BillService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Envelope\BillFill;
use App\Homepage\HomepageTodayService;
use App\Http\HomepageMiddleware;
use App\Money\CurrencyCatalog;
use App\Trip\Drive;

return [
    'settings' => require __DIR__ . '/settings.php',
    PDO::class => static function (Psr\Container\ContainerInterface $container): PDO {
        $settings = $container->get('settings');

        return Database::connect($settings['db'], $settings['migrations']);
    },
    BillService::class => static function (Psr\Container\ContainerInterface $container): BillService {
        $pdo = $container->get(PDO::class);
        $currencies = $container->get(CurrencyCatalog::class);
        $actor = $container->get(Actor::class);

        return new BillService($pdo, $currencies, $actor, new BillFill($pdo, $currencies, $actor, new Drive(true)));
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
    HomepageTodayService::class => static function (Psr\Container\ContainerInterface $container): HomepageTodayService {
        $settings = $container->get('settings');
        $timezone = is_string($settings['homepageTimezone'] ?? null) ? $settings['homepageTimezone'] : 'America/Los_Angeles';

        return new HomepageTodayService($container->get(PDO::class), $timezone);
    },
    HomepageMiddleware::class => static function (Psr\Container\ContainerInterface $container): HomepageMiddleware {
        return new HomepageMiddleware($container->get('settings'));
    },
];
