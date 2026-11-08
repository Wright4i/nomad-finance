<?php

declare(strict_types=1);

namespace App;

use App\Account\AccountService;
use App\Auth\Actor;
use App\Auth\AuthService;
use App\Bill\BillService;
use App\Calendar\CalendarEntryService;
use App\Category\CategoryService;
use App\Dashboard\DashboardService;
use App\Demo\DemoLibrary;
use App\Envelope\EnvelopeService;
use App\Transfer\TransferService;
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
        private readonly CalendarEntryService $entries,
        private readonly Actor $actor,
        private readonly DemoLibrary $demo,
        private readonly TransferService $transfers,
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
        $entries = $this->entries;
        $actor = $this->actor;
        $demo = $this->demo;
        $transfers = $this->transfers;

        $app->group('/api', function (RouteCollectorProxy $group) use ($auth, $accounts, $bills, $categories, $payCycle, $envelopes, $dashboard, $trips, $places, $vehicles, $entries, $actor, $demo, $transfers): void {
            $group->get('/session', function (ServerRequestInterface $request, ResponseInterface $response) use ($auth) {
                return Json::write($response, [
                    'needsSetup' => $auth->needsSetup(),
                    'allowNewUser' => $auth->allowsNewUser(),
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

            $group->group('', function (RouteCollectorProxy $secure) use ($auth, $accounts, $bills, $categories, $payCycle, $envelopes, $dashboard, $trips, $places, $vehicles, $entries, $actor, $demo, $transfers): void {
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
                $secure->post('/trips/{id:[0-9]+}/route', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($trips) {
                    $direction = self::body($request)['direction'] ?? '';
                    $trips->move((int) $args['id'], is_string($direction) ? $direction : '');

                    return Json::write($response, ['ok' => true]);
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
                $secure->get('/calendar-entries', function (ServerRequestInterface $request, ResponseInterface $response) use ($entries) {
                    $params = $request->getQueryParams();
                    $start = $params['start'] ?? '';
                    $end = $params['end'] ?? '';
                    if (is_string($start) && is_string($end) && $start !== '' && $end !== '') {
                        return Json::write($response, ['entries' => $entries->between($start, $end)]);
                    }
                    $month = $params['month'] ?? '';

                    return Json::write($response, ['entries' => $entries->month(is_string($month) ? $month : '')]);
                });
                $secure->post('/calendar-entries', function (ServerRequestInterface $request, ResponseInterface $response) use ($entries) {
                    return Json::write($response, ['entry' => $entries->create(self::body($request))], 201);
                });
                $secure->patch('/calendar-entries/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($entries) {
                    return Json::write($response, ['entry' => $entries->update((int) $args['id'], self::body($request))]);
                });
                $secure->post('/calendar-entries/{id:[0-9]+}/delete', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($entries) {
                    $entries->delete((int) $args['id']);

                    return Json::write($response, ['ok' => true]);
                });

                $secure->post('/trips/{id:[0-9]+}/delete', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($trips) {
                    $body = self::body($request);
                    $confirm = ($body['confirm'] ?? false) === true || ($body['confirm'] ?? null) === 1 || ($body['confirm'] ?? null) === '1';
                    $trips->delete((int) $args['id'], $confirm);

                    return Json::write($response, ['ok' => true]);
                });

                $secure->get('/vehicles', function (ServerRequestInterface $request, ResponseInterface $response) use ($vehicles) {
                    $include = ($request->getQueryParams()['includeArchived'] ?? '') === '1';

                    return Json::write($response, ['vehicles' => $vehicles->list($include)]);
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
                $secure->post('/vehicles/{id:[0-9]+}/archive', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($vehicles) {
                    return Json::write($response, ['vehicle' => $vehicles->archive((int) $args['id'])]);
                });
                $secure->post('/vehicles/{id:[0-9]+}/unarchive', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($vehicles) {
                    return Json::write($response, ['vehicle' => $vehicles->unarchive((int) $args['id'])]);
                });
                $secure->post('/vehicles/{id:[0-9]+}/delete', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($vehicles) {
                    $body = self::body($request);
                    $confirm = ($body['confirm'] ?? false) === true || ($body['confirm'] ?? null) === 1 || ($body['confirm'] ?? null) === '1';
                    $vehicles->delete((int) $args['id'], $confirm);

                    return Json::write($response, ['ok' => true]);
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
                $secure->post('/accounts/{id:[0-9]+}/delete', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($accounts) {
                    $body = self::body($request);
                    $confirm = ($body['confirm'] ?? false) === true || ($body['confirm'] ?? null) === 1 || ($body['confirm'] ?? null) === '1';
                    $accounts->delete((int) $args['id'], $confirm);

                    return Json::write($response, ['ok' => true]);
                });

                $secure->get('/bills', function (ServerRequestInterface $request, ResponseInterface $response) use ($bills) {
                    $params = $request->getQueryParams();
                    $section = $params['section'] ?? '';
                    if (is_string($section) && $section !== '') {
                        $chunks = $params['chunks'] ?? 1;
                        $filter = [
                            'name' => is_string($params['name'] ?? null) ? $params['name'] : '',
                            'accountId' => is_numeric($params['accountId'] ?? null) ? (int) $params['accountId'] : 0,
                            'type' => is_string($params['type'] ?? null) ? $params['type'] : '',
                            'amount' => is_string($params['amount'] ?? null) ? $params['amount'] : '',
                        ];
                        $start = is_string($params['start'] ?? null) ? $params['start'] : null;
                        $end = is_string($params['end'] ?? null) ? $params['end'] : null;
                        $today = is_string($params['today'] ?? null) ? $params['today'] : null;

                        return Json::write($response, $bills->section($section, is_numeric($chunks) ? (int) $chunks : 1, $filter, $start, $end, $today));
                    }

                    return Json::write($response, ['bills' => $bills->list()]);
                });
                $secure->get('/bills/calendar', function (ServerRequestInterface $request, ResponseInterface $response) use ($bills) {
                    $params = $request->getQueryParams();
                    $start = $params['start'] ?? '';
                    $end = $params['end'] ?? '';
                    if (is_string($start) && is_string($end) && $start !== '' && $end !== '') {
                        return Json::write($response, ['occurrences' => $bills->calendarBetween($start, $end)]);
                    }
                    $month = $params['month'] ?? '';

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
                $secure->patch('/bills/{id:[0-9]+}/fuel', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($bills) {
                    return Json::write($response, ['bill' => $bills->updateFuel((int) $args['id'], self::body($request))]);
                });
                $secure->post('/bills/{id:[0-9]+}/fuel/delete', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($bills) {
                    $body = self::body($request);
                    $confirm = ($body['confirm'] ?? false) === true || ($body['confirm'] ?? null) === 1 || ($body['confirm'] ?? null) === '1';
                    $bills->deleteFuel((int) $args['id'], $confirm, $body);

                    return Json::write($response, ['ok' => true]);
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
                $secure->post('/bills/{id:[0-9]+}/delete', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($bills) {
                    $body = self::body($request);
                    $confirm = ($body['confirm'] ?? false) === true || ($body['confirm'] ?? null) === 1 || ($body['confirm'] ?? null) === '1';
                    $bills->deleteBill((int) $args['id'], $confirm, $body);

                    return Json::write($response, ['ok' => true]);
                });
                $secure->post('/logs', function (ServerRequestInterface $request, ResponseInterface $response) use ($bills) {
                    return Json::write($response, ['bill' => $bills->log(self::body($request))], 201);
                });

                $secure->get('/buckets', function (ServerRequestInterface $request, ResponseInterface $response) use ($categories) {
                    $query = $request->getQueryParams();
                    $include = ($query['includeArchived'] ?? '') === '1';
                    $today = is_string($query['today'] ?? null) ? $query['today'] : null;

                    return Json::write($response, ['categories' => $categories->list($include, $today)]);
                });
                $secure->post('/buckets', function (ServerRequestInterface $request, ResponseInterface $response) use ($categories) {
                    $body = self::body($request);
                    $today = is_string($body['today'] ?? null) ? $body['today'] : null;

                    return Json::write($response, ['category' => $categories->create($body, $today)], 201);
                });
                $secure->get('/buckets/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($categories) {
                    $today = $request->getQueryParams()['today'] ?? null;

                    return Json::write($response, [
                        'category' => $categories->get((int) $args['id'], is_string($today) ? $today : null),
                    ]);
                });
                $secure->patch('/buckets/{id:[0-9]+}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($categories) {
                    $body = self::body($request);
                    $today = is_string($body['today'] ?? null) ? $body['today'] : null;

                    return Json::write($response, ['category' => $categories->update((int) $args['id'], $body, $today)]);
                });
                $secure->post('/buckets/{id:[0-9]+}/archive', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($categories) {
                    return Json::write($response, ['category' => $categories->archive((int) $args['id'])]);
                });
                $secure->post('/buckets/{id:[0-9]+}/unarchive', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($categories) {
                    return Json::write($response, ['category' => $categories->unarchive((int) $args['id'])]);
                });
                $secure->post('/buckets/{id:[0-9]+}/delete', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($categories) {
                    $body = self::body($request);
                    $confirm = ($body['confirm'] ?? false) === true || ($body['confirm'] ?? null) === 1 || ($body['confirm'] ?? null) === '1';
                    $categories->delete((int) $args['id'], $confirm);

                    return Json::write($response, ['ok' => true]);
                });

                $secure->get('/pay-cycles/month', function (ServerRequestInterface $request, ResponseInterface $response) use ($payCycle) {
                    $query = $request->getQueryParams();
                    $start = $query['start'] ?? '';
                    $end = $query['end'] ?? '';
                    if (is_string($start) && is_string($end) && $start !== '' && $end !== '') {
                        return Json::write($response, [
                            'paydays' => $payCycle->between($start, $end, (string) ($query['today'] ?? '')),
                        ]);
                    }

                    return Json::write($response, [
                        'paydays' => $payCycle->monthPay((string) ($query['month'] ?? ''), (string) ($query['today'] ?? '')),
                    ]);
                });
                $secure->get('/pay-cycles', function (ServerRequestInterface $request, ResponseInterface $response) use ($payCycle) {
                    $query = $request->getQueryParams();
                    $include = ($query['includeArchived'] ?? '') === '1';
                    $start = is_string($query['start'] ?? null) && $query['start'] !== '' ? $query['start'] : null;
                    $end = is_string($query['end'] ?? null) && $query['end'] !== '' ? $query['end'] : null;

                    return Json::write($response, [
                        'payCycles' => $payCycle->list((string) ($query['today'] ?? ''), $include, $start, $end),
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
                    $query = $request->getQueryParams();
                    $start = is_string($query['start'] ?? null) && $query['start'] !== '' ? $query['start'] : null;
                    $end = is_string($query['end'] ?? null) && $query['end'] !== '' ? $query['end'] : null;

                    return Json::write($response, [
                        'payroll' => $payCycle->payroll((int) $args['id'], (string) ($query['today'] ?? ''), $start, $end),
                    ]);
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
                $secure->post('/pay-cycles/{id:[0-9]+}/delete', function (ServerRequestInterface $request, ResponseInterface $response, array $args) use ($payCycle) {
                    $body = self::body($request);
                    $confirm = ($body['confirm'] ?? false) === true || ($body['confirm'] ?? null) === 1 || ($body['confirm'] ?? null) === '1';
                    $payCycle->delete((int) $args['id'], $confirm, (string) ($body['today'] ?? ''));

                    return Json::write($response, ['ok' => true]);
                });

                $secure->get('/budget', function (ServerRequestInterface $request, ResponseInterface $response) use ($envelopes) {
                    $query = $request->getQueryParams();
                    $payCycleId = isset($query['payCycleId']) && $query['payCycleId'] !== '' ? (int) $query['payCycleId'] : null;
                    $periodId = isset($query['periodId']) && $query['periodId'] !== '' ? (int) $query['periodId'] : null;

                    return Json::write($response, $envelopes->show(
                        (string) ($query['today'] ?? ''),
                        $payCycleId,
                        $periodId,
                        ($query['upcoming'] ?? '') === '1',
                        ($query['acctPeriod'] ?? '') === '1',
                    ));
                });
                $secure->get('/transfers/card', function (ServerRequestInterface $request, ResponseInterface $response) use ($transfers) {
                    $query = $request->getQueryParams();

                    return Json::write($response, $transfers->cardPreview(
                        (int) ($query['cardId'] ?? 0),
                        (string) ($query['today'] ?? ''),
                    ));
                });
                $secure->post('/transfers/card', function (ServerRequestInterface $request, ResponseInterface $response) use ($transfers) {
                    return Json::write($response, $transfers->payCard(self::body($request)));
                });
                $secure->post('/transfers/preview', function (ServerRequestInterface $request, ResponseInterface $response) use ($transfers) {
                    return Json::write($response, $transfers->previewMove(self::body($request)));
                });
                $secure->post('/transfers', function (ServerRequestInterface $request, ResponseInterface $response) use ($transfers) {
                    return Json::write($response, $transfers->move(self::body($request)));
                });
                $secure->put('/budget', function (ServerRequestInterface $request, ResponseInterface $response) use ($envelopes) {
                    return Json::write($response, $envelopes->save(self::body($request)));
                });
                $secure->post('/budget/recalculate', function (ServerRequestInterface $request, ResponseInterface $response) use ($envelopes) {
                    return Json::write($response, $envelopes->recalculate(self::body($request)));
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
