<?php

namespace App\Services;

use App\Models\User;
use App\Services\System\InstallationTimezoneService;
use App\Support\AmcAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class TechnicianPerformance
{
    public const COLUMNS = ['name', 'completed_work', 'escalation_requests', 'approved_escalations', 'sla_on_time', 'sla_late', 'sla_rate'];
    public const HEADERS = ['Technician', 'Work completed', 'Escalations requested', 'Escalations approved', 'Within SLA', 'Outside SLA', 'SLA compliance'];

    public function query(Request $request): Builder
    {
        abort_unless(AmcAccess::isAdmin($request->user()), 403);
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'designation_id' => ['sometimes', 'integer', 'exists:technician_designations,id'],
            'workforce_type' => ['sometimes', Rule::in(['employee', 'freelancer'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'sort' => ['sometimes', Rule::in(self::COLUMNS)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $zone = app(InstallationTimezoneService::class)->settings()['timezone'];
        $from = isset($filters['from']) ? \Carbon\CarbonImmutable::parse($filters['from'], $zone)->startOfDay()->utc() : null;
        $until = isset($filters['to']) ? \Carbon\CarbonImmutable::parse($filters['to'], $zone)->startOfDay()->addDay()->utc() : null;
        $period = static fn ($query, string $column) => $query
            ->when($from, fn ($q) => $q->where($column, '>=', $from))
            ->when($until, fn ($q) => $q->where($column, '<', $until));

        $work = $period(DB::table('ticket_work_reports')->where('progress', 'completed'), 'created_at')
            ->select('technician_id')->selectRaw('COUNT(DISTINCT ticket_id) AS completed_work')->groupBy('technician_id');
        $completedTickets = $period(DB::table('ticket_work_reports')->where('progress', 'completed'), 'created_at')
            ->select('technician_id', 'ticket_id')->selectRaw('MIN(created_at) AS completed_at')
            ->groupBy('technician_id', 'ticket_id');
        $sla = DB::query()->fromSub($completedTickets, 'completed')
            ->join('tickets', 'tickets.id', '=', 'completed.ticket_id')
            ->whereNotNull('tickets.sla_resolution_due_at')
            ->select('completed.technician_id')
            ->selectRaw('SUM(CASE WHEN completed.completed_at <= tickets.sla_resolution_due_at THEN 1 ELSE 0 END) AS sla_on_time')
            ->selectRaw('SUM(CASE WHEN completed.completed_at > tickets.sla_resolution_due_at THEN 1 ELSE 0 END) AS sla_late')
            ->selectRaw('ROUND(100.0 * SUM(CASE WHEN completed.completed_at <= tickets.sla_resolution_due_at THEN 1 ELSE 0 END) / COUNT(*), 1) AS sla_rate')
            ->groupBy('completed.technician_id');
        $requests = $period(DB::table('ticket_escalations'), 'created_at')->select('requester_id')
            ->selectRaw('COUNT(*) AS escalation_requests')->groupBy('requester_id');
        $approvals = $period(DB::table('ticket_escalations')->where('state', 'approved'), 'decided_at')->select('requester_id')
            ->selectRaw('COUNT(*) AS approved_escalations')->groupBy('requester_id');

        $query = User::query()->where('users.role', 'technician')->whereNull('users.customer_id')
            ->leftJoin('technician_designations as designation', 'designation.id', '=', 'users.designation_id')
            ->leftJoinSub($work, 'work', 'work.technician_id', '=', 'users.id')
            ->leftJoinSub($sla, 'sla', 'sla.technician_id', '=', 'users.id')
            ->leftJoinSub($requests, 'requests', 'requests.requester_id', '=', 'users.id')
            ->leftJoinSub($approvals, 'approvals', 'approvals.requester_id', '=', 'users.id')
            ->select(['users.id', 'users.name', 'users.workforce_type', 'users.status', 'designation.name as designation'])
            ->selectRaw('COALESCE(work.completed_work, 0) AS completed_work')
            ->selectRaw('COALESCE(sla.sla_on_time, 0) AS sla_on_time, COALESCE(sla.sla_late, 0) AS sla_late, sla.sla_rate AS sla_rate')
            ->selectRaw('COALESCE(requests.escalation_requests, 0) AS escalation_requests, COALESCE(approvals.approved_escalations, 0) AS approved_escalations');
        if (! empty($filters['search'])) {
            $term = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('users.name', 'like', $term)->orWhere('designation.name', 'like', $term));
        }
        foreach (['designation_id', 'workforce_type', 'status'] as $key) {
            if (isset($filters[$key])) $query->where('users.'.$key, $filters[$key]);
        }
        $sort = $filters['sort'] ?? 'completed_work';
        return $query->orderBy($sort === 'name' ? 'users.name' : $sort, $filters['direction'] ?? 'desc')->orderBy('users.id');
    }

    public function row(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'designation' => $user->designation,
            'workforce_type' => $user->workforce_type, 'status' => $user->status,
            ...collect(array_slice(self::COLUMNS, 1))->mapWithKeys(fn ($key) => [$key => $key === 'sla_rate'
                ? ($user->{$key} === null ? null : (float) $user->{$key}) : (int) $user->{$key}])->all(),
        ];
    }

    public function exportRow(User $user): array
    {
        $row = $this->row($user);
        return array_map(fn ($key) => $row[$key] ?? '—', self::COLUMNS);
    }
}
