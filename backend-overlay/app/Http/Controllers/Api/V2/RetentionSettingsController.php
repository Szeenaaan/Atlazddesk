<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\System\RetentionService;
use App\Support\AmcAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Enforces separation between VPS diagnostics and contractor business housekeeping. */
final class RetentionSettingsController extends Controller
{
    public function __construct(private readonly RetentionService $retention) {}

    public function technicalIndex(Request $request) { return $this->index($request, 'technical'); }
    public function technicalUpdate(Request $request) { return $this->update($request, 'technical'); }
    public function businessIndex(Request $request) { return $this->index($request, 'business'); }
    public function businessUpdate(Request $request) { return $this->update($request, 'business'); }

    private function index(Request $request, string $scope)
    {
        $this->authorizeScope($request, $scope);
        return response()->json(['data' => $this->retention->policies($scope), 'scope' => $scope])
            ->header('Cache-Control', 'no-store, private');
    }

    private function update(Request $request, string $scope)
    {
        $this->authorizeScope($request, $scope);
        $allowed = array_keys(array_filter(RetentionService::CATEGORIES, fn ($d) => $d['scope'] === $scope));
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:1024'],
            'policies' => ['required', 'array', 'size:'.count($allowed)],
            'policies.*.category' => ['required', 'string', Rule::in($allowed)],
            'policies.*.enabled' => ['required', 'boolean'],
            'policies.*.retention_days' => ['required', 'integer', 'min:7', 'max:3650'],
            'policies.*.run_every_days' => ['required', 'integer', 'min:1', 'max:30'],
            'policies.*.batch_size' => ['required', 'integer', 'min:25', 'max:1000'],
            'policies.*.max_batches' => ['required', 'integer', 'min:1', 'max:20'],
        ]);
        $categories = array_column($validated['policies'], 'category');
        if (count(array_unique($categories)) !== count($allowed) || array_diff($allowed, $categories)) {
            throw ValidationException::withMessages(['policies' => ['Provide each authorized retention category exactly once.']]);
        }
        if (! Hash::check($validated['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
        }
        foreach ($validated['policies'] as $row) {
            $minimum = RetentionService::CATEGORIES[$row['category']]['minimum_days'];
            if ($row['retention_days'] < $minimum || ($row['category'] === 'rotated_logs' && $row['retention_days'] > 365)) {
                throw ValidationException::withMessages(['policies' => ["{$row['category']} requires {$minimum}–".($row['category'] === 'rotated_logs' ? '365' : '3650').' retention days.']]);
            }
        }
        // Only the whitelisted policy metadata can be updated; no arbitrary table/file selection.
        $data = $this->retention->save($scope, $validated['policies'], (int) $request->user()->id);
        AmcAudit::record($request, $scope === 'technical' ? 'system.retention.updated' : 'contractor.retention.updated', $request->user(), [
            'categories' => array_column($validated['policies'], 'category'),
        ]);
        return response()->json(['data' => $data, 'message' => 'Retention settings saved. Automatic cleanup will follow the updated settings.'])
            ->header('Cache-Control', 'no-store, private');
    }

    private function authorizeScope(Request $request, string $scope): void
    {
        abort_unless(in_array($scope, ['technical', 'business'], true), 404);
        $needed = $scope === 'technical' ? 'super_admin' : 'admin';
        abort_unless($request->user()?->role === $needed, 403);
    }
}
