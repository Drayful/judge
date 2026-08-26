@extends('layouts.judging')

@section('title', 'Бригада '.strtoupper($panel['panel']).' — '.($panel['slot'] ?? ''))

@php
    $athlete = $current?->athlete;
    $cityLine = $athlete?->club ?? '—';
    $pKey = $panel['panel'];
    $slot = $panel['slot'] ?? null;
    $isAdd = $pKey === 'd';
    $isSubtract = in_array($pKey, ['a', 'e'], true);
    $isPenalty = $pKey === 'penalty';
    $authUser = auth()->user();

    $saved = $myScore?->score !== null ? (float) $myScore->score : null;
    $isDifficultyAverageTablet = $authUser?->isDifficultyAverageJudge() ?? false;
    $alreadySubmitted = ! $isDifficultyAverageTablet && $myScore !== null && $myScore->submitted_at !== null;
    $requiresManualAverage = $isDifficultyAverageTablet && $current && ! $current->isBodyOnlyApparatus();
    $manualAverageSubmitted = $isDifficultyAverageTablet
        && $myScore?->average_submitted_at !== null
        && $myScore?->average_score !== null;
    $submittedDisplay = $alreadySubmitted && $myScore->score !== null
        ? number_format((float) $myScore->score, 3, '.', '')
        : null;

    $aBaseFloat = (float) $aBase;
    $eBaseFloat = (float) $eBase;
    $panelBase = $pKey === 'e' ? $eBaseFloat : ($pKey === 'a' ? $aBaseFloat : 0.0);

    $isHeadJudge = $authUser && in_array($authUser->role, ['head_judge', 'superior_jury', 'admin', 'super_admin'], true);
    $ageMin = $category->age_min;
    $ageMax = $category->age_max;
    $isBpBodyTablet = $current
        && $current->isBodyOnlyApparatus()
        && in_array($slot, ['DA1', 'DA2'], true)
        && ($panel['subpanel'] ?? null) === 'db';
    $isGroupProgram = $category->program === 'group';
@endphp

