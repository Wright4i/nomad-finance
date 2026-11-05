<?php

declare(strict_types=1);

use App\Database\Database;

return [
    'settings' => require __DIR__ . '/settings.php',
    PDO::class => static function (Psr\Container\ContainerInterface $container): PDO {
        $settings = $container->get('settings');

        return Database::connect($settings['db'], $settings['migrations']);
    },
];
