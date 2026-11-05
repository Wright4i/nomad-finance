<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$db = getenv('FINANCE_DB');

return [
    'displayErrorDetails' => false,
    'db' => is_string($db) && $db !== '' ? $db : $root . '/data/finance.sqlite',
    'migrations' => $root . '/db/migrations',
];
