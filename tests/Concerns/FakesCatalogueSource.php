<?php

namespace Tests\Concerns;

use App\Catalogue\CatalogueSource;
use App\Catalogue\ShowRecord;
use Throwable;

trait FakesCatalogueSource
{
    /** @param  list<ShowRecord>|Throwable  $result */
    protected function fakeSource(array|Throwable $result): void
    {
        $this->app->instance(CatalogueSource::class, new class($result) implements CatalogueSource
        {
            public function __construct(private array|Throwable $result) {}

            public function shows(): array
            {
                if ($this->result instanceof Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        });
    }

    protected function record(int $id, string $title, bool $onAir = false): ShowRecord
    {
        $slug = str($title)->slug()->toString();

        return new ShowRecord($id, $title, $slug, "https://lwtv.local/show/{$slug}/", $onAir);
    }
}
