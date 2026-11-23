<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A show from the LezWatch.TV catalogue, cached locally by `lwtv:sync`.
 */
class Show extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'title', 'slug', 'url', 'on_air'];

    protected function casts(): array
    {
        return ['on_air' => 'boolean'];
    }
}
