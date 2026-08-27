<?php

namespace App\Services;

use App\Models\Category;
use App\Models\StreamSession;
use App\Models\Tournament;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Формирует XLSX итогового протокола в формате образца results_*.xlsx.
 */
class FinalProtocolExporter
{
    /**
     * @param  array{title:string, birth_year:?int, division:?string, max_vidi:int, rows:list<array{place:int, name:string, year:?int, club:string, vidi:list<float>, total:float}>}  $data
     */
    public function build(Tournament $tournament, array $data): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $this->renderSheet($spreadsheet->getActiveSheet(), $tournament, $data);

        return $spreadsheet;
    }

    /**
     * Групповой итоговый протокол по образцу: рейтинговая строка команды и
     * отдельные строки состава без дублирования командных оценок.
     *
     * @param  array{title:string, birth_year:?int, division:?string, max_vidi:int, rows:list<array{place:?int, status:string, name:string, club:string, members:list<array{name:string, year:?int}>, vidi:list<?float>, total:float}>}  $data
     */
    public function buildTeams(Tournament $tournament, array $data): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $this->renderTeamSheet($spreadsheet->getActiveSheet(), $tournament, $data);

        return $spreadsheet;
    }

    /**
     * Выгрузка текущего экрана просмотра потока.
     *
     * @param  list<array{number:int|string|null, name:string, apparatus:string, score:?float, place:?int, place_of:int}>  $rows
     */
    public function buildStreamReview(
        Tournament $tournament,
        Category $category,
        ?StreamSession $session,
        array $rows,
    ): Spreadsheet {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Просмотр потока');

        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', $tournament->name);
        $sheet->mergeCells('A2:E2');
        $sheet->setCellValue('A2', $category->name);
        $sheet->mergeCells('A3:E3');
        $sessionLabel = $session === null
            ? 'Поток без отдельной сессии'
            : collect([
                $session->title,
                $session->scheduled_on?->format('d.m.Y'),
                $session->starts_at ? substr((string) $session->starts_at, 0, 5) : null,
            ])->filter()->implode(' · ');
        $sheet->setCellValue('A3', $sessionLabel);

        $headers = ['№', 'ФИО гимнастки', 'Предмет', 'Оценка', 'Место'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'5', $header);
        }

        $rowNumber = 6;
        foreach ($rows as $row) {
            $sheet->setCellValue('A'.$rowNumber, $row['number']);
            $sheet->setCellValue('B'.$rowNumber, $row['name']);
            $sheet->setCellValue('C'.$rowNumber, $row['apparatus']);
            if ($row['score'] !== null) {
                $sheet->setCellValue('D'.$rowNumber, round($row['score'], 3));
            }
            if ($row['place'] !== null) {
                $sheet->setCellValue('E'.$rowNumber, $row['place'].'/'.$row['place_of']);
            }
            $rowNumber++;
        }

        foreach (['A1', 'A2', 'A3'] as $cell) {
            $sheet->getStyle($cell)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A3')->getFont()->setSize(11);
        $sheet->getStyle('A5:E5')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A5:E5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F766E');
        $sheet->getStyle('A5:E5')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(24);
        $sheet->getRowDimension(5)->setRowHeight(25);

        $lastDataRow = max(5, $rowNumber - 1);
        $sheet->getStyle("A5:E{$lastDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A6:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C6:E{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D6:D{$lastDataRow}")->getNumberFormat()->setFormatCode('0.000');
        $sheet->getColumnDimension('A')->setWidth(9);
        $sheet->getColumnDimension('B')->setWidth(34);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(14);
        $sheet->getColumnDimension('E')->setWidth(18);
        $sheet->freezePane('A6');
        $sheet->setAutoFilter("A5:E{$lastDataRow}");
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);

        return $spreadsheet;
    }

    /**
     * Протоколы ПО ВИДАМ: одна книга, по листу на предмет.
     *
     * @param  array{title:string, birth_year:?int, division:?string, apparatus:list<array{label:string, rows:list<array{place:int, name:string, year:?int, club:string, score:float}>}>}  $data
     */
    public function buildByApparatus(Tournament $tournament, array $data): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $first = true;
        $usedTitles = [];

        foreach ($data['apparatus'] as $ap) {
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $first = false;

            $rows = array_map(fn ($r) => [
                'place' => $r['place'],
                'name' => $r['name'],
                'year' => $r['year'],
                'club' => $r['club'],
                'vidi' => [$r['score']],
                'total' => $r['score'],
            ], $ap['rows']);

            $this->renderSheet($sheet, $tournament, [
                'title' => $data['title'].' · '.$ap['label'],
                'birth_year' => $data['birth_year'],
                'division' => $data['division'],
                'max_vidi' => 1,
                'vidi_headers' => [$ap['label']],
                'rows' => $rows,
            ]);

            $sheet->setTitle($this->uniqueSheetTitle($ap['label'], $usedTitles));
        }

        if ($first) {
            // не было ни одного предмета — вернём пустой лист-заглушку.
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'Нет результатов по видам.');
        }

        return $spreadsheet;
    }

    /**
     * @param  array{title:string, birth_year:?int, division:?string, max_vidi:int, vidi_headers?:list<string>, rows:list<array{place:int, name:string, year:?int, club:string, vidi:list<float>, total:float}>}  $data
     */
    private function renderSheet(Worksheet $sheet, Tournament $tournament, array $data): void
    {
        $maxVidi = max(1, (int) $data['max_vidi']);

        // Колонки: № | Гимнастка | Год | Город | Вид 1..N | Итог | Место
        $totalCols = 4 + $maxVidi + 2;
        $lastColLetter = Coordinate::stringFromColumnIndex($totalCols);

        $sheet->setTitle($this->safeSheetTitle($data['birth_year'], $data['division']));

        // Шапка
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', $tournament->name);

        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->setCellValue('A2', $this->datesLine($tournament));

        $sheet->mergeCells("A3:{$lastColLetter}3");
        $sheet->setCellValue('A3', 'Итоговый протокол '.$data['title']);

        foreach (['A1', 'A2', 'A3'] as $cell) {
            $sheet->getStyle($cell)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A3')->getFont()->setBold(true);

        // Заголовки таблицы (строка 5)
        $headerRow = 5;
        $headers = ['№', 'Гимнастка', 'Год', 'Город'];
        $vidiHeaders = $data['vidi_headers'] ?? [];
        for ($i = 1; $i <= $maxVidi; $i++) {
            $headers[] = $vidiHeaders[$i - 1] ?? ('Вид '.$i);
        }
        $headers[] = 'Итог';
        $headers[] = 'Место';

        $col = 1;
        foreach ($headers as $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).$headerRow, $h);
            $col++;
        }
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Данные
        $r = $headerRow + 1;
        $num = 1;
        $firstDataRow = $r;
        foreach ($data['rows'] as $row) {
            $sheet->setCellValue('A'.$r, $num);
            $sheet->setCellValue('B'.$r, $row['name']);
            $sheet->setCellValue('C'.$r, $row['year']);
            $sheet->setCellValue('D'.$r, $row['club']);

            $c = 5;
            for ($i = 0; $i < $maxVidi; $i++) {
                $val = $row['vidi'][$i] ?? null;
                if ($val !== null) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($c).$r, round((float) $val, 3));
                }
                $c++;
            }
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($c).$r, round((float) $row['total'], 3));
            $c++;
            $sheet->setCellValue(
                Coordinate::stringFromColumnIndex($c).$r,
                ($row['status'] ?? null) === 'not_performed' ? 'Не выступила' : $row['place'],
            );

            $r++;
            $num++;
        }
        $lastDataRow = $r - 1;

        // Границы таблицы
        if ($lastDataRow >= $firstDataRow) {
            $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$lastDataRow}")
                ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

            // Числовые значения остаются числами, но в итоговом протоколе всегда
            // отображаются с точностью до тысячных (включая завершающие нули).
            $lastScoreCol = Coordinate::stringFromColumnIndex(5 + $maxVidi);
            $sheet->getStyle("E{$firstDataRow}:{$lastScoreCol}{$lastDataRow}")
                ->getNumberFormat()->setFormatCode('0.000');
        }

        // Подписи
        $signRow = $lastDataRow + 2;
        $sheet->setCellValue('B'.$signRow, 'Главный судья');
        $sheet->setCellValue('D'.$signRow, '____________________');
        $sheet->setCellValue('B'.($signRow + 2), 'Главный секретарь');
        $sheet->setCellValue('D'.($signRow + 2), '____________________');

        $this->autoSizeColumns($sheet, $totalCols);
    }

    /**
     * @param  array{title:string, birth_year:?int, division:?string, max_vidi:int, rows:list<array{place:?int, status:string, name:string, club:string, members:list<array{name:string, year:?int}>, vidi:list<?float>, total:float}>}  $data
     */
    private function renderTeamSheet(Worksheet $sheet, Tournament $tournament, array $data): void
    {
        $maxVidi = max(1, (int) $data['max_vidi']);
        $totalCols = 4 + $maxVidi + 2;
        $lastColLetter = Coordinate::stringFromColumnIndex($totalCols);
        $totalCol = Coordinate::stringFromColumnIndex(5 + $maxVidi);
        $placeCol = Coordinate::stringFromColumnIndex(6 + $maxVidi);

        $sheet->setTitle($this->safeGroupSheetTitle($data['title']));
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', $tournament->name);
        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->setCellValue('A2', $this->datesLine($tournament));
        $sheet->mergeCells("A3:{$lastColLetter}3");
        $sheet->setCellValue('A3', 'Итоговый протокол '.$data['title']);

        foreach (['A1', 'A2', 'A3'] as $cell) {
            $sheet->getStyle($cell)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setSize(11);
        $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);
        $sheet->getRowDimension(1)->setRowHeight(32);
        $sheet->getRowDimension(2)->setRowHeight(22);
        $sheet->getRowDimension(3)->setRowHeight(28);

        $headerRow = 4;
        $headers = ['№', 'Группа', 'Год', 'Город'];
        for ($index = 1; $index <= $maxVidi; $index++) {
            $headers[] = 'Вид '.$index;
        }
        $headers[] = 'Итог';
        $headers[] = 'Место';
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$headerRow, $header);
        }
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        $rowNumber = $headerRow + 1;
        $teamNumber = 1;
        $firstDataRow = $rowNumber;
        foreach ($data['rows'] as $team) {
            $teamRow = $rowNumber;
            $sheet->setCellValue('A'.$teamRow, $teamNumber++);
            $sheet->setCellValue('B'.$teamRow, $team['name']);
            $sheet->setCellValue('D'.$teamRow, $team['club']);

            $scoreColumn = 5;
            for ($index = 0; $index < $maxVidi; $index++) {
                $score = $team['vidi'][$index] ?? null;
                if ($score !== null) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($scoreColumn).$teamRow, round((float) $score, 3));
                }
                $scoreColumn++;
            }
            $sheet->setCellValue($totalCol.$teamRow, round((float) $team['total'], 3));
            $sheet->setCellValue(
                $placeCol.$teamRow,
                ($team['status'] ?? null) === 'not_performed' ? 'Не выступила' : $team['place'],
            );

            $sheet->getStyle("A{$teamRow}:{$lastColLetter}{$teamRow}")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9D9D9');
            $sheet->getStyle('B'.$teamRow)->getFont()->setBold(true);
            $sheet->getRowDimension($teamRow)->setRowHeight(22);
            $rowNumber++;

            foreach ($team['members'] as $member) {
                $sheet->setCellValue('B'.$rowNumber, $member['name']);
                if ($member['year'] !== null) {
                    $sheet->setCellValue('C'.$rowNumber, (int) $member['year']);
                }
                $sheet->getRowDimension($rowNumber)->setRowHeight(21);
                $rowNumber++;
            }
        }
        $lastDataRow = $rowNumber - 1;

        if ($lastDataRow >= $firstDataRow) {
            $tableStyle = $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$lastDataRow}");
            $tableStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $tableStyle->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$firstDataRow}:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("C{$firstDataRow}:C{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("D{$firstDataRow}:D{$lastDataRow}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
            $sheet->getStyle("E{$firstDataRow}:{$totalCol}{$lastDataRow}")
                ->getNumberFormat()->setFormatCode('0.000');
            $sheet->getStyle("E{$firstDataRow}:{$placeCol}{$lastDataRow}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $signRow = $lastDataRow + 2;
        $signatureCol = Coordinate::stringFromColumnIndex(max(5, $totalCols - 3));
        $sheet->setCellValue('B'.$signRow, 'Главный судья');
        $sheet->setCellValue($signatureCol.$signRow, '____________________');
        $sheet->setCellValue('B'.($signRow + 2), 'Главный секретарь');
        $sheet->setCellValue($signatureCol.($signRow + 2), '____________________');
        $sheet->getStyle("B{$signRow}:{$lastColLetter}".($signRow + 2))->getFont()->setBold(true);

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(40);
        for ($column = 5; $column <= $totalCols; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth($column === $totalCols ? 12 : 14);
        }
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.25)->setRight(0.25);
        $sheet->freezePane('A5');
    }

    private function datesLine(Tournament $tournament): string
    {
        $from = $tournament->starts_on?->format('d.m.Y');
        $to = $tournament->ends_on?->format('d.m.Y');

        if ($from && $to) {
            return $from === $to ? $from : "{$from} - {$to}";
        }

        return $from ?? $to ?? '';
    }

    private function safeSheetTitle(?int $year, ?string $division): string
    {
        $title = trim(($year ?? '').'_'.($division ?? ''), '_');
        $title = preg_replace('/[\\\\\/\?\*\[\]:]/u', '_', $title) ?? 'Протокол';

        return mb_substr($title !== '' ? $title : 'Протокол', 0, 31);
    }

    private function safeGroupSheetTitle(string $title): string
    {
        $title = preg_replace('~[\\/\?*\[\]:]~u', '_', trim($title)) ?? 'Групповые';
        $title = preg_replace('/\s+г\.р\.\s*/u', ' ', $title) ?? $title;

        return mb_substr($title !== '' ? $title : 'Групповые', 0, 31);
    }

    /**
     * @param  array<string, bool>  $used
     */
    private function uniqueSheetTitle(string $label, array &$used): string
    {
        $base = preg_replace('/[\\\\\/\?\*\[\]:]/u', '_', trim($label)) ?: 'Вид';
        $base = mb_substr($base, 0, 28);
        $title = $base;
        $n = 1;
        while (isset($used[$title])) {
            $title = mb_substr($base, 0, 25).' '.(++$n);
        }
        $used[$title] = true;

        return $title;
    }

    private function autoSizeColumns(Worksheet $sheet, int $totalCols): void
    {
        for ($c = 1; $c <= $totalCols; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }
}
