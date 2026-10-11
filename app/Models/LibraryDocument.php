<?php

namespace App\Models;

use Database\Factories\LibraryDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'description', 'category', 'file_path', 'published', 'uploaded_at'])]
class LibraryDocument extends Model
{
    /** @use HasFactory<LibraryDocumentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'uploaded_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('published', true);
    }
}
