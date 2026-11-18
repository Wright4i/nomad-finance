<?php

declare(strict_types=1);

namespace App\Trip;

final class Locality
{
    /** @var array<string, string> */
    private const US = [
        'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR',
        'California' => 'CA', 'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE',
        'District of Columbia' => 'DC', 'Florida' => 'FL', 'Georgia' => 'GA', 'Hawaii' => 'HI',
        'Idaho' => 'ID', 'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA',
        'Kansas' => 'KS', 'Kentucky' => 'KY', 'Louisiana' => 'LA', 'Maine' => 'ME',
        'Maryland' => 'MD', 'Massachusetts' => 'MA', 'Michigan' => 'MI', 'Minnesota' => 'MN',
        'Mississippi' => 'MS', 'Missouri' => 'MO', 'Montana' => 'MT', 'Nebraska' => 'NE',
        'Nevada' => 'NV', 'New Hampshire' => 'NH', 'New Jersey' => 'NJ', 'New Mexico' => 'NM',
        'New York' => 'NY', 'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH',
        'Oklahoma' => 'OK', 'Oregon' => 'OR', 'Pennsylvania' => 'PA', 'Rhode Island' => 'RI',
        'South Carolina' => 'SC', 'South Dakota' => 'SD', 'Tennessee' => 'TN', 'Texas' => 'TX',
        'Utah' => 'UT', 'Vermont' => 'VT', 'Virginia' => 'VA', 'Washington' => 'WA',
        'West Virginia' => 'WV', 'Wisconsin' => 'WI', 'Wyoming' => 'WY',
    ];

    /**
     * @param array<string, mixed> $address
     * @return array{city:string,region:?string,address:string}|null
     */
    public static function fromAddress(array $address): ?array
    {
        $city = null;
        foreach (['city', 'town', 'village', 'municipality', 'hamlet'] as $key) {
            if (isset($address[$key]) && is_string($address[$key]) && trim($address[$key]) !== '') {
                $city = trim($address[$key]);
                break;
            }
        }
        if ($city === null || mb_strlen($city) > 80) {
            return null;
        }
        $region = self::region($address);

        return ['city' => $city, 'region' => $region, 'address' => self::line($address, $city, $region)];
    }

    /** @param array<string, mixed> $address */
    private static function line(array $address, string $city, ?string $region): string
    {
        $number = self::part($address, 'house_number');
        $road = self::part($address, 'road') ?? self::part($address, 'pedestrian');
        $street = trim(($number ?? '') . ' ' . ($road ?? ''));
        $tail = $city . ($region !== null ? ', ' . $region : '');
        $postcode = self::part($address, 'postcode');
        if ($postcode !== null) {
            $tail .= ' ' . $postcode;
        }
        $line = $street !== '' ? $street . ', ' . $tail : $tail;

        return mb_strlen($line) > 160 ? mb_substr($line, 0, 160) : $line;
    }

    /** @param array<string, mixed> $address */
    private static function part(array $address, string $key): ?string
    {
        if (!isset($address[$key]) || !is_string($address[$key])) {
            return null;
        }
        $text = trim($address[$key]);

        return $text === '' ? null : $text;
    }

    /** @param array<string, mixed> $address */
    private static function region(array $address): ?string
    {
        foreach (['ISO3166-2-lvl4', 'ISO3166-2-lvl3', 'ISO3166-2-lvl5', 'ISO3166-2-lvl6'] as $key) {
            if (!isset($address[$key]) || !is_string($address[$key])) {
                continue;
            }
            if (preg_match('/^[A-Z]{2}-([A-Z0-9]{1,3})$/', $address[$key], $match) === 1) {
                return $match[1];
            }
        }
        if (!isset($address['state']) || !is_string($address['state'])) {
            return null;
        }
        $state = trim($address['state']);
        if (preg_match('/^[A-Z]{2}$/', $state) === 1) {
            return $state;
        }

        return self::US[$state] ?? null;
    }
}