@section('content')
    <div class="judge-console h-screen overflow-hidden flex flex-col select-none" data-panel="{{ $pKey }}" data-slot="{{ $slot }}">
        <div class="judge-shell w-full max-w-[1600px] mx-auto px-2.5 py-2.5 flex-1 min-h-0 flex flex-col gap-2.5">

            {{-- ====== ШАПКА (одна строка) ====== --}}
            <div class="judge-topbar shrink-0 flex h-20 items-center gap-2 px-2">
                <a href="{{ route('judge.tournaments') }}" class="judge-back-button grid h-10 w-10 shrink-0 place-items-center rounded-xl text-lg text-slate-300 hover:text-white" aria-label="Назад к турнирам">←</a>

                <div class="flex-1 min-w-0 px-2 py-1 flex items-center gap-3 h-full">
                    @if($athlete)
                        <div class="min-w-0 w-full">
                            <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Live performance</div>
                            <div class="flex min-w-0 flex-col items-start gap-0.5">
                                <span @if($pKey === 'e') data-e-large-athlete-name @endif class="{{ $pKey === 'e' ? 'text-3xl md:text-4xl' : 'text-2xl md:text-3xl' }} max-w-full truncate font-bold leading-none tracking-tight text-white">{{ $athlete->last_name }} {{ $athlete->first_name }}</span>
                                <span class="max-w-full truncate text-sm text-slate-300">№ {{ $current?->start_number ?? '—' }} · {{ $category->name }} · {{ $current->apparatus ?? '—' }} · {{ $cityLine }}</span>
                            </div>
                        </div>
                    @else
                        <span class="text-sm text-amber-200">Нет активного выступления</span>
                    @endif
                </div>

                @if($ageMin !== null)
                    <div class="judge-meta-chip rounded-xl px-3 h-10 flex items-center gap-2" title="Минимальный возраст в категории">
                        <span class="text-[9px] uppercase tracking-wider text-slate-500">Мин.</span>
                        <span class="text-base font-bold text-cyan-200 tabular-nums">{{ $ageMin }}</span>
                    </div>
                @endif
                @if($ageMax !== null)
                    <div class="judge-meta-chip rounded-xl px-3 h-10 flex items-center gap-2" title="Максимальный возраст в категории">
                        <span class="text-[9px] uppercase tracking-wider text-slate-500">Макс.</span>
                        <span class="text-base font-bold text-cyan-200 tabular-nums">{{ $ageMax }}</span>
                    </div>
                @endif
                @if($isHeadJudge)
                    <div class="judge-meta-chip rounded-xl px-3 h-10 flex items-center">
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-emerald-200">Ответственный</span>
                    </div>
                @endif
                <div class="judge-meta-chip judge-slot-chip rounded-xl px-3 h-10 flex items-center">
                    <span class="text-[9px] uppercase tracking-wider text-slate-400 mr-2">Панель</span>
                    <span class="text-base font-mono font-bold">{{ $slot ?? '—' }}</span>
                </div>
            </div>

            @if ($errors->any())
                <div class="shrink-0 rounded-lg border border-rose-700/60 bg-rose-950/40 px-3 py-1 text-xs text-rose-100">
                    {{ $errors->first() }}
                </div>
            @endif

            @if($slotInactive)
                <div class="flex-1 min-h-0 grid place-items-center">
                    <div class="judge-state-card rounded-3xl p-8 text-center max-w-md">
                        <h2 class="text-lg font-semibold text-slate-100">Слот {{ $slot }} отключён</h2>
                        <p class="mt-2 text-sm text-slate-400">Секретарь исключил этот слот из состава бригады. Оценка не требуется и не принимается сервером.</p>
                    </div>
                </div>
            @elseif(! $current || ! $athlete)
                <div class="flex-1 min-h-0 grid place-items-center">
                    <div class="judge-state-card rounded-3xl p-8 text-center max-w-md">
                        <h2 class="text-lg font-semibold text-amber-100">Нет активного выступления</h2>
                        <p class="mt-2 text-sm text-amber-100/80">Секретарь должен запустить выступление. Ввод открывается только для статуса <code class="text-amber-300">performing</code>.</p>
                    </div>
                </div>
            @elseif($isDifficultyAverageTablet && $current->isBodyOnlyApparatus())
                <div class="flex-1 min-h-0 grid place-items-center">
                    <div class="judge-state-card rounded-3xl p-8 text-center max-w-lg">
                        <h2 class="text-2xl font-bold text-cyan-100">БП: отдельная средняя не требуется</h2>
                        <p class="mt-3 text-sm text-slate-300">Для выступления без предмета официальный D по-прежнему рассчитывается объединённой бригадой DB1, DB2, DA1 и DA2.</p>
                    </div>
                </div>
            @elseif($requiresManualAverage && ! $manualAverageSubmitted)
                <div class="flex-1 min-h-0 grid place-items-center">
                    <div
                        x-data="{
                            average: '',
                            busy: false,
                            error: null,
                            async submitAverage() {
                                if (this.busy || this.average === '') return;
                                this.busy = true;
                                this.error = null;
                                const csrfToken = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '';
                                const body = new FormData();
                                body.append('_token', csrfToken);
                                body.append('tournament_id', @js((string) $tournament->id));
                                body.append('slot', @js($slot));
                                body.append('average_score', this.average);
                                try {
                                    const response = await fetch(@js(route('judge.submit-average')), {
                                        method: 'POST',
                                        credentials: 'same-origin',
                                        headers: {
                                            'X-Requested-With': 'XMLHttpRequest',
                                            'X-CSRF-TOKEN': csrfToken,
                                            'Accept': 'application/json',
                                        },
                                        body,
                                    });
                                    const data = await response.json().catch(() => ({}));
                                    if (! response.ok || data.ok === false) {
                                        throw new Error(data.error || data.message || ('Ошибка ' + response.status));
                                    }
                                    if (window.JudgeAsync) {
                                        await window.JudgeAsync.refresh(data.redirect_url || @js(route('judge.tournament.tablet', $tournament)), { force: true, silent: true });
                                    } else {
                                        window.location.href = data.redirect_url || @js(route('judge.tournament.tablet', $tournament));
                                    }
                                } catch (error) {
                                    this.error = error?.message || 'Не удалось сохранить среднюю оценку.';
                                    this.busy = false;
                                }
                            },
                        }"
                        class="judge-state-card w-full max-w-2xl rounded-3xl p-8 text-center"
                    >
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">Официальная итоговая оценка · {{ $slot }}</div>
                        <h2 class="mt-3 text-3xl font-bold text-white">Введите среднюю {{ $slot === 'DB_AVG' ? 'DB' : 'DA' }}</h2>
                        <p class="mt-2 text-sm text-slate-400">Это значение без дополнительного округления сразу станет официальной оценкой {{ $slot === 'DB_AVG' ? 'DB' : 'DA' }} и войдёт в итоговый D. Индивидуальные оценки судей остаются в Live и истории.</p>

                        <form class="mt-7" @submit.prevent="submitAverage()">
                            <input
                                type="number"
                                x-model="average"
                                step="0.001"
                                min="0"
                                max="99.999"
                                required
                                inputmode="decimal"
                                autofocus
                                class="block w-full rounded-lg border border-cyan-700 bg-slate-900 px-6 py-5 text-center font-mono text-6xl font-extrabold tabular-nums text-cyan-100 shadow-inner focus:border-cyan-400 focus:ring-cyan-400"
                                placeholder="0.000"
                            >
                            <button type="submit" :disabled="busy || average === ''"
                                class="mt-5 w-full rounded-lg bg-emerald-600 px-6 py-5 text-2xl font-bold uppercase tracking-wide text-white shadow-lg hover:bg-emerald-500 disabled:cursor-wait disabled:opacity-50">
                                <span x-show="! busy">Отправить среднюю</span>
                                <span x-show="busy">Сохранение…</span>
                            </button>
                        </form>

                        <button type="button"
                            onclick="returnDifficultyPanel(@js((string) $tournament->id), @js((string) $current->id), @js(route('judge.return-difficulty-panel')), @js(route('judge.tournament.tablet', $tournament)), @js($slot === 'DB_AVG' ? 'DB' : 'DA'))"
                            class="mt-4 w-full rounded-lg border border-amber-700 bg-amber-950/50 px-5 py-3 text-sm font-bold text-amber-100 hover:bg-amber-900/60">
                            ↩ Вернуть всю бригаду {{ $slot === 'DB_AVG' ? 'DB' : 'DA' }} на доработку
                        </button>

                        <div x-cloak x-show="error" class="mt-4 rounded-lg border border-rose-700 bg-rose-950/60 px-4 py-3 text-sm text-rose-100" x-text="error"></div>
                    </div>
                </div>
            @elseif($isDifficultyAverageTablet && $manualAverageSubmitted)
                <div class="flex-1 min-h-0 grid place-items-center">
                    <div class="judge-state-card rounded-3xl p-10 text-center w-full max-w-2xl">
                        <div class="text-xs uppercase tracking-widest text-emerald-300/80">Официальная средняя {{ $slot === 'DB_AVG' ? 'DB' : 'DA' }} отправлена</div>
                        <div class="mt-3 text-8xl font-bold tabular-nums text-emerald-100">{{ number_format((float) $myScore->average_score, 3, '.', '') }}</div>
                        <p class="mt-4 text-sm text-emerald-100/80">Значение уже участвует в итоговой оценке. Дождитесь следующей гимнастки.</p>
                        <button type="button"
                            onclick="returnDifficultyPanel(@js((string) $tournament->id), @js((string) $current->id), @js(route('judge.return-difficulty-panel')), @js(route('judge.tournament.tablet', $tournament)), @js($slot === 'DB_AVG' ? 'DB' : 'DA'))"
                            class="mt-5 w-full rounded-lg border border-amber-700 bg-amber-950/50 px-5 py-3 text-sm font-bold text-amber-100 hover:bg-amber-900/60">
                            ↩ Вернуть всю бригаду {{ $slot === 'DB_AVG' ? 'DB' : 'DA' }} на доработку
                        </button>
                    </div>
                </div>
            @elseif($alreadySubmitted)
                <div class="flex-1 min-h-0 grid place-items-center">
                    <div class="judge-state-card rounded-3xl p-10 text-center">
                        <div class="text-xs uppercase tracking-widest text-emerald-300/80">Оценка {{ $slot }} отправлена</div>
                        <div class="mt-3 text-8xl font-bold tabular-nums text-emerald-100">{{ $submittedDisplay }}</div>
                        <p class="mt-4 text-sm text-emerald-100/80">Дождитесь следующей гимнастки.</p>
                    </div>
                </div>
            @else
                @if($isBpBodyTablet)
                    <div class="shrink-0 rounded-lg border border-cyan-700/60 bg-cyan-950/40 px-3 py-1.5 text-xs text-cyan-100">
                        БП (без предмета): планшет трудности тела — оценка войдёт в общий расчёт D вместе с DB1 и DB2.
                    </div>
                @endif
                @if($myScore && ! $isDifficultyAverageTablet && $myScore->submitted_at === null)
                    <div class="shrink-0 rounded-lg border border-amber-700/60 bg-amber-950/40 px-3 py-1.5 text-xs text-amber-100">
                        Оценка возвращена на доработку — исправьте при необходимости и отправьте снова.
                    </div>
                @endif
                <x-judge-panel
                    :type="$pKey"
                    :subpanel="$panel['subpanel'] ?? null"
                    :penalty-type="$panel['penalty_type'] ?? null"
                    :slot="$slot"
                    :base="$panelBase"
                    :saved="$saved"
                    :entries="$myScore?->entries ?? []"
                    :age-group="$myScore?->age_group ?? 'junior'"
                    :group-program="$isGroupProgram"
                    :tournament="$tournament"
                    :performance="$current"
                />
            @endif
        </div>
    </div>

@endsection

