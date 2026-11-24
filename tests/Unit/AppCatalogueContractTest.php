<?php

namespace Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppCatalogueContract;

/**
 * Holds the sample files in tests/Fixtures/app/ to docs/api/app-catalogue-v1.md.
 *
 * The broken-sample cases prove each rule actually fires, so a passing run
 * means the samples follow the contract, not that the checker is asleep.
 */
class AppCatalogueContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function sample(string $name): array
    {
        $path = dirname(__DIR__)."/Fixtures/app/{$name}.json";

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_catalogue_sample_follows_the_contract(): void
    {
        $this->assertSame([], AppCatalogueContract::catalogueViolations(self::sample('catalogue')));
    }

    public function test_availability_sample_follows_the_contract(): void
    {
        $this->assertSame([], AppCatalogueContract::availabilityViolations(
            self::sample('availability'),
            self::sample('catalogue'),
        ));
    }

    /** @return array<string, array{Closure(array<string, mixed>): array<string, mixed>, string}> */
    public static function brokenCatalogues(): array
    {
        return [
            'old schema version' => [fn ($d) => [...$d, 'schema_version' => '1'], 'schema_version'],
            'local time' => [fn ($d) => [...$d, 'generated_at' => '2026-11-23T18:00:00-05:00'], 'generated_at'],
            'missing dictionary' => [function ($d) {
                unset($d['terms']['genres']);

                return $d;
            }, 'terms.genres'],
            'unsorted shows' => [function ($d) {
                $d['shows'] = array_reverse($d['shows']);

                return $d;
            }, 'must be sorted by id'],
            'missing field' => [function ($d) {
                unset($d['shows'][0]['tmdb_id']);

                return $d;
            }, 'shows[0].tmdb_id: missing'],
            'empty string for missing' => [fn ($d) => self::setShow($d, 'slug', ''), 'missing values are null'],
            'draft trigger spelling' => [fn ($d) => self::setShow($d, 'trigger', 'medium'), 'shows[0].trigger'],
            'unknown star' => [fn ($d) => self::setShow($d, 'star', 'black'), 'shows[0].star'],
            'unrounded score' => [fn ($d) => self::setShow($d, 'lwtv_score', 84.4), 'shows[0].lwtv_score'],
            'viewer score out of range' => [fn ($d) => self::setShow($d, 'viewer_score', 7), 'shows[0].viewer_score'],
            'unknown trope id' => [fn ($d) => self::setShow($d, 'tropes', [999]), 'shows[0].tropes'],
            'term id as string' => [fn ($d) => self::setShow($d, 'genres', ['301']), 'shows[0].genres'],
            'queer_death without the trope' => [fn ($d) => self::setShow($d, 'queer_death', true), 'shows[0].queer_death'],
            'similar to a missing show' => [fn ($d) => self::setShow($d, 'similar', [12345]), 'shows[0].similar'],
            'similar to itself' => [fn ($d) => self::setShow($d, 'similar', [90001]), 'lists the show itself'],
            'year_end before year_start' => [fn ($d) => self::setShow($d, 'year_end', 2001), 'is before year_start'],
            'encoded entity' => [fn ($d) => self::setShow($d, 'title', 'Lantern&#8217;s Years'), 'HTML entity'],
            'html in text' => [fn ($d) => self::setShow($d, 'title', '<em>Lantern</em>'), 'has HTML'],
        ];
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $break */
    #[DataProvider('brokenCatalogues')]
    public function test_broken_catalogue_is_caught(Closure $break, string $expected): void
    {
        $violations = AppCatalogueContract::catalogueViolations($break(self::sample('catalogue')));

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString($expected, implode("\n", $violations));
    }

    /** @return array<string, array{Closure(array<string, mixed>): array<string, mixed>, string}> */
    public static function brokenAvailability(): array
    {
        return [
            'no attribution' => [function ($d) {
                unset($d['attribution']);

                return $d;
            }, 'attribution'],
            'provider maps to unknown service' => [function ($d) {
                $d['providers']['8']['service'] = 'netflicks';

                return $d;
            }, 'providers.8.service'],
            'region lists unknown service' => [function ($d) {
                $d['regions']['GB']['services'][] = 'itvx';

                return $d;
            }, 'regions.GB.services'],
            'lowercase region code' => [function ($d) {
                $d['regions']['us'] = $d['regions']['US'];

                return $d;
            }, 'regions.us'],
            'unknown offer type' => [function ($d) {
                $d['shows']['90001']['US']['subscription'] = [1899];

                return $d;
            }, 'shows.90001.US.subscription'],
            'empty offer type' => [function ($d) {
                $d['shows']['90001']['US']['free'] = [];

                return $d;
            }, 'leave empty types out'],
            'unknown provider' => [function ($d) {
                $d['shows']['90001']['US']['flatrate'] = [4242];

                return $d;
            }, 'shows.90001.US.flatrate'],
            'region not in regions' => [function ($d) {
                $d['shows']['90001']['CA'] = ['flatrate' => [8]];

                return $d;
            }, 'shows.90001.CA'],
            'show not in catalogue' => [function ($d) {
                $d['shows']['12345'] = ['US' => ['flatrate' => [8]]];

                return $d;
            }, 'shows.12345'],
        ];
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $break */
    #[DataProvider('brokenAvailability')]
    public function test_broken_availability_is_caught(Closure $break, string $expected): void
    {
        $violations = AppCatalogueContract::availabilityViolations(
            $break(self::sample('availability')),
            self::sample('catalogue'),
        );

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString($expected, implode("\n", $violations));
    }

    /**
     * @param  array<string, mixed>  $catalogue
     * @return array<string, mixed>
     */
    private static function setShow(array $catalogue, string $field, mixed $value): array
    {
        $catalogue['shows'][0][$field] = $value;

        return $catalogue;
    }
}
