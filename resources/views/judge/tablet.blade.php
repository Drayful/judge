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

                <div class="rounded-lg bg-[#1c2547] border border-slate-700 px-3 h-full flex items-center gap-2">
                    <span class="text-[10px] uppercase text-slate-400">Юн.</span>
                    <span class="text-base font-bold text-cyan-200 tabular-nums">13</span>
                </div>
                <div class="rounded-lg bg-[#1c2547] border border-slate-700 px-3 h-full flex items-center gap-2">
                    <span class="text-[10px] uppercase text-slate-400">Сен.</span>
                    <span class="text-base font-bold text-cyan-200 tabular-nums">16</span>
                </div>
                <div class="rounded-lg bg-[#0e5a3f] border border-emerald-700 px-3 h-full flex items-center">
                    <span class="text-xs font-semibold uppercase tracking-wider text-emerald-50">Ответственный судья</span>
                </div>
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
                actions: [],                 // [{v, cat, label}]
                busy: false,
                error: null,
                numpadOpen: false,
                numpadValue: '',

                // Лимиты по категориям (A1: dance, dynamic — макс. 2)
                cat: { dance: 0, dynamic: 0 },
                catMax: { dance: 2, dynamic: 2 },
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
                    });
                    if (this.actions.length > 40) this.actions.pop();
                },
                // alias на старое название «press» (используется в партиалах)
                press(v, cat) { return this.add(v, cat); },

                set(v) {
                    this.draft = this.round3(v);
                    this.actions = v === 0 ? [] : [{ v: v, cat: null, label: '' }];
                    this.resetCats();
                },

                /** «ОТМЕНА» — по новому ТЗ: удаляет ПОСЛЕДНЕЕ действие из истории и пересчитывает сумму. */
                cancel() {
                    if (this.actions.length === 0) return;
                    const last = this.actions.shift();
                    if (last.cat && this.cat[last.cat] !== undefined) {
                        this.cat[last.cat] = Math.max(0, this.cat[last.cat] - 1);
                    }
                    this.draft = this.round3(this.draft - last.v);
                    if (this.draft < 0) this.draft = 0;
                },

                /** Полный сброс (вешается на отдельную «X (0.0)» кнопку). */
                clearAll() {
                    this.draft = 0;
                    this.actions = [];
                    this.resetCats();
                    this.error = null;
                },
                resetCats() { Object.keys(this.cat).forEach(k => { this.cat[k] = 0; }); },

                workingTotal() { return this.draft; },
                finalScore() {
                    if (this.mode === 'add') return this.draft;
                    if (this.mode === 'subtract') {
                        const r = this.round3(this.base - this.draft);
                        return r < 0 ? 0 : r;
                    }
                    return this.draft;
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
                    this.add(this.round3(v), null);
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
