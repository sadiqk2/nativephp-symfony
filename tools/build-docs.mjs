/**
 * Builds docs/*.md into a static site in docs/ that GitHub Pages can serve as-is.
 *
 * Why generated HTML rather than Jekyll: Pages' Jekyll mode would have to be configured
 * blind — there is no Ruby here, so the first look at the result would be the published
 * site. This produces files that can be opened, screenshotted and checked before anyone
 * else sees them, which is the same reason this project screenshots the demo instead of
 * trusting its tests alone. The cost is that the site is rebuilt by running this script.
 *
 *   node tools/build-docs.mjs
 *
 * The Markdown stays the source of truth and stays readable on GitHub; each file gets an
 * .html twin beside it, plus assets/site.css and .nojekyll (which stops Pages trying to
 * process any of it as Jekyll).
 *
 * Needs `marked`, which is not vendored — install it anywhere and point NODE_PATH at it:
 *
 *   npm install marked
 *   DOCS_ROOT=/path/to/repo node /path/to/repo/tools/build-docs.mjs
 */

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { marked } from 'marked';

// DOCS_ROOT exists so the script can be run from wherever `marked` happens to be
// installed — ESM resolves bare specifiers from the *working* directory upwards, not from
// the script's location, and this repository does not vendor node modules.
const root = process.env.DOCS_ROOT ?? join(dirname(fileURLToPath(import.meta.url)), '..');
const docsDir = join(root, 'docs');
const repo = 'https://github.com/sadiqk2/nativephp-symfony';

/** The nav, in reading order — not alphabetical, and not derived from the filesystem. */
const NAV = [
    { file: 'README.md', title: 'Overview', blurb: 'What is here, and what is authoritative' },
    { section: 'Using it' },
    { file: 'getting-started-desktop.md', title: 'Desktop', blurb: 'From composer require to a window' },
    { file: 'getting-started-mobile.md', title: 'Mobile', blurb: 'Both render paths, and what is verified' },
    { section: 'Reference' },
    { file: 'desktop-api.md', title: 'Desktop API', blurb: 'Windows, menus, dialogs, processes, events' },
    { file: 'mobile-api.md', title: 'Mobile API', blurb: '16 bridge groups and the native-UI layers' },
    { section: 'Working with it' },
    { file: 'recipes.md', title: 'Recipes', blurb: 'Multi-window, workers, native screens, packaging' },
    { file: 'testing.md', title: 'Testing', blurb: 'FakeRuntime, expectations, event simulation' },
    { file: 'troubleshooting.md', title: 'Troubleshooting', blurb: 'Symptom → cause → fix' },
];

const pages = NAV.filter((entry) => entry.file);
const htmlName = (file) => (file === 'README.md' ? 'index.html' : file.replace(/\.md$/, '.html'));

/**
 * Heading ids, matched to GitHub's own slugger so in-page links written against GitHub keep
 * working here — and so a link that is broken *there* is broken here too, rather than being
 * quietly repaired by a more forgiving rule.
 *
 * Two details are the whole reason this is not a one-liner: each space becomes its own
 * hyphen rather than collapsing (so `## Desktop — boot`, whose em dash is stripped, is
 * `desktop--boot` with two), and underscores survive (`_windowId` → `_windowid`).
 */
const slug = (text) =>
    text
        .toLowerCase()
        .replace(/<[^>]+>/g, '')
        .replace(/[`~!@#$%^&*()+=<>?,./:;"'|{}[\]\\\u2013\u2014]/g, '')
        .trim()
        .replace(/\s/g, '-');

function renderer(currentFile) {
    const r = new marked.Renderer();
    const known = new Set(pages.map((p) => p.file));

    r.heading = ({ text, depth }) => {
        const inline = marked.parseInline(text);
        const id = slug(text);
        // The anchor is a link on the heading itself rather than a floating symbol: one
        // element to style, and it works on touch, where hover-reveal does not.
        return `<h${depth} id="${id}"><a class="anchor" href="#${id}">${inline}</a></h${depth}>\n`;
    };

    r.link = ({ href, title, tokens }) => {
        const text = marked.parseInline(tokens.map((t) => t.raw).join(''));
        let target = href;

        if (known.has(href)) {
            target = htmlName(href);
        } else if (href.startsWith('../')) {
            // Everything above docs/ stays on GitHub: the analysis and specification files
            // are not part of this site, and a broken relative link is worse than a jump.
            target = `${repo}/blob/main/${href.slice(3)}`;
        } else if (/^[\w-]+\.md#/.test(href)) {
            const [file, fragment] = href.split('#');
            target = known.has(file) ? `${htmlName(file)}#${fragment}` : target;
        }

        const attrs = target.startsWith('http') ? ' target="_blank" rel="noopener"' : '';
        return `<a href="${target}"${title ? ` title="${title}"` : ''}${attrs}>${text}</a>`;
    };

    // Tables are the densest thing in these documents and the first to break a narrow
    // viewport, so each one scrolls inside its own box rather than widening the page.
    r.table = (token) => {
        const head = token.header.map((c) => `<th>${marked.parseInline(c.text)}</th>`).join('');
        const body = token.rows
            .map((row) => `<tr>${row.map((c) => `<td>${marked.parseInline(c.text)}</td>`).join('')}</tr>`)
            .join('\n');
        return `<div class="table-scroll"><table><thead><tr>${head}</tr></thead><tbody>\n${body}\n</tbody></table></div>\n`;
    };

    return r;
}

