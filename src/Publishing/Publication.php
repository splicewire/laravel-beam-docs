<?php

namespace Splicewire\Beam\Docs\Publishing;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Facades\Beam;

/** Host-owned delivery attempts. Snapshot columns never change after insertion. */
class Publication extends Model
{
    protected static function booted(): void
    {
        static::updating(function (self $publication): void {
            if ($publication->isDirty(['snapshot', 'sha256', 'version', 'namespace', 'slug', 'is_private', 'retry_of'])) {
                throw new \LogicException('A publication snapshot cannot be modified.');
            }
        });
    }

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['snapshot'];

    protected $casts = [
        'is_private' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return Beam::table('docs_publications');
    }
}
