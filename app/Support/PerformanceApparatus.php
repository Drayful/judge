<?php

namespace App\Support;

/**
 * Вид выступления (предмет / БП) — нормализация из Excel и ручного ввода.
 */
class PerformanceApparatus
{
    public const BODY_ONLY_LABEL = 'БП';

    /**
     * Именованный список предметов РГ для формирования групп.
     * Порядок = типичный порядок кругов; «Б.П.» — упражнение без предмета.
     *
     * @var list<string>
     */
    public const RG_APPARATUS = ['Б.П.', 'Скакалка', 'Обруч', 'Мяч', 'Булавы', 'Лента', '2 предмета'];

    /**
     * Базовая метка без суффикса повтора («Мяч · 2» → «Мяч»).
     */
    public static function baseLabel(?string $raw): string
    {
        $t = trim((string) $raw);

        return (string) preg_replace('/\s*·\s*\d+$/u', '', $t);
    }

    /**
     * Ключ для сопоставления предмета в настройках сессии и в выступлении.
     * Например, «Б.П.» и «БП» — один и тот же вид.
     */
    public static function sessionKey(?string $raw): string
    {
        return self::normalize(self::baseLabel($raw)) ?? '';
    }

    /**
     * БП (без предмета): все DA-судьи работают как DB, D = trimmed mean по 4 оценкам.
     * Только по apparatus конкретного выступления — в одном потоке могут быть и БП, и предмет.
     */
    public static function isBodyOnly(?string $apparatus): bool
    {
        return self::isBodyOnlyLabel(self::baseLabel($apparatus));
    }

    /**
     * Маркер БП в ячейке H+ стартового протокола: «B», «Б», «б.п.» и т.п.
     */
    public static function isBodyOnlyCellMarker(?string $raw): bool
    {
        $t = trim((string) $raw);
        if ($t === '') {
            return false;
        }

        if (self::isBodyOnlyLabel(self::baseLabel($t))) {
            return true;
        }

        return (bool) preg_match('/^[БбBb]$/u', $t);
    }

    /**
     * Явное название предмета (не БП и не заглушка «Вид N»).
     */
    public static function isExplicitApparatusLabel(?string $raw): bool
    {
        $t = trim((string) $raw);
        if ($t === '') {
            return false;
        }

        if (self::isBodyOnlyCellMarker($t)) {
            return false;
        }

        if (self::isBodyOnly($t)) {
            return false;
        }

        return ! preg_match('/^Вид\s+\d+(?:\s*·\s*\d+)?$/u', self::baseLabel($t));
    }

    /**
     * «Б.П.» / «БП» в строке группы стартового протокола.
     */
    public static function isBodyOnlyStream(?string $text): bool
    {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }

        if (preg_match('/\bб\.?\s*п\.?\b/iu', $text)) {
            return true;
        }

        return (bool) preg_match('/\bбп\b/iu', str_replace(['.', ' '], '', $text));
    }

    /**
     * «1 вид», «2 вида», «3 вида» в названии группы.
     */
    public static function vidCountFromGroupName(?string $groupLine): ?int
    {
        $groupLine = trim((string) $groupLine);
        if ($groupLine === '') {
            return null;
        }

        if (preg_match('/(\d+)\s*вид(?:а|ов)?/iu', $groupLine, $m)) {
            $n = (int) $m[1];
            if ($n >= 1 && $n <= 50) {
                return $n;
            }
        }

        return null;
    }

    /**
     * Круги и снаряды потока — только по названию группы.
     *
     * @return list<string>
     */
    public static function apparatusLabelsFromGroupName(?string $groupLine): array
    {
        $hasBp = self::isBodyOnlyStream($groupLine);
        $nExplicit = self::vidCountFromGroupName($groupLine);

        // Только «Б.П.» без «N вид» — один круг БП.
        if ($hasBp && $nExplicit === null) {
            return [self::BODY_ONLY_LABEL];
        }

        // «Б.П.; N вид» — первый круг всегда БП, далее Вид 1, Вид 2…
        if ($hasBp && $nExplicit !== null) {
            $labels = [self::BODY_ONLY_LABEL];
            if ($nExplicit === 1) {
                $labels[] = 'Вид 1';

                return $labels;
            }

            for ($i = 1; $i < $nExplicit; $i++) {
                $labels[] = 'Вид '.$i;
            }

            return $labels;
        }

        $n = $nExplicit ?? 1;
        $labels = [];
        for ($i = 1; $i <= $n; $i++) {
            $labels[] = 'Вид '.$i;
        }

        return $labels;
    }

    /**
     * Нормализует метку для сохранения в performances.apparatus.
     */
    public static function normalize(?string $raw): ?string
    {
        $t = trim((string) $raw);
        if ($t === '') {
            return null;
        }

        if (self::isBodyOnlyCellMarker($t)) {
            return self::BODY_ONLY_LABEL;
        }

        $base = self::baseLabel($t);
        if (self::isBodyOnlyLabel($base)) {
            if ($t === $base) {
                return self::BODY_ONLY_LABEL;
            }

            return self::BODY_ONLY_LABEL.preg_replace('/^'.preg_quote($base, '/').'/u', '', $t);
        }

        return $t;
    }

    private static function isBodyOnlyLabel(string $base): bool
    {
        $compact = mb_strtolower(str_replace([' ', '.', '/', '\\', '-'], '', $base));

        return in_array($compact, ['бп', 'free', 'безпредмета', 'body', 'bp'], true);
    }
}
