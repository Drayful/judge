<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Category;
use App\Models\Performance;
use App\Models\Tournament;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class StartProtocolImportService
{
    /** Первая колонка зоны видов / «рисунков» (H, далее I, J, …). */
    private const VID_FIRST_COLUMN = 'H';

    /**
     * Очередь строк текущего потока (до смены «Поток»/«Группа»/конца листа).
     * Число кругов по потоку: max(1, «N» из строки «Группа: … N вида», max непустых ячеек H+ по строкам, ширина колонок).
     * У каждой участницы столько выступлений, сколько кругов; текст вида — из ячейки столбца k или «Вид k+1».
     * Порядок: сначала все по колонке H, затем по I… (по стартовому внутри колонки).
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
     * От H вправо непустые ячейки задают подписи видов; число кругов — по потоку (см. выше). Стартовый № в B или C.
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
                $b = $this->cellStr($sheet, $row, 'B');

                if ($b !== '' && str_contains($b, 'Группа:')) {
                    $this->flushStreamBuffer($currentCategory, $currentGroup, $stats);
                    if (preg_match('/Группа:\s*(.+)/u', $b, $m)) {
                        $currentGroup = trim($m[1]);
                    } else {
                        $currentGroup = trim($b);
                    }
                    $groupApparatus = $this->extractApparatusLabel($currentGroup);
                    $groupBirthYear = $this->extractBirthYear($currentGroup);
                    $currentCategory = null;

                    continue;
                }

                if ($b !== '' && preg_match('/Поток\s+(\d+)/u', $b, $m)) {
                    $this->flushStreamBuffer($currentCategory, $currentGroup, $stats);

                    $streamNo = (int) $m[1];

                    $streamTime = null;
                    if (preg_match('/(\d{2}:\d{2}\s*-\s*\d{2}:\d{2})/u', $b, $tm)) {
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
                    'vid_slots' => $parsed['vid_slots'],
                    'group_line' => $currentGroup,
                ];
            }

            $this->flushStreamBuffer($currentCategory, $currentGroup, $stats);
        });

        return $stats;
    }

    /**
     * @return array{start:int,full_name:string,year:?int,club:string,vid_slots:list<array{offset:int,raw:string}>}|null
     */
    private function parseAthleteRow(Worksheet $sheet, int $row): ?array
    {
        $b = $this->cellStr($sheet, $row, 'B');
        $c = $this->cellStr($sheet, $row, 'C');

        $startColIdx = null;
        if ($this->parseInt($b) !== null) {
            $startColIdx = Coordinate::columnIndexFromString('B');
        } elseif ($this->parseInt($c) !== null) {
            $startColIdx = Coordinate::columnIndexFromString('C');
        } else {
            return null;
        }

        $startNum = $this->parseInt($this->cellStr($sheet, $row, Coordinate::stringFromColumnIndex($startColIdx)));
        if ($startNum === null) {
            return null;
        }

        $nameColIdx = $startColIdx + 1;
        $yearColIdx = $startColIdx + 2;
        $clubColIdx = $startColIdx + 3;
        $vidStartIdx = Coordinate::columnIndexFromString(self::VID_FIRST_COLUMN);

        $name = $this->cellStr($sheet, $row, Coordinate::stringFromColumnIndex($nameColIdx));
        $yearRaw = $sheet->getCell(Coordinate::stringFromColumnIndex($yearColIdx).$row)->getValue();
        $club = $this->cellStr($sheet, $row, Coordinate::stringFromColumnIndex($clubColIdx));

        if ($name === '' || mb_strlen($name) < 3) {
            return null;
        }

        $year = $this->parseYear($yearRaw);

        $sheetHigh = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $lastIdx = max($vidStartIdx, $sheetHigh);
        $vidSlots = [];
        for ($ci = $vidStartIdx; $ci <= $lastIdx; $ci++) {
            $cell = $this->cellStr($sheet, $row, Coordinate::stringFromColumnIndex($ci));
            if ($cell !== '') {
                $vidSlots[] = [
                    'offset' => $ci - $vidStartIdx,
                    'raw' => $cell,
                ];
            }
        }

        return [
            'start' => $startNum,
            'full_name' => $name,
            'year' => $year,
            'club' => $club,
            'vid_slots' => $vidSlots,
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

        $roundCount = $this->resolveStreamRoundCount($this->streamRowBuffer, $groupLine);

        $expanded = [];
        foreach ($this->streamRowBuffer as $r) {
            $line = $r['group_line'] ?? $groupLine;
            for ($k = 0; $k < $roundCount; $k++) {
                $expanded[] = [
                    'start' => $r['start'],
                    'full_name' => $r['full_name'],
                    'year' => $r['year'],
                    'club' => $r['club'],
                    'vid_raw' => $this->slotRawAtOffset($r['vid_slots'], $k),
                    'column_offset' => $k,
                    'group_line' => $line,
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

            $base = $this->resolveApparatusLabelForImport($item['vid_raw'], (int) $item['column_offset']);

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
            $c = $a['column_offset'] <=> $b['column_offset'];
            if ($c !== 0) {
                return $c;
            }

            return $a['start'] <=> $b['start'];
        });

        return $rows;
    }

    /**
     * Сколько кругов в этом потоке: не меньше объявленного в «Группа: … N вида», не меньше фактических колонок H+.
     *
     * @param  list<array{vid_slots:list<array{offset:int,raw:string}>}>  $buffer
     */
    private function resolveStreamRoundCount(array $buffer, string $groupLine): int
    {
        $nFromGroup = $this->extractVidCountFromGroup($groupLine) ?? 0;
        $maxSlotsPerRow = 0;
        $maxOffset = -1;
        foreach ($buffer as $r) {
            $slots = $r['vid_slots'] ?? [];
            $maxSlotsPerRow = max($maxSlotsPerRow, count($slots));
            foreach ($slots as $slot) {
                $maxOffset = max($maxOffset, (int) $slot['offset']);
            }
        }
        $nFromSpan = $maxOffset >= 0 ? $maxOffset + 1 : 0;

        return max(1, $nFromGroup, $maxSlotsPerRow, $nFromSpan);
    }

    /**
     * Число видов из строки группы («2 вида», «… 3 вида …»).
     */
    private function extractVidCountFromGroup(string $groupLine): ?int
    {
        if (preg_match('/(\d+)\s*вид/u', $groupLine, $m)) {
            $n = (int) $m[1];
            if ($n >= 1 && $n <= 50) {
                return $n;
            }
        }

        return null;
    }

    private function slotRawAtOffset(array $vidSlots, int $offset): ?string
    {
        foreach ($vidSlots as $slot) {
            if ((int) $slot['offset'] === $offset) {
                $t = trim((string) ($slot['raw'] ?? ''));

                return $t !== '' ? $t : null;
            }
        }

        return null;
    }

    private function resolveApparatusLabelForImport(?string $vidRaw, int $columnOffset): string
    {
        $t = trim((string) $vidRaw);
        if ($t !== '') {
            return $t;
        }

        return 'Вид '.($columnOffset + 1);
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
