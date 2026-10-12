<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\MusicTrack;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MusicUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
    }

    private function performance(?int $athleteUserId = null, array $categoryOverrides = []): Performance
    {
        $tournament = Tournament::forceCreate(['name' => 'Кубок', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-02']);
        $category = Category::forceCreate(array_merge(['tournament_id' => $tournament->id, 'name' => 'Поток 1'], $categoryOverrides));
        $athlete = Athlete::forceCreate([
            'first_name' => 'Имя',
            'last_name' => 'Фамилия',
            'birthdate' => '2015-01-01',
            'club' => 'Клуб',
            'iin' => '150101123456',
            'user_id' => $athleteUserId,
        ]);

        return Performance::forceCreate([
            'category_id' => $category->id,
            'athlete_id' => $athlete->id,
            'order_index' => 0,
            'status' => 'scheduled',
            'is_counted' => true,
        ]);
    }

    private function mp3(string $name = 'song.mp3'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x00", 500));
    }

    public function test_secretary_uploads_music_and_new_version_replaces_previous(): void
    {
        $performance = $this->performance();
        $secretary = User::factory()->create(['role' => 'secretary']);

        foreach (['first.mp3', 'second.mp3'] as $name) {
            $this->actingAs($secretary)
                ->post(route('secretary.category.performance.music', $performance->category_id), [
                    'performance_id' => $performance->id,
                    'music' => $this->mp3($name),
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $tracks = MusicTrack::query()->where('performance_id', $performance->id)->orderBy('version')->get();
        $this->assertSame([1, 2], $tracks->pluck('version')->all());
        $this->assertFalse($tracks[0]->is_active);
        $this->assertTrue($tracks[1]->is_active);
        $this->assertSame($secretary->id, $tracks[1]->uploaded_by);
        $this->assertSame('second.mp3', $performance->fresh()->track->original_name);
        Storage::disk(config('filesystems.default'))->assertExists($tracks[1]->path);
    }

    public function test_athlete_uploads_own_music(): void
    {
        $user = User::factory()->create(['role' => 'athlete']);
        $performance = $this->performance($user->id);

        $this->actingAs($user)
            ->post(route('athlete.music.store'), [
                'performance_id' => $performance->id,
                'type' => 'backup',
                'music' => $this->mp3(),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Музыка загружена для выбранного выхода.');

        $this->assertNotNull($performance->fresh()->trackBackup);
    }

    public function test_secretary_can_upload_after_deadline(): void
    {
        $performance = $this->performance(null, ['music_deadline_at' => now()->subDay()]);
        $secretary = User::factory()->create(['role' => 'secretary']);

        $this->actingAs($secretary)
            ->post(route('secretary.category.performance.music', $performance->category_id), [
                'performance_id' => $performance->id,
                'music' => $this->mp3(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($performance->fresh()->track);
    }

    public function test_public_upload_is_closed_after_deadline(): void
    {
        $performance = $this->performance(null, ['music_deadline_at' => now()->subDay()]);

        $this->post(route('public-athlete-upload.store', $performance->athlete_id), [
            'music' => [$performance->id => $this->mp3()],
        ])->assertSessionHasErrors('upload');

        $this->assertSame(0, MusicTrack::query()->count());
    }
}
