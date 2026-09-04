# tools/

## The documentation site

`build-docs.mjs` renders **every** Markdown document in the repository — the guides in `docs/`,
the specifications and analysis at the root, the two bundle READMEs, the demo and the upstream
patches — into a static site in `docs/`, which GitHub Pages serves as-is.

```bash
npm install marked highlight.js
DOCS_ROOT="$PWD" node tools/build-docs.mjs      # a home page, 26 documents, a 404
php -S 127.0.0.1:8000 -t docs                   # look at it before publishing
```

`DOCS_ROOT` exists because ESM resolves a bare `import 'marked'` from the importing file's
directory upwards, so the script has to be runnable from wherever the modules were installed
while still writing into this repository.

Publishing: **Settings → Pages → Deploy from a branch → `main` / `/docs`**. Pages on a private
repository needs a paid plan; on a public one it is free. `.nojekyll` is what stops Pages
trying to process the directory as a Jekyll project.

### What the site has

| | |
|---|---|
| Home | A landing page built from `NAV`, not rendered from a document: hero, four entry points, a copyable quick start, the verified-so-far note, and an index of every page grouped as the sidebar groups it. Nothing on it duplicates a Markdown file, so nothing on it can drift out of step with one |
| Navigation | Grouped in reading order — *Start here*, *Reference*, *Working with it*, *How it works*, *The packages*, *Contributing*, *Record* — not alphabetically, and not derived from the filesystem |
| Search | Client-side over a prebuilt 66 KB index; `/` focuses it, arrows and Enter work, hits show the matching heading and a highlighted excerpt |
| Themes | Light, dark, or follow the system — three states, cycled by the toggle, applied before first paint so nothing flashes |
| Code | Highlighted at build time by highlight.js, so no script runs in the reader's browser to colour it; every block has a copy button |
| Reading aids | On-this-page index that tracks the section you are in, previous/next pager, edit-on-GitHub per page |
| Offline | No CDN, no webfont, no analytics, no external request of any kind |

### Design decisions worth knowing before editing

- **Generated HTML rather than Jekyll or a remote theme.** Jekyll cannot run in this
  environment (no Ruby), so its first rendering would have been the published site. Generated
  files can be opened, screenshotted and read first — the same reason this project screenshots
  the demo instead of trusting its tests. The trade is a rebuild step: change a `.md`, re-run
  the script.
- **Heading ids match GitHub's slugger exactly**, including the two details that are easy to
  miss: each space becomes its own hyphen (`## Desktop — boot`, em dash stripped, gives
  `desktop--boot`) and underscores survive (`_windowId` → `_windowid`). Deliberate in both
  directions — a link written against GitHub keeps working, and a link that is broken *there*
  stays broken here rather than being quietly repaired. It has found two so far, both dead on
  GitHub as well.
- **Links resolve against the document they live in.** A link to something the site carries
  becomes a page link; anything else — a source file, a directory — goes to GitHub rather than
  404ing inside the site.
- **Headerless Markdown tables** (`| | |`, used as two-column layout throughout these
  documents) render without an empty header band, and their first column is treated as the
  label.
- **The accent is NativePHP's own, measured rather than guessed.** Upstream's application icon
  (`resources/build/icon.png`) is a slate blue `#54608D` and a teal `#4CABAD`; the logo here uses
  both unaltered. The teal only reaches 2.71:1 against white, below the 4.5:1 body-sized link
  text needs, so light mode darkens it to `#0f7378` (5.61:1) and dark mode uses the brand value
  as-is (7.11:1). Changing the palette means changing four tokens — and checking those ratios
  again.
- Light and dark are both first-class: tokens for light on `:root`, redefined under
  `prefers-color-scheme: dark` (guarded so an explicit light choice wins), and again under
  `[data-theme="dark"]` so the toggle wins in both directions. No colour is declared only
  inside a media query.

### Checking the output

There are no tests for a stylesheet, but links and anchors are mechanically checkable and
worth checking after every build — that is how both broken anchors were found:

```bash
python3 - <<'PY'
import re
from pathlib import Path
docs, bad = Path('docs'), 0
for p in sorted(docs.glob('*.html')):
    s = p.read_text(); ids = set(re.findall(r'id="([^"]+)"', s))
    for href in re.findall(r'href="([^"]+)"', s):
        if href.startswith(('http', 'mailto', 'data:')): continue
        if href.startswith('#'):
            if href[1:] not in ids: bad += 1; print('dead anchor', p.name, href)
            continue
        target, _, frag = href.partition('#')
        if target and target not in ('assets/site.css', 'assets/site.js') and not (docs / target).exists():
            bad += 1; print('dead link', p.name, href)
        elif frag and target.endswith('.html') and f'id="{frag}"' not in (docs / target).read_text():
            bad += 1; print('dead cross-page anchor', p.name, href)
print('problems:', bad)
PY
```

And to see it, rather than trust it: `php -S 127.0.0.1:8000 -t docs`. In this container the
pages can also be rendered headlessly through the demo's Electron —
`ELECTRON_DISABLE_SANDBOX=1` and a disabled D-Bus address are the two things without which it
silently never paints.
