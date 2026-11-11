<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$db = getenv('FINANCE_DB');
$newUser = getenv('FINANCE_NEW_USER');
$homepageToken = getenv('FINANCE_HOMEPAGE_TOKEN');
$homepageTz = getenv('FINANCE_HOMEPAGE_TZ');

return [
    'displayErrorDetails' => false,
    'db' => is_string($db) && $db !== '' ? $db : $root . '/data/finance.sqlite',
    'migrations' => $root . '/db/migrations',
    // Off unless FINANCE_NEW_USER=1. Setup can still create the owner.
    'allowNewUser' => is_string($newUser) && in_array(strtolower(trim($newUser)), ['1', 'true'], true),
    // Empty disables GET /api/homepage/today.
    'homepageToken' => is_string($homepageToken) && $homepageToken !== '' ? $homepageToken : '',
    'homepageTimezone' => is_string($homepageTz) && $homepageTz !== '' ? $homepageTz : 'America/Los_Angeles',
];
