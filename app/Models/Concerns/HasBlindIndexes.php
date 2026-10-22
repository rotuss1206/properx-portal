<?php

namespace App\Models\Concerns;

use App\Support\BlindIndex;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Keeps blind-index columns in sync with encrypted attributes and lets queries
 * find a record by the plain value: Model::whereBlindIndex('npn', '12345678').
 *
 * The model defines: protected const BLIND_INDEXES = ['attribute' => 'index_column'];
 */
trait HasBlindIndexes
{
    /**
     * The index is computed when the value is assigned rather than in a model event,
     * so it is also kept when events are muted (e.g. while seeding).
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        parent::setAttribute($key, $value);

        if (array_key_exists($key, static::BLIND_INDEXES)) {
            $this->attributes[static::BLIND_INDEXES[$key]] = BlindIndex::for($key, $value === null ? null : (string) $value);
        }

        return $this;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function whereBlindIndex(Builder $query, string $attribute, ?string $value): void
    {
        $column = static::BLIND_INDEXES[$attribute]
            ?? throw new InvalidArgumentException("[{$attribute}] has no blind index.");

        $query->where($query->qualifyColumn($column), BlindIndex::for($attribute, $value));
    }
}
