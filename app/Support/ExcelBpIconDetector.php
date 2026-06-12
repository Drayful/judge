<?php

namespace App\Support;

use GdImage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Иконка БП в ячейках H+ стартового протокола (силуэт гимнастки без предмета).
 */
class ExcelBpIconDetector
{
    private const REFERENCE_PATH = 'import/bp_apparatus_icon.png';

    private const MATCH_SIZE = 24;

    /** @var array<string, true> */
    private array $bpCells = [];

    public function indexSheet(Worksheet $sheet, string $firstColumn = 'H'): void
    {
        $this->bpCells = [];
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

            if (! $this->matchesReference($drawing)) {
                continue;
            }

            $offset = $ci - $vidStartIdx;
            $this->bpCells[$row.':'.$offset] = true;
        }
    }

    public function cellHasBpIcon(int $row, int $columnOffset): bool
    {
        return isset($this->bpCells[$row.':'.$columnOffset]);
    }

    private function matchesReference(Drawing|MemoryDrawing $drawing): bool
    {
        $reference = $this->loadReference();
        $candidate = $this->drawingToImage($drawing);
        if ($reference === null || $candidate === null) {
            if ($reference instanceof GdImage) {
                imagedestroy($reference);
            }
            if ($candidate instanceof GdImage) {
                imagedestroy($candidate);
            }

            return false;
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

            return false;
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

        return ($diff / $total) <= 0.35;
    }

    private function loadReference(): ?GdImage
    {
        $path = resource_path(self::REFERENCE_PATH);
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
