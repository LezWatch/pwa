<?php

namespace Tests\Support;

/**
 * Checks a response against docs/api/app-catalogue-v1.md.
 *
 * Each method returns a list of violations, as "path: problem" strings, so a
 * test can show every problem at once. An empty list means the response
 * follows the contract. Rules come from the doc; when the two disagree, the
 * doc wins and this class is the bug.
 */
final class AppCatalogueContract
{
    public const SCHEMA_VERSION = 1;

    public const TERM_FIELDS = ['tropes', 'genres', 'formats', 'countries', 'stations'];

    public const SHOW_FIELDS = [
        'id', 'slug', 'title', 'url', 'year_start', 'year_end', 'on_air',
        'formats', 'countries', 'stations', 'genres', 'tropes', 'queer_death',
        'trigger', 'star', 'lwtv_score', 'viewer_score', 'viewer_votes',
        'tmdb_id', 'similar',
    ];

    public const TRIGGERS = ['low', 'med', 'high'];

    public const STARS = ['gold', 'silver', 'bronze', 'anti'];

    public const OFFER_TYPES = ['flatrate', 'free', 'ads', 'rent', 'buy'];

    public const QUEER_DEATH_TROPE = 'dead-queers';

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $catalogue
     * @return list<string>
     */
    public static function catalogueViolations(array $catalogue): array
    {
        $check = new self;
        $check->envelope($catalogue);
        $check->catalogue($catalogue);
        $check->text($catalogue, '');

        return $check->errors;
    }

    /**
     * @param  array<string, mixed>  $availability
     * @param  array<string, mixed>|null  $catalogue  When given, every show id must be in it.
     * @return list<string>
     */
    public static function availabilityViolations(array $availability, ?array $catalogue = null): array
    {
        $check = new self;
        $check->envelope($availability);
        $check->availability($availability, $catalogue);
        $check->text($availability, '');

        return $check->errors;
    }

    private function fail(string $path, string $problem): void
    {
        $this->errors[] = "{$path}: {$problem}";
    }

