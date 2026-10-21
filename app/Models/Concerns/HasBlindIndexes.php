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
     * "creating" / "updating" fire after every "saving" listener, so values the model
     * generates while saving (e.g. a certificate code) are indexed too.
     */
    protected static function bootHasBlindIndexes(): void
    {
        $sync = function (self $model) {
            foreach (static::BLIND_INDEXES as $attribute => $column) {
                if ($model->isDirty($attribute) || ($model->getAttribute($column) === null && $model->getAttribute($attribute) !== null)) {
                    $model->setAttribute($column, BlindIndex::for($attribute, $model->getAttribute($attribute)));
                }
            }
        };

        static::creating($sync);
        static::updating($sync);
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
