<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\ExcelProtocolIconDetector;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? null;
if ($path === null || ! is_readable($path)) {
    fwrite(STDERR, "Usage: php scripts/debug_import.php <file.xlsx>\n");
    exit(1);
}

$sheet = IOFactory::load($path)->getActiveSheet();
$detector = new ExcelProtocolIconDetector;
$detector->indexSheet($sheet, 'H');

echo "File: {$path}\n";
echo "Drawings in collection: ".count($sheet->getDrawingCollection())."\n\n";

foreach ($sheet->getDrawingCollection() as $i => $drawing) {
    echo "Drawing #{$i}: coord=".$drawing->getCoordinates().' class='.get_class($drawing)."\n";
}

echo "\n--- Rows with Группа / Поток ---\n";
for ($row = 1; $row <= min(30, (int) $sheet->getHighestRow()); $row++) {
    foreach (['A', 'B', 'C'] as $col) {
        $v = trim((string) $sheet->getCell($col.$row)->getValue());
        if ($v !== '' && (str_contains($v, 'Группа:') || str_contains($v, 'Поток'))) {
            echo "R{$row} {$col}: {$v}\n";
        }
    }
}

echo "\n--- Athlete rows (A/B start + H/I) ---\n";
$vidStart = Coordinate::columnIndexFromString('H');
for ($row = 1; $row <= (int) $sheet->getHighestRow(); $row++) {
    $a = trim((string) $sheet->getCell('A'.$row)->getValue());
    $b = trim((string) $sheet->getCell('B'.$row)->getValue());
    if (! preg_match('/^\d+$/', $a) && ! preg_match('/^\d+$/', $b)) {
        continue;
    }
    $h = trim((string) $sheet->getCell('H'.$row)->getValue());
    $i = trim((string) $sheet->getCell('I'.$row)->getValue());
    $hIcon = $detector->cellIconType($row, 0) ?? '-';
    $iIcon = $detector->cellIconType($row, 1) ?? '-';
    echo "R{$row} start=".($a ?: $b)." name=".substr($b, 0, 25)." H=[{$h}] icon={$hIcon} | I=[{$i}] icon={$iIcon}\n";
}
