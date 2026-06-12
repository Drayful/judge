<?php

namespace App\Services;

use App\Support\PerformanceApparatus;
use App\Models\Athlete;
use App\Models\Category;
use App\Models\Performance;
use App\Models\Tournament;
use App\Support\CategoryMeta;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class StartProtocolImportService
{
    /**
     * Очередь строк текущего потока (до смены «Поток»/«Группа»/конца листа).
     * Число кругов и снаряды — только из названия группы («N вида», «Б.П.», «Б.П.; 2 вида»).
     * Порядок в очереди: сначала все по 1-му кругу, затем по 2-му… (по стартовому внутри круга).
     *
     * @var list<array{start:int,full_name:string,year:?int,club:string,vid_slots:list<array{offset:int,raw:string}>,group_line:?string}>
     */
    private array $streamRowBuffer = [];

    /**
     * В рамках одного сброса потока: сколько раз уже встречалась подпись снаряда у участницы (для «Мяч» + «Мяч» в двух колонках).
     *
     * @var array<int, array<string, int>>
     */
    private array $importBaseOccurrence = [];

    /**
     * Импортирует стартовый протокол Excel: блоки «Группа: …», «Поток N …», строки участниц.
     * Снаряды и число кругов — только из строки «Группа: …». Стартовый № в A, B или C.
     *
     * @return array{categories_created:int, categories_reused:int, athletes_created:int, performances_created:int, rows_skipped:int}
     */
    public function importFromPath(Tournament $tournament, string $path): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Файл не найден или недоступен.');
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable $e) {
            throw new RuntimeException('Не удалось прочитать Excel: '.$e->getMessage(), 0, $e);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = (int) $sheet->getHighestRow();

        $currentGroup = null;
        $groupApparatus = null;
        $groupBirthYear = null;
        $currentCategory = null;

        $stats = [
            'categories_created' => 0,
            'categories_reused' => 0,
            'athletes_created' => 0,
            'performances_created' => 0,
            'rows_skipped' => 0,
        ];

        DB::transaction(function () use ($sheet, $highestRow, $tournament, &$currentGroup, &$groupApparatus, &$groupBirthYear, &$currentCategory, &$stats) {
            for ($row = 1; $row <= $highestRow; $row++) {
                $line = $this->findGroupOrStreamLine($sheet, $row);

                if ($line !== '' && str_contains($line, 'Группа:')) {
                    $this->flushStreamBuffer($currentCategory, $currentGroup, $stats);
                    if (preg_match('/Группа:\s*(.+)/u', $line, $m)) {
                        $currentGroup = trim($m[1]);
                    } else {
                        $currentGroup = trim($line);
                    }
                    $groupApparatus = $this->extractApparatusLabel($currentGroup);
                    $groupBirthYear = $this->extractBirthYear($currentGroup);
                    $currentCategory = null;

                    continue;
                }

                if ($line !== '' && preg_match('/Поток\s+(\d+)/u', $line, $m)) {
                    $this->flushStreamBuffer($currentCategory, $currentGroup, $stats);

                    $streamNo = (int) $m[1];

                    $streamTime = null;
                    if (preg_match('/(\d{2}:\d{2}\s*-\s*\d{2}:\d{2})/u', $line, $tm)) {
                        $streamTime = $tm[1];
                    }

                    if ($currentGroup === null) {
                        $currentGroup = 'Без группы';
                    }

                    $categoryName = $currentGroup.' — Поток '.$streamNo;
                    if ($streamTime !== null) {
                        $categoryName .= ' ('.$streamTime.')';
                    }

                    $existing = Category::query()
                        ->where('tournament_id', $tournament->id)
                        ->where('name', $categoryName)
                        ->first();

                    if ($existing) {
                        $currentCategory = $existing;
                        $stats['categories_reused']++;
                    } else {
                        $currentCategory = Category::query()->create([
                            'tournament_id' => $tournament->id,
                            'name' => $categoryName,
                            'program' => 'individual',
                            'apparatus' => $groupApparatus,
                            'birth_year' => $groupBirthYear,
                            'division' => CategoryMeta::extractDivision($currentGroup),
                            'age_min' => $groupBirthYear,
                            'age_max' => $groupBirthYear,
                            'is_published' => false,
                        ]);
                        $stats['categories_created']++;
                    }

                    continue;
                }

                if ($currentCategory === null) {
                    continue;
                }

                $parsed = $this->parseAthleteRow($sheet, $row);
                if ($parsed === null) {
                    continue;
                }

                $this->streamRowBuffer[] = [
                    'start' => $parsed['start'],
                    'full_name' => $parsed['full_name'],
                    'year' => $parsed['year'],
                    'club' => $parsed['club'],
                    'group_line' => $currentGroup,
                ];
            }

            $this->flushStreamBuffer($currentCategory, $currentGroup, $stats);
        });

        return $stats;
    }

    /**
     * @return array{start:int,full_name:string,year:?int,club:string}|null
     */
    private function parseAthleteRow(Worksheet $sheet, int $row): ?array
    {
        $startColIdx = $this->detectStartColumnIndex($sheet, $row);
        if ($startColIdx === null) {
            return null;
        }

        $startNum = $this->parseInt($this->cellStr($sheet, $row, Coordinate::stringFromColumnIndex($startColIdx)));
        if ($startNum === null || $startNum < 1 || $startNum > 999) {
            return null;
        }

        $nameColIdx = $startColIdx + 1;
        [$yearColIdx, $clubColIdx] = $this->resolveYearClubColumnIndices($sheet, $row, $nameColIdx);

        $name = $this->cellStr($sheet, $row, Coordinate::stringFromColumnIndex($nameColIdx));
        $yearRaw = $sheet->getCell(Coordinate::stringFromColumnIndex($yearColIdx).$row)->getValue();
        $club = $this->cellStr($sheet, $row, Coordinate::stringFromColumnIndex($clubColIdx));

        if ($name === '' || mb_strlen($name) < 3) {
            return null;
        }

        return [
            'start' => $startNum,
            'full_name' => $name,
            'year' => $this->parseYear($yearRaw),
            'club' => $club,
        ];
    }

    /**
     * @param  array{start:int,full_name:string,year:?int,club:string,vid_slots:list<array{offset:int,raw:string}>,group_line:?string}  $rows
     */
    private function flushStreamBuffer(?Category $currentCategory, ?string $currentGroup, array &$stats): void
    {
        if ($currentCategory === null || $this->streamRowBuffer === []) {
            $this->streamRowBuffer = [];

            return;
        }

        $groupLine = $currentGroup ?? '';
        $this->importBaseOccurrence = [];

        Performance::query()
            ->where('category_id', $currentCategory->id)
            ->where('status', 'scheduled')
            ->delete();

        $apparatusLabels = PerformanceApparatus::apparatusLabelsFromGroupName($groupLine);

        $expanded = [];
        foreach ($this->streamRowBuffer as $r) {
            foreach ($apparatusLabels as $roundIndex => $apparatusBase) {
                $expanded[] = [
                    'start' => $r['start'],
                    'full_name' => $r['full_name'],
                    'year' => $r['year'],
                    'club' => $r['club'],
                    'round_index' => $roundIndex,
                    'apparatus_base' => $apparatusBase,
                ];
            }
        }

        $this->streamRowBuffer = [];

        $ordered = $this->orderRowsByColumnOffsetThenStart($expanded);

        $orderIndex = 0;
        foreach ($ordered as $item) {
            [$lastName, $firstName] = $this->splitName($item['full_name']);

            $birthdate = null;
            if ($item['year'] !== null) {
                $birthdate = Carbon::createFromDate($item['year'], 1, 1)->startOfDay();
            }

            $athlete = $this->resolveAthlete($lastName, $firstName, $birthdate, $item['club'], $stats);

            $base = (string) $item['apparatus_base'];

            if (!isset($this->importBaseOccurrence[$athlete->id])) {
                $this->importBaseOccurrence[$athlete->id] = [];
            }
            if (!isset($this->importBaseOccurrence[$athlete->id][$base])) {
                $this->importBaseOccurrence[$athlete->id][$base] = 0;
            }
            $this->importBaseOccurrence[$athlete->id][$base]++;
            $occ = $this->importBaseOccurrence[$athlete->id][$base];
            $apparatus = $occ === 1 ? $base : $base.' · '.$occ;

            $dupQuery = Performance::query()
                ->where('category_id', $currentCategory->id)
                ->where('athlete_id', $athlete->id)
                ->where('apparatus', $apparatus);

            $duplicate = $dupQuery->exists();

            if ($duplicate) {
                $stats['rows_skipped']++;

                continue;
            }

            $orderIndex++;

            Performance::query()->create([
                'category_id' => $currentCategory->id,
                'athlete_id' => $athlete->id,
                'start_number' => $item['start'],
                'order_index' => $orderIndex,
                'status' => 'scheduled',
                'apparatus' => $apparatus !== '' ? $apparatus : null,
            ]);

            $stats['performances_created']++;
        }
    }

    /**
     * Сначала колонка H (offset 0), затем I (1)…; внутри колонки — по возрастанию стартового.
     *
     * @param  list<array{start:int,full_name:string,year:?int,club:string,vid_raw:?string,column_offset:int,group_line:?string}>  $rows
     * @return list<array{start:int,full_name:string,year:?int,club:string,vid_raw:?string,column_offset:int,group_line:?string}>
     */
    private function orderRowsByColumnOffsetThenStart(array $rows): array
    {
        usort($rows, function ($a, $b) {
            $c = $a['round_index'] <=> $b['round_index'];
            if ($c !== 0) {
                return $c;
            }

            return $a['start'] <=> $b['start'];
        });

        return $rows;
    }

    /**
     * Строка «Группа» / «Поток» может быть в A, B или C (объединённые ячейки).
     */
    private function findGroupOrStreamLine(Worksheet $sheet, int $row): string
    {
        foreach (['A', 'B', 'C', 'D'] as $col) {
            $v = $this->cellStr($sheet, $row, $col);
            if ($v === '') {
                continue;
            }
            if (str_contains($v, 'Группа:') || preg_match('/Поток\s+\d+/u', $v)) {
                return $v;
            }
        }

        return '';
    }

    /**
     * Стартовый №: колонка A, B или C (в протоколах чаще A или B).
     */
    private function detectStartColumnIndex(Worksheet $sheet, int $row): ?int
    {
        foreach (['A', 'B', 'C'] as $col) {
            $val = $this->cellStr($sheet, $row, $col);
            $n = $this->parseInt($val);
            if ($n !== null && $n >= 1 && $n <= 999) {
                return Coordinate::columnIndexFromString($col);
            }
        }

        return null;
    }

    /**
     * Год рождения и клуб: ищем 4-значный год в 1–4 колонках после ФИО (пропуск пустой C).
     *
     * @return array{0:int,1:int}
     */
    private function resolveYearClubColumnIndices(Worksheet $sheet, int $row, int $nameColIdx): array
    {
        for ($ci = $nameColIdx + 1; $ci <= $nameColIdx + 4; $ci++) {
            $val = $sheet->getCell(Coordinate::stringFromColumnIndex($ci).$row)->getValue();
            if ($this->parseYear($val) !== null) {
                return [$ci, $ci + 1];
            }
        }

        return [$nameColIdx + 2, $nameColIdx + 3];
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

        return trim(preg_replace('/\s+/u', ' ', (string) $val));
    }

    private function parseInt(string $s): ?int
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $s)) {
            return (int) $s;
        }
        if (is_numeric($s)) {
            return (int) round((float) $s);
        }

        return null;
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
        $full = preg_replace('/\s+/u', ' ', trim($full));
        $parts = preg_split('/\s+/u', $full, 2);
        $last = $parts[0] ?? '';
        $first = $parts[1] ?? '';

        return [$last, $first !== '' ? $first : '—'];
    }

    private function extractApparatusLabel(string $groupLine): ?string
    {
        if (preg_match('/(\d+)\s*вид/u', $groupLine, $m)) {
            return 'Вид '.$m[1];
        }

        return null;
    }

    private function extractBirthYear(string $groupLine): ?int
    {
        if (preg_match('/\b(19|20)\d{2}\b/u', $groupLine, $m)) {
            return (int) $m[0];
        }

        return null;
    }
}
