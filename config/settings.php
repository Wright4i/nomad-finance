<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$db = getenv('FINANCE_DB');
$newUser = getenv('FINANCE_NEW_USER');

return [
    'displayErrorDetails' => false,
    'db' => is_string($db) && $db !== '' ? $db : $root . '/data/finance.sqlite',
    'migrations' => $root . '/db/migrations',
    // Off unless FINANCE_NEW_USER=1. Setup can still create the owner.
    'allowNewUser' => is_string($newUser) && in_array(strtolower(trim($newUser)), ['1', 'true'], true),
];
