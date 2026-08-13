<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Tournament;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Excel-выгрузки, которые секретарь использует до начала судейства. */
class StartProtocolExporter
{
    public function buildStartSheet(Tournament $tournament): Spreadsheet
    {
        return $this->buildStartDocument($tournament, 'СТАРТОВЫЙ ЛИСТ', 'Стартовый лист');
    }

    public function buildStartProtocol(Tournament $tournament): Spreadsheet
    {
        return $this->buildStartDocument($tournament, 'СТАРТОВЫЙ ПРОТОКОЛ', 'Стартовый протокол');
    }

    public function buildProgramme(Tournament $tournament): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Программа');

        $sheet->mergeCells('A1:G1');
        $sheet->setCellValue('A1', $tournament->name);
        $sheet->mergeCells('A2:G2');
        $sheet->setCellValue('A2', 'ПРОГРАММА СОРЕВНОВАНИЙ');
        $this->styleTitle($sheet, 'A1:G2');

        $headers = ['Дата', 'Время', 'Группа', 'Поток', 'Программа', 'Вид / предмет', 'Участниц'];
        $sheet->fromArray($headers, null, 'A4');
        $this->styleHeader($sheet, 'A4:G4');

        $row = 5;
        $categories = Category::query()
            ->with(['group', 'sessions', 'performances'])
            ->where('tournament_id', $tournament->id)
            ->orderedByPerformanceTime()
            ->get();

        foreach ($categories as $category) {
            $sessions = $category->sessions;
            if ($sessions->isEmpty()) {
                $this->writeProgrammeRow($sheet, $row++, $category, null);

                continue;
            }
            foreach ($sessions as $session) {
                $this->writeProgrammeRow($sheet, $row++, $category, $session);
            }
        }

        if ($row === 5) {
            $sheet->mergeCells('A5:G5');
            $sheet->setCellValue('A5', 'Потоки ещё не сформированы.');
        } else {
            $sheet->getStyle('A4:G'.($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }

        foreach (['A' => 14, 'B' => 16, 'C' => 32, 'D' => 12, 'E' => 16, 'F' => 32, 'G' => 12] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A5');

        return $spreadsheet;
    }

    private function buildStartDocument(Tournament $tournament, string $heading, string $sheetTitle): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);

        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', $tournament->name);
        $sheet->mergeCells('A2:F2');
        $sheet->setCellValue('A2', $heading);
        $this->styleTitle($sheet, 'A1:F2');

        $row = 4;
        $categories = Category::query()
            ->with(['group', 'performances.athlete'])
            ->where('tournament_id', $tournament->id)
            ->orderedByPerformanceTime()
            ->get();

        foreach ($categories as $category) {
            $groupLabel = $category->group?->name ?? $category->name;
            $time = trim(($category->starts_at_label ?? '').(($category->ends_at_label ?? '') !== '' ? '–'.$category->ends_at_label : ''));
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("A{$row}", trim($groupLabel.($time !== '' ? ' · '.$time : '')));
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAF7');
            $row++;

            $streamTitle = 'Поток '.($category->stream_no ?? $category->id);
            if ($time !== '') {
                $streamTitle .= ' '.$time;
            }
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("A{$row}", $streamTitle);
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true);
            $row++;

            $sheet->fromArray(['№', 'Ст. №', 'Плановое время', 'Фамилия, имя', 'Год', 'Клуб'], null, "A{$row}");
            $this->styleHeader($sheet, "A{$row}:F{$row}");
            $row++;

            $performances = $category->performances
                ->sortBy([['order_index', 'asc'], ['id', 'asc']])
                ->unique('athlete_id')
                ->values();
            foreach ($performances as $index => $performance) {
                $athlete = $performance->athlete;
                $sheet->fromArray([
                    $index + 1,
                    $performance->start_number,
                    $performance->scheduled_at_label,
                    trim(($athlete?->last_name ?? '').' '.($athlete?->first_name ?? '')),
                    $athlete?->birthdate?->year,
                    $athlete?->club,
                ], null, "A{$row}");
                $row++;
            }
            if ($performances->isNotEmpty()) {
                $sheet->getStyle('A'.($row - $performances->count()).':F'.($row - 1))
                    ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }
            $row++;
        }

        if ($categories->isEmpty()) {
            $sheet->mergeCells('A4:F4');
            $sheet->setCellValue('A4', 'Потоки ещё не сформированы.');
        }

        foreach (['A' => 8, 'B' => 10, 'C' => 16, 'D' => 34, 'E' => 10, 'F' => 28] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        return $spreadsheet;
    }

    private function writeProgrammeRow(Worksheet $sheet, int $row, Category $category, mixed $session): void
    {
        $time = $session
            ? trim(($session->starts_at ? substr((string) $session->starts_at, 0, 5) : '').($session->ends_at ? '–'.substr((string) $session->ends_at, 0, 5) : ''))
            : trim(($category->starts_at_label ?? '').(($category->ends_at_label ?? '') !== '' ? '–'.$category->ends_at_label : ''));
        $apparatus = $session ? implode(', ', $session->apparatus ?? []) : ($category->apparatus ?? '—');
        $count = $session
            ? $category->performances->where('stream_session_id', $session->id)->unique('athlete_id')->count()
            : $category->performances->unique('athlete_id')->count();
        $sheet->fromArray([
            $session?->scheduled_on?->format('d.m.Y') ?? '',
            $time,
            $category->group?->name ?? $category->name,
            $category->stream_no ? 'Поток '.$category->stream_no : '—',
            $category->program === 'group' ? 'Групповая' : 'Индивидуальная',
            $apparatus,
            $count,
        ], null, "A{$row}");
    }

    private function styleTitle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle('A1')->getFont()->setSize(13);
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
    }
}
