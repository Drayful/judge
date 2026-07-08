<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Entry;
use App\Models\Tournament;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Импорт «списка участвующих» (Excel) в пул участниц турнира (entries).
 *
 * Формат файла: по одному листу на (год + буква категории) — «2019 А», «2018С»,
 * «2020 и мл»; колонки A=ФИО, B=год, C=клуб. Плюс листы «груп…» (групповые команды,
 * свободный формат) и «Лист судей» (пропускается).
 *
 * Импорт только наполняет пул. Группы (набор предметов) и потоки (время, стартовые
 * номера, очередь выступлений) формируются отдельно — см. StreamBuilderService.
 */
class StartProtocolImportService
{
    /**
     * @return array{sheets_processed:int, sheets_skipped:int, entries_created:int, athletes_created:int, entries_skipped:int, group_teams_created:int}
     */
    public function importFromPath(Tournament $tournament, string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException('Файл не найден или недоступен.');
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable $e) {
            throw new RuntimeException('Не удалось прочитать Excel: '.$e->getMessage(), 0, $e);
        }

        $stats = [
            'sheets_processed' => 0,
            'sheets_skipped' => 0,
            'entries_created' => 0,
            'athletes_created' => 0,
            'entries_skipped' => 0,
            'group_teams_created' => 0,
        ];

