<?php

namespace App\Services;

use App\Models\{Ticket, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TechnicianAssignmentLimits
{
    private const DEFAULT_KEY = 'technician_assignment_limit';
    private const ACTIVE_EXCLUDED = ['resolved', 'customer_confirmation', 'closed', 'cancelled'];

    public function countsStatus(?string $slug): bool { return $slug !== null && ! in_array($slug, self::ACTIVE_EXCLUDED, true); }

    public function defaultLimit(): ?int
    {
        return $this->parse(DB::table('amc_installation_settings')->where('key', self::DEFAULT_KEY)->value('value'));
    }

    public function override(User $technician): ?int
    {
        return $this->parse(DB::table('amc_installation_settings')->where('key', $this->userKey($technician->id))->value('value'));
    }

    public function activeCount(User $technician): int
    {
        return Ticket::where('assigned_to', $technician->id)
            ->whereHas('status', fn ($query) => $query->whereNotIn('slug', self::ACTIVE_EXCLUDED))->count();
    }

    public function capacity(User $technician): array
    {
        $default = $this->defaultLimit();
        $override = $this->override($technician);
        return ['default_limit' => $default, 'override_limit' => $override,
            'effective_limit' => $override ?? $default, 'active_tickets' => $this->activeCount($technician)];
    }

    /** Called inside the ticket transaction after locking the target technician row. */
    public function assertAvailable(User $technician): void
    {
        DB::table('amc_installation_settings')->insertOrIgnore([
            'key' => self::DEFAULT_KEY, 'value' => '0', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $default = $this->parse(DB::table('amc_installation_settings')
            ->where('key', self::DEFAULT_KEY)->lockForUpdate()->value('value'));
        $limit = $this->override($technician) ?? $default;
        if ($limit !== null && $this->activeCount($technician) >= $limit) {
            throw ValidationException::withMessages(['assigned_to' =>
                'This technician has reached the active ticket limit. Choose another technician or increase the limit.']);
        }
    }

    public function saveDefault(?int $limit, int $actorId): void
    {
        DB::table('amc_installation_settings')->insertOrIgnore([
            'key' => self::DEFAULT_KEY, 'value' => '0', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('amc_installation_settings')->where('key', self::DEFAULT_KEY)->lockForUpdate()->first();
        DB::table('amc_installation_settings')->where('key', self::DEFAULT_KEY)->update([
            'value' => (string) ($limit ?? 0), 'updated_by' => $actorId, 'updated_at' => now(),
        ]);
    }

    public function saveOverride(User $technician, ?int $limit, int $actorId): void
    {
        $key = $this->userKey($technician->id);
        if ($limit === null) DB::table('amc_installation_settings')->where('key', $key)->delete();
        else DB::table('amc_installation_settings')->updateOrInsert(['key' => $key], [
            'value' => (string) $limit, 'updated_by' => $actorId, 'updated_at' => now(),
        ]);
    }

    public function clearOverride(User $technician): void
    {
        DB::table('amc_installation_settings')->where('key', $this->userKey($technician->id))->delete();
    }

    private function userKey(int $id): string { return self::DEFAULT_KEY.':user:'.$id; }
    private function parse(?string $value): ?int { return $value !== null && (int) $value > 0 ? (int) $value : null; }
}
