<?php

namespace App\Services\System;

use Illuminate\Support\Facades\DB;
use DateTimeZone;
use Throwable;

/** Store all timestamps in UTC; expose an independently selected display timezone. */
final class InstallationTimezoneService
{
    public function settings(): array
    {
        $rows = DB::table('amc_installation_settings')->whereIn('key', [
            'display_country', 'display_timezone',
        ])->pluck('value', 'key');
        return [
            'country_code' => ($rows['display_country'] ?? '') ?: null,
            'timezone' => $rows['display_timezone'] ?? 'UTC',
        ];
    }

    public function zonesForCountry(string $country): array
    {
        if (! preg_match('/^[A-Z]{2}$/D', $country)) return [];
        try {
            return DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country);
        } catch (Throwable) {
            return [];
        }
    }

    public function validCombination(?string $country, string $zone): bool
    {
        if ($country === null || $country === '') return $zone === 'UTC';
        return in_array($zone, $this->zonesForCountry($country), true);
    }
}
