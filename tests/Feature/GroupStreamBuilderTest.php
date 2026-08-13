<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Entry;
use App\Models\Group;
use App\Models\Performance;
use App\Models\Tournament;
use App\Models\User;
use App\Services\StreamBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class GroupStreamBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function secretary(): User
    {
        return User::factory()->create(['role' => 'secretary']);
    }

    private function seedPool(Tournament $tournament, int $count, int $year = 2018, string $div = 'C'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $athlete = Athlete::create(['first_name' => 'Имя'.$i, 'last_name' => 'Фамилия'.$i]);
            Entry::create([
                'tournament_id' => $tournament->id,
                'athlete_id' => $athlete->id,
                'program' => 'individual',
                'birth_year' => $year,
                'division' => $div,
                'order_index' => $i,
            ]);
        }
    }

    /**
     * @return array{groups:array{Group,Group},entries:Collection<int, Entry>,sheet:string}
     */
    private function seedImportedTeamsInTwoGroups(Tournament $tournament): array
    {
        $sheet = 'Групповые 2016-2017, МС';
        $groups = [
            Group::create([
                'tournament_id' => $tournament->id,
                'program' => 'group',
                'birth_year' => 2016,
                'name' => 'Групповые — старшая',
                'apparatus' => ['Мяч'],
                'number_mode' => 'continuous',
                'order_index' => 1,
            ]),
            Group::create([
                'tournament_id' => $tournament->id,
                'program' => 'group',
                'birth_year' => 2010,
                'name' => 'Групповые — младшая',
                'apparatus' => ['Мяч'],
                'number_mode' => 'continuous',
                'order_index' => 2,
            ]),
        ];

        $entries = collect();
        foreach (range(1, 4) as $index) {
            $group = $groups[$index <= 2 ? 0 : 1];
            $athlete = Athlete::create([
                'first_name' => '—',
                'last_name' => 'Команда '.$index,
                'is_team' => true,
            ]);
            $entries->push(Entry::create([
                'tournament_id' => $tournament->id,
                'athlete_id' => $athlete->id,
                'group_id' => $group->id,
                'program' => 'group',
                'birth_year' => $group->birth_year,
                'stream_no' => 1,
                'start_number' => (($index - 1) % 2) + 1,
                'order_index' => (($index - 1) % 2) + 1,
                'meta' => [
                    'sheet' => $sheet,
                    'members' => ['Гимнастка '.$index.'.1', 'Гимнастка '.$index.'.2'],
                ],
            ]));
        }

        $builder = app(StreamBuilderService::class);
        foreach ($groups as $group) {
            $builder->renumber($group);
        }

        return compact('groups', 'entries', 'sheet');
    }

    public function test_builder_page_renders(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $this->seedPool($tournament, 5);

        $this->actingAs($this->secretary())
            ->get(route('secretary.tournament.groups', $tournament))
            ->assertOk()
            ->assertSee('Пул участниц')
            ->assertSee('2018 г.р.');
    }

    public function test_unassigned_athlete_can_be_moved_between_existing_pools_one_at_a_time(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $this->seedPool($tournament, 2, 2018, 'A');
        $this->seedPool($tournament, 1, 2017, 'B');

        $source = Entry::query()
            ->where('tournament_id', $tournament->id)
            ->where('birth_year', 2018)
            ->firstOrFail();
        $target = Entry::query()
            ->where('tournament_id', $tournament->id)
            ->where('birth_year', 2017)
            ->firstOrFail();
        $source->update(['meta' => ['label' => '2018 и младше', 'sheet' => '2018 A']]);
        $target->update(['meta' => ['label' => '2017 год', 'sheet' => '2017 B']]);

        $this->actingAs($this->secretary())
            ->get(route('secretary.tournament.groups', $tournament))
            ->assertOk()
            ->assertSee('Выберите целевой пул')
            ->assertSee('2017 год');

        $this->actingAs($this->secretary())
            ->post(route('secretary.entries.move-pool', [$tournament, $source]), [
                'target_entry_id' => $target->id,
            ])
            ->assertRedirect(route('secretary.tournament.groups', $tournament))
            ->assertSessionHas('status');

        $source->refresh();
        $this->assertNull($source->group_id);
        $this->assertSame('individual', $source->program);
        $this->assertSame(2017, $source->birth_year);
        $this->assertSame('B', $source->division);
        $this->assertSame('2017 год', $source->meta['label']);
        $this->assertSame('2018 A', $source->meta['sheet']);
        $this->assertSame(1, Entry::query()->where('tournament_id', $tournament->id)->where('birth_year', 2018)->where('division', 'A')->count());
        $this->assertSame(2, Entry::query()->where('tournament_id', $tournament->id)->where('birth_year', 2017)->where('division', 'B')->count());
    }

    public function test_create_group_and_generate_streams(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $this->seedPool($tournament, 26); // → потоки по 12: 12, 12, 2
        $secretary = $this->secretary();

        $this->actingAs($secretary)
            ->post(route('secretary.tournament.groups.store', $tournament), [
                'program' => 'individual',
                'birth_year' => 2018,
                'division' => 'C',
                'apparatus' => ['Б.П.', 'Мяч'],
                'number_mode' => 'continuous',
            ])->assertRedirect(route('secretary.tournament.groups', $tournament));

        $group = $tournament->groups()->firstOrFail();
        $this->assertSame(26, Entry::where('group_id', $group->id)->count());

        $this->actingAs($secretary)
            ->post(route('secretary.tournament.groups.streams', [$tournament, $group]), [
                'stream_size' => 12,
                'number_mode' => 'continuous',
                'start_time' => '08:00',
                'minutes_per_athlete' => 2,
            ])->assertRedirect(route('secretary.tournament.groups', $tournament));

        // 3 потока (12/12/2)
        $categories = Category::where('group_id', $group->id)->orderBy('stream_no')->get();
        $this->assertCount(3, $categories);

        // Каждая участница × 2 предмета = 52 выступления.
        $totalPerf = Performance::whereIn('category_id', $categories->pluck('id'))->count();
        $this->assertSame(52, $totalPerf);

        // Сквозная нумерация 1..26.
        $this->assertSame(1, (int) Entry::where('group_id', $group->id)->min('start_number'));
        $this->assertSame(26, (int) Entry::where('group_id', $group->id)->max('start_number'));

        // Время первого потока проставлено.
        $first = $categories->firstWhere('stream_no', 1);
        $this->assertSame('08:00', $first->starts_at_label);
        $this->assertSame('08:48', $first->ends_at_label);
        // Время попадает в название потока.
        $this->assertStringContainsString('Поток 1 (08:00–08:48)', $first->name);

        // Круг-за-кругом: первое выступление 1-го потока — БП стартового №1.
        $firstPerf = Performance::where('category_id', $first->id)->orderBy('order_index')->first();
        $this->assertSame('БП', $firstPerf->apparatus);
        $this->assertSame(1, (int) $firstPerf->start_number);
    }

    public function test_queue_add_and_remove_recalculate_participant_times_and_following_streams(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $this->seedPool($tournament, 3);
        $secretary = $this->secretary();

        $this->actingAs($secretary)->post(route('secretary.tournament.groups.store', $tournament), [
            'program' => 'individual',
            'birth_year' => 2018,
            'division' => 'C',
            'apparatus' => ['Б.П.'],
            'number_mode' => 'continuous',
        ]);
        $group = $tournament->groups()->firstOrFail();

        $this->actingAs($secretary)->post(route('secretary.tournament.groups.streams', [$tournament, $group]), [
            'stream_size' => 2,
            'start_time' => '08:00',
            'minutes_per_athlete' => 2,
        ]);

        $first = Category::query()->where('group_id', $group->id)->where('stream_no', 1)->firstOrFail();
        $second = Category::query()->where('group_id', $group->id)->where('stream_no', 2)->firstOrFail();
        $this->assertSame('08:04', $first->ends_at_label);
        $this->assertSame('08:04', $second->starts_at_label);

        $extra = Athlete::create(['first_name' => 'Новая', 'last_name' => 'Участница']);
        $this->actingAs($secretary)
            ->post(route('secretary.queue.add', $first), [
                'athlete_id' => $extra->id,
                'apparatus' => 'Б.П.',
            ])
            ->assertRedirect();

        $first->refresh();
        $second->refresh();
        $added = Performance::query()->where('category_id', $first->id)->where('athlete_id', $extra->id)->firstOrFail();
        $this->assertSame('08:04', $added->scheduled_at_label);
        $this->assertSame('08:06', $first->ends_at_label);
        $this->assertSame('08:06', $second->starts_at_label);
        $this->assertSame('08:08', $second->ends_at_label);

        $secondApparatus = $this->actingAs($secretary)
            ->post(route('secretary.queue.add', $first), [
                'athlete_id' => $extra->id,
                'apparatus' => 'Мяч',
            ]);
        $secondApparatus->assertRedirect();

        $first->refresh();
        $second->refresh();
        $extraPerformances = Performance::query()
            ->where('category_id', $first->id)
            ->where('athlete_id', $extra->id)
            ->orderBy('order_index')
            ->get();
        $this->assertCount(2, $extraPerformances);
        $this->assertSame(['08:04', '08:06'], $extraPerformances->pluck('scheduled_at_label')->all());
        $this->assertSame('08:08', $first->ends_at_label);
        $this->assertSame('08:08', $second->starts_at_label);
        $this->assertSame('08:10', $second->ends_at_label);

        $this->actingAs($secretary)
            ->post(route('secretary.queue.remove', $extraPerformances->last()))
            ->assertRedirect();

        $this->actingAs($secretary)
            ->post(route('secretary.queue.remove', $added))
            ->assertRedirect();

        $first->refresh();
        $second->refresh();
        $this->assertSame('08:04', $first->ends_at_label);
        $this->assertSame('08:04', $second->starts_at_label);
        $this->assertSame('08:06', $second->ends_at_label);
    }

    public function test_shuffle_keeps_stream_membership_and_number_set(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $this->seedPool($tournament, 20, 2018, 'A');
        $secretary = $this->secretary();

        $this->actingAs($secretary)->post(route('secretary.tournament.groups.store', $tournament), [
            'program' => 'individual', 'birth_year' => 2018, 'division' => 'A',
            'apparatus' => ['Б.П.'], 'number_mode' => 'continuous',
        ]);
        $group = $tournament->groups()->firstOrFail();
        $this->actingAs($secretary)->post(route('secretary.tournament.groups.streams', [$tournament, $group]), [
            'stream_size' => 10, // 2 потока по 10
        ]);

        $membershipBefore = Entry::where('group_id', $group->id)->pluck('stream_no', 'athlete_id')->toArray();
        ksort($membershipBefore);

        $this->actingAs($secretary)
            ->post(route('secretary.tournament.groups.shuffle', [$tournament, $group]))
            ->assertRedirect(route('secretary.tournament.groups', $tournament));

        $membershipAfter = Entry::where('group_id', $group->id)->pluck('stream_no', 'athlete_id')->toArray();
        ksort($membershipAfter);
        $numbersAfter = Entry::where('group_id', $group->id)->pluck('start_number')->sort()->values()->toArray();

        // Кто в каком потоке — не изменилось; набор номеров 1..20 сохранился.
        $this->assertSame($membershipBefore, $membershipAfter);
        $this->assertSame(range(1, 20), $numbersAfter);

        // Очередь пересобрана: у каждого потока по 10 выступлений.
        foreach (Category::where('group_id', $group->id)->get() as $cat) {
            $this->assertSame(10, Performance::where('category_id', $cat->id)->count());
        }
    }

    public function test_imported_group_teams_can_be_shuffled_between_groups_from_the_same_excel_sheet(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        ['groups' => $groups, 'entries' => $entries, 'sheet' => $sheet] = $this->seedImportedTeamsInTwoGroups($tournament);
        $before = $entries->pluck('group_id', 'id')->all();

        $this->actingAs($this->secretary())
            ->get(route('secretary.tournament.groups', $tournament))
            ->assertOk()
            ->assertSee('Перемешать групповые команды из Excel')
            ->assertSee($sheet);

        $this->actingAs($this->secretary())
            ->post(route('secretary.tournament.groups.shuffle-imported-teams', $tournament), ['sheet' => $sheet])
            ->assertRedirect(route('secretary.tournament.groups', $tournament))
            ->assertSessionHas('status');

        $after = Entry::query()->whereKey($entries->pluck('id'))->pluck('group_id', 'id')->all();
        $this->assertNotSame($before, $after);
        $this->assertSame(2, Entry::query()->where('group_id', $groups[0]->id)->count());
        $this->assertSame(2, Entry::query()->where('group_id', $groups[1]->id)->count());

        foreach ($groups as $group) {
            $category = Category::query()->where('group_id', $group->id)->firstOrFail();
            $this->assertSame(2, Performance::query()->where('category_id', $category->id)->count());
        }

        $this->assertSame(
            ['Гимнастка 1.1', 'Гимнастка 1.2'],
            Entry::query()->findOrFail($entries->first()->id)->meta['members'],
        );
    }

    public function test_one_imported_team_can_be_moved_to_a_group_of_another_year_from_the_same_excel_sheet(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        ['groups' => $groups, 'entries' => $entries] = $this->seedImportedTeamsInTwoGroups($tournament);
        $entry = $entries->firstWhere('group_id', $groups[0]->id);

        $this->assertNotNull($entry);
        $this->assertNotSame($groups[0]->birth_year, $groups[1]->birth_year);

        $this->actingAs($this->secretary())
            ->get(route('secretary.tournament.groups', $tournament))
            ->assertOk()
            ->assertSee($groups[1]->name);

        $this->actingAs($this->secretary())
            ->post(route('secretary.entries.move-group', [$tournament, $entry]), [
                'target_group_id' => $groups[1]->id,
            ])
            ->assertRedirect(route('secretary.tournament.groups', $tournament))
            ->assertSessionHas('status');

        $entry->refresh();
        $this->assertSame($groups[1]->id, $entry->group_id);
        $this->assertSame(2016, $entry->birth_year);
        $this->assertSame(1, Entry::query()->where('group_id', $groups[0]->id)->count());
        $this->assertSame(3, Entry::query()->where('group_id', $groups[1]->id)->count());

        $sourceCategory = Category::query()->where('group_id', $groups[0]->id)->firstOrFail();
        $targetCategory = Category::query()->where('group_id', $groups[1]->id)->firstOrFail();
        $this->assertSame(1, Performance::query()->where('category_id', $sourceCategory->id)->count());
        $this->assertSame(3, Performance::query()->where('category_id', $targetCategory->id)->count());
    }

    public function test_imported_team_cannot_be_moved_to_another_year_from_a_different_excel_sheet(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        ['groups' => $groups, 'entries' => $entries] = $this->seedImportedTeamsInTwoGroups($tournament);
        $entry = $entries->firstWhere('group_id', $groups[0]->id);

        Entry::query()->where('group_id', $groups[1]->id)->get()->each(function (Entry $targetEntry) {
            $meta = $targetEntry->meta;
            $meta['sheet'] = 'Другой Excel-лист';
            $targetEntry->update(['meta' => $meta]);
        });

        $this->actingAs($this->secretary())
            ->post(route('secretary.entries.move-group', [$tournament, $entry]), [
                'target_group_id' => $groups[1]->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('group_move');

        $this->assertSame($groups[0]->id, $entry->fresh()->group_id);
    }

    public function test_imported_group_teams_cannot_be_shuffled_after_a_performance_started(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        ['entries' => $entries, 'sheet' => $sheet] = $this->seedImportedTeamsInTwoGroups($tournament);
        $before = $entries->pluck('group_id', 'id')->all();

        Performance::query()->firstOrFail()->update(['status' => 'performing']);

        $this->actingAs($this->secretary())
            ->post(route('secretary.tournament.groups.shuffle-imported-teams', $tournament), ['sheet' => $sheet])
            ->assertRedirect()
            ->assertSessionHasErrors('excel_group_shuffle');

        $after = Entry::query()->whereKey($entries->pluck('id'))->pluck('group_id', 'id')->all();
        $this->assertSame($before, $after);
    }

    public function test_move_entry_between_streams(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $this->seedPool($tournament, 20, 2018, 'A');
        $secretary = $this->secretary();

        $this->actingAs($secretary)->post(route('secretary.tournament.groups.store', $tournament), [
            'program' => 'individual', 'birth_year' => 2018, 'division' => 'A',
            'apparatus' => ['Б.П.'], 'number_mode' => 'continuous',
        ]);
        $group = $tournament->groups()->firstOrFail();
        $this->actingAs($secretary)->post(route('secretary.tournament.groups.streams', [$tournament, $group]), [
            'stream_size' => 10,
        ]);

        // Берём участницу из потока 1 и переносим в поток 2.
        $entry = Entry::where('group_id', $group->id)->where('stream_no', 1)->orderBy('start_number')->firstOrFail();

        $this->actingAs($secretary)
            ->post(route('secretary.entries.move', $entry), ['stream_no' => 2])
            ->assertRedirect(route('secretary.tournament.groups', $tournament));

        $entry->refresh();
        $this->assertSame(2, (int) $entry->stream_no);

        // Поток 1 → 9, поток 2 → 11; номера остались сквозными 1..20.
        $this->assertSame(9, Entry::where('group_id', $group->id)->where('stream_no', 1)->count());
        $this->assertSame(11, Entry::where('group_id', $group->id)->where('stream_no', 2)->count());
        $this->assertSame(range(1, 20), Entry::where('group_id', $group->id)->pluck('start_number')->sort()->values()->toArray());

        $cat2 = Category::where('group_id', $group->id)->where('stream_no', 2)->firstOrFail();
        $this->assertSame(11, Performance::where('category_id', $cat2->id)->count());
    }

    public function test_manual_entry_added_to_group_with_streams(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $this->seedPool($tournament, 12);
        $secretary = $this->secretary();

        $this->actingAs($secretary)->post(route('secretary.tournament.groups.store', $tournament), [
            'program' => 'individual', 'birth_year' => 2018, 'division' => 'C',
            'apparatus' => ['Б.П.'], 'number_mode' => 'continuous',
        ]);
        $group = $tournament->groups()->firstOrFail();

        $this->actingAs($secretary)->post(route('secretary.tournament.groups.streams', [$tournament, $group]), [
            'stream_size' => 12, 'start_time' => '08:00', 'minutes_per_athlete' => 2,
        ]);

        // Ручная вставка забытой участницы в группу.
        $this->actingAs($secretary)
            ->post(route('secretary.tournament.entries.store', $tournament), [
                'full_name' => 'Забытая Участница',
                'program' => 'individual',
                'group_id' => $group->id,
                'club' => 'Клуб X',
            ])->assertRedirect(route('secretary.tournament.groups', $tournament));

        // Стало 13 в группе, номер 13 у новенькой, попала в поток и в очередь.
        $this->assertSame(13, Entry::where('group_id', $group->id)->count());
        $newbie = Entry::where('group_id', $group->id)
            ->whereHas('athlete', fn ($q) => $q->where('last_name', 'Забытая'))
            ->firstOrFail();
        $this->assertSame(13, (int) $newbie->start_number);
        $this->assertNotNull($newbie->stream_no);

        $category = Category::where('group_id', $group->id)->where('stream_no', $newbie->stream_no)->firstOrFail();
        $this->assertSame(13, Performance::where('category_id', $category->id)->count());
    }

    public function test_mass_generate_streams_for_all_groups(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        $this->seedPool($tournament, 5, 2018, 'A');
        $this->seedPool($tournament, 3, 2017, 'B');
        $secretary = $this->secretary();

        // Две группы без потоков.
        foreach ([[2018, 'A'], [2017, 'B']] as [$year, $div]) {
            $this->actingAs($secretary)->post(route('secretary.tournament.groups.store', $tournament), [
                'program' => 'individual', 'birth_year' => $year, 'division' => $div,
                'apparatus' => ['Б.П.'], 'number_mode' => 'continuous',
            ]);
        }
        $this->assertSame(0, Category::whereIn('group_id', $tournament->groups()->pluck('id'))->count());

        // Массово нарезать потоки во всех группах.
        $this->actingAs($secretary)
            ->post(route('secretary.tournament.streams.all', $tournament), [
                'stream_size' => 4, 'start_time' => '09:00', 'minutes_per_athlete' => 2,
            ])->assertRedirect(route('secretary.tournament.groups', $tournament));

        // 2018/A: 5/4 → 2 потока; 2017/B: 3 → 1 поток.
        $groupA = $tournament->groups()->where('birth_year', 2018)->firstOrFail();
        $groupB = $tournament->groups()->where('birth_year', 2017)->firstOrFail();
        $this->assertSame(2, Category::where('group_id', $groupA->id)->count());
        $this->assertSame(1, Category::where('group_id', $groupB->id)->count());

        // Каскад: пять участниц A занимают 10 минут, затем стартует B.
        $this->assertSame('09:00', Category::where('group_id', $groupA->id)->where('stream_no', 1)->value('starts_at_label'));
        $this->assertSame('09:10', Category::where('group_id', $groupB->id)->where('stream_no', 1)->value('starts_at_label'));
    }

    public function test_assemble_tournament_one_click(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        // Два пула: 2018/A (5) и 2017/B (3).
        $this->seedPool($tournament, 5, 2018, 'A');
        $this->seedPool($tournament, 3, 2017, 'B');

        $this->actingAs($this->secretary())
            ->post(route('secretary.tournament.assemble', $tournament), [
                'apparatus' => ['Б.П.'],
                'stream_size' => 4,
                'start_time' => '08:00',
                'minutes_per_athlete' => 2,
                'number_mode' => 'continuous',
            ])->assertRedirect(route('secretary.tournament.groups', $tournament));

        // Две группы, весь пул привязан.
        $this->assertSame(2, $tournament->groups()->count());
        $this->assertSame(0, Entry::where('tournament_id', $tournament->id)->whereNull('group_id')->count());

        // 2018/A: 5 уч. / размер 4 → 2 потока; 2017/B: 3 уч. → 1 поток. Итого 3 потока.
        $this->assertSame(3, Category::whereIn('group_id', $tournament->groups()->pluck('id'))->count());

        // Каскад времени: следующая группа стартует после всех пяти участниц первой.
        $groupA = $tournament->groups()->where('birth_year', 2018)->firstOrFail();
        $groupB = $tournament->groups()->where('birth_year', 2017)->firstOrFail();
        $this->assertSame('08:00', Category::where('group_id', $groupA->id)->where('stream_no', 1)->value('starts_at_label'));
        $this->assertSame('08:10', Category::where('group_id', $groupB->id)->where('stream_no', 1)->value('starts_at_label'));

        // Повторный запуск — новых пулов нет, ошибка (ничего не дублируется).
        $this->actingAs($this->secretary())
            ->post(route('secretary.tournament.assemble', $tournament), [
                'apparatus' => ['Б.П.'], 'stream_size' => 4,
            ])->assertSessionHasErrors('assemble');
        $this->assertSame(2, $tournament->groups()->count());
    }

    public function test_assemble_uses_separate_apparatus_for_group_program(): void
    {
        $tournament = Tournament::create(['name' => 'T', 'timezone' => 'Asia/Almaty']);
        // Индивидуальный пул и групповой (команды).
        $this->seedPool($tournament, 3, 2018, 'A');
        $team = Athlete::create(['first_name' => '—', 'last_name' => 'Nova']);
        Entry::create([
            'tournament_id' => $tournament->id, 'athlete_id' => $team->id,
            'program' => 'group', 'birth_year' => 2016, 'division' => null,
        ]);

        $this->actingAs($this->secretary())
            ->post(route('secretary.tournament.assemble', $tournament), [
                'apparatus' => ['Б.П.'],           // индивидуальные
                'group_apparatus' => ['Обруч', 'Мяч'], // групповые — свои
                'stream_size' => 12,
                'start_time' => '08:00', 'minutes_per_athlete' => 2,
            ])->assertRedirect(route('secretary.tournament.groups', $tournament));

        $indiv = $tournament->groups()->where('program', 'individual')->firstOrFail();
        $grp = $tournament->groups()->where('program', 'group')->firstOrFail();

        $this->assertSame(['Б.П.'], $indiv->apparatusLabels());
        $this->assertSame(['Обруч', 'Мяч'], $grp->apparatusLabels());

        // Групповые — секцией после индивидуальных (каскад времени): индивид с 08:00,
        // групповые позже.
        $indivStart = Category::where('group_id', $indiv->id)->where('stream_no', 1)->value('starts_at_label');
        $grpStart = Category::where('group_id', $grp->id)->where('stream_no', 1)->value('starts_at_label');
        $this->assertSame('08:00', $indivStart);
        $this->assertTrue($grpStart > $indivStart, "групповые ({$grpStart}) должны идти после индивидуальных ({$indivStart})");
    }
}
