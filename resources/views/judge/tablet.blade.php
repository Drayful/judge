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
                <x-judge-panel
                    :type="$pKey"
                    :subpanel="$panel['subpanel'] ?? null"
                    :penalty-type="$panel['penalty_type'] ?? null"
                    :slot="$slot"
                    :base="$panelBase"
                    :saved="$saved"
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
                acroPending: false,          // DA: следующий балл — акробатика
                acroCount: 0,                // DA: сколько акробатик уже засчитано
                acroMax: 3,                  // DA: максимум засчитываемых акробатик

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
                    if (opts.initial && opts.initial !== 0) {
                        this.draft = opts.initial;
                        this.actions = [{ v: opts.initial, cat: null, label: '' }];
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
                    this.pendingSymbol = { symbol: symbol, label: label };
                    this.error = null;
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
                        notDone: true,
                        inTotal: false,
                    });
                    if (this.actions.length > 40) this.actions.pop();
                    this.pendingSymbol = null;
                },

                // ====== DA-бригада: акробатика (макс. 3) + значения ======
                /** Включить/выключить режим «следующий балл — акробатика». */
                toggleAcro() {
                    this.acroPending = ! this.acroPending;
                    this.error = null;
                },
                /** «Х» (DA) — несделанная акробатика: занимает слот (если < 3), но даёт 0 баллов. */
                markAcroNotDone() {
                    this.acroPending = false;
                    const counted = this.acroCount < this.acroMax;
                    if (counted) this.acroCount += 1;
                    this.actions.unshift({
                        v: 0,
                        acro: true,
                        counted: counted,
                        notDone: true,
                        label: 'Акробатика',
                        inTotal: false,
                    });
                    if (this.actions.length > 40) this.actions.pop();
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
                        const next = this.round3(this.draft + v);
                        if (next < 0 || next > 99.999) return;
                        this.draft = next;
                        this.actions.unshift({
                            v: v,
                            symbol: this.pendingSymbol.symbol,
                            label: this.pendingSymbol.label,
                            notDone: false,
                            inTotal: true,
                        });
                        if (this.actions.length > 40) this.actions.pop();
                        this.pendingSymbol = null;
                        return;
                    }

                    // DA: акробатика (засчитывается только первые 3)
                    if (this.acroPending) {
                        this.acroPending = false;
                        const counted = this.acroCount < this.acroMax;
                        if (counted) {
                            const next = this.round3(this.draft + v);
                            if (next > 99.999) return;
                            this.draft = next;
                            this.acroCount += 1;
                        }
                        this.actions.unshift({
                            v: v,
                            acro: true,
                            counted: counted,
                            label: 'Акробатика',
                            inTotal: counted,
                        });
                        if (this.actions.length > 40) this.actions.pop();
                        return;
                    }

                    // DA: простое значение
                    const next = this.round3(this.draft + v);
                    if (next < 0 || next > 99.999) return;
                    this.draft = next;
                    this.actions.unshift({ v: v, acro: false, label: '', inTotal: true });
                    if (this.actions.length > 40) this.actions.pop();
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
                    // DA: режим акробатики включён, но значение не выбрано — выключаем.
                    if (this.acroPending) {
                        this.acroPending = false;
                        return;
                    }
                    if (this.actions.length === 0) return;
                    const last = this.actions.shift();
                    if (last.cat && this.cat[last.cat] !== undefined) {
                        this.cat[last.cat] = Math.max(0, this.cat[last.cat] - 1);
                    }
                    if (last.acro && last.counted) {
                        this.acroCount = Math.max(0, this.acroCount - 1);
                    }
                    // Вычитаем из суммы только то, что в неё попадало.
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
                    this.acroCount = 0;
                    this.acroPending = false;
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
                    if (this.mode === 'add') return this.totalDeduction();
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
                    if (j.performance_id !== lastPid || j.category_id !== lastCid) {
                        lastPid = j.performance_id;
                        lastCid = j.category_id;
                        window.location.reload();
                    }
                } catch (e) {}
            }, 3500);
        })();
    </script>
@endpush
