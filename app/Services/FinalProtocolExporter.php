<?php

namespace App\Services;

use App\Models\Tournament;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
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
        }

        // Подписи
        $signRow = $lastDataRow + 2;
        $sheet->setCellValue('B'.$signRow, 'Главный судья');
        $sheet->setCellValue('D'.$signRow, '____________________');
        $sheet->setCellValue('B'.($signRow + 2), 'Главный секретарь');
        $sheet->setCellValue('D'.($signRow + 2), '____________________');

        $this->autoSizeColumns($sheet, $totalCols);
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
