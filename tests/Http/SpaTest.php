<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\JsonErrorHandler;
use App\Http\SessionMiddleware;
use App\Routes;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SpaTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        putenv('FINANCE_DB=' . $this->path);
    }

    protected function tearDown(): void
    {
        putenv('FINANCE_DB');
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testPagesServeTheBuiltShell(): void
    {
        $root = dirname(__DIR__, 2);
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require $root . '/config/container.php');
        $container = $builder->build();
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addErrorMiddleware(false, false, false)->setDefaultErrorHandler($container->get(JsonErrorHandler::class));
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(SessionMiddleware::class);
        $container->get(Routes::class)->register($app);

        $factory = new ServerRequestFactory();
        foreach (['/', '/accounts'] as $path) {
            $response = $app->handle($factory->createServerRequest('GET', $path));
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertStringContainsString('id="app"', (string) $response->getBody());
        }
    }
}