function tableOfContents(markdown) {
    const items = [];
    let inFence = false;

    for (const line of markdown.split('\n')) {
        if (line.startsWith('```')) inFence = !inFence;
        if (inFence) continue;

        const match = /^(##|###)\s+(.*)$/.exec(line);
        if (match) {
            const text = match[2].replace(/`/g, '').replace(/\*\*/g, '');
            items.push({ depth: match[1].length, text, id: slug(match[2]) });
        }
    }

    if (items.length < 3) return '';

    const links = items
        .map((i) => `<li class="d${i.depth}"><a href="#${i.id}">${i.text}</a></li>`)
        .join('\n');

    return `<nav class="toc" aria-label="On this page"><p class="toc-title">On this page</p><ul>\n${links}\n</ul></nav>`;
}

function sidebar(currentFile) {
    return NAV.map((entry) => {
        if (entry.section) return `<p class="nav-section">${entry.section}</p>`;
        const current = entry.file === currentFile;
        return `<a class="nav-link${current ? ' current' : ''}" href="${htmlName(entry.file)}"${current ? ' aria-current="page"' : ''}>
            <span class="nav-title">${entry.title}</span>
            <span class="nav-blurb">${entry.blurb}</span>
        </a>`;
    }).join('\n');
}

const shell = ({ title, body, toc, nav, isIndex }) => `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${title} · NativePHP for Symfony</title>
<meta name="description" content="Build desktop, iOS and Android applications with Symfony and PHP, on NativePHP's runtimes.">
<link rel="stylesheet" href="assets/site.css">
<link rel="icon" href="data:image/svg+xml,${encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="#6366f1"/><path d="M9 22V10h3l8 8V10h3v12h-3l-8-8v8z" fill="#fff"/></svg>')}">
</head>
<body${isIndex ? ' class="is-index"' : ''}>
<a class="skip" href="#content">Skip to content</a>

<div class="layout">
    <aside class="sidebar">
        <a class="brand" href="index.html">
            <span class="brand-mark" aria-hidden="true">NS</span>
            <span class="brand-text">NativePHP<span class="brand-dim"> for Symfony</span></span>
        </a>
        <nav class="nav" aria-label="Documentation">
${nav}
        </nav>
        <div class="sidebar-foot">
            <a href="${repo}" target="_blank" rel="noopener">Source on GitHub ↗</a>
            <p class="sidebar-note">Desktop is verified by a running packaged app. Mobile is verified by tests, not yet by a device.</p>
        </div>
    </aside>

    <main id="content">
        ${toc}
        <article class="prose">
${body}
        </article>
        <footer class="foot">
            <p>MIT. <code>native-symfony</code> is a provisional vendor name — <code>nativephp/*</code> is someone else's brand.</p>
        </footer>
    </main>
</div>
</body>
</html>
`;

const hero = `<div class="hero">
    <p class="eyebrow">Documentation</p>
    <h1>Symfony, in a native window</h1>
    <p class="lede">Two bundles on NativePHP's runtimes: an Electron desktop app around the Symfony
    application you already have, and iOS/Android with 54 device methods and a native-UI path.
    Your controllers, templates and console do not change.</p>
    <div class="hero-links">
        <a class="cta" href="getting-started-desktop.html">Start with desktop →</a>
        <a class="cta ghost" href="getting-started-mobile.html">Or mobile</a>
    </div>
</div>`;

mkdirSync(join(docsDir, 'assets'), { recursive: true });
writeFileSync(join(docsDir, '.nojekyll'), '');
writeFileSync(
    join(docsDir, 'assets', 'site.css'),
    readFileSync(join(root, 'tools', 'docs-theme.css'), 'utf8'),
);

let built = 0;
for (const page of pages) {
    const markdown = readFileSync(join(docsDir, page.file), 'utf8');
    marked.use({ renderer: renderer(page.file) });

    const isIndex = page.file === 'README.md';
    const body = (isIndex ? hero : '') + marked.parse(markdown);

    writeFileSync(
        join(docsDir, htmlName(page.file)),
        shell({
            title: page.title,
            body,
            toc: tableOfContents(markdown),
            nav: sidebar(page.file),
            isIndex,
        }),
    );
    built++;
}

console.log(`built ${built} pages into docs/`);
