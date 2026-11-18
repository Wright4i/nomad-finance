<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Auth\AuthService;
use App\Database\Database;
use App\Demo\DemoLibrary;
use App\Http\JsonErrorHandler;
use App\Http\SessionMiddleware;
use App\Money\CurrencyCatalog;
use App\Routes;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HomepageTodayRouteTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nomad-finance-' . bin2hex(random_bytes(4)) . '.sqlite';
        putenv('FINANCE_DB=' . $this->path);
        putenv('FINANCE_HOMEPAGE_TOKEN=homepage-test-secret');
    }

    protected function tearDown(): void
    {
        putenv('FINANCE_DB');
        putenv('FINANCE_HOMEPAGE_TOKEN');
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testRouteRejectsMissingTokenAndAcceptsValidToken(): void
    {
        $pdo = Database::connect($this->path, dirname(__DIR__, 2) . '/db/migrations');
        $catalog = new CurrencyCatalog();
        $auth = new AuthService($pdo, $catalog, new DemoLibrary($pdo, $catalog));
        $auth->setup([
            'name' => 'Ada',
            'password' => 'correct horse battery',
            'baseCurrency' => 'USD',
        ]);

        $app = $this->app();
        $factory = new ServerRequestFactory();

        $missing = $app->handle($factory->createServerRequest('GET', '/api/homepage/today'));
        self::assertSame(401, $missing->getStatusCode());

        $bad = $app->handle(
            $factory->createServerRequest('GET', '/api/homepage/today')
                ->withHeader('X-Homepage-Token', 'wrong')
        );
        self::assertSame(401, $bad->getStatusCode());

        $ok = $app->handle(
            $factory->createServerRequest('GET', '/api/homepage/today')
                ->withHeader('X-Homepage-Token', 'homepage-test-secret')
        );
        self::assertSame(200, $ok->getStatusCode());
        $body = json_decode((string) $ok->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['ready']);
        self::assertArrayHasKey('todayLabel', $body);
    }

    public function testRouteIsHiddenWhenTokenIsUnset(): void
    {
        putenv('FINANCE_HOMEPAGE_TOKEN');
        $app = $this->app();
        $factory = new ServerRequestFactory();
        $response = $app->handle(
            $factory->createServerRequest('GET', '/api/homepage/today')
                ->withHeader('X-Homepage-Token', 'homepage-test-secret')
        );
        self::assertSame(404, $response->getStatusCode());
    }

    private function app(): \Slim\App
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $container = $builder->build();
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addErrorMiddleware(false, false, false)->setDefaultErrorHandler($container->get(JsonErrorHandler::class));
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(SessionMiddleware::class);
        $container->get(Routes::class)->register($app);

        return $app;
    }
}
