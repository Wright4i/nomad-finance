<?php

declare(strict_types=1);

use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Money\CurrencyCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = require dirname(__DIR__) . '/config/settings.php';
$pdo = Database::connect($settings['db'], $settings['migrations']);
(new DemoLibrary($pdo, new CurrencyCatalog()))->resetDemo();

fwrite(STDOUT, "Demo books restored.\n");
