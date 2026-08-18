<?php

namespace App\Services;

use App\Models\Category;
use App\Models\StreamSession;
use App\Models\Tournament;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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
            ->with(['group', 'sessions', 'performances.athlete'])
            ->where('tournament_id', $tournament->id)
            ->orderedByPerformanceTime()
            ->get();

        $hasSessions = $categories->contains(fn (Category $category) => $category->sessions->isNotEmpty());
        if ($hasSessions) {
            $blocks = $this->startDocumentBlocks($categories);
            $currentDate = null;
            $firstDateSection = true;

            foreach ($blocks as $block) {
                $dateKey = $block['session']?->scheduled_on?->format('Y-m-d') ?? 'undated';
                if ($dateKey !== $currentDate) {
                    if (! $firstDateSection) {
                        $sheet->setBreak("A{$row}", Worksheet::BREAK_ROW);
                    }
                    $row = $this->writeDateHeading($sheet, $row, $block['session']);
                    $currentDate = $dateKey;
                    $firstDateSection = false;
                }

                $row = $this->writeStartBlock(
                    $sheet,
                    $row,
                    $block['category'],
                    $block['session'],
                    $block['performances'],
                    $block['unassigned'],
                );
            }
        } else {
            foreach ($categories as $category) {
                $row = $this->writeStartBlock(
                    $sheet,
                    $row,
                    $category,
                    null,
                    $category->performances,
                );
            }
        }

        if ($categories->isEmpty()) {
            $sheet->mergeCells('A4:F4');
            $sheet->setCellValue('A4', 'Потоки ещё не сформированы.');
        }

        foreach (['A' => 8, 'B' => 10, 'C' => 16, 'D' => 34, 'E' => 10, 'F' => 28] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToPage(true)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(1, 2);
        $sheet->getPageMargins()
            ->setTop(0.4)
            ->setRight(0.3)
            ->setBottom(0.4)
            ->setLeft(0.3);

        return $spreadsheet;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, array{category:Category,session:?StreamSession,performances:Collection,unassigned:bool}>
     */
    private function startDocumentBlocks(Collection $categories): Collection
    {
        return $categories
            ->flatMap(function (Category $category): array {
                if ($category->sessions->isEmpty()) {
                    return [[
                        'category' => $category,
                        'session' => null,
                        'performances' => $category->performances,
                        'unassigned' => false,
                    ]];
                }

                $blocks = $category->sessions->map(fn (StreamSession $session): array => [
                    'category' => $category,
                    'session' => $session,
                    'performances' => $category->performances
                        ->where('stream_session_id', $session->id),
                    'unassigned' => false,
                ])->all();
                $unassigned = $category->performances->whereNull('stream_session_id');
                if ($unassigned->isNotEmpty()) {
                    $blocks[] = [
                        'category' => $category,
                        'session' => null,
                        'performances' => $unassigned,
                        'unassigned' => true,
                    ];
                }

                return $blocks;
            })
            ->sortBy(fn (array $block): string => implode('|', [
                $block['session']?->scheduled_on?->format('Y-m-d') ?? '9999-12-31',
                $block['session']?->starts_at ?? '99:99:99',
                str_pad((string) ($block['category']->stream_no ?? $block['category']->id), 8, '0', STR_PAD_LEFT),
                str_pad((string) ($block['session']?->session_no ?? 9999), 8, '0', STR_PAD_LEFT),
            ]))
            ->values();
    }

    private function writeDateHeading(Worksheet $sheet, int $row, ?StreamSession $session): int
    {
        $label = $session?->scheduled_on
            ? 'ДЕНЬ СОРЕВНОВАНИЙ · '.$session->scheduled_on->format('d.m.Y')
            : 'БЕЗ НАЗНАЧЕННОЙ ДАТЫ / СЕССИИ';
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", $label);
        $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle("A{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('BDD7EE');
        $sheet->getRowDimension($row)->setRowHeight(22);

        return $row + 2;
    }

    private function writeStartBlock(
        Worksheet $sheet,
        int $row,
        Category $category,
        ?StreamSession $session,
        Collection $performances,
        bool $unassigned = false,
    ): int {
        $groupLabel = $category->group?->name ?? $category->name;
        $categoryTime = trim(($category->starts_at_label ?? '').(($category->ends_at_label ?? '') !== '' ? '–'.$category->ends_at_label : ''));
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", trim($groupLabel.($session === null && ! $unassigned && $categoryTime !== '' ? ' · '.$categoryTime : '')));
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAF7');
        $row++;

        $titleParts = ['Поток '.($category->stream_no ?? $category->id)];
        if ($session !== null) {
            $titleParts[] = trim((string) $session->title) !== '' ? $session->title : 'Сессия '.$session->session_no;
            $titleParts[] = $session->scheduled_on?->format('d.m.Y') ?? 'дата не указана';
            $sessionTime = trim(($session->starts_at ? substr((string) $session->starts_at, 0, 5) : '').($session->ends_at ? '–'.substr((string) $session->ends_at, 0, 5) : ''));
            if ($sessionTime !== '') {
                $titleParts[] = $sessionTime;
            }
            if (($session->apparatus ?? []) !== []) {
                $titleParts[] = implode(', ', $session->apparatus);
            }
        } elseif ($unassigned) {
            $titleParts[] = 'не назначено на сессию';
        } elseif ($categoryTime !== '') {
            $titleParts[] = $categoryTime;
        }

        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("A{$row}", implode(' · ', $titleParts));
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true);
        $sheet->getStyle("A{$row}:F{$row}")->getAlignment()->setWrapText(true);
        $row++;

        $headerRow = $row;
        $sheet->fromArray(['№', 'Ст. №', 'Плановое время', 'Фамилия, имя', 'Год', 'Клуб'], null, "A{$row}");
        $this->styleHeader($sheet, "A{$row}:F{$row}");
        $row++;

        $performances = $performances
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
        if ($performances->isEmpty()) {
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("A{$row}", 'Нет назначенных участниц.');
            $sheet->getStyle("A{$row}:F{$row}")->getFont()->setItalic(true)->getColor()->setRGB('64748B');
            $row++;
        }
        $sheet->getStyle("A{$headerRow}:F".($row - 1))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        return $row + 1;
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
