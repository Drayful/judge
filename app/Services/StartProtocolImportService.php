<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Entry;
use App\Models\Tournament;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
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
        $iinCol = $this->detectIinColumn($sheet, $highestRow);
        $order = 0;

        for ($row = 1; $row <= $highestRow; $row++) {
            $name = $this->cellStr($sheet, $row, 'A');
            if (mb_strlen($name) < 3) {
                continue;
            }

            // Год из ячейки — это реальная дата рождения гимнастки (для протокола).
            // Категория же определяется ЛИСТОМ (год листа + буква), поэтому entry.birth_year
            // берём из листа: так «2020 и младше» и т.п. не разъезжаются по годам.
            $rowYear = $this->parseYear($sheet->getCell('B'.$row)->getValue());
            $club = $this->cellStr($sheet, $row, 'C');
            $iin = $this->readIin($sheet, $row, $iinCol);

            [$lastName, $firstName] = $this->splitName($name);
            $realYear = $rowYear ?? $sheetYear;
            $birthdate = $realYear !== null ? Carbon::createFromDate($realYear, 1, 1)->startOfDay() : null;

            $athlete = $this->resolveAthlete($lastName, $firstName, $birthdate, $club, $stats, $iin);

            if ($this->entryExists($tournament, $athlete->id, 'individual')) {
                $stats['entries_skipped']++;

                continue;
            }

            Entry::query()->create([
                'tournament_id' => $tournament->id,
                'athlete_id' => $athlete->id,
                'program' => 'individual',
                'birth_year' => $sheetYear,
                'division' => $division,
                'club' => $club !== '' ? $club : null,
                'order_index' => ++$order,
                'meta' => ['sheet' => $title, 'label' => $label, 'real_year' => $rowYear],
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
        $currentYear = $sheetYear;

        /** @var list<array{name:string, club:string, year:?int, members:list<string>}> $teams */
        $teams = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            $a = $this->cellStr($sheet, $row, 'A');
            if ($a === '') {
                continue;
            }
            $b = $this->cellStr($sheet, $row, 'B');
            $c = $this->cellStr($sheet, $row, 'C');

            if ($this->isGroupSectionHeader($a)) {
                $currentYear = $this->firstYearIn($a) ?? $currentYear;

                if ($c !== '') {
                    $teams[] = $this->newImportedTeam($this->teamName($c), $c, $currentYear);
                }

                continue;
            }

            if ($this->isTeamHeader($a)) {
                $teams[] = $this->newImportedTeam(
                    $this->teamName($a),
                    $c !== '' ? $c : $a,
                    $this->firstYearIn($a) ?? $currentYear,
                );

                continue;
            }

            if ($this->isShortTeamHeader($a, $b, $c)) {
                $teams[] = $this->newImportedTeam($this->teamName($a), $c, $currentYear);

                continue;
            }

            // строка-участница до первого заголовка → неявная команда листа
            if ($teams === []) {
                $teams[] = [
                    'name' => $label !== '' ? $label : $title,
                    'club' => '',
                    'year' => $currentYear,
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
            // Заголовок без участниц — почти всегда ложное срабатывание (клубная
            // подпись и т.п.). Команду без состава не заводим.
            if ($team['members'] === []) {
                continue;
            }

            $teamName = $team['name'] !== '' ? $team['name'] : ($label !== '' ? $label : $title);
            $birthdate = $team['year'] !== null ? Carbon::createFromDate($team['year'], 1, 1)->startOfDay() : null;
            $teamClub = $team['club'] !== '' ? $team['club'] : '';

            $athlete = $this->resolveAthlete($teamName, '—', $birthdate, $teamClub, $stats, null, true);

            // Ростер команды: настоящие участницы как отдельные athletes.
            $rosterSync = [];
            $position = 0;
            foreach ($team['members'] as $memberRaw) {
                [$mLast, $mFirst, $mYear] = $this->parseMember($memberRaw);
                if (mb_strlen($mLast) < 2) {
                    continue;
                }
                $mBirth = $mYear !== null ? Carbon::createFromDate($mYear, 1, 1)->startOfDay() : null;
                $member = $this->resolveAthlete($mLast, $mFirst, $mBirth, $teamClub, $stats);
                $rosterSync[$member->id] = ['position' => ++$position];
            }
            $athlete->members()->sync($rosterSync);

            if ($this->entryExists($tournament, $athlete->id, 'group')) {
                $stats['entries_skipped']++;

                continue;
            }

            Entry::query()->create([
                'tournament_id' => $tournament->id,
                'athlete_id' => $athlete->id,
                'program' => 'group',
                // Категория = лист (год листа), а не год конкретной команды — иначе
                // «2014-2015», «КМС» и т.п. разъезжаются по годам.
                'birth_year' => $team['year'] ?? $sheetYear,
                'division' => null,
                'club' => $team['club'] !== '' ? $team['club'] : null,
                'meta' => [
                    'sheet' => $title,
                    'label' => $label,
                    'members' => $team['members'],
                    'real_year' => $team['year'],
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
     * Строка-заголовок команды. Два признака:
     *  1) начинается с года/диапазона лет + название: «2014-2015 "Nova"»,
     *     «2015 Eveline», «2014-2015 IMPULSE» (название может быть без кавычек);
     *  2) содержит клуб/федерацию/кавычки: «"MGS" г. Алматы», «Федерация г. Алматы».
     * Строки-участницы этим не считаются: у них год (если есть) стоит В КОНЦЕ
     * («Фех София 2014»), а не в начале.
     */
    private function isTeamHeader(string $a): bool
    {
        if (preg_match('/^\s*\d{4}(\s*[-–—]\s*\d{4})?\s+\S/u', $a)) {
            return true;
        }

        return (bool) preg_match('/[«»“”"]|федерац|\bteam\b|ШХГ|СХГ|СДЮ|г\.\s*алмат|MGS/iu', $a);
    }

    private function isShortTeamHeader(string $a, string $b, string $c): bool
    {
        return $b === ''
            && $c !== ''
            && mb_strlen($a) >= 2
            && $this->parseYear($a) === null;
    }

    private function isGroupSectionHeader(string $a): bool
    {
        return (bool) preg_match('/^\s*груп\S*.*(?:19|20)\d{2}/iu', $a);
    }

    /** @return array{name:string,club:string,year:?int,members:list<string>} */
    private function newImportedTeam(string $name, string $club, ?int $year): array
    {
        return [
            'name' => $name,
            'club' => $club,
            'year' => $year,
            'members' => [],
        ];
    }

    private function teamName(string $header): string
    {
        // Название в кавычках — берём его.
        if (preg_match('/[«“"]([^»”"]+)[»”"]/u', $header, $m)) {
            return trim($m[1]);
        }

        // Иначе отрезаем ведущий год/диапазон лет: «2014-2015 IMPULSE» → «IMPULSE».
        $stripped = trim((string) preg_replace('/^\s*\d{4}(\s*[-–—]\s*\d{4})?\s+/u', '', $header));

        return $stripped !== '' ? $stripped : trim($header);
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

    private function resolveAthlete(string $lastName, string $firstName, ?Carbon $birthdate, string $clubFromRow, array &$stats, ?string $iin = null, bool $isTeam = false): Athlete
    {
        // ИИН — самый надёжный идентификатор: если есть, ищем в первую очередь по нему.
        if ($iin !== null) {
            $byIin = Athlete::query()->where('iin', $iin)->first();
            if ($byIin) {
                if ($clubFromRow !== '' && ($byIin->club === null || $byIin->club === '')) {
                    $byIin->club = $clubFromRow;
                    $byIin->save();
                }

                return $byIin;
            }
        }

        $q = Athlete::query()
            ->where('is_team', $isTeam)
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($lastName)])
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($firstName)]);

        if ($birthdate !== null) {
            $q->whereDate('birthdate', $birthdate);
        } else {
            $q->whereNull('birthdate');
        }

        $found = $q->first();

        if ($found) {
            $dirty = false;
            if ($clubFromRow !== '' && ($found->club === null || $found->club === '')) {
                $found->club = $clubFromRow;
                $dirty = true;
            }
            if ($iin !== null && ($found->iin === null || $found->iin === '')) {
                $found->iin = $iin;
                $dirty = true;
            }
            if ($dirty) {
                $found->save();
            }

            return $found;
        }

        $stats['athletes_created']++;

        return Athlete::query()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birthdate' => $birthdate,
            'iin' => $iin,
            'is_team' => $isTeam,
            'club' => $clubFromRow !== '' ? $clubFromRow : null,
        ]);
    }

    /**
     * Разбор строки участницы команды: «Фех София 2014» → [фамилия, имя, год].
     *
     * @return array{0:string,1:string,2:?int}
     */
    private function parseMember(string $raw): array
    {
        $s = $this->cleanMemberName($raw);
        $year = null;
        if (preg_match('/\b((?:19|20)\d{2})\b\s*$/u', $s, $m)) {
            $year = (int) $m[1];
            $s = trim((string) preg_replace('/\b'.$m[1].'\b\s*$/u', '', $s));
        }
        $parts = preg_split('/\s+/u', trim($s), 2);
        $last = $parts[0] ?? $s;
        $first = ($parts[1] ?? '') !== '' ? $parts[1] : '—';

        return [$last, $first, $year];
    }

    /**
     * Определяет столбец с ИИН: тот, где чаще всего встречаются значения из 11–12 цифр
     * (11 — если Excel потерял ведущий ноль у годов 200x). Возвращает индекс столбца.
     */
    private function detectIinColumn(Worksheet $sheet, int $highestRow): ?int
    {
        $maxCol = min(Coordinate::columnIndexFromString($sheet->getHighestColumn()), 15);
        $rows = min($highestRow, 80);

        $best = null;
        $bestHits = 0;
        for ($c = 1; $c <= $maxCol; $c++) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $hits = 0;
            for ($r = 1; $r <= $rows; $r++) {
                if ($this->iinDigits($sheet->getCell($letter.$r)->getValue()) !== null) {
                    $hits++;
                }
            }
            if ($hits > $bestHits) {
                $bestHits = $hits;
                $best = $c;
            }
        }

        return $bestHits >= 2 ? $best : null;
    }

    /**
     * Читает ИИН из строки: сперва из найденного столбца, иначе — скан по строке.
     */
    private function readIin(Worksheet $sheet, int $row, ?int $iinCol): ?string
    {
        if ($iinCol !== null) {
            $v = $this->iinDigits($sheet->getCell(Coordinate::stringFromColumnIndex($iinCol).$row)->getValue());
            if ($v !== null) {
                return $v;
            }
        }

        // Фоллбэк: ищем в строке ячейку с 11–12 цифрами (год=4 цифры и текст отсеются).
        for ($c = 1; $c <= 15; $c++) {
            $v = $this->iinDigits($sheet->getCell(Coordinate::stringFromColumnIndex($c).$row)->getValue());
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Нормализует значение к 12-значному ИИН или null. Принимает 11 цифр (потерянный
     * ведущий ноль) и дополняет слева. Ячейка должна быть «числовой» (цифры/пробелы).
     */
    private function iinDigits(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            $v = sprintf('%.0f', $v);
        }
        $v = (string) $v;
        if (! preg_match('/^\s*\d[\d\s]*\d\s*$/u', $v)) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $v);
        $len = strlen($digits);
        if ($len === 12) {
            return $digits;
        }
        if ($len === 11) {
            return '0'.$digits;
        }

        return null;
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
