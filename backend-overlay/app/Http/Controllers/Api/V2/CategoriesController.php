<?php
namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\{TicketCategory, Ticket};
use Illuminate\Support\Facades\DB;
use App\Support\{AmcAccess, AmcAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoriesController extends Controller
{
    public function index(Request $request) {
        abort_unless(AmcAccess::isAdmin($request->user()), 403);
        $data = $request->validate(['asset_filter' => ['sometimes', Rule::in(['with_assets', 'without_assets'])]]);
        $query = TicketCategory::query()->withCount('assets');
        if (($data['asset_filter'] ?? '') === 'without_assets') $query->whereDoesntHave('assets');
        if (($data['asset_filter'] ?? '') === 'with_assets') $query->whereHas('assets');
        return response()->json($query->orderBy('name')->get());
    }
    public function store(Request $request) {
        abort_unless(AmcAccess::isAdmin($request->user()), 403);
        $data = $request->validate(['name' => ['required','string','max:100',Rule::unique('ticket_categories')]]);
        $category = TicketCategory::create($data + ['slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(10)), 'is_active' => true]);
        AmcAudit::record($request, 'category.created', $category);
        return response()->json(['data' => $category], 201);
    }
    public function update(Request $request, int $id) {
        abort_unless(AmcAccess::isAdmin($request->user()), 403);
        $category = TicketCategory::findOrFail($id);
        $data = $request->validate(['name' => ['sometimes','required','string','max:100',Rule::unique('ticket_categories')->ignore($id)], 'is_active' => ['sometimes','boolean']]);
        $category->update($data);
        AmcAudit::record($request, 'category.updated', $category, ['fields' => array_keys($data)]);
        return response()->json(['data' => $category]);
    }

    public function destroy(Request $request, int $id) {
        abort_unless(AmcAccess::isAdmin($request->user()), 403);
        try {
            DB::transaction(function () use ($request, $id) {
                $category = TicketCategory::query()->lockForUpdate()->findOrFail($id);
                abort_if($category->assets()->exists(), 422, 'This category has linked assets and cannot be deleted.');
                abort_if(Ticket::where('category_id', $id)->exists(), 422, 'This category is used by tickets. Deactivate it to preserve ticket history.');
                $category->delete();
                AmcAudit::record($request, 'category.deleted', $category);
            });
        } catch (\Illuminate\Database\QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 1451) abort(422, 'This category is linked to business records and cannot be deleted.');
            throw $error;
        }
        return response()->json(['message' => 'Category deleted.']);
    }
}
