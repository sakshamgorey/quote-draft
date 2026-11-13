<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** text[] <-> list<string>. Also used directly for bulk upserts, which skip casts. */
class PostgresArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return self::parse($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return self::literal((array) $value);
    }

    /** @param  list<string>  $values */
    public static function literal(array $values): string
    {
        $quoted = array_map(fn ($v) => '"'.addcslashes((string) $v, '"\\').'"', array_values($values));

        return '{'.implode(',', $quoted).'}';
    }

    /** @return list<string> */
    public static function parse(?string $value): array
    {
        $inner = trim((string) $value, '{}');

        return $inner === '' ? [] : str_getcsv($inner, ',', '"', '\\');
    }
}
