<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Протокол · {{ $category->name }}</title>
    <style>
        body { margin: 0; background: #e9edf2; color: #172033; font: 14px Arial, sans-serif; }
        .toolbar { padding: 16px; text-align: center; }
        button { padding: 10px 20px; border: 0; border-radius: 6px; background: #075985; color: white; cursor: pointer; }
        section { max-width: 1000px; margin: 16px auto; padding: 32px; background: white; }
        h1 { font-size: 21px; } h2 { font-size: 17px; } .meta { color: #475569; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        th, td { padding: 9px 7px; border: 1px solid #94a3b8; text-align: left; }
        th { background: #e2e8f0; } .number { text-align: right; white-space: nowrap; }
        .note { margin-top: 20px; color: #475569; font-size: 12px; }
        @page { size: A4 landscape; margin: 12mm; }
        @media print {
            body { background: white; color: black; }
            .toolbar { display: none; } section { margin: 0; padding: 0; max-width: none; }
            section + section { break-before: page; } tr { break-inside: avoid; } thead { display: table-header-group; }
        }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Печать / сохранить PDF</button></div>
    @forelse($sections as $section)
        <section>
            <div class="meta">{{ $category->tournament->name }}</div>
            <h1>Протокол результатов · {{ $category->name }}</h1>
            <h2>{{ $section['year'] ? $section['year'].' год рождения' : 'Год рождения не указан' }}</h2>
            @if($session)<div class="meta">{{ $session->scheduled_on?->format('d.m.Y') }} · {{ substr($session->starts_at ?? '', 0, 5) }}–{{ substr($session->ends_at ?? '', 0, 5) }}</div>@endif
            <table>
                <thead><tr><th>№</th><th>Гимнастка / команда</th><th>Школа</th><th>Упражнение</th><th>Итог</th><th>Место в многоборье</th></tr></thead>
                <tbody>
                    @foreach($section['performances'] as $performance)
                        @php($place = $section['places'][$performance->athlete_id] ?? [])
                        <tr>
                            <td>{{ $performance->start_number ?? '—' }}</td>
                            <td>{{ trim(($performance->athlete?->last_name ?? '').' '.($performance->athlete?->first_name ?? '')) }}</td>
                            <td>{{ $performance->athlete?->club ?? '—' }}</td>
                            <td>{{ $performance->apparatus ?? '—' }}</td>
                            <td class="number">{{ \App\Support\SecretaryLiveUi::formatScore($performance->total !== null ? (float) $performance->total : null) }}</td>
                            <td class="number">{{ isset($place['place'], $place['place_of']) ? $place['place'].' / '.$place['place_of'] : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="note">Места рассчитаны в своей возрастной группе и соревновательной категории, как в Excel. Результаты на момент открытия страницы; неподтверждённые оценки могут измениться.</p>
        </section>
    @empty
        <section>Нет выступлений для выбранного года и сессии.</section>
    @endforelse
</body>
</html>
