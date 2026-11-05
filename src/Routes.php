<?php

declare(strict_types=1);

namespace App;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Auth\AuthService;
use App\Bill\BillService;
use App\Category\CategoryService;
use App\Dashboard\DashboardService;
use App\Demo\DemoLibrary;
use App\Envelope\EnvelopeService;
use App\Trip\PlaceSearch;
use App\Trip\TripService;
use App\Vehicle\VehicleService;
use App\PayCycle\PayCycleService;
use App\Http\AuthMiddleware;
use App\Http\Csrf;
use App\Http\CsrfMiddleware;
use App\Http\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

final class Routes
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AccountService $accounts,
        private readonly BillService $bills,
        private readonly CategoryService $categories,
        private readonly PayCycleService $payCycle,
        private readonly EnvelopeService $envelopes,
        private readonly DashboardService $dashboard,
        private readonly TripService $trips,
        private readonly PlaceSearch $places,
        private readonly VehicleService $vehicles,
        private readonly Actor $actor,
        private readonly DemoLibrary $demo,
    ) {
    }

    public function register(App $app): void
    {
        $auth = $this->auth;
        $accounts = $this->accounts;
        $bills = $this->bills;
        $categories = $this->categories;
        $payCycle = $this->payCycle;
        $envelopes = $this->envelopes;
        $dashboard = $this->dashboard;
        $trips = $this->trips;
        $places = $this->places;
        $vehicles = $this->vehicles;
        $actor = $this->actor;
        $demo = $this->demo;

        $app->group('/api', function (RouteCollectorProxy $group) use ($auth, $accounts, $bills, $categories, $payCycle, $envelopes, $dashboard, $trips, $places, $vehicles, $actor, $demo): void {
            $group->get('/session', function (ServerRequestInterface $request, ResponseInterface $response) use ($auth) {
                return Json::write($response, [
                    'needsSetup' => $auth->needsSetup(),
                    'csrfToken' => Csrf::token(),
                    'user' => $auth->current(),
                ]);
            });

            $group->group('', function (RouteCollectorProxy $open) use ($auth): void {
                $open->post('/setup', function (ServerRequestInterface $request, ResponseInterface $response) use ($auth) {
                    $user = $auth->setup(self::body($request));

                    return Json::write($response, ['user' => $user], 201);
                });
                $open->post('/users', function (ServerRequestInterface $request, ResponseInterface $response) use ($auth) {
                    $user = $auth->createUser(self::body($request));

                    return Json::write($response, ['user' => $user], 201);
                });
                $open->post('/login', function (ServerRequestInterface $request, ResponseInterface $response) use ($auth) {
                    $user = $auth->login(self::body($request));

                    return Json::write($response, ['user' => $user]);
                });
                $open->post('/demo/enter', function (ServerRequestInterface $request, ResponseInterface $response) use ($auth) {
                    return Json::write($response, ['user' => $auth->enterDemo()]);
                });
            })->add(CsrfMiddleware::class);

            $group->group('', function (RouteCollectorProxy $secure) use ($auth, $accounts, $bills, $categories, $payCycle, $envelopes, $dashboard, $trips, $places, $vehicles, $actor, $demo): void {
                $secure->post('/logout', function (ServerRequestInterface $request, ResponseInterface $response) use ($auth) {
                    $auth->logout();

                    return Json::write($response, ['ok' => true]);
                });
                $secure->post('/demo/reset', function (ServerRequestInterface $request, ResponseInterface $response) use ($actor, $demo, $auth) {
                    $actor->requireDemo();
                    $demo->resetDemo();

                    return Json::write($response, ['user' => $auth->current()]);
                });
                $secure->patch('/settings', function (ServerRequestInterface $request, ResponseInterface $response) use ($auth) {
                    return Json::write($response, ['user' => $auth->updateSettings(self::body($request))]);
                });

                $secure->get('/places/reverse', function (ServerRequestInterface $request, ResponseInterface $response) use ($places) {
                    $params = $request->getQueryParams();
                    $lat = $params['lat'] ?? '';
                    $lon = $params['lon'] ?? '';
                    if (!is_string($lat) || !is_string($lon) || $lat === '' || $lon === '') {
                        return Json::write($response, ['place' => null]);
                    }

                    return Json::write($response, ['place' => $places->reverse($lat, $lon)]);
                });
                $secure->get('/places', function (ServerRequestInterface $request, ResponseInterface $response) use ($places) {
                    $query = $request->getQueryParams()['q'] ?? '';

                    return Json::write($response, ['places' => $places->search(is_string($query) ? $query : '')]);
                });

                $secure->get('/trips', function (ServerRequestInterface $request, ResponseInterface $response) use ($trips) {
                    $params = $request->getQueryParams();
                    $today = $params['today'] ?? '';
                    $withFuel = ($params['fuel'] ?? '1') !== '0';

                    return Json::write($response, ['trips' => $trips->list(is_string($today) ? $today : '', $withFuel)]);
                });
                $secure->get('/trips/route', function (ServerRequestInterface $request, ResponseInterface $response) use ($trips) {
                    $today = $request->getQueryParams()['today'] ?? '';

                    return Json::write($response, ['legs' => $trips->route(is_string($today) ? $today : '')]);
                });
                $secure->post('/trips', function (ServerRequestInterface $request, ResponseInterface $response) use ($trips) {
                    return Json::write($response, ['trip' => $trips->create(self::body($request))], 201);
                });
                $secure->get('/trips/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($trips) {
                    $today = $request->getQueryParams()['today'] ?? '';

                    return Json::write($response, ['trip' => $trips->get((int) $args['id'], is_string($today) ? $today : '')]);
                });
                $secure->patch('/trips/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($trips) {
                    return Json::write($response, ['trip' => $trips->update((int) $args['id'], self::body($request))]);
                });
                $secure->post('/trips/{id:[0-9]+}/locate', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($trips, $places) {
                    $today = self::body($request)['today'] ?? '';
                    $today = is_string($today) ? $today : '';
                    $trip = $trips->get((int) $args['id'], $today);
                    $hasCity = is_string($trip['city'] ?? null) && $trip['city'] !== '';
                    $hasAddress = is_string($trip['address'] ?? null) && $trip['address'] !== '';
                    if ($hasCity && $hasAddress) {
                        return Json::write($response, ['trip' => $trip]);
                    }
                    $found = $places->reverse((string) $trip['latitude'], (string) $trip['longitude']);
                    if ($found === null) {
                        return Json::write($response, ['trip' => $trip]);
                    }

                    return Json::write($response, [
                        'trip' => $trips->rememberLocality((int) $args['id'], $found['city'], $found['region'], $found['address'], $today),
                    ]);
                });
                $secure->post('/trips/{id:[0-9]+}/delete', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($trips) {
                    $body = self::body($request);
                    $confirm = ($body['confirm'] ?? false) === true || ($body['confirm'] ?? null) === 1 || ($body['confirm'] ?? null) === '1';
                    $trips->delete((int) $args['id'], $confirm);

                    return Json::write($response, ['ok' => true]);
                });

                $secure->get('/vehicles', function (ServerRequestInterface $request, ResponseInterface $response) use ($vehicles) {
                    return Json::write($response, ['vehicles' => $vehicles->list()]);
                });
                $secure->post('/vehicles', function (ServerRequestInterface $request, ResponseInterface $response) use ($vehicles) {
                    return Json::write($response, ['vehicle' => $vehicles->create(self::body($request))], 201);
                });
                $secure->get('/vehicles/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($vehicles) {
                    return Json::write($response, ['vehicle' => $vehicles->get((int) $args['id'])]);
                });
                $secure->patch('/vehicles/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($vehicles) {
                    return Json::write($response, ['vehicle' => $vehicles->update((int) $args['id'], self::body($request))]);
                });

                $secure->get('/accounts', function (ServerRequestInterface $request, ResponseInterface $response) use ($accounts) {
                    $include = ($request->getQueryParams()['includeArchived'] ?? '') === '1';

                    return Json::write($response, ['accounts' => $accounts->list($include)]);
                });
                $secure->post('/accounts', function (ServerRequestInterface $request, ResponseInterface $response) use ($accounts) {
                    return Json::write($response, ['account' => $accounts->create(self::body($request))], 201);
                });
                $secure->get('/accounts/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($accounts) {
                    return Json::write($response, ['account' => $accounts->get((int) $args['id'])]);
                });
                $secure->patch('/accounts/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($accounts) {
                    return Json::write($response, ['account' => $accounts->update((int) $args['id'], self::body($request))]);
                });
                $secure->post('/accounts/{id:[0-9]+}/archive', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($accounts) {
                    return Json::write($response, ['account' => $accounts->archive((int) $args['id'])]);
                });
                $secure->post('/accounts/{id:[0-9]+}/unarchive', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($accounts) {
                    return Json::write($response, ['account' => $accounts->unarchive((int) $args['id'])]);
                });

                $secure->get('/bills', function (ServerRequestInterface $request, ResponseInterface $response) use ($bills) {
                    return Json::write($response, ['bills' => $bills->list()]);
                });
                $secure->get('/bills/calendar', function (ServerRequestInterface $request, ResponseInterface $response) use ($bills) {
                    $month = $request->getQueryParams()['month'] ?? '';

                    return Json::write($response, ['occurrences' => $bills->calendar(is_string($month) ? $month : '')]);
                });
                $secure->post('/bills', function (ServerRequestInterface $request, ResponseInterface $response) use ($bills) {
                    return Json::write($response, ['bill' => $bills->create(self::body($request))], 201);
                });
                $secure->get('/bills/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($bills) {
                    return Json::write($response, ['bill' => $bills->get((int) $args['id'])]);
                });
                $secure->patch('/bills/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($bills) {
                    return Json::write($response, ['bill' => $bills->update((int) $args['id'], self::body($request))]);
                });
                $secure->post('/bills/{id:[0-9]+}/paid', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($bills) {
                    return Json::write($response, ['bill' => $bills->markPaid((int) $args['id'], self::body($request))]);
                });
                $secure->post('/bills/{id:[0-9]+}/pause', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($bills) {
                    return Json::write($response, ['bill' => $bills->pause((int) $args['id'], self::body($request))]);
                });
                $secure->post('/bills/{id:[0-9]+}/resume', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($bills) {
                    return Json::write($response, ['bill' => $bills->resume((int) $args['id'], self::body($request))]);
                });
                $secure->post('/bills/{id:[0-9]+}/unpay', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($bills) {
                    return Json::write($response, ['bill' => $bills->unpay((int) $args['id'])]);
                });
                $secure->post('/bills/{id:[0-9]+}/archive', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($bills) {
                    return Json::write($response, ['bill' => $bills->archiveBill((int) $args['id'], self::body($request))]);
                });
                $secure->post('/bills/{id:[0-9]+}/unarchive', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($bills) {
                    return Json::write($response, ['bill' => $bills->unarchiveBill((int) $args['id'], self::body($request))]);
                });
                $secure->post('/logs', function (ServerRequestInterface $request, ResponseInterface $response) use ($bills) {
                    return Json::write($response, ['bill' => $bills->log(self::body($request))], 201);
                });

                $secure->get('/categories', function (ServerRequestInterface $request, ResponseInterface $response) use ($categories) {
                    $query = $request->getQueryParams();
                    $include = ($query['includeArchived'] ?? '') === '1';
                    $today = is_string($query['today'] ?? null) ? $query['today'] : null;

                    return Json::write($response, ['categories' => $categories->list($include, $today)]);
                });
                $secure->post('/categories', function (ServerRequestInterface $request, ResponseInterface $response) use ($categories) {
                    $body = self::body($request);
                    $today = is_string($body['today'] ?? null) ? $body['today'] : null;

                    return Json::write($response, ['category' => $categories->create($body, $today)], 201);
                });
                $secure->get('/categories/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($categories) {
                    $today = $request->getQueryParams()['today'] ?? null;

                    return Json::write($response, [
                        'category' => $categories->get((int) $args['id'], is_string($today) ? $today : null),
                    ]);
                });
                $secure->patch('/categories/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($categories) {
                    $body = self::body($request);
                    $today = is_string($body['today'] ?? null) ? $body['today'] : null;

                    return Json::write($response, ['category' => $categories->update((int) $args['id'], $body, $today)]);
                });
                $secure->post('/categories/{id:[0-9]+}/archive', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($categories) {
                    return Json::write($response, ['category' => $categories->archive((int) $args['id'])]);
                });
                $secure->post('/categories/{id:[0-9]+}/unarchive', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($categories) {
                    return Json::write($response, ['category' => $categories->unarchive((int) $args['id'])]);
                });

                $secure->get('/pay-cycles/month', function (ServerRequestInterface $request, ResponseInterface $response) use ($payCycle) {
                    $query = $request->getQueryParams();

                    return Json::write($response, [
                        'paydays' => $payCycle->monthPay((string) ($query['month'] ?? ''), (string) ($query['today'] ?? '')),
                    ]);
                });
                $secure->get('/pay-cycles', function (ServerRequestInterface $request, ResponseInterface $response) use ($payCycle) {
                    $query = $request->getQueryParams();
                    $include = ($query['includeArchived'] ?? '') === '1';

                    return Json::write($response, [
                        'payCycles' => $payCycle->list((string) ($query['today'] ?? ''), $include),
                    ]);
                });
                $secure->post('/pay-cycles', function (ServerRequestInterface $request, ResponseInterface $response) use ($payCycle) {
                    $body = self::body($request);

                    return Json::write($response, [
                        'payCycle' => $payCycle->create($body, (string) ($body['today'] ?? '')),
                    ], 201);
                });
                $secure->get('/pay-cycles/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($payCycle) {
                    $today = (string) ($request->getQueryParams()['today'] ?? '');

                    return Json::write($response, ['payCycle' => $payCycle->get((int) $args['id'], $today)]);
                });
                $secure->patch('/pay-cycles/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($payCycle) {
                    $body = self::body($request);

                    return Json::write($response, [
                        'payCycle' => $payCycle->update((int) $args['id'], $body, (string) ($body['today'] ?? '')),
                    ]);
                });
                $secure->post('/pay-cycles/{id:[0-9]+}/archive', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($payCycle) {
                    $body = self::body($request);

                    return Json::write($response, [
                        'payCycle' => $payCycle->archive((int) $args['id'], (string) ($body['today'] ?? '')),
                    ]);
                });
                $secure->get('/pay-cycles/{id:[0-9]+}/payroll', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($payCycle) {
                    $today = (string) ($request->getQueryParams()['today'] ?? '');

                    return Json::write($response, ['payroll' => $payCycle->payroll((int) $args['id'], $today)]);
                });
                $secure->put('/pay-cycles/{id:[0-9]+}/payroll', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($payCycle) {
                    $body = self::body($request);

                    return Json::write($response, [
                        'payroll' => $payCycle->savePayroll((int) $args['id'], $body, (string) ($body['today'] ?? '')),
                    ]);
                });
                $secure->post('/pay-cycles/{id:[0-9]+}/unarchive', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($payCycle) {
                    $body = self::body($request);

                    return Json::write($response, [
                        'payCycle' => $payCycle->unarchive((int) $args['id'], (string) ($body['today'] ?? '')),
                    ]);
                });

                $secure->get('/envelopes', function (ServerRequestInterface $request, ResponseInterface $response) use ($envelopes) {
                    $query = $request->getQueryParams();
                    $payCycleId = isset($query['payCycleId']) && $query['payCycleId'] !== '' ? (int) $query['payCycleId'] : null;
                    $periodId = isset($query['periodId']) && $query['periodId'] !== '' ? (int) $query['periodId'] : null;

                    return Json::write($response, $envelopes->show(
                        (string) ($query['today'] ?? ''),
                        $payCycleId,
                        $periodId,
                        ($query['upcoming'] ?? '') === '1',
                    ));
                });
                $secure->put('/envelopes', function (ServerRequestInterface $request, ResponseInterface $response) use ($envelopes) {
                    return Json::write($response, $envelopes->save(self::body($request)));
                });

                $secure->get('/dashboard', function (ServerRequestInterface $request, ResponseInterface $response) use ($dashboard) {
                    $query = $request->getQueryParams();
                    $payCycleId = isset($query['payCycleId']) && $query['payCycleId'] !== '' ? (int) $query['payCycleId'] : null;
                    $periodId = isset($query['periodId']) && $query['periodId'] !== '' ? (int) $query['periodId'] : null;

                    return Json::write($response, $dashboard->show(
                        (string) ($query['today'] ?? ''),
                        $payCycleId,
                        $periodId,
                        ($query['upcoming'] ?? '') === '1',
                    ));
                });
            })->add(AuthMiddleware::class)->add(CsrfMiddleware::class);
        });

        $app->get('/', [$this, 'page']);
        $app->get('/{path:.*}', [$this, 'page']);
    }

    public function page(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $index = dirname(__DIR__) . '/public/build/index.html';
        if (!is_file($index)) {
            $response->getBody()->write('UI is not built. In dev, open http://127.0.0.1:5173');

            return $response->withStatus(503)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
        $response->getBody()->write((string) file_get_contents($index));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** @return array<string, mixed> */
    private static function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }
}
