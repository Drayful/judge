<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MusicTrack extends Model
{
    protected $fillable = [
        'athlete_id',
        'performance_id',
        'type',
        'version',
        'uploaded_by',
        'replaced_at',
        'is_active',
        'locked_after',
        'original_name',
        'disk',
        'path',
        'content_type',
        'size_bytes',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'bool',
        'is_active' => 'bool',
        'replaced_at' => 'datetime',
        'locked_after' => 'datetime',
    ];

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function performance(): BelongsTo
    {
        return $this->belongsTo(Performance::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function temporaryDownloadUrl(int $minutes = 5): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $this->path,
            now()->addMinutes($minutes),
            [
                'ResponseContentDisposition' => 'attachment; filename="'.$this->original_name.'"',
            ],
        );
    }

    /** Временная ссылка для встроенного проигрывателя без forced-download. */
    public function temporaryPlayUrl(int $minutes = 30): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $this->path,
            now()->addMinutes($minutes),
            ['ResponseContentType' => $this->content_type ?: 'audio/mpeg'],
        );
    }
}
