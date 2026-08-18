/**
 * Builds the documentation site: every Markdown document in the repository becomes a page in
 * docs/, which GitHub Pages serves as-is.
 *
 * Why generated HTML rather than Jekyll or a remote theme: there is no Ruby in this
 * environment, so a Jekyll site's first rendering would have been the published one. These
 * files can be opened, screenshotted and read before anyone else sees them — the same reason
 * this project screenshots the demo rather than trusting its tests alone. The cost is that the
 * site is rebuilt by running this script.
 *
 *   npm install marked highlight.js
 *   DOCS_ROOT="$PWD" node tools/build-docs.mjs
 *
 * DOCS_ROOT exists because ESM resolves a bare `import 'marked'` from the importing file's
 * directory upwards, so the script has to be runnable from wherever the modules were installed
 * while still writing into this repository.
 *
 * Everything it emits is self-contained: system fonts, an inline SVG favicon, one stylesheet,
 * one small script, one JSON search index. No CDN, no webfont, no analytics. Documentation
 * gets read while something is broken, sometimes behind a proxy.
 */

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { join, dirname, posix } from 'node:path';
import { fileURLToPath } from 'node:url';
import { marked } from 'marked';
import hljs from 'highlight.js';

const root = process.env.DOCS_ROOT ?? join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'docs');
const REPO = 'https://github.com/sadiqk2/nativephp-symfony';
const BLOB = `${REPO}/blob/main`;

/**
 * Every page, in reading order, grouped the way someone learns this rather than the way the
 * filesystem happens to be arranged. `src` is relative to the repository root; `slug` names
 * the file written into docs/.
 */
const NAV = [
    { group: 'Start here' },
    { home: true, slug: 'index', title: 'Home', blurb: 'Symfony as a desktop, iOS and Android application' },
    { src: 'docs/README.md', slug: 'introduction', title: 'Introduction', blurb: 'What is here, what is authoritative, and what has drifted' },
    { src: 'docs/getting-started-desktop.md', slug: 'desktop', title: 'Desktop', blurb: 'composer require to a window on screen' },
    { src: 'docs/getting-started-mobile.md', slug: 'mobile', title: 'Mobile', blurb: 'iOS and Android, and what a build needs' },

    { group: 'Reference' },
    { src: 'docs/desktop-api.md', slug: 'desktop-api', title: 'Desktop API', blurb: 'Windows, menus, dialogs, processes, events' },
    { src: 'docs/mobile-api.md', slug: 'mobile-api', title: 'Mobile API', blurb: '54 bridge methods and the native-UI layers' },
    { src: 'CONTRACT.md', slug: 'contract', title: 'Wire protocol', blurb: 'All 116 endpoints and 44 events, exactly' },
    { src: 'NATIVE-UI-CONTRACT.md', slug: 'native-ui-contract', title: 'Native-UI format', blurb: 'What SwiftUI and Compose consume' },

    { group: 'Working with it' },
    { src: 'docs/recipes.md', slug: 'recipes', title: 'Recipes', blurb: 'Multi-window, workers, native screens, packaging' },
    { src: 'docs/testing.md', slug: 'testing', title: 'Testing', blurb: 'FakeRuntime, expectations, event simulation' },
    { src: 'docs/troubleshooting.md', slug: 'troubleshooting', title: 'Troubleshooting', blurb: 'Symptom → cause → fix' },

    { group: 'How it works' },
    { src: 'ARCHITECTURE.md', slug: 'architecture', title: 'Architecture', blurb: 'Desktop vs mobile, and the shared traps' },
    { src: 'ANALYSIS.md', slug: 'analysis', title: 'Deep dive', blurb: 'Boot sequence, port map, the Laravel-isms' },
    { src: 'MOBILE-ANALYSIS.md', slug: 'mobile-analysis', title: 'Mobile analysis', blurb: 'Both render paths, and a corrected conclusion' },
    { src: 'PLAN.md', slug: 'plan', title: 'Plan', blurb: 'The milestones, and where each one landed' },

    { group: 'The packages' },
    { src: 'bundle/README.md', slug: 'desktop-bundle', title: 'Desktop bundle', blurb: 'What ships in native-symfony/desktop-bundle' },
    { src: 'mobile-bundle/README.md', slug: 'mobile-bundle', title: 'Mobile bundle', blurb: 'What ships in native-symfony/mobile-bundle' },
    { src: 'demo/README.md', slug: 'demo', title: 'The demo app', blurb: 'Deskpad: both bundles, driven end to end' },
    { src: 'upstream-patches/README.md', slug: 'upstream-patches', title: 'Upstream patches', blurb: 'Eleven fixes, ten of them open PRs' },

    { group: 'Record' },
    { src: 'SPIKE-RESULTS.md', slug: 'spike-results', title: 'M1 — the spike', blurb: 'Proving it possible at all' },
    { src: 'M2-RESULTS.md', slug: 'm2-results', title: 'M2 — the bundle', blurb: 'The 116-endpoint surface' },
    { src: 'M3-RESULTS.md', slug: 'm3-results', title: 'M3 — the build', blurb: 'Packaging, and what it got wrong first' },
];

