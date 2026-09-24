<div id="score-history-modal" data-pause-live-refresh="1" class="hidden fixed inset-0 z-50 p-2 sm:p-4">
    <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" data-history-close></div>
    <div class="relative mx-auto flex h-[calc(100vh-1rem)] w-[min(98vw,1320px)] flex-col overflow-hidden rounded-2xl border-2 border-sky-700/70 bg-slate-950 p-4 shadow-2xl shadow-sky-950/60 sm:h-[calc(100vh-2rem)] sm:p-6">
        <div class="flex items-start justify-between gap-3">
            <h3 id="score-history-title" class="text-xl font-extrabold text-white sm:text-2xl">История выставления оценки</h3>
            <button type="button" data-history-close class="rounded-xl border border-slate-600 bg-slate-800 px-4 py-2 text-lg font-bold text-white hover:bg-slate-700">✕</button>
        </div>
        <div id="score-history-body" class="mt-4 min-h-0 flex-1 space-y-5 overflow-y-auto pr-1 text-base text-slate-100"></div>
    </div>
</div>

<script>
(() => {
    const histories = @json($scoreHistoryByPerformance ?? []);
    const currentPerformanceId = @json($currentPerformance?->id);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const modal = document.getElementById('score-history-modal');
    const title = document.getElementById('score-history-title');
    const body = document.getElementById('score-history-body');
    if (! modal) return;
    let liveInterval = null;
    let liveRequestInFlight = false;
    let liveSelection = null;
    let liveRenderedHtml = null;
    let liveScrollPointerDown = false;
    let liveScrollLockedUntil = 0;

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const slotActions = (performanceHistory, slot, score) => {
        const updateUrl = performanceHistory?.update_url;
        const returnUrl = performanceHistory?.return_url;
        if (! updateUrl || ! returnUrl) return '';
        const returnConfirm = /^(LINE|TIME|RESP)/.test(slot)
            ? ''
            : ` onsubmit="return confirm('Вернуть оценку ${esc(slot)} судье на доработку?');"`;
        return `
            <div class="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-800 pt-3">
                <form method="POST" action="${esc(updateUrl)}" class="flex items-center gap-2 flex-1 min-w-[200px]">
                    <input type="hidden" name="_token" value="${esc(csrf)}">
                    <input type="hidden" name="slot" value="${esc(slot)}">
                    <input type="hidden" name="input_mode" value="deduction">
                    <label class="text-[10px] text-slate-500 shrink-0">Исходный балл / сбавка</label>
                    <input type="number" name="score" step="0.001" min="0" max="99.999" value="${esc(score)}" required
                           class="flex-1 rounded-md border border-slate-700 bg-slate-950 text-slate-100 text-xs py-1.5 px-2 font-mono">
                    <button type="submit" class="rounded-md border border-amber-700/60 bg-amber-900/30 px-3 py-1.5 text-xs text-amber-100 hover:bg-amber-800/40">Сохранить</button>
                </form>
                <form method="POST" action="${esc(returnUrl)}"${returnConfirm}>
                    <input type="hidden" name="_token" value="${esc(csrf)}">
                    <input type="hidden" name="slot" value="${esc(slot)}">
                    <button type="submit" class="rounded-md border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs text-slate-300 hover:bg-slate-800">↩ На доработку</button>
                </form>
            </div>`;
    };

    const dcDisplay = (sym) => {
        if (sym === 'C_UP') return 'C↗↗';
        if (sym === 'C_DOWN') return 'C↓↓';
        return sym || '';
    };

    const entryLine = (e) => {
        const showEx = e.exchange && e.symbol !== 'DE';
        const exTag = showEx ? ` <span class="text-indigo-300/90">(${esc(String(e.exchange).toUpperCase())})</span>` : '';
        const dcSym = e.symbol && ['CC', 'CR', 'C_UP', 'C_DOWN'].includes(e.symbol)
            ? `<span class="font-black text-indigo-300">${esc(dcDisplay(e.symbol))}</span> `
            : '';
        const sym = dcSym || (e.symbol ? `<span class="font-black">${esc(e.symbol)}</span> ` : (e.acro ? '<span class="font-black text-indigo-300">A</span> ' : ''));
        const label = e.label ? `<span class="font-semibold text-slate-200">${esc(e.label)}</span>${exTag} ` : '';
        const val = e.combo
            ? '<span class="text-emerald-300">выполнено</span>'
            : (e.notDone ? '<span class="text-rose-300">Х · 0 (не выполнен)</span>' : `<span class="font-mono tabular-nums">${Number(e.v).toFixed(2)}</span>`);
        const counted = (e.notDone || e.combo) ? '' : (e.counted === false ? ' <span class="text-[10px] text-rose-300">не в зачёте</span>' : '');
        return `<li class="flex min-h-14 items-center gap-3 rounded-xl border border-sky-800/60 bg-slate-900 px-3 py-2 text-base shadow-sm ${e.counted === false && !e.notDone && !e.combo ? 'opacity-60' : ''}">${sym}${label}<span class="ml-auto text-xl font-extrabold">${val}</span>${counted}</li>`;
    };

    const slotBlock = (performanceHistory, slot, withActions = false, scoreOverride = undefined) => {
        const h = scoreOverride === undefined ? performanceHistory?.slots?.[slot] : scoreOverride;
        if (! h) return '';
        const ag = h.age_group === 'junior' ? 'Юниоры' : (h.age_group === 'senior' ? 'Сеньоры' : null);
        const meta = [h.judge, ag, h.submitted_at ? 'отправлено ' + h.submitted_at : null].filter(Boolean).map(esc).join(' · ');
        const entries = Array.isArray(h.entries) && h.entries.length
            ? `<ul class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">${h.entries.map(entryLine).join('')}</ul>`
            : '<div class="mt-3 text-sm text-slate-400">История нажатий не передана (оценка введена без планшета или старой версией).</div>';
        const actions = withActions ? slotActions(performanceHistory, slot, h.display_score) : '';
        return `
            <div class="rounded-2xl border-2 ${h.returned ? 'border-rose-500 bg-rose-900/60' : h.same_club ? 'border-amber-400 bg-amber-950/40' : 'border-emerald-700/70 bg-emerald-950/25'} p-5">
                ${h.returned ? '<div class="font-bold text-rose-200">ВОЗВРАЩЕНО НА ДОРАБОТКУ</div>' : ''}
                ${h.same_club ? '<div class="text-amber-200">Судья и участница из одной школы</div>' : ''}
                <div class="flex items-center justify-between gap-2">
                    <div class="font-mono text-2xl font-black text-emerald-300 sm:text-3xl">${esc(slot)} <span class="text-white">${esc(h.display_score)}</span>${h.display_label === 'Сбавка' ? ' <span class="text-sm font-sans text-emerald-200">сбавка</span>' : ''}</div>
                    <div class="text-sm font-medium text-slate-300">${meta}</div>
                </div>
                ${actions}
                ${entries}
            </div>`;
    };

    const liveActionsBlock = (actions) => {
        if (! Array.isArray(actions) || actions.length === 0) {
            return '<div class="rounded-2xl border-2 border-dashed border-sky-600 bg-sky-950/40 px-6 py-12 text-center text-xl font-semibold text-sky-100">Судья пока не завершил ни одного действия для этой оценки.<div class="mt-2 text-sm font-normal text-sky-300">Выбор элемента без балла здесь не показывается. Окно обновляется автоматически.</div></div>';
        }

        const latest = actions[0];
        const latestDraft = latest.draft_score !== null && latest.draft_score !== undefined ? esc(latest.draft_score) : '—';

        return `
            <div class="rounded-2xl border-2 border-sky-600/80 bg-sky-950/35 p-4 sm:p-5">
                <div class="mb-4 grid gap-3 md:grid-cols-[1fr_auto]">
                    <div class="rounded-xl border border-cyan-500/70 bg-cyan-900/45 px-5 py-4">
                        <div class="text-sm font-bold uppercase tracking-wider text-cyan-200">Текущий черновик</div>
                        <div class="mt-1 font-mono text-5xl font-black tabular-nums text-white sm:text-6xl">${latestDraft}</div>
                        <div class="mt-2 text-lg font-bold text-cyan-100">${esc(latest.action || 'Действие')}</div>
                    </div>
                    <div class="flex min-w-52 flex-col justify-center rounded-xl border border-amber-500/60 bg-amber-950/45 px-5 py-4 text-amber-100">
                        <div class="text-lg font-bold">${esc(latest.judge || 'Судья')}</div>
                        <div class="mt-1 font-mono text-2xl font-black">${esc(latest.created_at || '—')}</div>
                        <div class="mt-2 text-sm text-amber-300">LIVE · обновление каждую секунду</div>
                    </div>
                </div>
                <div class="mb-2 text-sm font-bold uppercase tracking-wider text-sky-200">История завершённых действий</div>
                <div data-live-actions-scroll class="grid max-h-[42vh] gap-3 overflow-y-auto pr-1 lg:grid-cols-2">
                    ${actions.map((action) => {
                        const entries = Array.isArray(action.entries) && action.entries.length
                            ? `<ul class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">${action.entries.map(entryLine).join('')}</ul>`
                            : '';
                        return `
                            <div class="rounded-xl border border-slate-700 bg-slate-950/80 px-4 py-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-base font-bold text-white">${esc(action.action || 'Действие')}</div>
                                        <div class="mt-1 text-sm text-slate-400">${esc(action.judge || 'Судья')} · ${esc(action.created_at || '—')}</div>
                                    </div>
                                    ${action.draft_score !== null && action.draft_score !== undefined ? `<div class="shrink-0 rounded-lg bg-sky-900/70 px-3 py-2 text-right"><div class="text-xs font-bold uppercase text-sky-300">Сумма</div><div class="font-mono text-2xl font-black text-white">${esc(action.draft_score)}</div></div>` : ''}
                                </div>
                                ${entries}
                            </div>`;
                    }).join('')}
                </div>
            </div>`;
    };

    const stopLive = () => {
        if (liveInterval) clearInterval(liveInterval);
        liveInterval = null;
        liveSelection = null;
        liveRequestInFlight = false;
        liveRenderedHtml = null;
    };

    const lockLiveScroll = (milliseconds = 900) => {
        liveScrollLockedUntil = Math.max(liveScrollLockedUntil, Date.now() + milliseconds);
    };

    const isLiveScrollTarget = (target) => liveSelection
        && target instanceof Element
        && (target === body || target.closest('#score-history-body, [data-live-actions-scroll]'));

    body.addEventListener('pointerdown', (event) => {
        if (! isLiveScrollTarget(event.target)) return;
        liveScrollPointerDown = true;
        lockLiveScroll();
    });
    const finishLiveScrollPointer = () => {
        if (! liveScrollPointerDown) return;
        liveScrollPointerDown = false;
        lockLiveScroll(1200);
    };
    document.addEventListener('pointerup', finishLiveScrollPointer);
    document.addEventListener('pointercancel', finishLiveScrollPointer);
    window.addEventListener('blur', finishLiveScrollPointer);
    body.addEventListener('wheel', () => lockLiveScroll(1200), { passive: true });
    body.addEventListener('touchmove', () => lockLiveScroll(1200), { passive: true });

    const liveScrollIsBusy = () => liveScrollPointerDown || Date.now() < liveScrollLockedUntil;

    const refreshLive = async () => {
        if (! liveSelection || liveRequestInFlight || modal.classList.contains('hidden')) return;
        if (body.contains(document.activeElement) && document.activeElement?.matches('input, select, textarea')) return;
        if (liveScrollIsBusy()) return;
        liveRequestInFlight = true;
        try {
            const url = new URL(liveSelection.url, window.location.origin);
            url.searchParams.set('slot', liveSelection.slot);
            const response = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (! response.ok) throw new Error(`Ошибка ${response.status}`);
            const data = await response.json();
            if (! liveSelection || String(data.performance_id) !== String(liveSelection.performanceId) || data.slot !== liveSelection.slot) return;
            const finalScore = data.score
                ? slotBlock(liveSelection.performanceHistory, liveSelection.slot, true, data.score)
                : '<div class="rounded-2xl border-2 border-dashed border-amber-700 bg-amber-950/25 p-5 text-lg font-semibold text-amber-200">Итоговая оценка ещё не отправлена.' + slotActions(liveSelection.performanceHistory, liveSelection.slot, '') + '</div>';
            const nextHtml = finalScore + liveActionsBlock(data.actions);
            if (nextHtml === liveRenderedHtml || liveScrollIsBusy()) return;

            const actionsScroll = body.querySelector('[data-live-actions-scroll]');
            const bodyScrollTop = body.scrollTop;
            const actionsScrollTop = actionsScroll?.scrollTop ?? 0;
            const actionsAtBottom = actionsScroll
                ? actionsScroll.scrollTop + actionsScroll.clientHeight >= actionsScroll.scrollHeight - 4
                : false;

            body.innerHTML = nextHtml;
            liveRenderedHtml = nextHtml;
            body.scrollTop = Math.min(bodyScrollTop, Math.max(0, body.scrollHeight - body.clientHeight));

            const nextActionsScroll = body.querySelector('[data-live-actions-scroll]');
            if (nextActionsScroll) {
                nextActionsScroll.scrollTop = actionsAtBottom
                    ? Math.max(0, nextActionsScroll.scrollHeight - nextActionsScroll.clientHeight)
                    : Math.min(actionsScrollTop, Math.max(0, nextActionsScroll.scrollHeight - nextActionsScroll.clientHeight));
            }
        } catch (error) {
            if (body.childElementCount === 0) {
                body.innerHTML = `<div class="rounded-lg border border-rose-800/70 bg-rose-950/35 px-3 py-2 text-sm text-rose-100">${esc(error?.message || 'Не удалось получить Live-действия.')}</div>`;
            }
        } finally {
            liveRequestInFlight = false;
        }
    };

    const open = (performanceHistory, slots, heading, withActions = false) => {
        stopLive();
        const blocks = slots.map((s) => slotBlock(performanceHistory, s, withActions && slots.length === 1)).filter(Boolean);
        if (! blocks.length) return;
        title.textContent = heading;
        body.innerHTML = blocks.join('');
        modal.classList.remove('hidden');
    };

    const openLive = (performanceHistory, performanceId, slot) => {
        stopLive();
        title.textContent = `${performanceHistory?.athlete || 'Гимнастка'} — ${slot} · Live`;
        body.innerHTML = '<div class="rounded-xl border border-sky-900/70 bg-sky-950/20 px-4 py-6 text-center text-sm text-sky-200">Загружаю действия судьи…</div>';
        modal.classList.remove('hidden');
        liveSelection = {
            performanceHistory,
            performanceId,
            slot,
            url: performanceHistory?.live_history_url,
        };
        if (! liveSelection.url) return;
        refreshLive();
        liveInterval = setInterval(refreshLive, 1000);
    };

    document.querySelectorAll('[data-history-slot]').forEach((td) => {
        td.addEventListener('click', () => {
            const slot = td.dataset.historySlot;
            const performanceHistory = histories[String(currentPerformanceId)];
            if (performanceHistory?.slots?.[slot]) open(performanceHistory, [slot], 'История выставления — ' + slot, true);
        });
    });

    document.querySelectorAll('[data-stream-history-score]').forEach((button) => {
        button.addEventListener('click', () => {
            const performanceHistory = histories[String(button.dataset.performanceId)];
            const slot = button.dataset.slot;
            if (! performanceHistory) return;
            openLive(performanceHistory, button.dataset.performanceId, slot);
        });
    });

    const totalBadge = document.getElementById('total-score-badge');
    if (totalBadge) {
        totalBadge.addEventListener('click', () => {
            const performanceHistory = histories[String(currentPerformanceId)];
            if (performanceHistory) {
                open(performanceHistory, Object.keys(performanceHistory.slots || {}), 'История выставления оценок — все судьи');
            }
        });
    }

    const close = () => {
        stopLive();
        modal.classList.add('hidden');
    };
    modal.querySelectorAll('[data-history-close]').forEach((el) => el.addEventListener('click', close));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') close();
    });
    window.addEventListener('judge:before-page-update', stopLive, { once: true });
})();
</script>
