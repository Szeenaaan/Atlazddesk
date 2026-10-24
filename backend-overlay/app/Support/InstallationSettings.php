<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Only nonsecret, nonbusiness configuration is writable from the support console. */
final class InstallationSettings
{
    public function all(): array
    {
        $stored = DB::table('amc_installation_settings')->pluck('value', 'key');
        $label = (string) ($stored['installation_label'] ?? config('app.name', 'SupportDesk'));
        return [
            'installation_label' => in_array(strtolower($label), ['atlazddesk', 'altazzdesk'], true) ? 'SupportDesk' : $label,
            'support_email' => (string) ($stored['support_email'] ?? ''),
            'queue_warning_depth' => (int) ($stored['queue_warning_depth'] ?? 100),
            'disk_warning_percent' => (int) ($stored['disk_warning_percent'] ?? 15),
            'country_code' => (string) ($stored['display_country'] ?? ''),
            'timezone' => (string) ($stored['display_timezone'] ?? 'UTC'),
        ];
    }
}
