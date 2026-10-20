<?php
namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\TicketCategory;
use App\Support\{AmcAccess, AmcAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoriesController extends Controller
{
    public function index(Request $request) {
        abort_unless(AmcAccess::isAdmin($request->user()), 403);
        return response()->json(TicketCategory::orderBy('name')->get());
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
}
