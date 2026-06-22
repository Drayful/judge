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

    $saved = $myScore?->score !== null ? (float) $myScore->score : null;
    $alreadySubmitted = $myScore !== null && $myScore->submitted_at !== null;
    $submittedDisplay = $alreadySubmitted && $myScore->score !== null
        ? number_format((float) $myScore->score, 3, '.', '')
        : null;

    $aBaseFloat = (float) $aBase;
    $eBaseFloat = (float) $eBase;
    $panelBase = $pKey === 'e' ? $eBaseFloat : ($pKey === 'a' ? $aBaseFloat : 0.0);

    $authUser = auth()->user();
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
    <div class="h-screen overflow-hidden flex flex-col select-none">
        <div class="w-full max-w-[1600px] mx-auto px-2 py-2 flex-1 min-h-0 flex flex-col gap-2">

            {{-- ====== ШАПКА (одна строка) ====== --}}
            <div class="shrink-0 flex items-center gap-2 h-12">
                <a href="{{ route('judge.tournaments') }}" class="text-xs text-slate-400 hover:text-slate-200 px-2">←</a>

                <div class="flex-1 min-w-0 rounded-lg bg-[#0f1830] border border-slate-800 px-3 py-1.5 flex items-center gap-3 h-full">
                    @if($athlete)
                        <span class="text-base font-semibold text-white truncate">{{ $athlete->last_name }} {{ $athlete->first_name }}</span>
                        <span class="text-[11px] text-slate-400 truncate">№ {{ $current?->start_number ?? '—' }} · {{ $category->name }} · {{ $current->apparatus ?? '—' }} · {{ $cityLine }}</span>
                    @else
                        <span class="text-sm text-amber-200">Нет активного выступления</span>
                    @endif
                </div>

                @if($ageMin !== null)
                    <div class="rounded-lg bg-[#1c2547] border border-slate-700 px-3 h-full flex items-center gap-2" title="Минимальный возраст в категории">
                        <span class="text-[10px] uppercase text-slate-400">Мин.</span>
                        <span class="text-base font-bold text-cyan-200 tabular-nums">{{ $ageMin }}</span>
                    </div>
                @endif
                @if($ageMax !== null)
                    <div class="rounded-lg bg-[#1c2547] border border-slate-700 px-3 h-full flex items-center gap-2" title="Максимальный возраст в категории">
                        <span class="text-[10px] uppercase text-slate-400">Макс.</span>
                        <span class="text-base font-bold text-cyan-200 tabular-nums">{{ $ageMax }}</span>
                    </div>
                @endif
                @if($isHeadJudge)
                    <div class="rounded-lg bg-[#0e5a3f] border border-emerald-700 px-3 h-full flex items-center">
                        <span class="text-xs font-semibold uppercase tracking-wider text-emerald-50">Ответственный судья</span>
                    </div>
                @endif
                <div class="rounded-lg bg-[#0f1830] border border-slate-800 px-3 h-full flex items-center">
                    <span class="text-[10px] uppercase text-slate-400 mr-1">Слот</span>
                    <span class="text-sm font-mono text-emerald-300">{{ $slot ?? '—' }}</span>
                </div>
            </div>

            @if ($errors->any())
                <div class="shrink-0 rounded-lg border border-rose-700/60 bg-rose-950/40 px-3 py-1 text-xs text-rose-100">
                    {{ $errors->first() }}
                </div>
            @endif

            @if(! $current || ! $athlete)
                <div class="flex-1 min-h-0 grid place-items-center">
                    <div class="rounded-xl border border-amber-800/50 bg-amber-950/30 p-6 text-center max-w-md">
                        <h2 class="text-lg font-semibold text-amber-100">Нет активного выступления</h2>
                        <p class="mt-2 text-sm text-amber-100/80">Секретарь должен вызвать гимнастку (<code class="text-amber-300">scheduled / on_deck / performing</code>).</p>
                    </div>
                </div>
            @elseif($alreadySubmitted)
                <div class="flex-1 min-h-0 grid place-items-center">
                    <div class="rounded-2xl border border-emerald-800/60 bg-emerald-950/30 p-10 text-center">
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
                @if($myScore && $myScore->submitted_at === null && is_array($myScore->entries) && count($myScore->entries) > 0)
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
                />
            @endif
        </div>
    </div>

@endsection

