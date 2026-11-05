<?php

declare(strict_types=1);

namespace App\Bill;

final class BillTypes
{
    /** @var array<string, string> */
    public const GROUP = [
        'lodging' => 'needs',
        'rent' => 'needs',
        'electricity' => 'needs',
        'water' => 'needs',
        'fuel' => 'needs',
        'propane' => 'needs',
        'groceries' => 'needs',
        'internet' => 'needs',
        'cellular' => 'needs',
        'vehicle' => 'needs',
        'maintenance' => 'needs',
        'insurance' => 'needs',
        'roadside' => 'needs',
        'healthcare' => 'needs',
        'mailbox' => 'needs',
        'laundry' => 'needs',
        'mortgage' => 'needs',
        'storage' => 'needs',
        'other' => 'needs',
        'restaurants' => 'wants',
        'entertainment' => 'wants',
        'subscriptions' => 'wants',
        'shopping' => 'wants',
        'travel' => 'wants',
        'memberships' => 'wants',
        'software' => 'wants',
        'pets' => 'wants',
        'misc' => 'wants',
        'debt' => 'savings',
        'emergency' => 'savings',
        'repairs' => 'savings',
        'investments' => 'savings',
        'liquidities' => 'savings',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::GROUP);
    }

    public static function group(string $type): string
    {
        return self::GROUP[$type] ?? '';
    }

    public static function namesItself(string $type): bool
    {
        return $type === 'other' || $type === 'misc';
    }
}
