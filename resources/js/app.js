import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

const ASYNC_PAGE_SELECTOR = '[data-async-page]';
let scrollIntentRevision = 0;

if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
['wheel', 'touchmove', 'pointerdown'].forEach((eventName) => {
    window.addEventListener(eventName, () => { scrollIntentRevision += 1; }, { passive: true, capture: true });
});
window.addEventListener('keydown', (event) => {
    if (['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', ' '].includes(event.key)) {
        scrollIntentRevision += 1;
    }
}, { capture: true });

const asyncPage = {
    busy: false,

    pageRoot(doc = document) {
        return doc.querySelector(ASYNC_PAGE_SELECTOR);
    },

    showStatus(message, tone = 'loading') {
        let status = document.getElementById('global-async-status');
        if (!status) {
            status = document.createElement('div');
            status.id = 'global-async-status';
            status.setAttribute('role', 'status');
            document.body.appendChild(status);
        }

        const tones = {
            loading: 'border-amber-700 bg-slate-950/95 text-amber-100',
            success: 'border-emerald-700 bg-emerald-950/95 text-emerald-50',
            error: 'border-rose-700 bg-rose-950/95 text-rose-50',
        };
        status.className = `fixed bottom-4 right-4 z-[100] max-w-sm rounded-xl border px-4 py-2 text-sm shadow-2xl ${tones[tone] || tones.loading}`;
        status.textContent = message;
        status.classList.remove('hidden');

        clearTimeout(status._hideTimer);
        if (tone !== 'loading') {
            status._hideTimer = setTimeout(() => status.classList.add('hidden'), 2200);
        }
    },

    shouldHandle(form) {
        if (!this.pageRoot()) return false;
        if (!(form instanceof HTMLFormElement)) return false;
        if (form.dataset.async === 'false' || form.hasAttribute('data-native-submit')) return false;
        if (form.target && form.target !== '_self') return false;
        if (form.hasAttribute('download')) return false;

        const method = (form.getAttribute('method') || 'GET').toUpperCase();
        if (method === 'GET' || method === 'DIALOG') return false;

        const action = new URL(form.action || window.location.href, window.location.href);
        return action.origin === window.location.origin;
    },

    async executeScripts(root) {
        const scripts = Array.from(root.querySelectorAll('script'));
        for (const oldScript of scripts) {
            const script = document.createElement('script');
            for (const { name, value } of Array.from(oldScript.attributes)) {
                script.setAttribute(name, value);
            }
            if (script.src) script.async = false;
            script.textContent = oldScript.textContent;

            const loaded = script.src
                ? new Promise((resolve) => {
                    script.addEventListener('load', resolve, { once: true });
                    script.addEventListener('error', resolve, { once: true });
                })
                : null;
            oldScript.replaceWith(script);
            if (loaded) await loaded;
        }
    },

    async replaceFromHtml(html, url = window.location.href, options = {}) {
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const incoming = this.pageRoot(parsed);
        const current = this.pageRoot();
        if (!incoming || !current) return false;

        const scrollX = window.scrollX;
        const scrollY = window.scrollY;
        const initialScrollIntentRevision = scrollIntentRevision;
        const nestedScroll = Array.from(current.querySelectorAll('[data-preserve-scroll]'))
            .map((element) => ({
                key: element.id || element.dataset.preserveScroll,
                left: element.scrollLeft,
                top: element.scrollTop,
            }))
            .filter(({ key }) => key);
        const htmlElement = document.documentElement;
        const body = document.body;
        const previousHtmlOverflowAnchor = htmlElement.style.overflowAnchor;
        const previousBodyOverflowAnchor = body.style.overflowAnchor;
        const previousScrollBehavior = htmlElement.style.scrollBehavior;
        const active = document.activeElement;
        const focusKey = active instanceof HTMLElement
            ? (active.id ? `#${CSS.escape(active.id)}` : (active.getAttribute('name') ? `[name="${CSS.escape(active.getAttribute('name'))}"]` : null))
            : null;
        const restoreScroll = () => {
            if (scrollIntentRevision !== initialScrollIntentRevision) return;
            const maxScrollY = Math.max(0, htmlElement.scrollHeight - window.innerHeight);
            window.scrollTo(scrollX, Math.min(scrollY, maxScrollY));
            nestedScroll.forEach(({ key, left, top }) => {
                const escaped = CSS.escape(String(key));
                const element = document.getElementById(key)
                    || document.querySelector(`[data-preserve-scroll="${escaped}"]`);
                if (element instanceof HTMLElement) element.scrollTo(left, top);
            });
        };

        // Replacing the whole Live root makes browser scroll anchoring follow
        // newly inserted score/history blocks before our saved position is restored.
        // Freeze anchoring during the swap so background polling cannot move the screen.
        htmlElement.style.overflowAnchor = 'none';
        body.style.overflowAnchor = 'none';
        htmlElement.style.scrollBehavior = 'auto';

        try {
            window.dispatchEvent(new CustomEvent('judge:before-page-update'));
            current.replaceWith(document.importNode(incoming, true));
            restoreScroll();

            const title = parsed.querySelector('title')?.textContent;
            if (title) document.title = title;
            const csrf = parsed.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (csrf) document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', csrf);

            if (url && url !== window.location.href) history.replaceState({}, '', url);

            const newRoot = this.pageRoot();
            await this.executeScripts(newRoot);
            window.dispatchEvent(new CustomEvent('judge:page-updated', { detail: { url } }));

            if (focusKey) {
                const nextFocus = document.querySelector(focusKey);
                if (nextFocus instanceof HTMLElement) nextFocus.focus({ preventScroll: true });
            }

            // Images, fonts, Alpine and conditional score blocks can finish laying out
            // after the first frame. Keep the exact position until layout settles,
            // but immediately stop restoring when the user starts scrolling.
            await Promise.all([0, 50, 150, 350, 650].map((delay) => new Promise((resolve) => {
                setTimeout(() => {
                    requestAnimationFrame(() => {
                        restoreScroll();
                        resolve();
                    });
                }, delay);
            })));
        } finally {
            htmlElement.style.overflowAnchor = previousHtmlOverflowAnchor;
            body.style.overflowAnchor = previousBodyOverflowAnchor;
            htmlElement.style.scrollBehavior = previousScrollBehavior;
        }

        if (!options.silent) this.showStatus('Интерфейс обновлён без перезагрузки', 'success');
        return true;
    },

    async refresh(url = window.location.href, options = {}) {
        if (this.busy && !options.force) return false;
        this.busy = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), options.timeoutMs ?? 8000);
        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Async-Page': '1',
                },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });
            if (!response.ok) throw new Error(`Ошибка ${response.status}`);
            return await this.replaceFromHtml(await response.text(), response.url || url, options);
        } catch (error) {
            this.showStatus(
                error?.name === 'AbortError' ? 'Обновление заняло слишком много времени' : (error?.message || 'Не удалось обновить страницу'),
                'error',
            );
            return false;
        } finally {
            clearTimeout(timeout);
            this.busy = false;
        }
    },

    async submit(form, submitter = null) {
        if (this.busy) return;
        this.busy = true;

        const action = new URL(form.action || window.location.href, window.location.href);
        const method = (form.getAttribute('method') || 'POST').toUpperCase();
        const body = new FormData(form);
        if (submitter?.name && !body.has(submitter.name)) body.append(submitter.name, submitter.value);

        const previousDisabled = submitter?.disabled ?? false;
        if (submitter) submitter.disabled = true;
        form.setAttribute('aria-busy', 'true');
        form.classList.add('opacity-70');
        this.showStatus('Применяю…', 'loading');

        try {
            const response = await fetch(action, {
                method,
                body,
                headers: {
                    Accept: 'text/html, application/json;q=0.9',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Async-Page': '1',
                },
                credentials: 'same-origin',
            });

            const contentType = response.headers.get('content-type') || '';
            if (contentType.includes('application/json')) {
                const data = await response.json();
                if (!response.ok || data.ok === false) throw new Error(data.error || data.message || `Ошибка ${response.status}`);
                window.dispatchEvent(new CustomEvent('judge:async-action', { detail: data }));
                if (data.redirect_url) await this.refresh(data.redirect_url, { force: true });
                else this.showStatus(data.message || 'Сохранено', 'success');
                return;
            }

            if (contentType.includes('text/html')) {
                const replaced = await this.replaceFromHtml(await response.text(), response.url || window.location.href);
                if (!replaced) window.location.assign(response.url || window.location.href);
                return;
            }

            if (!response.ok) throw new Error(`Ошибка ${response.status}`);
            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            const disposition = response.headers.get('content-disposition') || '';
            const fileName = disposition.match(/filename\*?=(?:UTF-8'')?["']?([^"';]+)/i)?.[1];
            link.href = objectUrl;
            link.download = fileName ? decodeURIComponent(fileName) : 'download';
            link.click();
            URL.revokeObjectURL(objectUrl);
            this.showStatus('Файл подготовлен', 'success');
        } catch (error) {
            this.showStatus(error?.message || 'Действие не выполнено', 'error');
        } finally {
            this.busy = false;
            if (document.contains(form)) {
                form.removeAttribute('aria-busy');
                form.classList.remove('opacity-70');
            }
            if (submitter && document.contains(submitter)) submitter.disabled = previousDisabled;
        }
    },
};

window.JudgeAsync = asyncPage;

document.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    const form = event.target;
    if (!asyncPage.shouldHandle(form)) return;

    event.preventDefault();
    asyncPage.submit(form, event.submitter || null);
});
