<?php

namespace App\Support;

class NzRegions
{
    /** Canonical NZ regions for display and select options. */
    public const REGIONS = [
        'Northland',
        'Auckland',
        'Waikato',
        'Bay of Plenty',
        'Gisborne',
        "Hawke's Bay",
        'Taranaki',
        'Manawatū-Whanganui',
        'Wellington',
        'Tasman',
        'Nelson',
        'Marlborough',
        'West Coast',
        'Canterbury',
        'Otago',
        'Southland',
    ];

    public static function fromCity(?string $city): ?string
    {
        if (! $city) {
            return null;
        }

        $needle = mb_strtolower(trim($city));

        $cityToRegion = [
            'auckland' => 'Auckland',
            'manukau' => 'Auckland',
            'north shore' => 'Auckland',
            'waitakere' => 'Auckland',
            'papakura' => 'Auckland',
            'devonport' => 'Auckland',
            'grey lynn' => 'Auckland',
            'ponsonby' => 'Auckland',
            'mt eden' => 'Auckland',
            'henderson' => 'Auckland',
            'takapuna' => 'Auckland',
            'albany' => 'Auckland',

            'hamilton' => 'Waikato',
            'cambridge' => 'Waikato',
            'te awamutu' => 'Waikato',
            'huntly' => 'Waikato',
            'thames' => 'Waikato',
            'tokoroa' => 'Waikato',

            'tauranga' => 'Bay of Plenty',
            'rotorua' => 'Bay of Plenty',
            'whakatane' => 'Bay of Plenty',
            'mount maunganui' => 'Bay of Plenty',

            'wellington' => 'Wellington',
            'lower hutt' => 'Wellington',
            'porirua' => 'Wellington',
            'upper hutt' => 'Wellington',
            'kapiti' => 'Wellington',
            'te aro' => 'Wellington',

            'christchurch' => 'Canterbury',
            'rangiora' => 'Canterbury',
            'ashburton' => 'Canterbury',
            'timaru' => 'Canterbury',

            'dunedin' => 'Otago',
            'queenstown' => 'Otago',
            'oamaru' => 'Otago',
            'invercargill' => 'Southland',
            'gore' => 'Southland',

            'whangarei' => 'Northland',
            'kerikeri' => 'Northland',
            'kaitaia' => 'Northland',

            'gisborne' => 'Gisborne',
            'napier' => "Hawke's Bay",
            'hastings' => "Hawke's Bay",
            'new plymouth' => 'Taranaki',
            'palmerston north' => 'Manawatū-Whanganui',
            'whanganui' => 'Manawatū-Whanganui',
            'nelson' => 'Nelson',
            'blenheim' => 'Marlborough',
            'greymouth' => 'West Coast',
            'westport' => 'West Coast',
        ];

        if (isset($cityToRegion[$needle])) {
            return $cityToRegion[$needle];
        }

        foreach ($cityToRegion as $key => $region) {
            if (str_contains($needle, $key)) {
                return $region;
            }
        }

        return null;
    }
}
