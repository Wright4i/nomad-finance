<?php

declare(strict_types=1);

namespace App\Bill;

final class BillTypes
{
    /** @var array<string, string> */
    public const LABEL = [
        'lodging' => 'Lodging',
        'rent' => 'Rent',
        'electricity' => 'Electricity',
        'water' => 'Water',
        'fuel' => 'Fuel',
        'propane' => 'Propane',
        'groceries' => 'Groceries',
        'internet' => 'Internet',
        'cellular' => 'Cellular',
        'vehicle' => 'Vehicle',
        'maintenance' => 'Maintenance',
        'insurance' => 'Insurance',
        'roadside' => 'Roadside',
        'healthcare' => 'Healthcare',
        'mailbox' => 'Mailbox',
        'laundry' => 'Laundry',
        'mortgage' => 'Mortgage',
        'storage' => 'Storage',
        'other' => 'Other',
        'restaurants' => 'Restaurants',
        'entertainment' => 'Entertainment',
        'subscriptions' => 'Subscriptions',
        'shopping' => 'Shopping',
        'travel' => 'Travel',
        'memberships' => 'Memberships',
        'software' => 'Software',
        'pets' => 'Pets',
        'misc' => 'Misc',
        'debt' => 'Debt',
        'emergency' => 'Emergency',
        'repairs' => 'Repairs',
        'investments' => 'Investments',
        'liquidities' => 'Liquidities',
    ];

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

    public static function label(string $type): string
    {
        return self::LABEL[$type] ?? $type;
    }

    public static function namesItself(string $type): bool
    {
        return $type === 'other' || $type === 'misc';
    }
}
