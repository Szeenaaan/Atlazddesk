<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use LogicException;

/** Search only explicitly listed, safe diagnostic metadata; never payloads or exception messages. */
final class SafeDiagnosticSearch
{
    private const ALLOWED_COLUMNS = ['uuid', 'connection', 'queue', 'exception_class', 'request_id', 'event'];

    public static function apply(Builder $query, array $columns, string $term): void
    {
        if ($term === '') {
            return;
        }

        foreach ($columns as $column) {
            if (! in_array($column, self::ALLOWED_COLUMNS, true)) {
                throw new LogicException('Unsafe diagnostic search column.');
            }
        }

        // ! is the LIKE escape character on MariaDB/MySQL and SQLite. Wildcards supplied
        // by users are matched literally; all search content stays a bound parameter.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $pattern = '%'.$escaped.'%';
        $query->where(static function (Builder $builder) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $builder->orWhereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }
}
