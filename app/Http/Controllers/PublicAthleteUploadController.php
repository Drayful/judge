<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Performance;
use App\Services\MusicTrackUploadService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PublicAthleteUploadController extends Controller
{
    public function index(Request $request): View
    {
        $iin = $request->query('iin');
        $athlete = is_string($iin) && preg_match('/^\d{12}$/', $iin)
            ? Athlete::query()->where('iin', $iin)->first()
            : null;

        if (! $athlete) {
            return view('public-athlete-upload.index');
        }

        return $this->form($athlete);
    }

    public function search(Request $request): View|RedirectResponse
    {
        $data = $request->validate([
            'iin' => ['required', 'string', 'regex:/^\\d{12}$/'],
        ], [
            'iin.regex' => 'ИИН должен состоять из 12 цифр.',
        ]);

        $athlete = Athlete::query()
            ->where('iin', $data['iin'])
            ->first();

        if (! $athlete) {
            return back()->withErrors(['iin' => 'Атлетка с таким ИИН не найдена.'])->onlyInput('iin');
        }

        return redirect()->route('public-athlete-upload.index', ['iin' => $athlete->iin]);
    }

    public function store(Request $request, Athlete $athlete, MusicTrackUploadService $uploader): RedirectResponse
    {
        $data = $request->validate([
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'music' => ['nullable', 'array'],
            'music.*' => ['nullable', 'file', 'mimetypes:audio/mpeg,audio/mp4,audio/x-m4a,audio/wav', 'max:30720'],
        ]);

        $files = array_filter($request->file('music', []));
        if (! $request->hasFile('photo') && $files === []) {
            return $this->redirectToForm($athlete)
                ->withErrors(['upload' => 'Выберите хотя бы один музыкальный файл или фотографию.']);
        }

        $performances = Performance::query()
            ->with('category.tournament')
            ->where('athlete_id', $athlete->id)
            ->whereIn('id', array_map('intval', array_keys($files)))
            ->get()
            ->keyBy('id');

        foreach (array_keys($files) as $performanceId) {
            if (! $performances->has((int) $performanceId)) {
                abort(422, 'Некорректное выступление.');
            }
        }

        try {
            foreach ($performances as $performance) {
                $uploader->store($performance, $files[$performance->id], null);
            }
        } catch (DomainException $e) {
            return $this->redirectToForm($athlete)->withErrors(['upload' => $e->getMessage()]);
        }

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $disk = config('filesystems.default');
            $path = Storage::disk($disk)->putFileAs(
                "athletes/{$athlete->id}/photo",
                $file,
                now()->format('Ymd_His').'_'.bin2hex(random_bytes(6)).'_'.$file->getClientOriginalName(),
            );

            $athlete->update([
                'photo_disk' => $disk,
                'photo_path' => $path,
                'photo_original_name' => $file->getClientOriginalName(),
            ]);
        }

        return redirect()
            ->route('public-athlete-upload.index')
            ->with('status', 'Файлы успешно загружены.');
    }

    private function form(Athlete $athlete): View
    {
        $performances = Performance::query()
            ->with(['category.tournament', 'track'])
            ->where('athlete_id', $athlete->id)
            ->where('status', '!=', 'withdrawn')
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        return view('public-athlete-upload.index', compact('athlete', 'performances'));
    }

    private function redirectToForm(Athlete $athlete): RedirectResponse
    {
        return redirect()->route('public-athlete-upload.index', ['iin' => $athlete->iin]);
    }
}
