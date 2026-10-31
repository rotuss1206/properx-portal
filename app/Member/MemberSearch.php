<?php

namespace App\Member;

use App\Models\LibraryDocument;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The member area's header search: the member's own documents (license, tax, certificate, as
 * the dashboard lists them: type and year, never file names) and the published compliance
 * library. Every word of the query must match. The query is never logged nor put in a URL
 * (App\Http\Controllers\Member\SearchController keeps it in the session).
 */
final class MemberSearch
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 100;

    /**
     * @return Collection<int, array{kind: string, icon: string, type: string, name: string, detail: ?string, date: ?CarbonInterface, url: string}>
     */
    public static function for(User $user, string $query, ?int $limit = null): Collection
    {
        $words = self::words($query);

        if ($words === []) {
            return collect();
        }

        $documents = DocumentList::for($user)
            ->filter(fn (array $row) => $row['url'] !== null && self::matches($words, [$row['name'], $row['detail'], $row['type']]))
            ->map(fn (array $row) => [
                'kind' => $row['kind'],
                'icon' => $row['icon'],
                'type' => $row['type'],
                'name' => $row['name'],
                'detail' => $row['detail'],
                'date' => $row['updated'],
                'url' => $row['url'],
            ]);

        $library = collect();

        if ($user->can('viewAny', LibraryDocument::class)) {
            $library = LibraryDocument::query()
                ->published()
                ->where(function ($sql) use ($words) {
                    foreach ($words as $word) {
                        $like = '%'.addcslashes($word, '\\%_').'%';
                        $sql->where(fn ($sql) => $sql->where('title', 'like', $like)
                            ->orWhere('description', 'like', $like)
                            ->orWhere('category', 'like', $like));
                    }
                })
                ->latest('uploaded_at')
                ->limit(50)
                ->get()
                ->map(fn (LibraryDocument $document) => [
                    'kind' => 'library',
                    'icon' => 'library',
                    'type' => 'Library',
                    'name' => $document->title,
                    'detail' => $document->category,
                    'date' => $document->uploaded_at,
                    'url' => route('member.documents.download', ['type' => 'library', 'document' => $document->getKey()]),
                ]);
        }

        $results = $documents->concat($library)->values();

        return $limit === null ? $results : $results->take($limit)->values();
    }

    /**
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $query = Str::limit(trim($query), self::MAX_LENGTH, '');

        if (mb_strlen($query) < self::MIN_LENGTH) {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/u', mb_strtolower($query)) ?: []));
    }

    /**
     * @param  list<string>  $words
     * @param  array<int, ?string>  $fields
     */
    private static function matches(array $words, array $fields): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter($fields)));

        foreach ($words as $word) {
            if (! str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }
}