    /** @param array<string, mixed> $data */
    private function envelope(array $data): void
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $this->fail('schema_version', 'must be the integer '.self::SCHEMA_VERSION);
        }

        $generated = $data['generated_at'] ?? null;
        if (! is_string($generated) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|\+00:00)$/', $generated)) {
            $this->fail('generated_at', 'must be an ISO 8601 time in UTC');
        }

        if (! is_string($data['data_version'] ?? null) || $data['data_version'] === '') {
            $this->fail('data_version', 'must be a non-empty string');
        }
    }

    /** @param array<string, mixed> $data */
    private function catalogue(array $data): void
    {
        $terms = $data['terms'] ?? null;
        if (! is_array($terms)) {
            $this->fail('terms', 'missing');
            $terms = [];
        }

        foreach (self::TERM_FIELDS as $name) {
            $dictionary = $terms[$name] ?? null;
            if (! is_array($dictionary)) {
                $this->fail("terms.{$name}", 'missing');

                continue;
            }
            foreach ($dictionary as $id => $term) {
                $path = "terms.{$name}.{$id}";
                $this->idKey($path, $id);
                if (! is_array($term) || ! $this->isText($term['slug'] ?? null) || ! $this->isText($term['name'] ?? null)) {
                    $this->fail($path, 'must be { "slug", "name" } with non-empty strings');
                }
            }
        }

        $shows = $data['shows'] ?? null;
        if (! is_array($shows) || ! array_is_list($shows)) {
            $this->fail('shows', 'must be an array');

            return;
        }

        $ids = array_column($shows, 'id');
        $sorted = $ids;
        sort($sorted);
        if ($ids !== $sorted) {
            $this->fail('shows', 'must be sorted by id');
        }
        if (count($ids) !== count(array_unique($ids))) {
            $this->fail('shows', 'ids must be unique');
        }

        $deathTropes = array_keys(array_filter(
            $terms['tropes'] ?? [],
            fn ($term) => ($term['slug'] ?? null) === self::QUEER_DEATH_TROPE,
        ));

        foreach ($shows as $i => $show) {
            $this->show("shows[{$i}]", $show, $terms, $ids, $deathTropes);
        }

        // Dictionaries hold the terms shows use and no others, so the phone
        // never offers a trope (A2, B2) that can't filter anything.
        foreach (self::TERM_FIELDS as $field) {
            $used = [];
            foreach ($shows as $show) {
                foreach (is_array($show[$field] ?? null) ? $show[$field] : [] as $id) {
                    $used[(string) $id] = true;
                }
            }
            foreach (array_keys(is_array($terms[$field] ?? null) ? $terms[$field] : []) as $id) {
                if (! isset($used[(string) $id])) {
                    $this->fail("terms.{$field}.{$id}", 'no show uses it; leave unused terms out');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $terms
     * @param  list<mixed>  $ids
     * @param  list<int|string>  $deathTropes
     */
    private function show(string $path, mixed $show, array $terms, array $ids, array $deathTropes): void
    {
        if (! is_array($show)) {
            $this->fail($path, 'must be an object');

            return;
        }

        foreach (self::SHOW_FIELDS as $field) {
            if (! array_key_exists($field, $show)) {
                $this->fail("{$path}.{$field}", 'missing (missing values are null, but the field is always present)');
            }
        }

        $id = $show['id'] ?? null;
        if (! is_int($id) || $id < 1) {
            $this->fail("{$path}.id", 'must be a positive integer');
        }

        foreach (['slug', 'title', 'url'] as $field) {
            if (! $this->isText($show[$field] ?? null)) {
                $this->fail("{$path}.{$field}", 'must be a non-empty string');
            }
        }
        if (is_string($show['url'] ?? null) && ! str_starts_with($show['url'], 'https://')) {
            $this->fail("{$path}.url", 'must be an https URL');
        }

        foreach (['year_start', 'year_end', 'tmdb_id'] as $field) {
            $this->nullableInt("{$path}.{$field}", $show[$field] ?? null);
        }
        if (is_int($show['year_start'] ?? null) && is_int($show['year_end'] ?? null) && $show['year_end'] < $show['year_start']) {
            $this->fail("{$path}.year_end", 'is before year_start');
        }

        foreach (['on_air', 'queer_death'] as $field) {
            if (! is_bool($show[$field] ?? null)) {
                $this->fail("{$path}.{$field}", 'must be true or false');
            }
        }

        foreach (self::TERM_FIELDS as $field) {
            $this->termIds("{$path}.{$field}", $show[$field] ?? null, $terms[$field] ?? []);
        }

        $tropes = is_array($show['tropes'] ?? null) ? $show['tropes'] : [];
        $hasDeathTrope = array_intersect(array_map('strval', $tropes), array_map('strval', $deathTropes)) !== [];
        if (is_bool($show['queer_death'] ?? null) && $show['queer_death'] !== $hasDeathTrope) {
            $this->fail("{$path}.queer_death", 'must be true exactly when tropes has the '.self::QUEER_DEATH_TROPE.' trope');
        }

        $this->oneOf("{$path}.trigger", $show['trigger'] ?? null, self::TRIGGERS);
        $this->oneOf("{$path}.star", $show['star'] ?? null, self::STARS);

        $score = $show['lwtv_score'] ?? null;
        if ($score !== null && (! is_int($score) || $score < 0 || $score > 100)) {
            $this->fail("{$path}.lwtv_score", 'must be a whole number from 0 to 100, or null');
        }

        $viewer = $show['viewer_score'] ?? null;
        if ($viewer !== null && ((! is_int($viewer) && ! is_float($viewer)) || $viewer < 1 || $viewer > 5)) {
            $this->fail("{$path}.viewer_score", 'must be a number from 1 to 5, or null');
        }

        $votes = $show['viewer_votes'] ?? null;
        if ($votes !== null && (! is_int($votes) || $votes < 0)) {
            $this->fail("{$path}.viewer_votes", 'must be a whole number, or null');
        }

        $similar = $show['similar'] ?? null;
        if (! is_array($similar) || ! array_is_list($similar)) {
            $this->fail("{$path}.similar", 'must be an array of show ids');
        } else {
            foreach ($similar as $other) {
                if (! is_int($other) || ! in_array($other, $ids, true)) {
                    $this->fail("{$path}.similar", "{$this->json($other)} is not a show in this catalogue");
                } elseif ($other === $id) {
                    $this->fail("{$path}.similar", 'lists the show itself');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $catalogue
     */
    private function availability(array $data, ?array $catalogue): void
    {
        $attribution = $data['attribution'] ?? null;
        if (! is_array($attribution) || ! $this->isText($attribution['text'] ?? null) || ! $this->isText($attribution['url'] ?? null)) {
            $this->fail('attribution', 'must have non-empty "text" and "url"');
        }

        $services = is_array($data['services'] ?? null) ? $data['services'] : null;
        if ($services === null) {
            $this->fail('services', 'missing');
            $services = [];
        }
        foreach ($services as $slug => $service) {
            if (! is_string($slug) || ! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
                $this->fail("services.{$slug}", 'key must be a lowercase slug');
            }
            if (! is_array($service) || ! $this->isText($service['name'] ?? null)) {
                $this->fail("services.{$slug}", 'must have a non-empty "name"');
            }
        }

        $providers = is_array($data['providers'] ?? null) ? $data['providers'] : null;
        if ($providers === null) {
            $this->fail('providers', 'missing');
            $providers = [];
        }
        foreach ($providers as $id => $provider) {
            $path = "providers.{$id}";
            $this->idKey($path, $id);
            if (! is_array($provider) || ! $this->isText($provider['name'] ?? null)) {
                $this->fail($path, 'must have a non-empty "name"');

                continue;
            }
            foreach (['service', 'logo'] as $field) {
                if (! array_key_exists($field, $provider)) {
                    $this->fail("{$path}.{$field}", 'missing');
                }
            }
            $service = $provider['service'] ?? null;
            if ($service !== null && ! array_key_exists($service, $services)) {
                $this->fail("{$path}.service", "\"{$service}\" is not in services");
            }
            if (($provider['logo'] ?? null) !== null && ! $this->isText($provider['logo'])) {
                $this->fail("{$path}.logo", 'must be a non-empty string, or null');
            }
        }

        $regions = is_array($data['regions'] ?? null) ? $data['regions'] : null;
        if ($regions === null) {
            $this->fail('regions', 'missing');
            $regions = [];
        }
        foreach ($regions as $code => $region) {
            $path = "regions.{$code}";
            if (! is_string($code) || ! preg_match('/^[A-Z]{2}$/', $code)) {
                $this->fail($path, 'key must be an ISO 3166-1 alpha-2 code');
            }
            if (! is_array($region) || ! $this->isText($region['name'] ?? null)) {
                $this->fail($path, 'must have a non-empty "name"');

                continue;
            }
            $list = $region['services'] ?? null;
            if (! is_array($list) || ! array_is_list($list)) {
                $this->fail("{$path}.services", 'must be an array of service slugs');

                continue;
            }
            if (count($list) !== count(array_unique($list))) {
                $this->fail("{$path}.services", 'lists a service twice');
            }
            foreach ($list as $slug) {
                if (! is_string($slug) || ! array_key_exists($slug, $services)) {
                    $this->fail("{$path}.services", "{$this->json($slug)} is not in services");
                }
            }
        }

        $catalogueIds = $catalogue === null ? null : array_map('strval', array_column($catalogue['shows'] ?? [], 'id'));

        $shows = is_array($data['shows'] ?? null) ? $data['shows'] : null;
        if ($shows === null) {
            $this->fail('shows', 'missing');
            $shows = [];
        }
        foreach ($shows as $showId => $byRegion) {
            $path = "shows.{$showId}";
            $this->idKey($path, $showId);
            if ($catalogueIds !== null && ! in_array((string) $showId, $catalogueIds, true)) {
                $this->fail($path, 'is not a show in the catalogue');
            }
            if (! is_array($byRegion) || $byRegion === []) {
                $this->fail($path, 'must list at least one region (leave empty shows out)');

                continue;
            }
            foreach ($byRegion as $code => $offers) {
                $regionPath = "{$path}.{$code}";
                if (! array_key_exists($code, $regions)) {
                    $this->fail($regionPath, 'is not in regions');
                }
                if (! is_array($offers) || $offers === []) {
                    $this->fail($regionPath, 'must list at least one offer type (leave empty regions out)');

                    continue;
                }
                foreach ($offers as $type => $ids) {
                    $typePath = "{$regionPath}.{$type}";
                    if (! in_array($type, self::OFFER_TYPES, true)) {
                        $this->fail($typePath, 'is not an offer type ('.implode(', ', self::OFFER_TYPES).')');
                    }
                    if (! is_array($ids) || ! array_is_list($ids) || $ids === []) {
                        $this->fail($typePath, 'must be a non-empty array of provider ids (leave empty types out)');

                        continue;
                    }
                    foreach ($ids as $providerId) {
                        if (! is_int($providerId) || ! array_key_exists((string) $providerId, $providers)) {
                            $this->fail($typePath, "{$this->json($providerId)} is not in providers");
                        }
                    }
                }
            }
        }
    }

    /**
     * Text is plain UTF-8, entities decoded, no HTML, and never "".
     */
    private function text(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->text($item, $path === '' ? (string) $key : "{$path}.{$key}");
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        if ($value === '') {
            $this->fail($path, 'is "" (missing values are null)');
        } elseif (! mb_check_encoding($value, 'UTF-8')) {
            $this->fail($path, 'is not valid UTF-8');
        } elseif (preg_match('/&(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', $value)) {
            $this->fail($path, 'has an HTML entity; decode it');
        } elseif (preg_match('/<\/?[a-z][^>]*>/i', $value)) {
            $this->fail($path, 'has HTML; send plain text');
        }
    }

    private function idKey(string $path, int|string $key): void
    {
        if (! preg_match('/^[1-9]\d*$/', (string) $key)) {
            $this->fail($path, 'key must be a positive integer id');
        }
    }

    /** @param array<string, mixed> $dictionary */
    private function termIds(string $path, mixed $ids, array $dictionary): void
    {
        if (! is_array($ids) || ! array_is_list($ids)) {
            $this->fail($path, 'must be an array of term ids (empty when none)');

            return;
        }
        foreach ($ids as $id) {
            if (! is_int($id) || ! array_key_exists((string) $id, $dictionary)) {
                $this->fail($path, "{$this->json($id)} is not in its term dictionary");
            }
        }
    }

    /** @param list<string> $allowed */
    private function oneOf(string $path, mixed $value, array $allowed): void
    {
        if ($value !== null && ! in_array($value, $allowed, true)) {
            $this->fail($path, "{$this->json($value)} must be one of ".implode(', ', $allowed).', or null');
        }
    }

    private function nullableInt(string $path, mixed $value): void
    {
        if ($value !== null && ! is_int($value)) {
            $this->fail($path, 'must be an integer, or null');
        }
    }

    private function isText(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    /** A value as JSON, for messages. */
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '?';
    }
}
