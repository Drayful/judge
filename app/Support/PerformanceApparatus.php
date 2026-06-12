<?php

namespace App\Support;

/**
 * Вид выступления (предмет / БП) — нормализация из Excel и ручного ввода.
 */
class PerformanceApparatus
{
    public const BODY_ONLY_LABEL = 'БП';

    /**
     * Базовая метка без суффикса повтора («Мяч · 2» → «Мяч»).
     */
    public static function baseLabel(?string $raw): string
    {
        $t = trim((string) $raw);

        return (string) preg_replace('/\s*·\s*\d+$/u', '', $t);
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
     * «Б.П.» в строке группы (справочно; не делает весь поток БП автоматически).
     */
    public static function isBodyOnlyStream(?string $text): bool
    {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }

        return (bool) preg_match('/\bб\.?\s*п\.?\b/iu', $text);
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