@push('body-scripts')
    <script>
        function judgeTablet(opts) {
            return {
                // Конфиг панели
                mode: opts.mode,             // 'add' | 'subtract' | 'penalty'
                base: opts.base,             // 10.0 для A/E
                panel: opts.panel,
                subpanel: opts.subpanel,
                penaltyType: opts.penaltyType,
                submitUrl: opts.submitUrl,
                tabletUrl: opts.tabletUrl,
                tournamentId: opts.tournamentId,

                // Стейт
                page: 1,
                draft: 0,
                actions: [],                 // [{v, cat, label}] или [{v, symbol, label, notDone}]
                busy: false,
                error: null,
                numpadOpen: false,
                numpadValue: '',
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
                        junior: { elements: 6, dbMax: 3, deMax: 3, dbMin: 0, deMin: 0, risks: 1 },
                        senior: { elements: 9, dbMax: 5, deMax: 5, dbMin: 4, deMin: 4, risks: 1 },
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
                cat: { dance: 0, dynamic: 0 },
                catMax: { dance: 2, dynamic: 2 },
                // Блок A: «танц. шаги» и «дин. изменения» — авто-сбавка 0.6 за каждый блок
                // (итого −1.2). Нажатие на 0.3 даёт «кредит» и уменьшает сбавку блока;
                // максимум (2×0.3=0.6) полностью убирает сбавку блока. Применяется только к панели A.
                hasCombo: opts.panel === 'a',
                comboAuto: 0.6,
                comboStep: 0.3,
                comboCats: ['dance', 'dynamic'],
                catLabel: {
                    dance: 'Танц. шаги',
                    dynamic: 'Дин./эфф.',
                    rhythm: 'Ритм',
                    union: 'Соединение',
                    interrupt: 'Прерывание',
                    character: 'Характер',
                    bodyExpr: 'Экспр. тела',
                    faceExpr: 'Экспр. лица',
                    space: 'Площадка',
                    musicChar: 'Муз. характер',
                    musicIntro: 'Муз. вступл.',
                    musicDyn: 'Муз. динамика',
                    link: 'Связь',
                },

                init() {
                    if (opts.initialAgeGroup) {
                        this.ageGroup = opts.initialAgeGroup;
                    }
                    if (opts.initialEntries && opts.initialEntries.length > 0) {
                        this.restoreFromEntries(opts.initialEntries);
                    } else if (opts.initial && opts.initial !== 0) {
                        this.draft = opts.initial;
                        this.actions = [{ v: opts.initial, cat: null, label: '' }];
                    }
                },

                catFromLabel(label) {
                    if (! label) return null;
                    for (const [k, v] of Object.entries(this.catLabel)) {
                        if (v === label) return k;
                    }
                    return null;
                },

                /** Восстановить историю нажатий после возврата на доработку. */
                restoreFromEntries(entries) {
                    this.actions = [];
                    this.resetCats();
                    for (const e of entries) {
                        const cat = e.cat || this.catFromLabel(e.label);
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
                    if (this.mode === 'subtract' || this.mode === 'penalty') {
                        this.draft = this.round3(
                            this.actions.filter(a => a.inTotal !== false && ! a.combo).reduce((s, a) => s + a.v, 0)
                        );
                    }
                },

                round3(v) { return Math.round(v * 1000) / 1000; },

                /** Добавить значение (всегда положительное; mode определяет, прибавлять или вычитать на итог). */
                add(v, cat) {
                    cat = cat || null;

                    // Блок «танц. шаги / дин. изменения»: нажатие = кредит, который уменьшает
                    // авто-сбавку блока. На draft не влияет — вклад считается в comboPenalty().
                    if (cat && this.isComboCat(cat)) {
                        if (this.cat[cat] >= this.catMax[cat]) return;
                        this.cat[cat] += 1;
                        this.actions.unshift({
                            v: this.comboStep,
                            cat: cat,
                            label: this.catLabel[cat] || cat,
                            combo: true,
                            inTotal: false,
                        });
                        if (this.actions.length > 40) this.actions.pop();
                        return;
                    }

                    if (cat && this.catMax[cat] !== undefined) {
                        if (this.cat[cat] >= this.catMax[cat]) return;
                        this.cat[cat] += 1;
                    }
                    const next = this.round3(this.draft + v);
                    if (next < 0 || next > 99.999) return;
                    this.draft = next;
                    this.actions.unshift({
                        v: v,
                        cat: cat,
                        label: cat ? (this.catLabel[cat] || cat) : '',
                        inTotal: true,
                    });
                    if (this.actions.length > 40) this.actions.pop();
                },
                // alias на старое название «press» (используется в партиалах)
                press(v, cat) { return this.add(v, cat); },

                set(v) {
                    this.draft = this.round3(v);
                    this.actions = v === 0 ? [] : [{ v: v, cat: null, label: '', inTotal: true }];
                    this.resetCats();
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
                    if (this.actions.length > 40) this.actions.pop();
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
                /** «Х» (DA индивид.) — несделанная акробатика. */
                markAcroNotDone() {
                    this.acroPending = false;
                    this.actions.unshift({
                        v: 0,
                        acro: true,
                        notDone: true,
                        label: 'Акробатика',
                    });
                    if (this.actions.length > 40) this.actions.pop();
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
                    if (this.actions.length > 40) this.actions.pop();
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
                        if (this.actions.length > 40) this.actions.pop();
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
                        if (this.actions.length > 40) this.actions.pop();
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
                    if (this.actions.length > 40) this.actions.pop();
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
                        if (used >= lim.elements) continue;

                        if (a.symbol === 'R') {
                            if (risks >= lim.risks) continue;
                            risks += 1;
                            used += 1;
                            counted.add(i);
                            total += a.v;
                            continue;
                        }

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
                        risksOver: risks > lim.risks,
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
                        if (used >= lim.elements) break;
                        const isRisk = x.a.symbol === 'R';
                        if (isRisk && risks >= lim.risks) continue;
                        counted.add(x.i);
                        if (isRisk) risks += 1;
                        used += 1;
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
                    const missing = items.filter(s => ! s.ok);
                    const penalty = this.round3(missing.length * 0.3);

                    return { items, missing, penalty };
                },

                /**
                 * DA: засчитываются максимум 12 (юниоры) / 15 (сеньоры) элементов
                 * в порядке ввода; акробатик среди них не больше 3.
                 * Несделанная акробатика («Х») занимает слот акробатики с 0 баллов.
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
                            if (isAcro && acro < lim.acro && used < lim.elements) { acro += 1; used += 1; }
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

                /** Авто-сбавка одного блока с учётом «кредитов» (нажатий). */
                blockPenalty(cat) {
                    return this.round3(Math.max(0, this.comboAuto - this.comboStep * (this.cat[cat] || 0)));
                },
                /** Суммарная авто-сбавка блоков (танц. шаги + дин. изменения). */
                comboPenalty() {
                    if (! this.hasCombo) return 0;
                    let sum = 0;
                    for (const c of this.comboCats) {
                        sum += Math.max(0, this.comboAuto - this.comboStep * (this.cat[c] || 0));
                    }
                    return this.round3(sum);
                },
                /** Итоговая сбавка = ручные сбавки + авто-сбавка блоков. */
                totalDeduction() { return this.round3(this.draft + this.comboPenalty()); },

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
                            label: a.label || null,
                            symbol: a.symbol || null,
                            exchange: a.exchange || null,
                            acro: !! a.acro,
                            combo: !! a.combo,
                            notDone: !! a.notDone,
                            counted: this.panel === 'd' ? this.isCounted(i) : (a.inTotal !== false && ! a.combo),
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
                        // Перезагружаем — попадём в состояние "оценка отправлена"
                        window.location.href = data.redirect_url || this.tabletUrl;
                    } catch (err) {
                        this.error = 'Сеть: ' + (err.message || err);
                        this.busy = false;
                    }
                },

                // ====== Numpad ======
                openNumpad() { this.numpadOpen = true; this.numpadValue = ''; },
                closeNumpad() { this.numpadOpen = false; this.numpadValue = ''; },
                numpadAppend(c) {
                    if (c === '.') {
                        if (this.numpadValue.includes('.')) return;
                        if (this.numpadValue === '') this.numpadValue = '0';
                        this.numpadValue += '.';
                        return;
                    }
                    if (this.numpadValue === '0') this.numpadValue = '';
                    if (this.numpadValue.length >= 6) return;
                    this.numpadValue += c;
                },
                numpadBackspace() { this.numpadValue = this.numpadValue.slice(0, -1); },
                applyNumpad() {
                    const v = parseFloat(this.numpadValue || '0');
                    if (isNaN(v) || v <= 0) { this.closeNumpad(); return; }
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
            const pingUrl = @json(route('judge.tournament.tablet.ping', $tournament));
            let lastPid = @json($current?->id);
            let lastCid = @json($category->id);
            let lastSubmitted = @json($alreadySubmitted);
            setInterval(async function () {
                try {
                    const r = await fetch(pingUrl, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    if (!r.ok) return;
                    const j = await r.json();
                    if (!j.resolved) return;
                    const submitted = !! j.score_submitted;
                    if (j.performance_id !== lastPid || j.category_id !== lastCid || submitted !== lastSubmitted) {
                        lastPid = j.performance_id;
                        lastCid = j.category_id;
                        lastSubmitted = submitted;
                        window.location.reload();
                    }
                } catch (e) {}
            }, 3500);
        })();
    </script>
@endpush
