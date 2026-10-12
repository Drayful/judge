<?php

namespace App\Http\Controllers;

use App\Models\MusicTrack;
use App\Models\Performance;
use App\Models\Tournament;
use App\Support\SecretaryLiveUi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Звукорежиссёр: видит текущий выход активного потока турнира и ближайших
 * в очереди, запускает их музыку. Оценки и очередь не меняет.
 */
class MusicOperatorController extends Controller
{
    private const UPCOMING_LIMIT = 5;

    public function index(Request $request): View
    {
        $tournaments = Tournament::query()
            ->whereHas('categories')
            ->orderByDesc('id')
            ->get(['id', 'name']);
        $selectedTournament = $this->selectedTournament($request, $tournaments);

        return view('music-operator.index', [
            'tournaments' => $tournaments,
            'selectedTournament' => $selectedTournament,
            'state' => $selectedTournament ? $this->state($selectedTournament) : null,
        ]);
    }

    public function live(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tournament' => ['required', 'integer', 'exists:tournaments,id'],
        ]);

        return response()->json($this->state(Tournament::query()->findOrFail($data['tournament'])));
    }

    /**
     * @return array{rev: string, stream: ?string, current: ?array, upcoming: list<array>}
     */
    private function state(Tournament $tournament): array
    {
        $category = $tournament->active_category_id
            ? $tournament->categories()->whereKey($tournament->active_category_id)->first()
            : null;

        $current = null;
        $upcoming = collect();
        if ($category !== null) {
            $sessionId = $tournament->active_stream_session_id !== null
                && $category->sessions()->whereKey((int) $tournament->active_stream_session_id)->exists()
                    ? (int) $tournament->active_stream_session_id
                    : null;

            $performances = Performance::query()
                ->with(['athlete', 'track', 'trackBackup'])
                ->where('category_id', $category->id)
                ->when(
                    $sessionId !== null,
                    fn ($query) => $query->where('stream_session_id', $sessionId),
                    fn ($query) => $query->whereNull('stream_session_id'),
                )
                ->whereIn('status', ['performing', 'on_deck', 'scheduled'])
                ->whereNull('withdrawn_at')
                ->get();
            $ordered = SecretaryLiveUi::orderedPerformances($performances);

            $current = $ordered->firstWhere('status', 'performing');
            $upcoming = $ordered
                ->reject(fn (Performance $performance) => $performance->id === $current?->id)
                ->take(self::UPCOMING_LIMIT)
                ->values();
        }

        $currentData = $current ? $this->performanceData($current) : null;
        $upcomingData = $upcoming->map(fn (Performance $performance) => $this->performanceData($performance))->all();

        return [
            'rev' => md5(json_encode([$category?->id, $currentData, $upcomingData])),
            'stream' => $category?->name,
            'current' => $currentData,
            'upcoming' => $upcomingData,
        ];
    }

    private function performanceData(Performance $performance): array
    {
        $athlete = $performance->athlete;

        return [
            'id' => $performance->id,
            'number' => $performance->start_number ?? $performance->order_index,
            'name' => trim(($athlete?->last_name ?? '').' '.($athlete?->is_team ? '' : ($athlete?->first_name ?? ''))),
            'club' => $athlete?->club,
            'apparatus' => $performance->apparatus,
            'status' => $performance->status,
            'track' => $this->trackData($performance->track),
            'backup' => $this->trackData($performance->trackBackup),
        ];
    }

    private function trackData(?MusicTrack $track): ?array
    {
        if ($track === null) {
            return null;
        }

        return [
            'id' => $track->id,
            'name' => $track->original_name,
            'play_url' => route('tracks.play', $track),
            'download_url' => route('tracks.download', $track),
        ];
    }

    /** @param Collection<int, Tournament> $tournaments */
    private function selectedTournament(Request $request, Collection $tournaments): ?Tournament
    {
        $requestedId = $request->integer('tournament');
        if ($requestedId > 0 && $tournaments->contains('id', $requestedId)) {
            $request->session()->put('music_operator_tournament_id', $requestedId);

            return Tournament::query()->find($requestedId);
        }

        $rememberedId = (int) $request->session()->get('music_operator_tournament_id', 0);
        $id = $tournaments->firstWhere('id', $rememberedId)?->id ?? $tournaments->first()?->id;

        return $id ? Tournament::query()->find($id) : null;
    }
}
