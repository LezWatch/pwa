<?php

namespace App\Catalogue;

final readonly class ShowRecord
{
    public function __construct(
        public int $id,
        public string $title,
        public string $slug,
        public string $url,
        public bool $onAir,
    ) {}
}
