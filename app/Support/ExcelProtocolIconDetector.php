<?php

namespace App\Support;

use GdImage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Иконки в ячейках H+ стартового протокола (колонка не важна — H и I могут меняться):
 * — силуэт гимнастки → вид (с предметом);
 * — буква «B» → БП (без предмета).
 */
class ExcelProtocolIconDetector
{
    private const VID_REFERENCE_PATH = 'import/vid_apparatus_icon.png';

    private const BP_REFERENCE_PATH = 'import/bp_letter_icon.png';

    private const MATCH_SIZE = 24;

    /** @var array<string, 'vid'|'bp'> */
    private array $cellIcons = [];

    public function indexSheet(Worksheet $sheet, string $firstColumn = 'H'): void
    {
        $this->cellIcons = [];
        $vidStartIdx = Coordinate::columnIndexFromString($firstColumn);

        foreach ($sheet->getDrawingCollection() as $drawing) {
            $coord = $drawing->getCoordinates();
            if ($coord === null || $coord === '') {
                continue;
            }

            [$col, $row] = Coordinate::coordinateFromString($coord);
            $ci = Coordinate::columnIndexFromString($col);
            if ($ci < $vidStartIdx) {
                continue;
            }

            $type = $this->matchIconType($drawing);
            if ($type === null) {
                continue;
            }

            $offset = $ci - $vidStartIdx;
            $this->cellIcons[$row.':'.$offset] = $type;
        }
    }

    public function cellIconType(int $row, int $columnOffset): ?string
    {
        return $this->cellIcons[$row.':'.$columnOffset] ?? null;
    }

    public function cellHasVidIcon(int $row, int $columnOffset): bool
    {
        return $this->cellIconType($row, $columnOffset) === 'vid';
    }

    public function cellHasBpIcon(int $row, int $columnOffset): bool
    {
        return $this->cellIconType($row, $columnOffset) === 'bp';
    }

    private function matchIconType(Drawing|MemoryDrawing $drawing): ?string
    {
        $bpScore = $this->similarityScore($drawing, self::BP_REFERENCE_PATH);
        $vidScore = $this->similarityScore($drawing, self::VID_REFERENCE_PATH);

        if ($bpScore === null && $vidScore === null) {
            return null;
        }

        $bpScore ??= 1.0;
        $vidScore ??= 1.0;

        if ($bpScore > 0.35 && $vidScore > 0.35) {
            return null;
        }

        return $bpScore <= $vidScore ? 'bp' : 'vid';
    }

    private function similarityScore(Drawing|MemoryDrawing $drawing, string $referencePath): ?float
    {
        $reference = $this->loadReference($referencePath);
        $candidate = $this->drawingToImage($drawing);
        if ($reference === null || $candidate === null) {
            if ($reference instanceof GdImage) {
                imagedestroy($reference);
            }
            if ($candidate instanceof GdImage) {
                imagedestroy($candidate);
            }

            return null;
        }

        $ref = $this->normalizeImage($reference);
        $cand = $this->normalizeImage($candidate);
        imagedestroy($reference);
        imagedestroy($candidate);

        if (! $ref instanceof GdImage || ! $cand instanceof GdImage) {
            if ($ref instanceof GdImage) {
                imagedestroy($ref);
            }
            if ($cand instanceof GdImage) {
                imagedestroy($cand);
            }

            return null;
        }

        $diff = 0;
        $total = self::MATCH_SIZE * self::MATCH_SIZE;
        for ($y = 0; $y < self::MATCH_SIZE; $y++) {
            for ($x = 0; $x < self::MATCH_SIZE; $x++) {
                $a = imagecolorat($ref, $x, $y) & 0xFF;
                $b = imagecolorat($cand, $x, $y) & 0xFF;
                if (abs($a - $b) > 48) {
                    $diff++;
                }
            }
        }

        imagedestroy($ref);
        imagedestroy($cand);

        return $diff / $total;
    }

    private function loadReference(string $relativePath): ?GdImage
    {
        $path = resource_path($relativePath);
        if (! is_readable($path)) {
            return null;
        }

        $image = @imagecreatefrompng($path);

        return $image instanceof GdImage ? $image : null;
    }

    private function drawingToImage(Drawing|MemoryDrawing $drawing): ?GdImage
    {
        if ($drawing instanceof MemoryDrawing) {
            $resource = $drawing->getImageResource();
            if (! $resource instanceof GdImage) {
                return null;
            }
            $w = imagesx($resource);
            $h = imagesy($resource);
            $copy = imagecreatetruecolor($w, $h);
            imagecopy($copy, $resource, 0, 0, 0, 0, $w, $h);

            return $copy;
        }

        $path = $drawing->getPath();
        if (! is_readable($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => @imagecreatefrompng($path) ?: null,
            'jpg', 'jpeg' => @imagecreatefromjpeg($path) ?: null,
            'gif' => @imagecreatefromgif($path) ?: null,
            default => null,
        };
    }

    private function normalizeImage(GdImage $image): ?GdImage
    {
        $out = imagecreatetruecolor(self::MATCH_SIZE, self::MATCH_SIZE);
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefill($out, 0, 0, $white);
        imagecopyresampled($out, $image, 0, 0, 0, 0, self::MATCH_SIZE, self::MATCH_SIZE, imagesx($image), imagesy($image));
        imagefilter($out, IMG_FILTER_GRAYSCALE);
        imagefilter($out, IMG_FILTER_CONTRAST, -30);

        return $out;
    }
}
