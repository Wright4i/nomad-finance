<?php

declare(strict_types=1);

use App\Http\JsonErrorHandler;
use App\Http\SessionMiddleware;
use App\Routes;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

$builder = new ContainerBuilder();
$builder->addDefinitions(require __DIR__ . '/../config/container.php');
$container = $builder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$settings = $container->get('settings');
$display = ($settings['displayErrorDetails'] ?? false) === true;
$errorMiddleware = $app->addErrorMiddleware($display, true, true);
$errorMiddleware->setDefaultErrorHandler($container->get(JsonErrorHandler::class));

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(SessionMiddleware::class);

$container->get(Routes::class)->register($app);

$app->run();