@push('body-scripts')
    <script>
        async function returnDifficultyPanel(tournamentId, performanceId, url, redirectUrl, panelLabel) {
            if (! window.confirm('Вернуть всю бригаду ' + panelLabel + ' на доработку? Индивидуальные оценки судей будут открыты повторно.')) return;
            const csrfToken = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '';
            const body = new FormData();
            body.append('_token', csrfToken);
            body.append('tournament_id', tournamentId);
            body.append('performance_id', performanceId);
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body,
                });
                const data = await response.json().catch(() => ({}));
                if (! response.ok || data.ok === false) {
                    throw new Error(data.error || data.message || ('Ошибка ' + response.status));
                }
                if (window.JudgeAsync) {
                    await window.JudgeAsync.refresh(data.redirect_url || redirectUrl, { force: true, silent: true });
                } else {
                    window.location.href = data.redirect_url || redirectUrl;
                }
            } catch (error) {
                window.alert(error?.message || 'Не удалось вернуть бригаду на доработку.');
            }
        }

        function judgeTablet(opts) {
            return {
                // Конфиг панели
                mode: opts.mode,             // 'add' | 'subtract' | 'penalty'
                base: opts.base,             // 10.0 для A/E
                deductionLimit: 10,          // A/E: суммарная сбавка не может быть больше 10.0
                panel: opts.panel,
                subpanel: opts.subpanel,
                penaltyType: opts.penaltyType,
                submitUrl: opts.submitUrl,
                liveActionUrl: opts.liveActionUrl,
                tabletUrl: opts.tabletUrl,
                timerUrl: opts.timerUrl,
                performanceId: opts.performanceId,
                timerStartedAt: opts.timerStartedAt,
                timerEndedAt: opts.timerEndedAt,
                timerDurationSeconds: opts.timerDurationSeconds,
                timePenalty: opts.timePenalty || 0,
                tournamentId: opts.tournamentId,

                // Стейт
                page: 1,
                draft: 0,
                actions: [],                 // [{v, cat, label}] или [{v, symbol, label, notDone}]
                busy: false,
                error: null,
                timerBusy: false,
                timerTick: 0,
                _timerInterval: null,
                numpadOpen: false,
                numpadValue: '',
                numpadPurpose: 'value',
                // D-бригада: выбранный символ элемента (сначала символ, потом значение/«не выполнен»)
                pendingSymbol: null,         // { symbol, label }
                _hintT: null,
                symbolFlow: opts.panel === 'd' && opts.subpanel === 'db', // DB: символ → значение
                groupDaFlow: !! opts.groupProgram && opts.subpanel === 'da', // DA группа: DC → значение
                groupProgram: !! opts.groupProgram,
                acroPending: false,          // DA индивид.: следующий балл — акробатика
                pendingDc: null,             // DA группа: { symbol, label }

                // Возрастная группа: лимиты зачёта элементов для бригад DB/DA
                ageGroup: 'junior',          // 'junior' | 'senior'
                limits: {
                    db: {
                        junior: { elements: 6, risks: 3 },
                        senior: { elements: 8, risks: 4 },
                    },
                    groupDb: {
                        junior: { elements: 6, dbMax: 3, deMax: 3, dbMin: 0, deMin: 3 },
                        senior: { elements: 9, dbMax: 5, deMax: 5, dbMin: 4, deMin: 4 },
                    },
                    da: {
                        junior: { elements: 12, acro: 3 },
                        senior: { elements: 15, acro: 3 },
                    },
                    groupDa: {
                        junior: { elementsMin: 6, elementsMax: 10, ccMin: 2, crMin: 2, multiMin: 2 },
                        senior: { elementsMin: 9, elementsMax: 14, ccMin: 3, crMin: 3, multiMin: 3 },
                    },
                },

                // Лимиты по категориям (A1: dance, dynamic — макс. 2)
                cat: {
                    dance: 0,
                    dynamic: 0,
                    collectiveSync: 0,
                    collectiveContrast: 0,
                    collectiveCanon: 0,
                    collectiveChoral: 0,
                    faceExpr: 0,
                    floorArea: 0,
                    formationDesign: 0,
                    formationAmplitude: 0,
                    interrupt: 0,
                    groupContactDuration: 0,
                    groupContactPose: 0,
                    musicIntro: 0,
                    musicNorms: 0,
                    musicEnd: 0,
                },
                catMax: {
                    dance: 2,
                    dynamic: opts.groupProgram ? 4 : 2,
                    collectiveSync: opts.groupProgram ? 1 : 0,
                    collectiveContrast: opts.groupProgram ? 1 : 0,
                    collectiveCanon: opts.groupProgram ? 1 : 0,
                    collectiveChoral: opts.groupProgram ? 1 : 0,
                },
                // Блок A: авто-сбавка равна количеству обязательных повторов × 0.3.
                // Нажатие на 0.3 подтверждает один выполненный повтор и уменьшает сбавку блока.
                hasCombo: opts.panel === 'a',
                comboStep: 0.3,
                comboCats: opts.groupProgram
                    ? ['dance', 'dynamic', 'collectiveSync', 'collectiveContrast', 'collectiveCanon', 'collectiveChoral']
                    : ['dance', 'dynamic'],
                oneTimeCreditCats: opts.groupProgram
                    ? ['collectiveSync', 'collectiveContrast', 'collectiveCanon', 'collectiveChoral']
                    : [],
                creditValues: {
                    interrupt: 0.6,
                    groupContactPose: 0.6,
                },
                catLabel: {
                    dance: 'Танц. шаги',
                    dynamic: 'Дин./эфф.',
                    rhythm: 'Ритм',
                    connections: 'Соединения',
                    interrupt: 'Прерывание непрерывности 4+ сек.',
                    character: 'Характер',
                    bodyExpr: 'Экспр. тела',
                    faceExpr: 'Экспрессия лица',
                    floorArea: 'Площадка',
                    musicNorms: 'Музыка',
                    musicIntro: 'Музыкальное вступление',
                    musicEnd: 'Конец',
                    collectiveSync: 'Синхронизация выполнена',
                    collectiveContrast: 'Контраст выполнен',
                    collectiveCanon: 'Канонадная',
                    collectiveChoral: 'Хорал',
                    formationDesign: 'Рисунки построений',
                    formationAmplitude: 'Амплитуда построений',
                    groupContactDuration: 'Гимнастка без предмета 5+ сек.',
                    groupContactPose: 'Нет контакта в начале/конце',
                    bodyConstruction: 'Конструкция/поднятое положение',
                },

                init() {
                    this.startTimerTicker();
                    if (opts.initialAgeGroup) {
                        this.ageGroup = opts.initialAgeGroup;
                    }
                    if (opts.initialEntries && opts.initialEntries.length > 0) {
                        this.restoreFromEntries(opts.initialEntries);
                    } else if (opts.hasInitial) {
                        // В БД A/E хранится финальная оценка, а планшет редактирует сбавку.
                        // Это важно для старых оценок без сохранённой истории нажатий.
                        const restored = this.mode === 'subtract'
                            ? Math.max(0, Math.min(this.deductionLimit, this.base - opts.initial))
                            : Math.max(0, opts.initial);
                        // У старых оценок A без истории итоговая сбавка уже включала штрафы
                        // за отсутствующие S и динамику. Они также считаются в comboPenalty(),
                        // поэтому отделяем их от ручной части, чтобы не начислять дважды.
                        const manualRestored = this.panel === 'a'
                            ? Math.max(0, restored - this.comboPenalty())
                            : restored;
                        this.draft = this.round3(manualRestored);
                        this.actions = this.draft === 0
                            ? []
                            : [{ v: this.draft, cat: null, label: '', inTotal: true }];
                    }

                    this.$watch('actions', () => this.publishLiveAction('Изменён черновик оценки'));
                    this.$watch('ageGroup', (value) => this.publishLiveAction('Выбрана возрастная группа: ' + value));
                },

                officialTimerRunning() {
                    return !! this.timerStartedAt && ! this.timerEndedAt;
                },

                officialTimerValue() {
                    this.timerTick;
                    let seconds = this.timerDurationSeconds;
                    if (this.officialTimerRunning()) {
                        const started = Date.parse(this.timerStartedAt);
                        seconds = Number.isFinite(started) ? Math.max(0, (Date.now() - started) / 1000) : null;
                    }

                    if (seconds === null || seconds === undefined || !Number.isFinite(Number(seconds))) {
                        return '—';
                    }

                    const value = Math.max(0, Math.floor(Number(seconds)));
                    return Math.floor(value / 60) + ':' + String(value % 60).padStart(2, '0');
                },

                startTimerTicker() {
                    if (! this.officialTimerRunning() || this._timerInterval) return;
                    this._timerInterval = setInterval(() => { this.timerTick += 1; }, 250);
                },

                stopTimerTicker() {
                    if (this._timerInterval) clearInterval(this._timerInterval);
                    this._timerInterval = null;
                },

                async recordOfficialTimer(action) {
                    if (! this.timerUrl || this.timerBusy) return;

                    const previousTimerState = {
                        endedAt: this.timerEndedAt,
                        durationSeconds: this.timerDurationSeconds,
                    };

                    // По нажатию «Стоп» сразу замораживаем показание на планшете.
                    // Сохранение и пересчёт на сервере выполняются уже после этого.
                    if (action === 'stop' && this.officialTimerRunning()) {
                        const stoppedAt = Date.now();
                        const startedAt = Date.parse(this.timerStartedAt);
                        if (Number.isFinite(startedAt)) {
                            this.timerDurationSeconds = Math.max(0, Math.floor((stoppedAt - startedAt) / 1000));
                        }
                        this.timerEndedAt = new Date(stoppedAt).toISOString();
                        this.stopTimerTicker();
                    }

                    this.timerBusy = true;
                    this.error = null;
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                    const abortController = new AbortController();
                    const requestTimeout = setTimeout(() => abortController.abort(), 15000);
                    try {
                        const response = await fetch(this.timerUrl, {
                            method: 'POST',
                            signal: abortController.signal,
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({ action, performance_id: this.performanceId }),
                        });
                        const responseText = await response.text();
                        let result = {};
                        try {
                            result = responseText ? JSON.parse(responseText) : {};
                        } catch (parseError) {
                            result = {};
                        }
                        if (! response.ok || ! result.ok) {
                            const statusHint = response.status === 419
                                ? 'Сессия истекла. Обновите страницу и войдите снова.'
                                : 'Не удалось зафиксировать время (HTTP ' + response.status + ').';
                            throw new Error(result.error || result.message || statusHint);
                        }

                        this.timerStartedAt = result.timer_started_at;
                        this.timerEndedAt = result.timer_ended_at;
                        this.timerDurationSeconds = result.duration_seconds;
                        this.timePenalty = Number(result.time_penalty || 0);
                        if (this.officialTimerRunning()) this.startTimerTicker();
                        else this.stopTimerTicker();
                    } catch (error) {
                        if (action === 'stop') {
                            this.timerEndedAt = previousTimerState.endedAt;
                            this.timerDurationSeconds = previousTimerState.durationSeconds;
                            if (this.officialTimerRunning()) this.startTimerTicker();
                        }
                        this.error = error?.name === 'AbortError'
                            ? 'Сервер не ответил за 15 секунд. Проверьте соединение; состояние таймера обновится автоматически.'
                            : (error?.message || 'Не удалось зафиксировать время.');
                    } finally {
                        clearTimeout(requestTimeout);
                        this.timerBusy = false;
                    }
                },

                publishLiveAction(action) {
                    if (! this.liveActionUrl) return;

                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                    const body = new FormData();
                    body.append('_token', csrfToken);
                    body.append('action', action);
                    body.append('draft_score', this.submitValue());
                    body.append('entries', JSON.stringify(this.historyForSubmit()));
                    body.append('age_group', this.ageGroup || '');
                    fetch(this.liveActionUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                        },
                        body,
                    }).catch(() => {});
                },

                catFromLabel(label) {
                    if (! label) return null;
                    const legacy = {
                        'Соединение': 'connections',
                        'Использование площадки': 'floorArea',
                        'Синхронизация': 'collectiveSync',
                        'Контраст': 'collectiveContrast',
                        'Каноническая': 'collectiveCanon',
                        'Хоровая': 'collectiveChoral',
                        'synchronization': 'collectiveSync',
                        'contrast': 'collectiveContrast',
                        'canonical': 'collectiveCanon',
                        'choral': 'collectiveChoral',
                        'Муз. характер': 'legacyMusicCharacter',
                        'Муз. динамика': 'legacyMusicDynamics',
                        'Связь': 'legacyLink',
                    };
                    if (legacy[label]) return legacy[label];
                    for (const [k, v] of Object.entries(this.catLabel)) {
                        if (v === label) return k;
                    }
                    return null;
                },

                /** Восстановить историю нажатий после возврата на доработку. */
                restoreFromEntries(entries) {
                    this.actions = [];
                    this.resetCats();
                    const reverseLogicV2 = entries.some(e => Number(e.logicVersion || 0) >= 2);
                    const legacyOneTimePenalties = new Set();
                    for (const e of entries) {
                        const cat = e.cat || this.catFromLabel(e.label);
                        // Раньше бинарная кнопка добавляла штраф. Теперь этот штраф уже
                        // существует по умолчанию, поэтому старую запись не дублируем.
                        if (this.panel === 'a' && this.oneTimeCreditCats.includes(cat) && ! e.combo) {
                            legacyOneTimePenalties.add(cat);
                            continue;
                        }
                        const action = {
                            v: e.v ?? 0,
                            cat: cat,
                            label: e.label || '',
                            symbol: e.symbol || null,
                            exchange: e.exchange || null,
                            acro: !! e.acro,
                            combo: !! e.combo,
                            notDone: !! e.notDone,
                            inTotal: e.combo ? false : (e.counted !== false),
                        };
                        this.actions.unshift(action);
                        if (cat && this.cat[cat] !== undefined) {
                            this.cat[cat] += 1;
                        }
                    }
                    // В старой истории отсутствие записи означало отсутствие штрафа.
                    // Новая история помечается logicVersion и сохраняет только реальные галочки.
                    if (this.panel === 'a' && ! reverseLogicV2) {
                        for (const cat of this.oneTimeCreditCats) {
                            if (legacyOneTimePenalties.has(cat) || this.cat[cat] > 0) continue;
                            this.cat[cat] = 1;
                            this.actions.unshift({
                                v: this.creditValue(cat),
                                cat: cat,
                                label: this.catLabel[cat] || cat,
                                combo: true,
                                inTotal: false,
                            });
                        }
                    }
                    if (this.mode === 'subtract' || this.mode === 'penalty') {
                        this.draft = this.round3(
                            this.actions.filter(a => a.inTotal !== false && ! a.combo).reduce((s, a) => s + a.v, 0)
                        );
                    }
                },

                round3(v) { return Math.round(v * 1000) / 1000; },

                categoryPenalty(cat) {
                    return this.round3(this.actions
                        .filter(a => a.cat === cat && a.inTotal !== false && ! a.combo)
                        .reduce((sum, a) => sum + Number(a.v || 0), 0));
                },

                hasPenalty(cat) { return this.actions.some(a => a.cat === cat && a.inTotal !== false); },

                recalculateDraft() {
                    this.draft = this.round3(this.actions
                        .filter(a => a.inTotal !== false && ! a.combo)
                        .reduce((sum, a) => sum + Number(a.v || 0), 0));
                },

                clearCategory(cat) {
                    this.actions = this.actions.filter(a => a.cat !== cat);
                    this.recalculateDraft();
                },

                /** Выбрать один взаимоисключающий итоговый штраф (включая 0). */
                selectPenalty(cat, value) {
                    if (this.mode === 'subtract') {
                        const withoutCurrent = this.round3(this.draft - this.categoryPenalty(cat));
                        const projected = this.round3(withoutCurrent + value + this.comboPenalty());
                        if (projected > this.deductionLimit) {
                            this.flashHint('Максимальная сбавка A/E — 10.00');
                            return;
                        }
                    }
                    this.clearCategory(cat);
                    if (value > 0) this.add(value, cat);
                },

                /** Обычный одноразовый штраф: нажатие добавляет, повторное снимает. */
                togglePenalty(value, cat) {
                    if (this.hasPenalty(cat)) {
                        this.clearCategory(cat);
                        return;
                    }
                    this.add(value, cat);
                },

                /** Счётчик с шагом и официальным максимумом категории. */
                incrementPenalty(value, cat, maximum) {
                    if (this.round3(this.categoryPenalty(cat) + value) > maximum) {
                        this.flashHint('Максимум для «' + (this.catLabel[cat] || cat) + '» — ' + maximum.toFixed(2));
                        return;
                    }
                    this.add(value, cat);
                },

                /** Добавить значение (всегда положительное; mode определяет, прибавлять или вычитать на итог). */
                add(v, cat) {
                    cat = cat || null;

                    // Блок «танц. шаги / дин. изменения»: нажатие = кредит, который уменьшает
                    // авто-сбавку блока. На draft не влияет — вклад считается в comboPenalty().
                    if (cat && this.isComboCat(cat)) {
                        if (this.cat[cat] >= this.catMax[cat]) return;
                        this.cat[cat] += 1;
                        this.actions.unshift({
                            v: this.creditValue(cat),
                            cat: cat,
                            label: this.catLabel[cat] || cat,
                            combo: true,
                            inTotal: false,
                        });
                        return;
                    }

                    if (cat && this.catMax[cat] !== undefined) {
                        if (this.cat[cat] >= this.catMax[cat]) return;
                        this.cat[cat] += 1;
                    }
                    const next = this.round3(this.draft + v);
                    if (next < 0 || next > 99.999) return;
                    if (this.mode === 'subtract') {
                        const projectedDeduction = this.round3(next + this.comboPenalty());
                        if (projectedDeduction > this.deductionLimit) {
                            this.flashHint('Максимальная сбавка A/E — 10.00');
                            return;
                        }
                    }
                    this.draft = next;
                    this.actions.unshift({
                        v: v,
                        cat: cat,
                        label: cat ? (this.catLabel[cat] || cat) : '',
                        inTotal: true,
                    });
                },
                // alias на старое название «press» (используется в партиалах)
                press(v, cat) { return this.add(v, cat); },

                set(v) {
                    const bounded = this.mode === 'subtract'
                        ? Math.min(this.deductionLimit, Math.max(0, v))
                        : v;
                    this.draft = this.round3(bounded);
                    this.actions = bounded === 0 ? [] : [{ v: bounded, cat: null, label: '', inTotal: true }];
                    this.resetCats();
                },

                setLinePenalty(type, value = 0.3) {
                    const next = this.round3(this.draft + value);
                    if (next > 99.999) return;
                    this.draft = next;
                    this.actions.unshift({
                        v: value,
                        cat: type,
                        label: type === 'line_ball' ? 'Мяч за линию' : 'Гимнастка за линию',
                        inTotal: true,
                    });
                },

                // ====== DB-бригада: символ → значение / «не выполнен» ======
                /** Выбрать символ элемента (прыжок, равновесие, поворот, риск). Значения пока нет. */
                selectSymbol(symbol, label) {
                    let exchange = null;
                    if (this.groupProgram && this.symbolFlow) {
                        if (symbol === 'R') {
                            exchange = null;
                        } else if (symbol === 'DE') {
                            exchange = 'de';
                        } else {
                            exchange = 'db';
                        }
                    }
                    this.pendingSymbol = { symbol: symbol, label: label, exchange: exchange };
                    this.error = null;
                },
                historyLabel(a) {
                    if (a.notDone) {
                        if (a.symbol === 'DE' || a.exchange === 'de') {
                            return 'DE Х·0';
                        }
                        if (a.exchange === 'db') {
                            return (a.label || a.symbol) + ' (DB) Х·0';
                        }
                        return (a.label || a.symbol) + ' Х·0';
                    }
                    if (a.symbol === 'R') {
                        return 'Риск ' + Number(a.v).toFixed(1);
                    }
                    if (a.symbol === 'DE' || a.exchange === 'de') {
                        return 'DE ' + Number(a.v).toFixed(1);
                    }
                    if (a.exchange === 'db') {
                        return (a.label || a.symbol) + ' (DB) ' + Number(a.v).toFixed(1);
                    }
                    return (a.symbol || '') + ' ' + Number(a.v).toFixed(1);
                },
                /** «Х» (DB) — элемент не выполнен: в историю символ с 0 баллов, на итог не влияет. */
                markNotDone() {
                    if (! this.pendingSymbol) {
                        this.flashHint('Сначала выберите символ элемента');
                        return;
                    }
                    this.actions.unshift({
                        v: 0,
                        symbol: this.pendingSymbol.symbol,
                        label: this.pendingSymbol.label,
                        exchange: this.pendingSymbol.symbol === 'R' ? null : (this.pendingSymbol.exchange || 'db'),
                        notDone: true,
                        inTotal: false,
                    });
                    this.pendingSymbol = null;
                },

                // ====== DA-бригада: акробатика + значения ======
                /** Включить/выключить режим «следующий балл — акробатика». */
                toggleAcro() {
                    this.acroPending = ! this.acroPending;
                    this.error = null;
                },
                /** Выбрать тип сотрудничества (CC, CR, C↗↗, C↓↓) для группового DA. */
                selectDcType(symbol, label) {
                    this.pendingDc = { symbol: symbol, label: label };
                    this.error = null;
                },
                dcDisplay(sym) {
                    if (sym === 'C_UP') return 'C↗↗';
                    if (sym === 'C_DOWN') return 'C↓↓';
                    return sym || '';
                },
                daHistoryLabel(a) {
                    const tag = this.dcDisplay(a.symbol) || (a.acro ? 'A' : '');
                    if (a.notDone) {
                        return tag + ' Х·0';
                    }
                    return tag + ' ' + Number(a.v).toFixed(1);
                },
                /** «Х» (DA индивид.) — обычный элемент или акробатика, если её режим был выбран. */
                markAcroNotDone() {
                    const isAcro = this.acroPending;
                    this.acroPending = false;
                    this.actions.unshift({
                        v: 0,
                        acro: isAcro,
                        notDone: true,
                        label: isAcro ? 'Акробатика' : 'Элемент',
                    });
                },
                /** «Х» (DA группа) — сотрудничество не выполнено: занимает слот DC, 0 баллов. */
                markDcNotDone() {
                    if (! this.pendingDc) {
                        this.flashHint('Сначала выберите тип сотрудничества');
                        return;
                    }
                    this.actions.unshift({
                        v: 0,
                        symbol: this.pendingDc.symbol,
                        label: this.pendingDc.label,
                        notDone: true,
                    });
                    this.pendingDc = null;
                },

                /** Присвоить значение: DB — выбранному символу; DA — акробатике или простому элементу. */
                assignValue(v) {
                    if (this.mode !== 'add') { return this.add(v, null); }

                    // DB: требуется выбранный символ
                    if (this.symbolFlow) {
                        if (! this.pendingSymbol) {
                            this.flashHint('Сначала выберите символ элемента');
                            return;
                        }
                        this.actions.unshift({
                            v: v,
                            symbol: this.pendingSymbol.symbol,
                            label: this.pendingSymbol.label,
                            exchange: this.pendingSymbol.symbol === 'R' ? null : (this.pendingSymbol.exchange || 'db'),
                            notDone: false,
                        });
                        this.pendingSymbol = null;
                        return;
                    }

                    // DA группа: только сотрудничество (тип выбран заранее)
                    if (this.groupDaFlow) {
                        if (! this.pendingDc) {
                            this.flashHint('Сначала выберите тип сотрудничества');
                            return;
                        }
                        this.actions.unshift({
                            v: v,
                            symbol: this.pendingDc.symbol,
                            label: this.pendingDc.label,
                            notDone: false,
                        });
                        this.pendingDc = null;
                        return;
                    }

                    // DA индивид.: акробатика или простое значение — зачёт решает daComputed()
                    this.actions.unshift({
                        v: v,
                        acro: this.acroPending,
                        notDone: false,
                        label: this.acroPending ? 'Акробатика' : '',
                    });
                    this.acroPending = false;
                },

                // ====== Возрастная группа и зачёт элементов (DB/DA) ======
                setAgeGroup(g) { this.ageGroup = g; },
                dbLim() { return this.limits.db[this.ageGroup]; },
                groupDbLim() { return this.limits.groupDb[this.ageGroup]; },

                /**
                 * Групповые упражнения DB: зачёт по порядку ввода, лимиты DB/DE.
                 */
                groupDbComputed() {
                    const lim = this.groupDbLim();
                    const counted = new Set();
                    let used = 0, dbUsed = 0, deUsed = 0, risks = 0, total = 0;

                    for (let i = this.actions.length - 1; i >= 0; i--) {
                        const a = this.actions[i];
                        if (a.notDone) continue;
                        if (a.symbol === 'R') {
                            // В групповых упражнениях риск всегда идёт в зачёт и
                            // не занимает слот DB/DE.
                            risks += 1;
                            counted.add(i);
                            total += a.v;
                            continue;
                        }

                        if (used >= lim.elements) continue;

                        const ex = a.symbol === 'DE' ? 'de' : (a.exchange || (a.symbol && a.symbol !== 'R' ? 'db' : null));
                        if (ex !== 'db' && ex !== 'de') continue;
                        if (ex === 'db' && dbUsed >= lim.dbMax) continue;
                        if (ex === 'de' && deUsed >= lim.deMax) continue;

                        if (ex === 'db') dbUsed += 1;
                        if (ex === 'de') deUsed += 1;
                        used += 1;
                        counted.add(i);
                        total += a.v;
                    }

                    return {
                        counted,
                        total: this.round3(total),
                        used,
                        dbUsed,
                        deUsed,
                        risks,
                        totalOver: used > lim.elements,
                        dbOver: dbUsed > lim.dbMax,
                        deOver: deUsed > lim.deMax,
                        risksOver: false,
                    };
                },

                dbComputed() {
                    if (this.groupProgram && this.symbolFlow) {
                        return this.groupDbComputed();
                    }

                    const lim = this.dbLim();
                    const items = this.actions
                        .map((a, i) => ({ a, i }))
                        .filter(x => ! x.a.notDone);
                    items.sort((x, y) => y.a.v - x.a.v);

                    const counted = new Set();
                    let risks = 0, used = 0, total = 0;
                    for (const x of items) {
                        const isRisk = x.a.symbol === 'R';

                        // Риски имеют собственный лимит и не занимают одно из
                        // мест 6/8, отведённых для обычных элементов DB.
                        if (isRisk) {
                            if (risks >= lim.risks) continue;
                        } else if (used >= lim.elements) {
                            continue;
                        }

                        counted.add(x.i);
                        if (isRisk) {
                            risks += 1;
                        } else {
                            used += 1;
                        }
                        total += x.a.v;
                    }

                    return { counted, total: this.round3(total), used, risks };
                },

                daLim() { return this.limits.da[this.ageGroup]; },
                groupDaLim() { return this.limits.groupDa[this.ageGroup]; },

                isDcMulti(sym) {
                    return sym === 'C_UP' || sym === 'C_DOWN';
                },

                /**
                 * Групповые упражнения DA (DC): зачёт по порядку ввода, лимиты по типам.
                 */
                groupDaComputed() {
                    const lim = this.groupDaLim();
                    const counted = new Set();
                    const dcSyms = ['CC', 'CR', 'C_UP', 'C_DOWN'];
                    let used = 0, cc = 0, cr = 0, multi = 0, total = 0;

                    for (let i = this.actions.length - 1; i >= 0; i--) {
                        const a = this.actions[i];
                        const sym = a.symbol;
                        if (! sym || ! dcSyms.includes(sym)) {
                            continue;
                        }

                        if (a.notDone) {
                            if (used < lim.elementsMax) {
                                used += 1;
                            }
                            continue;
                        }

                        if (used >= lim.elementsMax) {
                            continue;
                        }

                        used += 1;
                        counted.add(i);
                        total += a.v;
                        if (sym === 'CC') cc += 1;
                        else if (sym === 'CR') cr += 1;
                        else if (this.isDcMulti(sym)) multi += 1;
                    }

                    return {
                        counted,
                        total: this.round3(total),
                        used,
                        cc,
                        cr,
                        multi,
                    };
                },

                /**
                 * Групповой DA: −0.3 за каждую группу (CC, CR, броски/ловли), не выполнившую минимум.
                 */
                groupDaMinStatus() {
                    const lim = this.groupDaLim();
                    const c = this.groupDaComputed();
                    let missingGroups = 0;
                    if (c.cc < lim.ccMin) missingGroups += 1;
                    if (c.cr < lim.crMin) missingGroups += 1;
                    if (c.multi < lim.multiMin) missingGroups += 1;
                    const penalty = this.round3(missingGroups * 0.3);

                    return { missingGroups, penalty };
                },

                /**
                 * DB: минимум по одному элементу без риска (прыжок, равновесие, поворот).
                 * −0.3 за каждый отсутствующий тип; «Х» (notDone) всё равно снимает сбавку за отсутствие.
                 */
                dbMinElementsStatus() {
                    const required = [
                        { k: '^', label: 'Прыжок' },
                        { k: 'T', label: 'Равновесие' },
                        { k: '⚲', label: 'Поворот' },
                    ];
                    const presentKeys = new Set();
                    for (const a of this.actions) {
                        if (a.symbol && a.symbol !== 'R' && a.symbol !== 'DE') {
                            presentKeys.add(a.symbol);
                        }
                    }
                    const items = required.map(s => ({ ...s, ok: presentKeys.has(s.k) }));
                    if (this.groupProgram && this.symbolFlow) {
                        const c = this.groupDbComputed();
                        const requiredDe = this.groupDbLim().deMin;
                        for (let i = 1; i <= requiredDe; i++) {
                            items.push({
                                k: 'de-' + i,
                                label: 'DE ' + i,
                                ok: c.deUsed >= i,
                            });
                        }
                    }
                    const missing = items.filter(s => ! s.ok);
                    const penalty = this.round3(missing.length * 0.3);

                    return { items, missing, penalty };
                },

                /**
                 * DA: засчитываются максимум 12 (юниоры) / 15 (сеньоры) элементов
                 * в порядке ввода; акробатик среди них не больше 3.
                 * «Х» занимает слот элемента с 0 баллов; после выбора «Акробатика»
                 * он также занимает один из слотов акробатики.
                 */
                daComputed() {
                    if (this.groupDaFlow) {
                        return this.groupDaComputed();
                    }

                    const lim = this.daLim();
                    const counted = new Set();
                    let acro = 0, used = 0, total = 0;
                    // actions добавляются в начало — хронология идёт с конца массива.
                    for (let i = this.actions.length - 1; i >= 0; i--) {
                        const a = this.actions[i];
                        const isAcro = !! a.acro;
                        if (a.notDone) {
                            if (used >= lim.elements || (isAcro && acro >= lim.acro)) continue;
                            used += 1;
                            if (isAcro) acro += 1;
                            continue;
                        }
                        if (used >= lim.elements) continue;
                        if (isAcro && acro >= lim.acro) continue;
                        counted.add(i);
                        if (isAcro) acro += 1;
                        used += 1;
                        total += a.v;
                    }

                    return { counted, total: this.round3(total), used, acro };
                },

                /** Зачтён ли элемент истории под индексом i (для подсветки ленты). */
                isCounted(i) {
                    if (this.panel !== 'd') return true;
                    const c = this.symbolFlow ? this.dbComputed() : this.daComputed();
                    return c.counted.has(i);
                },

                flashHint(msg) {
                    this.error = msg;
                    clearTimeout(this._hintT);
                    this._hintT = setTimeout(() => { if (this.error === msg) this.error = null; }, 1600);
                },

                /** «ОТМЕНА» — удаляет последнее действие (или снимает выбор символа/акробатики). */
                cancel() {
                    // DB: символ выбран, но значение не присвоено — снимаем выбор.
                    if (this.symbolFlow && this.pendingSymbol) {
                        this.pendingSymbol = null;
                        return;
                    }
                    if (this.groupDaFlow && this.pendingDc) {
                        this.pendingDc = null;
                        return;
                    }
                    // DA индивид.: режим акробатики включён, но значение не выбрано — выключаем.
                    if (this.acroPending) {
                        this.acroPending = false;
                        return;
                    }
                    if (this.actions.length === 0) return;
                    const last = this.actions.shift();
                    if (last.cat && this.cat[last.cat] !== undefined) {
                        this.cat[last.cat] = Math.max(0, this.cat[last.cat] - 1);
                    }
                    // Вычитаем из суммы только то, что в неё попадало (для D итог считается из истории).
                    if (last.inTotal) {
                        this.draft = this.round3(this.draft - last.v);
                        if (this.draft < 0) this.draft = 0;
                    }
                },

                /** Полный сброс (вешается на отдельную «X (0.0)» кнопку). */
                clearAll() {
                    this.draft = 0;
                    this.actions = [];
                    this.resetCats();
                    this.acroPending = false;
                    this.pendingDc = null;
                    this.pendingSymbol = null;
                    this.error = null;
                },
                resetCats() { Object.keys(this.cat).forEach(k => { this.cat[k] = 0; }); },

                /** Авто-сбавка одного блока с учётом подтверждений (нажатий). */
                creditValue(cat) { return this.creditValues[cat] || this.comboStep; },
                blockPenalty(cat) {
                    const step = this.creditValue(cat);
                    const base = this.round3(step * (this.catMax[cat] || 0));
                    return this.round3(Math.max(0, base - step * (this.cat[cat] || 0)));
                },
                /** Суммарная авто-сбавка блоков (танц. шаги + дин. изменения). */
                comboPenalty() {
                    if (! this.hasCombo) return 0;
                    let sum = 0;
                    for (const c of this.comboCats) {
                        sum += this.blockPenalty(c);
                    }
                    return this.round3(sum);
                },
                /** Итоговая сбавка = ручные сбавки + авто-сбавка блоков. */
                totalDeduction() {
                    const total = this.round3(this.draft + this.comboPenalty());
                    return this.mode === 'subtract' ? Math.min(this.deductionLimit, total) : total;
                },

                workingTotal() { return this.totalDeduction(); },
                finalScore() {
                    if (this.mode === 'add') {
                        if (this.panel === 'd') {
                            if (this.symbolFlow) {
                                const base = this.dbComputed().total;
                                const penalty = this.dbMinElementsStatus().penalty;
                                const r = this.round3(base - penalty);
                                return r < 0 ? 0 : r;
                            }
                            if (this.groupDaFlow) {
                                const base = this.groupDaComputed().total;
                                const penalty = this.groupDaMinStatus().penalty;
                                const r = this.round3(base - penalty);
                                return r < 0 ? 0 : r;
                            }
                            return this.daComputed().total;
                        }
                        return this.totalDeduction();
                    }
                    if (this.mode === 'subtract') {
                        const r = this.round3(this.base - this.totalDeduction());
                        return r < 0 ? 0 : r;
                    }
                    return this.totalDeduction();
                },
                submitValue() {
                    if (this.mode === 'penalty') return this.draft.toFixed(3);
                    return this.finalScore().toFixed(3);
                },

                /** История нажатий для сервера (хронологический порядок, с пометкой зачёта). */
                historyForSubmit() {
                    const list = [];
                    for (let i = this.actions.length - 1; i >= 0; i--) {
                        const a = this.actions[i];
                        list.push({
                            v: a.v,
                            cat: a.cat || null,
                            label: a.label || null,
                            symbol: a.symbol || null,
                            exchange: a.exchange || null,
                            acro: !! a.acro,
                            combo: !! a.combo,
                            notDone: !! a.notDone,
                            counted: this.panel === 'd' ? this.isCounted(i) : (a.inTotal !== false && ! a.combo),
                            logicVersion: 2,
                        });
                    }
                    return list;
                },

                /** ОТПРАВИТЬ — fetch на route('judge.submit-score'). */
                async submit() {
                    if (this.busy) return;
                    this.busy = true;
                    this.error = null;
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                    const body = new FormData();
                    body.append('_token', csrfToken);
                    body.append('tournament_id', String(this.tournamentId));
                    body.append('panel', this.panel);
                    if (this.subpanel)    body.append('subpanel', this.subpanel);
                    if (this.penaltyType) body.append('penalty_type', this.penaltyType);
                    body.append('score', this.submitValue());
                    body.append('entries', JSON.stringify(this.historyForSubmit()));
                    body.append('age_group', this.ageGroup);
                    try {
                        const r = await fetch(this.submitUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json',
                            },
                            body,
                        });
                        const data = await r.json().catch(() => ({}));
                        if (!r.ok || data.ok === false) {
                            this.error = data.error || data.message || ('Ошибка ' + r.status);
                            this.busy = false;
                            return;
                        }
                        // Обновляем планшет на месте — попадём в состояние «оценка отправлена».
                        if (window.JudgeAsync) {
                            await window.JudgeAsync.refresh(data.redirect_url || this.tabletUrl, { force: true, silent: true });
                        } else {
                            window.location.href = data.redirect_url || this.tabletUrl;
                        }
                    } catch (err) {
                        this.error = 'Сеть: ' + (err.message || err);
                        this.busy = false;
                    }
                },

                // ====== Numpad ======
                openNumpad() { this.numpadPurpose = 'value'; this.numpadOpen = true; this.numpadValue = ''; },
                openFinalScoreNumpad() { this.numpadPurpose = 'finalScore'; this.numpadOpen = true; this.numpadValue = ''; },
                closeNumpad() { this.numpadOpen = false; this.numpadValue = ''; this.numpadPurpose = 'value'; },
                numpadAppend(c) {
                    if (c === '.') {
                        if (this.numpadValue.includes('.') || this.numpadValue.includes(',')) return;
                        if (this.numpadValue === '') this.numpadValue = '0';
                        this.numpadValue += '.';
                        return;
                    }
                    if (this.numpadValue === '0') this.numpadValue = '';
                    if (this.numpadValue.length >= 6) return;
                    this.numpadValue += c;
                },
                numpadBackspace() { this.numpadValue = this.numpadValue.slice(0, -1); },
                applyManualFinalScore(value) {
                    if (this.mode !== 'subtract') return;
                    const score = this.round3(value);
                    if (score < 0 || score > 10) {
                        this.flashHint('Итоговая оценка должна быть от 0.00 до 10.00');
                        return;
                    }

                    this.clearAll();
                    // Ручной итог не содержит расшифровки требований. Для A считаем
                    // обязательные S и динамику выполненными, иначе автосбавка исказит ввод.
                    if (this.panel === 'a') {
                        for (const cat of this.comboCats) {
                            for (let i = 0; i < (this.catMax[cat] || 0); i++) {
                                this.add(this.comboStep, cat);
                            }
                        }
                    }

                    const deduction = this.round3(Math.max(0, this.base - score));
                    this.draft = deduction;
                    if (deduction > 0) {
                        this.actions.unshift({
                            v: deduction,
                            cat: null,
                            label: 'Ручная итоговая оценка ' + score.toFixed(2),
                            inTotal: true,
                        });
                    }
                },
                applyNumpad() {
                    const normalized = String(this.numpadValue).trim().replace(',', '.');
                    if (! /^(?:\d+(?:\.\d*)?|\.\d+)$/.test(normalized)) {
                        this.flashHint('Введите корректное числовое значение');
                        return;
                    }
                    const v = parseFloat(normalized);
                    if (this.numpadPurpose === 'finalScore') {
                        if (v < 0 || v > 10) {
                            this.flashHint('Итоговая оценка должна быть от 0.00 до 10.00');
                            return;
                        }
                        this.applyManualFinalScore(v);
                        this.closeNumpad();
                        return;
                    }
                    if (v <= 0) { this.closeNumpad(); return; }
                    if (this.mode === 'add') {
                        this.assignValue(this.round3(v));
                    } else {
                        this.add(this.round3(v), null);
                    }
                    this.closeNumpad();
                },

                // ====== Helpers ======
                can(cat) {
                    if (!cat) return true;
                    if (this.catMax[cat] === undefined) return true;
                    return this.cat[cat] < this.catMax[cat];
                },
                left(cat) {
                    if (this.catMax[cat] === undefined) return null;
                    return Math.max(0, this.catMax[cat] - this.cat[cat]);
                },
                sumOf(cat) {
                    return this.round3(this.actions.filter(a => a.cat === cat).reduce((s, a) => s + a.v, 0));
                },
                isLimitCat(cat) { return cat === 'dance' || cat === 'dynamic'; },
                isComboCat(cat) { return this.hasCombo && this.comboCats.includes(cat); },
            };
        }

        (function () {
            const pageRoot = document.querySelector('[data-async-page]');
            const pingUrl = @json(route('judge.tournament.tablet.ping', $tournament));
            let lastRev = @json($tabletRev);
            const pingInterval = setInterval(async function () {
                if (pageRoot && ! pageRoot.isConnected) {
                    clearInterval(pingInterval);
                    return;
                }
                try {
                    const r = await fetch(pingUrl, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    if (!r.ok) return;
                    const j = await r.json();
                    if (!j.resolved || j.rev !== lastRev) {
                        lastRev = j.rev || null;
                        if (window.JudgeAsync) {
                            await window.JudgeAsync.refresh(window.location.href, { silent: true });
                        } else {
                            window.location.reload();
                        }
                    }
                } catch (e) {}
            }, 3500);
        })();
    </script>
@endpush
