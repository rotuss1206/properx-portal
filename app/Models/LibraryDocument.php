<?php

namespace App\Models;

use App\Contracts\DownloadableDocument;
use App\Observers\DocumentObserver;
use App\Services\Documents\DocumentVault;
use Database\Factories\LibraryDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['title', 'description', 'category', 'file_path', 'published', 'uploaded_at'])]
#[ObservedBy(DocumentObserver::class)]
class LibraryDocument extends Model implements DownloadableDocument
{
    /** @use HasFactory<LibraryDocumentFactory> */
    use HasFactory;

    public const CATEGORIES = [
        'Compliance Briefings',
        'Training',
        'Regulatory Updates',
        'Forms & Templates',
    ];

    public const DIRECTORY = 'library';

    public static function disk(): string
    {
        return config('portal.documents_disk');
    }

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

    /**
     * Every word must appear in the title, description or category.
     *
     * @param  Builder<self>  $query
     * @param  list<string>  $words
     */
    #[Scope]
    protected function matching(Builder $query, array $words): void
    {
        foreach ($words as $word) {
            $like = '%'.addcslashes($word, '\\%_').'%';

            $query->where(fn (Builder $sql) => $sql->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('category', 'like', $like));
        }
    }

    public static function downloadType(): string
    {
        return 'library';
    }

    public function storagePath(): string
    {
        return $this->file_path;
    }

    public function downloadName(): string
    {
        return Str::slug($this->title).'.'.pathinfo($this->file_path, PATHINFO_EXTENSION);
    }

    public function downloadMimeType(): string
    {
        return DocumentVault::mimeTypeForPath($this->file_path);
    }
}
