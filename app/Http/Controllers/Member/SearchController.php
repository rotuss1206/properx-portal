<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Member\MemberSearch;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The header search of the member area (App\Member\MemberSearch). The query is personal
 * input: it comes in POST bodies, is kept in the (encrypted) session for the results page and
 * never appears in a URL or a log line.
 */
class SearchController extends Controller
{
    private const SESSION_KEY = 'member.search';

    /** Suggestions under the field (public/portal/search.js). */
    public function suggest(Request $request): JsonResponse
    {
        $query = $this->query($request);
        $results = MemberSearch::for($request->user(), $query);

        return response()->json([
            'total' => $results->count(),
            'results' => $results->take(6)->map(fn (array $result) => [
                'kind' => $result['kind'],
                'type' => $result['type'],
                'name' => $result['name'],
                'detail' => $result['detail'],
                'date' => self::date($result['date']),
                'url' => $result['url'],
            ])->values(),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    /** Enter in the field: the results page, by way of the session. */
    public function submit(Request $request): RedirectResponse
    {
        $request->session()->put(self::SESSION_KEY, $this->query($request));

        return redirect()->route('member.search');
    }

    public function results(Request $request): View
    {
        $query = (string) $request->session()->get(self::SESSION_KEY, '');

        return view('member.search', [
            'page' => null,
            'title' => 'Search',
            'query' => $query,
            'tooShort' => MemberSearch::words($query) === [],
            'results' => MemberSearch::for($request->user(), $query),
        ]);
    }

    public static function date(?CarbonInterface $date): ?string
    {
        return $date?->format('j M Y');
    }

    private function query(Request $request): string
    {
        $query = $request->input('q');

        return is_string($query) ? mb_substr(trim($query), 0, MemberSearch::MAX_LENGTH) : '';
    }
}
