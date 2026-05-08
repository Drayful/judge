<?php

namespace App\Services;

use App\Models\MusicTrack;
use App\Models\Performance;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MusicTrackUploadService
{
    /**
     * Сохраняет новую версию трека для выхода. История предыдущих версий сохраняется.
     *
     * @throws DomainException дедлайн категории и нет права обойти
     */
    public function store(Performance $performance, UploadedFile $file, User $uploadedBy, string $type = 'primary'): MusicTrack
    {
        $type = in_array($type, ['primary', 'backup'], true) ? $type : 'primary';

        $performance->loadMissing('category.tournament');

        $deadline = $performance->category?->music_deadline_at;
        if ($deadline && now()->greaterThan($deadline) && ! $uploadedBy->canUploadMusicAfterDeadline()) {
            throw new DomainException('Загрузка/замена музыки закрыта по дедлайну обмена. Обратитесь к администратору или секретариату.');
        }

        $disk = config('filesystems.default');
        $path = Storage::disk($disk)->putFileAs(
            "tournaments/{$performance->category->tournament_id}/categories/{$performance->category_id}/performances/{$performance->id}",
            $file,
            now()->format('Ymd_His').'_'.bin2hex(random_bytes(6)).'_'.$file->getClientOriginalName(),
        );

        $latest = MusicTrack::query()
            ->where('performance_id', $performance->id)
            ->where('type', $type)
            ->orderByDesc('version')
            ->first();

        $nextVersion = ($latest?->version ?? 0) + 1;

        if ($latest && $latest->is_active) {
            $latest->is_active = false;
            $latest->replaced_at = now();
            $latest->save();
        }

        return MusicTrack::query()->create([
            'athlete_id' => $performance->athlete_id,
            'performance_id' => $performance->id,
            'type' => $type,
            'version' => $nextVersion,
            'uploaded_by' => $uploadedBy->id,
            'is_active' => true,
            'original_name' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $path,
            'content_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'is_primary' => true,
        ]);
    }
}
