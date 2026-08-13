<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\MusicTrack;
use App\Models\Performance;
use App\Services\MusicTrackUploadService;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AthleteMusicController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $athlete = Athlete::query()->where('user_id', $request->user()->id)->firstOrFail();

        $performances = Performance::query()
            ->with(['category.tournament', 'track', 'trackBackup'])
            ->where('athlete_id', $athlete->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        return view('athlete.music', [
            'athlete' => $athlete,
            'performances' => $performances,
            'now' => now(),
        ]);
    }

    public function store(Request $request, MusicTrackUploadService $uploader): RedirectResponse
    {
        $athlete = Athlete::query()->where('user_id', $request->user()->id)->firstOrFail();

        $request->validate([
            'performance_id' => ['required', 'integer'],
            'type' => ['nullable', 'string', 'in:primary,backup'],
            'music' => ['required', 'file', 'mimetypes:audio/mpeg,audio/mp4,audio/x-m4a,audio/wav', 'max:30720'],
        ]);

        $performance = Performance::query()
            ->where('id', (int) $request->input('performance_id'))
            ->where('athlete_id', $athlete->id)
            ->firstOrFail();

        $type = (string) ($request->input('type') ?: 'primary');
        $file = $request->file('music');

        try {
            $uploader->store($performance, $file, $request->user(), $type);
        } catch (DomainException $e) {
            return back()->with('status', $e->getMessage());
        }

        return back()->with('status', 'Музыка загружена для выбранного выхода.');
    }

    public function download(Request $request, MusicTrack $track)
    {
        $this->authorize('download', $track);

        return redirect()->away($track->temporaryDownloadUrl());
    }

    public function play(Request $request, MusicTrack $track)
    {
        $this->authorize('download', $track);

        return redirect()->away($track->temporaryPlayUrl());
    }
}