        DB::transaction(function () use ($spreadsheet, $tournament, &$stats) {
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $title = trim($sheet->getTitle());

                if (preg_match('/суд/iu', $title)) {
                    $stats['sheets_skipped']++;

                    continue;
                }

                if (preg_match('/^\s*груп/iu', $title)) {
                    $this->importGroupSheet($sheet, $tournament, $title, $stats);
                    $stats['sheets_processed']++;

                    continue;
                }

                if (preg_match('/^\s*\d{4}/u', $title)) {
                    $this->importIndividualSheet($sheet, $tournament, $title, $stats);
                    $stats['sheets_processed']++;

                    continue;
                }

                $stats['sheets_skipped']++;
            }
        });

        return $stats;
    }

    /**
     * Индивидуальный лист «2019 А» / «2018С» / «2020 и мл».
     */
    private function importIndividualSheet(Worksheet $sheet, Tournament $tournament, string $title, array &$stats): void
    {
        [$sheetYear, $division, $label] = $this->parseIndividualTitle($title);
        $highestRow = (int) $sheet->getHighestRow();
        $order = 0;

        for ($row = 1; $row <= $highestRow; $row++) {
            $name = $this->cellStr($sheet, $row, 'A');
            if (mb_strlen($name) < 3) {
                continue;
            }

            $rowYear = $this->parseYear($sheet->getCell('B'.$row)->getValue()) ?? $sheetYear;
            $club = $this->cellStr($sheet, $row, 'C');

            [$lastName, $firstName] = $this->splitName($name);
            $birthdate = $rowYear !== null ? Carbon::createFromDate($rowYear, 1, 1)->startOfDay() : null;

            $athlete = $this->resolveAthlete($lastName, $firstName, $birthdate, $club, $stats);

            if ($this->entryExists($tournament, $athlete->id, 'individual')) {
                $stats['entries_skipped']++;

                continue;
            }

            Entry::query()->create([
                'tournament_id' => $tournament->id,
                'athlete_id' => $athlete->id,
                'program' => 'individual',
                'birth_year' => $rowYear ?? $sheetYear,
                'division' => $division,
                'club' => $club !== '' ? $club : null,
                'order_index' => ++$order,
                'meta' => ['sheet' => $title, 'label' => $label],
            ]);

            $stats['entries_created']++;
        }
    }

    /**
     * Лист «груп…»: сегментируем на команды по строкам-заголовкам (клуб/федерация/
     * кавычки/Team). Каждая команда = одна entry (program=group), состав — в meta.
     */
    private function importGroupSheet(Worksheet $sheet, Tournament $tournament, string $title, array &$stats): void
    {
        [$sheetYear, , $label] = $this->parseGroupTitle($title);
        $highestRow = (int) $sheet->getHighestRow();

        /** @var list<array{name:string, club:string, year:?int, members:list<string>}> $teams */
        $teams = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            $a = $this->cellStr($sheet, $row, 'A');
            if ($a === '') {
                continue;
            }
            $b = $this->cellStr($sheet, $row, 'B');

            if ($this->isTeamHeader($a)) {
                $teams[] = [
                    'name' => $this->teamName($a),
                    'club' => $a,
                    'year' => $this->firstYearIn($a) ?? $sheetYear,
                    'members' => [],
                ];

                continue;
            }

            // строка-участница до первого заголовка → неявная команда листа
            if ($teams === []) {
                $teams[] = [
                    'name' => $label !== '' ? $label : $title,
                    'club' => '',
                    'year' => $sheetYear,
                    'members' => [],
                ];
            }

            $member = $this->cleanMemberName($a);
            if ($b !== '' && $this->parseYear($b) !== null) {
                $member .= ' '.$b;
            }
            $teams[count($teams) - 1]['members'][] = $member;
        }

        foreach ($teams as $team) {
            $teamName = $team['name'] !== '' ? $team['name'] : ($label !== '' ? $label : $title);
            $birthdate = $team['year'] !== null ? Carbon::createFromDate($team['year'], 1, 1)->startOfDay() : null;

            $athlete = $this->resolveAthlete($teamName, '—', $birthdate, $team['club'], $stats);

            if ($this->entryExists($tournament, $athlete->id, 'group')) {
                $stats['entries_skipped']++;

                continue;
            }

            Entry::query()->create([
                'tournament_id' => $tournament->id,
                'athlete_id' => $athlete->id,
                'program' => 'group',
                'birth_year' => $team['year'] ?? $sheetYear,
                'division' => null,
                'club' => $team['club'] !== '' ? $team['club'] : null,
                'meta' => [
                    'sheet' => $title,
                    'label' => $label,
                    'members' => $team['members'],
                ],
            ]);

            $stats['entries_created']++;
            $stats['group_teams_created']++;
        }
    }

    private function entryExists(Tournament $tournament, int $athleteId, string $program): bool
    {
        return Entry::query()
            ->where('tournament_id', $tournament->id)
            ->where('athlete_id', $athleteId)
            ->where('program', $program)
            ->exists();
    }

    /**
     * @return array{0:?int,1:?string,2:?string} [birth_year, division, label]
     */
    private function parseIndividualTitle(string $title): array
    {
        $year = preg_match('/(\d{4})/u', $title, $m) ? (int) $m[1] : null;

        $division = null;
        if (preg_match('/\d{4}\s*([АВСABCавсabc])/u', $title, $mm)) {
            $division = $this->normalizeDivision($mm[1]);
        }

        $label = preg_match('/и\s*мл|младш/iu', $title) ? $title : null;

        return [$year, $division, $label];
    }

    /**
     * @return array{0:?int,1:?string,2:string} [birth_year, division, label]
     */
    private function parseGroupTitle(string $title): array
    {
        $year = preg_match('/(\d{4})/u', $title, $m) ? (int) $m[1] : null;
        $label = trim((string) preg_replace('/^\s*груп[^\s]*\s*/iu', '', $title));

        return [$year, null, $label];
    }

    private function normalizeDivision(string $letter): string
    {
        $u = mb_strtoupper(trim($letter));
        $map = ['А' => 'A', 'В' => 'B', 'С' => 'C', 'A' => 'A', 'B' => 'B', 'C' => 'C'];

        return $map[$u] ?? $u;
    }

    /**
     * Строка-заголовок команды: кавычки, «Федерация», «Team», клубный токен.
     */
    private function isTeamHeader(string $a): bool
    {
        return (bool) preg_match('/[«»“”"]|федерац|\bteam\b|ШХГ|СХГ|СДЮ|г\.\s*алмат|MGS/iu', $a);
    }

    private function teamName(string $header): string
    {
        if (preg_match('/[«“"]([^»”"]+)[»”"]/u', $header, $m)) {
            return trim($m[1]);
        }

        return trim($header);
    }

    private function firstYearIn(string $s): ?int
    {
        if (preg_match('/(\d{4})/u', $s, $m)) {
            return $this->parseYear($m[1]);
        }

        return null;
    }

    private function cleanMemberName(string $a): string
    {
        // ведущая нумерация «1. », «2) », год в конце оставляем — он информативен.
        return trim((string) preg_replace('/^\s*\d+\s*[.)]\s*/u', '', $a));
    }

    private function resolveAthlete(string $lastName, string $firstName, ?Carbon $birthdate, string $clubFromRow, array &$stats): Athlete
    {
        $q = Athlete::query()
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($lastName)])
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($firstName)]);

        if ($birthdate !== null) {
            $q->whereDate('birthdate', $birthdate);
        } else {
            $q->whereNull('birthdate');
        }

        $found = $q->first();

        if ($found) {
            if ($clubFromRow !== '' && ($found->club === null || $found->club === '')) {
                $found->club = $clubFromRow;
                $found->save();
            }

            return $found;
        }

        $stats['athletes_created']++;

        return Athlete::query()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birthdate' => $birthdate,
            'club' => $clubFromRow !== '' ? $clubFromRow : null,
        ]);
    }

    private function cellStr(Worksheet $sheet, int $row, string $col): string
    {
        $val = $sheet->getCell($col.$row)->getValue();
        if ($val === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $val));
    }

    private function parseYear(mixed $d): ?int
    {
        if ($d === null || $d === '') {
            return null;
        }
        if (is_numeric($d)) {
            $y = (int) $d;
            if ($y >= 1990 && $y <= ((int) date('Y')) + 2) {
                return $y;
            }
        }

        return null;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitName(string $full): array
    {
        $full = (string) preg_replace('/\s+/u', ' ', trim($full));
        $parts = preg_split('/\s+/u', $full, 2);
        $last = $parts[0] ?? '';
        $first = $parts[1] ?? '';

        return [$last, $first !== '' ? $first : '—'];
    }
}
