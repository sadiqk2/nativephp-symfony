/*
 * The documentation site's behaviour, in one file with no dependencies.
 *
 * Everything here degrades: with JavaScript off you still get the whole site — every page,
 * every link, the sidebar, the on-this-page index — just without search, the theme toggle,
 * copy buttons or scroll tracking. That is the ordering these are built in.
 */

(() => {
    'use strict';

    const root = document.documentElement;

    // ── Theme ───────────────────────────────────────
    // Three states, not two: an explicit light or dark, or unset, which follows the system.
    // The button cycles through them so nobody is stuck overriding their own OS setting.

    const themeButton = document.querySelector('.icon-button.theme');

    const label = () => {
        const stored = localStorage.getItem('theme');
        return stored === 'dark' ? '☾' : stored === 'light' ? '☀' : '◐';
    };

    const paint = () => {
        if (themeButton) {
            themeButton.textContent = label();
            themeButton.title = `Theme: ${localStorage.getItem('theme') ?? 'system'}`;
        }
    };

    themeButton?.addEventListener('click', () => {
        const order = [null, 'light', 'dark'];
        const next = order[(order.indexOf(localStorage.getItem('theme')) + 1) % order.length];

        if (next) {
            localStorage.setItem('theme', next);
            root.dataset.theme = next;
        } else {
            localStorage.removeItem('theme');
            delete root.dataset.theme;
        }

        paint();
    });

    paint();

    // ── Copy buttons ────────────────────────────────

    for (const button of document.querySelectorAll('.copy')) {
        button.addEventListener('click', async () => {
            const code = button.closest('.code')?.querySelector('code');
            if (!code) return;

            try {
                await navigator.clipboard.writeText(code.innerText);
                button.textContent = 'Copied';
                button.classList.add('done');
            } catch {
                // A denied clipboard is not an error worth a dialog; say so on the button.
                button.textContent = 'Press ⌘C';
            }

            setTimeout(() => {
                button.textContent = 'Copy';
                button.classList.remove('done');
            }, 1600);
        });
    }

    // ── Mobile navigation ───────────────────────────

    const burger = document.querySelector('.burger');
    const sidebar = document.getElementById('sidebar');

    burger?.addEventListener('click', () => {
        const open = sidebar.classList.toggle('open');
        burger.setAttribute('aria-expanded', String(open));
    });

    sidebar?.addEventListener('click', (event) => {
        if (event.target.closest('a')) {
            sidebar.classList.remove('open');
            burger?.setAttribute('aria-expanded', 'false');
        }
    });

    // ── On this page: mark the section being read ───

    const tocLinks = [...document.querySelectorAll('.toc a')];

    if (tocLinks.length && 'IntersectionObserver' in window) {
        const byId = new Map(tocLinks.map((a) => [a.getAttribute('href').slice(1), a]));
        const headings = [...document.querySelectorAll('.prose h2[id], .prose h3[id]')].filter((h) =>
            byId.has(h.id),
        );

        let current = null;

        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (!entry.isIntersecting) continue;

                    current?.classList.remove('current');
                    current = byId.get(entry.target.id);
                    current?.classList.add('current');
                }
            },
            // Top-weighted: a heading counts as "being read" once it reaches the upper
            // quarter of the viewport, which is where the eye is, rather than at the edge.
            { rootMargin: '-80px 0px -70% 0px', threshold: 0 },
        );

        headings.forEach((h) => observer.observe(h));
    }

    // ── Search ──────────────────────────────────────
    // A prebuilt JSON index, fetched on first use. Small enough (tens of KB) that ranking in
    // the page is instant, and it keeps the site dependency-free and offline-capable.

    const input = document.getElementById('search');
    const panel = document.getElementById('search-panel');
    let index = null;
    let active = -1;

    const load = async () => {
        if (index) return index;

        try {
            index = await (await fetch('assets/search.json')).json();
        } catch {
            index = [];
        }

        return index;
    };

    const escape = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    /** A short window of text around the first match, so a hit shows its context. */
    const excerpt = (text, query) => {
        const at = text.toLowerCase().indexOf(query);
        if (at < 0) return '';

        const from = Math.max(0, at - 45);
        const slice = text.slice(from, from + 150);

        return `${from > 0 ? '…' : ''}${escape(slice)}…`.replace(
            new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'ig'),
            '<mark>$1</mark>',
        );
    };

    const rank = (query, entries) =>
        entries
            .map((entry) => {
                const q = query.toLowerCase();
                let score = 0;

                if (entry.t.toLowerCase().includes(q)) score += 50;
                if (entry.b.toLowerCase().includes(q)) score += 20;

                const heading = entry.h.find((h) => h.t.toLowerCase().includes(q));
                if (heading) score += 30;

                const body = entry.x.toLowerCase().indexOf(q);
                if (body >= 0) score += 10;

                return { entry, score, heading, body: body >= 0 ? excerpt(entry.x, q) : '' };
            })
            .filter((r) => r.score > 0)
            .sort((a, b) => b.score - a.score)
            .slice(0, 8);

    const render = (results, query) => {
        if (!results.length) {
            panel.innerHTML = `<p class="search-empty">Nothing matches “${escape(query)}”.</p>`;
            panel.hidden = false;
            return;
        }

        panel.innerHTML = results
            .map(
                (r, i) => `<a class="hit${i === active ? ' active' : ''}" href="${r.entry.u}${
                    r.heading ? `#${r.heading.a}` : ''
                }">
                <span class="hit-title">${escape(r.entry.t)}${
                    r.heading ? ` <span class="hit-sub">${escape(r.heading.t)}</span>` : ''
                }</span>
                ${r.body ? `<span class="hit-text">${r.body}</span>` : `<span class="hit-text">${escape(r.entry.b)}</span>`}
            </a>`,
            )
            .join('');
        panel.hidden = false;
    };

    let results = [];

    input?.addEventListener('input', async () => {
        const query = input.value.trim();
        active = -1;

        if (query.length < 2) {
            panel.hidden = true;
            return;
        }

        results = rank(query, await load());
        render(results, query);
    });

    input?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            panel.hidden = true;
            input.blur();
            return;
        }

        if (!results.length || panel.hidden) return;

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            active = (active + (event.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length;
            render(results, input.value.trim());
        }

        if (event.key === 'Enter' && active >= 0) {
            event.preventDefault();
            const hit = results[active];
            location.href = hit.entry.u + (hit.heading ? `#${hit.heading.a}` : '');
        }
    });

    document.addEventListener('click', (event) => {
        if (panel && !panel.hidden && !event.target.closest('.search')) panel.hidden = true;
    });

    // `/` focuses search, the convention every documentation site shares — but not while
    // someone is typing into something else.
    document.addEventListener('keydown', (event) => {
        const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName ?? '');

        if (event.key === '/' && !typing) {
            event.preventDefault();
            input?.focus();
        }
    });
})();