const pages = NAV.filter((n) => n.src && existsSync(join(root, n.src)));
const home = NAV.find((n) => n.home);
const bySource = new Map(pages.map((p) => [p.src, p]));
const fileOf = (page) => `${page.slug}.html`;

/**
 * GitHub's slugger, reproduced. Two details matter and both are easy to miss: each space
 * becomes its own hyphen, so `## Desktop — boot` (em dash stripped) is `desktop--boot` with
 * two; and underscores survive, so `_windowId` is `_windowid`. Matching it is deliberate in
 * both directions — a link written against GitHub keeps working here, and one that is broken
 * there stays broken rather than being quietly repaired by a more forgiving rule.
 */
const slugify = (text) =>
    text
        .toLowerCase()
        .replace(/<[^>]+>/g, '')
        .replace(/[`~!@#$%^&*()+=<>?,./:;"'|{}[\]\\–—]/g, '')
        .trim()
        .replace(/\s/g, '-');

const escapeHtml = (s) =>
    s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** Fence languages these documents use that highlight.js knows under another name. */
const LANGS = { console: 'bash', sh: 'bash', shell: 'bash', yml: 'yaml', jsonc: 'json', ts: 'typescript', kt: 'kotlin', mjs: 'javascript' };

function highlight(code, lang) {
    const name = LANGS[lang] ?? lang;

    return name && hljs.getLanguage(name)
        ? hljs.highlight(code, { language: name, ignoreIllegals: true }).value
        : escapeHtml(code);
}

/** Resolve a link written inside `page` to something that works on this site. */
function resolveLink(href, page) {
    if (/^(https?:|mailto:|#|data:)/.test(href)) return href;

    const [target, fragment] = href.split('#');
    const suffix = fragment ? `#${fragment}` : '';

    if (!target) return href;

    // Relative to the document the link lives in, then matched against the page list.
    const fromRoot = posix.normalize(posix.join(posix.dirname(page.src), target));
    const known = bySource.get(fromRoot);

    if (known) return fileOf(known) + suffix;

    // Something the site does not carry — a source file, a directory. Send it to GitHub
    // rather than leaving a link that 404s inside the site.
    return `${BLOB}/${fromRoot}${suffix}`;
}

function renderer(page) {
    const r = new marked.Renderer();

    r.heading = ({ text, depth }) => {
        const id = slugify(text);
        return `<h${depth} id="${id}"><a class="anchor" href="#${id}">${marked.parseInline(text)}</a></h${depth}>\n`;
    };

    r.link = ({ href, title, tokens }) => {
        const target = resolveLink(href, page);
        const label = marked.parseInline(tokens.map((t) => t.raw).join(''));
        const external = /^https?:/.test(target);

        return `<a href="${target}"${title ? ` title="${escapeHtml(title)}"` : ''}${
            external ? ' target="_blank" rel="noopener"' : ''
        }>${label}</a>`;
    };

    r.code = ({ text, lang }) => {
        const language = (lang ?? '').split(/\s+/)[0];

        // The copy button is in the markup rather than injected by script, so a page with
        // JavaScript off shows no half-built control.
        return `<figure class="code">
<figcaption><span class="code-lang">${escapeHtml(language || 'text')}</span><button class="copy" type="button">Copy</button></figcaption>
<pre><code${language ? ` class="language-${escapeHtml(language)}"` : ''}>${highlight(text, language)}</code></pre>
</figure>\n`;
    };

    r.table = (token) => {
        // These documents use headerless tables (`| | |`) as two-column layout; rendering an
        // empty header band for them looks like a mistake.
        const hasHeader = token.header.some((cell) => cell.text.trim() !== '');
        const head = hasHeader
            ? `<thead><tr>${token.header.map((c) => `<th>${marked.parseInline(c.text)}</th>`).join('')}</tr></thead>`
            : '';
        const body = token.rows
            .map((row) => `<tr>${row.map((c) => `<td>${marked.parseInline(c.text)}</td>`).join('')}</tr>`)
            .join('\n');

        return `<div class="table-scroll"><table${hasHeader ? '' : ' class="plain"'}>${head}<tbody>\n${body}\n</tbody></table></div>\n`;
    };

    return r;
}

function outline(markdown) {
    const items = [];
    let fenced = false;

    for (const line of markdown.split('\n')) {
        if (line.startsWith('```')) fenced = !fenced;
        if (fenced) continue;

        const m = /^(##|###)\s+(.*)$/.exec(line);
        if (m) items.push({ depth: m[1].length, raw: m[2], id: slugify(m[2]) });
    }

    return items;
}

/** Plain text for the search index: no markup, no code, collapsed whitespace. */
const searchText = (markdown) =>
    markdown
        .replace(/```[\s\S]*?```/g, ' ')
        .replace(/`[^`]*`/g, ' ')
        .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
        .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/[#>*_|]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 2000);

const sidebar = (current) =>
    NAV.filter((n) => n.group || n.home || bySource.has(n.src))
        .map((n) => {
            if (n.group) return `<p class="nav-group">${n.group}</p>`;
            const page = n.home ? home : bySource.get(n.src);
            const active = page.slug === current.slug;
            return `<a class="nav-item${active ? ' active' : ''}" href="${fileOf(page)}"${active ? ' aria-current="page"' : ''}><span class="nav-name">${page.title}</span><span class="nav-hint">${page.blurb}</span></a>`;
        })
        .join('\n');

const tocMarkup = (items) => {
    if (items.length < 3) return '<div class="toc-space"></div>';

    const links = items
        .map((i) => `<li class="lvl${i.depth}"><a href="#${i.id}">${escapeHtml(i.raw.replace(/[`*]/g, ''))}</a></li>`)
        .join('');

    return `<nav class="toc" aria-label="On this page"><p class="toc-head">On this page</p><ul>${links}</ul></nav>`;
};

const FAVICON = `data:image/svg+xml,${encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#3B475E"/><path d="M8.5 23V9h3.2l8.6 8.8V9h3.2v14h-3.2l-8.6-8.8V23z" fill="#4CABAD"/></svg>',
)}`;

function shell({ page, body, toc, prev, next }) {
    return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapeHtml(page.title)} · NativePHP for Symfony</title>
<meta name="description" content="${escapeHtml(page.blurb)} — NativePHP for Symfony: desktop, iOS and Android applications built with Symfony and PHP.">
<meta name="color-scheme" content="light dark">
<link rel="icon" href="${FAVICON}">
<link rel="stylesheet" href="assets/site.css">
<script>
// Before first paint, so a chosen theme never flashes the other one.
try { var t = localStorage.getItem('theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
</script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>

<header class="topbar">
    <button class="burger" type="button" aria-label="Open navigation" aria-expanded="false" aria-controls="sidebar"><span></span><span></span><span></span></button>
    <a class="brand" href="index.html"><span class="logo" aria-hidden="true"></span><span class="brand-name">NativePHP <span>for Symfony</span></span></a>
    <div class="actions">
        <div class="search">
            <label class="sr" for="search">Search documentation</label>
            <input type="search" id="search" placeholder="Search documentation" autocomplete="off" spellcheck="false">
            <kbd>/</kbd>
            <div class="search-panel" id="search-panel" hidden></div>
        </div>
        <button class="icon-button theme" type="button" aria-label="Switch between light and dark"></button>
        <a class="icon-button gh" href="${REPO}" target="_blank" rel="noopener" aria-label="Source on GitHub">GitHub</a>
    </div>
</header>

<div class="shell">
    <aside class="sidebar" id="sidebar">
        <nav aria-label="Documentation">
${sidebar(page)}
        </nav>
        <p class="sidebar-note">Desktop is verified by a packaged app that has been built and run. Mobile is verified by tests, not yet by a device.</p>
    </aside>

    <main id="main">
        <article class="prose">
${body}
        </article>

        <nav class="pager" aria-label="Pagination">
            ${prev ? `<a class="pager-link" href="${fileOf(prev)}"><span>← Previous</span><strong>${prev.title}</strong></a>` : '<span></span>'}
            ${next ? `<a class="pager-link next" href="${fileOf(next)}"><span>Next →</span><strong>${next.title}</strong></a>` : '<span></span>'}
        </nav>

        <footer class="footer">
            <a href="${BLOB}/${page.src}" target="_blank" rel="noopener">Edit this page on GitHub ↗</a>
            <p>MIT. <code>native-symfony</code> is a provisional vendor name — <code>nativephp/*</code> belongs to someone else.</p>
        </footer>
    </main>

    ${toc}
</div>

<script src="assets/site.js" defer></script>
</body>
</html>
`;
}

const everythingGrid = () => {
    let markup = '';
    let group = null;

    for (const entry of NAV) {
        if (entry.group) {
            if (group) markup += '</div></section>';
            group = entry.group;
            markup += `<section class="group"><h2>${group}</h2><div class="group-grid">`;
            continue;
        }

        if (entry.home || !bySource.has(entry.src)) continue;

        const page = bySource.get(entry.src);
        markup += `<a class="tile" href="${fileOf(page)}"><strong>${page.title}</strong><span>${page.blurb}</span></a>`;
    }

    return `${markup}</div></section>`;
};

const HERO = `<section class="hero">
    <p class="eyebrow">Documentation</p>
    <h1 class="hero-title">Take the Symfony app you already have to the desktop, iOS and Android</h1>
    <p class="hero-lede">Two bundles on NativePHP's runtimes. Your controllers, templates, console
    commands and tests do not change — they gain a native window, a menu bar, and 54 device methods.</p>
    <div class="hero-actions">
        <a class="button" href="desktop.html">Get started with desktop</a>
        <a class="button ghost" href="mobile.html">iOS and Android</a>
    </div>
</section>

<div class="cards">
    <a class="card" href="desktop.html">
        <h3>Desktop, around what you have</h3>
        <p>An Electron window serving your existing app. All 116 runtime endpoints and 44 events, wrapped as ordinary Symfony services.</p>
        <span class="more">Start here →</span>
    </a>
    <a class="card" href="mobile.html">
        <h3>Mobile without a rewrite</h3>
        <p>A Symfony app takes the WebView path by construction, so Twig stays Twig — or render real SwiftUI and Compose trees from PHP.</p>
        <span class="more">Read the guide →</span>
    </a>
    <a class="card" href="testing.html">
        <h3>Testable with no device</h3>
        <p><code>FakeRuntime</code> records at the transport seam, so your payload building is exercised rather than replaced by a stub.</p>
        <span class="more">Testing →</span>
    </a>
    <a class="card" href="troubleshooting.html">
        <h3>Written for silent failures</h3>
        <p>This runtime's usual failure mode is silence rather than an error, which is why troubleshooting is organised symptom-first.</p>
        <span class="more">Symptom → fix →</span>
    </a>
</div>

<div class="quickstart">
    <p class="quickstart-head">Desktop, from nothing</p>
    <figure class="code"><figcaption><span class="code-lang">bash</span><button class="copy" type="button">Copy</button></figcaption>
<pre><code class="language-bash">${highlight(
    `composer require native-symfony/desktop-bundle:^0.1

# bring the Electron runtime into the project and retarget it at Symfony
git clone --depth 1 https://github.com/NativePHP/desktop /tmp/np-desktop
bin/console native:install --source=/tmp/np-desktop/resources/electron

bin/console native:doctor   # says whether the runtime can reach your app
bin/console native:run      # a window, with your application in it`,
    'bash',
)}</code></pre></figure>
</div>

<p class="callout" id="what-is-verified"><strong>What is verified, plainly.</strong> Desktop is proven end to end: a packaged
application that has been built and run, with screenshots. Mobile is covered by tests — including byte
comparisons against upstream's own renderers — but no build produced here has been opened by Xcode or
Gradle, and no screen has been rendered on a device. That distinction is kept on every page.</p>

<div class="everything">
    <h2 class="everything-head">Everything here</h2>
    ${everythingGrid()}
</div>
`;

// ── build ────────────────────────────────────────────────────────────────────

mkdirSync(join(out, 'assets'), { recursive: true });
writeFileSync(join(out, '.nojekyll'), '');
writeFileSync(join(out, 'assets', 'site.css'), readFileSync(join(root, 'tools', 'docs-theme.css'), 'utf8'));
writeFileSync(join(out, 'assets', 'site.js'), readFileSync(join(root, 'tools', 'docs-site.js'), 'utf8'));

const index = [];

pages.forEach((page, i) => {
    const markdown = readFileSync(join(root, page.src), 'utf8');
    marked.use({ renderer: renderer(page) });

    const items = outline(markdown);

    writeFileSync(
        join(out, fileOf(page)),
        shell({
            page,
            body: marked.parse(markdown),
            toc: tocMarkup(items),
            prev: i === 0 ? home : pages[i - 1],
            next: pages[i + 1],
        }),
    );

    index.push({
        t: page.title,
        u: fileOf(page),
        b: page.blurb,
        h: items.filter((x) => 2 === x.depth).map((x) => ({ t: x.raw.replace(/[`*]/g, ''), a: x.id })),
        x: searchText(markdown),
    });
});

writeFileSync(
    join(out, fileOf(home)),
    shell({ page: { ...home, src: 'docs/README.md' }, body: HERO, toc: '<div class="toc-space"></div>', next: pages[0] }),
);

index.unshift({ t: 'Home', u: fileOf(home), b: home.blurb, h: [], x: 'NativePHP for Symfony desktop iOS Android documentation getting started' });

writeFileSync(join(out, 'assets', 'search.json'), JSON.stringify(index));

// A 404 that keeps the reader inside the site rather than showing GitHub's.
writeFileSync(
    join(out, '404.html'),
    shell({
        page: { slug: '404', title: 'Not found', blurb: 'That page does not exist', src: 'docs/README.md' },
        body: `<h1>Not found</h1>
<p>That page is not part of this documentation. Try the <a href="index.html">introduction</a>, the
<a href="desktop.html">desktop guide</a>, or <a href="troubleshooting.html">troubleshooting</a> if
something is broken.</p>`,
        toc: '<div class="toc-space"></div>',
    }),
);

console.log(`built ${pages.length} pages + 404 into docs/`);
