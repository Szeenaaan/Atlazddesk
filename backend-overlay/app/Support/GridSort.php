<?php
namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class GridSort
{
    /** Only explicit columns and belongs-to relations may be ordered by clients. */
    public static function apply(Builder $query, Request $request, array $columns, array $relations = [], string $default = 'id'): Builder
    {
        $data = $request->validate([
            'sort' => ['sometimes', Rule::in(array_merge($columns, array_keys($relations)))],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);
        $field = $data['sort'] ?? $default;
        $direction = $data['direction'] ?? 'desc';
        if (isset($relations[$field])) {
            [$relationName, $column] = $relations[$field];
            $relation = $query->getModel()->{$relationName}();
            $order = $relation->getRelated()->newQuery()->select($column)
                ->whereColumn($relation->getQualifiedOwnerKeyName(), $relation->getQualifiedForeignKeyName())->limit(1);
        } else {
            $order = $query->getModel()->qualifyColumn($field);
        }
        return $query->orderBy($order, $direction)->orderBy($query->getModel()->qualifyColumn('id'), 'desc');
    }
}
