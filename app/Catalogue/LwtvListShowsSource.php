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
        $url = (string) ($entry['url'] ?? '');

        // WordPress sends entities (&#8217;, &#038;) and sometimes a trailing
        // non-breaking space, which plain trim() leaves behind.
        $title = html_entity_decode((string) ($entry['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $title) ?? $title;

        // Last non-empty path segment: /show/hack-sign/ and /show/hack-sign both give hack-sign.
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH)), 'strlen'));
        $slug = $segments === [] ? '' : end($segments);

        $onAir = strtolower(trim((string) ($entry['onair'] ?? ''))) === 'yes';

        return new ShowRecord($id, $title, $slug, $url, $onAir);
    }
}
