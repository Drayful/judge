<?php

namespace App\Support;

/**
 * Разбор «человеческого» названия категории/группы на структурированные поля.
 *
 * Источник — строка «Группа: …» из стартового протокола, которая после импорта
 * сохраняется в Category::name, например:
 *   «2018 г.р., C, Б.П. — Поток 1 (08:00-08:25)»
 *   «2018 г.р., B, 1 вид — Поток 3»
 *   «2020 г.р., Б.П. — Поток 5»   (буквы категории нет)
 *
 * Возвращает год рождения и букву категории (division), чтобы итоговый протокол
 * можно было строить запросом по структуре, а не парсингом текста каждый раз.
 */
class CategoryMeta
{
    /**
     * @return array{birth_year: int|null, division: string|null}
     */
    public static function parse(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['birth_year' => null, 'division' => null];
        }

        // Отрезаем всё, что относится к потоку: «… — Поток N (время)».
        $head = preg_split('/\s*[—-]\s*Поток/iu', $name)[0] ?? $name;
        $head = trim($head);

        return [
            'birth_year' => self::extractBirthYear($head),
            'division' => self::extractDivision($head),
        ];
    }

    public static function extractBirthYear(?string $text): ?int
    {
        if ($text === null || $text === '') {
            return null;
        }
        if (preg_match('/\b(19|20)\d{2}\b/u', $text, $m)) {
            $year = (int) $m[0];
            if ($year >= 1990 && $year <= ((int) date('Y')) + 2) {
                return $year;
            }
        }

        return null;
    }

    /**
     * Буква категории (A/B/C/D или А/Б/В/Г). Берём отдельный «токен-букву»
     * между запятыми, игнорируя «г.р.», «N вид», «Б.П.» и т. п.
     */
    public static function extractDivision(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $segments = preg_split('/[,;]/u', $text) ?: [];
        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }
            // одиночная латинская/кириллическая буква уровня A..D / А..Г
            if (preg_match('/^[A-DА-Г]$/u', mb_strtoupper($seg))) {
                return mb_strtoupper($seg);
            }
        }

        return null;
    }

    /**
     * Человеческая подпись категории для заголовка протокола.
     */
    public static function divisionLabel(?string $division): string
    {
        $division = trim((string) $division);

        return $division !== '' ? $division : '—';
    }
}
