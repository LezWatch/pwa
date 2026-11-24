<?php

namespace App\Http\Controllers;

use App\Models\Show;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ShowController extends Controller
{
    /** Bump when the /api/shows shape changes, so installed PWAs can tell. */
    public const SCHEMA_VERSION = 1;

    public function index(): JsonResponse
    {
        $shows = $this->sortedShows()->map(fn (Show $show) => [
            'id' => $show->id,
            'title' => $show->title,
            'slug' => $show->slug,
            'url' => $show->url,
            'on_air' => $show->on_air,
        ])->values();

        return response()->json([
            'schema_version' => self::SCHEMA_VERSION,
            'count' => $shows->count(),
            'shows' => $shows,
        ]);
    }

    public function page(): View
    {
        return view('shows', ['shows' => $this->sortedShows()]);
    }

    /** @return Collection<int, Show> */
    private function sortedShows(): Collection
    {
        return Show::query()->orderByRaw('title COLLATE NOCASE')->orderBy('id')->get();
    }
}
