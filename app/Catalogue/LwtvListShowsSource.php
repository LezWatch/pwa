<?php

namespace App\Catalogue;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads shows from GET {api_url}/wp-json/lwtv/v1/list/shows.
 *
 * The route returns an object keyed by show id:
 *   {"27002": {"title": ".hack//SIGN", "url": "https://.../show/hack-sign/", "onair": "no"}}
 */
class LwtvListShowsSource implements CatalogueSource
{
    public const PATH = '/wp-json/lwtv/v1/list/shows';

    public function shows(): array
    {
        $url = config('lwtv.api_url').self::PATH;

        try {
            $response = Http::withOptions(['verify' => config('lwtv.verify_tls')])
                ->timeout(config('lwtv.timeout'))
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException $e) {
            throw new CatalogueUnavailable("Could not reach {$url}: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            throw new CatalogueUnavailable("{$url} returned HTTP {$response->status()}.");
        }

        $entries = $response->json();

        if (! is_array($entries) || $entries === []) {
            throw new CatalogueUnavailable("{$url} returned no shows.");
        }

        $shows = [];
        foreach ($entries as $id => $entry) {
            if (is_array($entry)) {
                $shows[] = $this->toShowRecord((int) $id, $entry);
            }
        }

        return $shows;
    }

    /**
     * Turn one entry from list/shows into our ShowRecord.
     *
     * @param  array{title?: string, url?: string, onair?: string}  $entry
     */
    public function toShowRecord(int $id, array $entry): ShowRecord
    {
        // TODO(Mika): build the ShowRecord from $entry. The tests in
        // tests/Unit/LwtvListShowsSourceTest.php are the spec:
        //   - title: decode HTML entities (&#8217; → ’, &#038; → &) and trim
        //     whitespace, including a trailing non-breaking space (U+00A0)
        //   - slug: the last non-empty path segment of the URL, with or
        //     without a trailing slash
        //   - onAir: true only for "yes", any case; missing or anything else
        //     is false
        throw new \LogicException('toShowRecord() not written yet');
    }
}
