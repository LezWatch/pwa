<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** A run still marked running after this long has almost certainly died. */
    public const STALLED_AFTER_MINUTES = 30;

    protected $fillable = [
        'trigger', 'status', 'started_at', 'finished_at',
        'total', 'added', 'updated', 'removed', 'error',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'total' => 'integer',
            'added' => 'integer',
            'updated' => 'integer',
            'removed' => 'integer',
        ];
    }

    /** Failed runs, plus running ones that have stalled (their process died). */
    public function scopeFailedOrStalled(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('status', self::STATUS_FAILED)
            ->orWhere(fn (Builder $q) => $q
                ->where('status', self::STATUS_RUNNING)
                ->where('started_at', '<', now()->subMinutes(self::STALLED_AFTER_MINUTES))));
    }

    public function isStalled(): bool
    {
        return $this->status === self::STATUS_RUNNING
            && $this->started_at->lt(now()->subMinutes(self::STALLED_AFTER_MINUTES));
    }
}
