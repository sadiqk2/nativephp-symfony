# tools/

## `build-docs.mjs` — the documentation site

Turns `docs/*.md` into a static site in `docs/` that GitHub Pages serves as-is. The Markdown
stays the source of truth and stays readable on GitHub; each file gets an `.html` twin beside
it, plus `docs/assets/site.css` and `docs/.nojekyll`.

```bash
npm install marked                       # the one dependency, not vendored
DOCS_ROOT="$PWD" node tools/build-docs.mjs
```

`DOCS_ROOT` exists because ESM resolves a bare `import 'marked'` from the *importing file's*
directory upwards, so the script has to be able to run from wherever `marked` was installed
while still writing into this repository.

**Preview it before publishing.** There is no build step at deploy time, so what you see
locally is what Pages serves:

```bash
php -S 127.0.0.1:8000 -t docs        # then open http://127.0.0.1:8000/
```

Publishing: repository **Settings → Pages → Deploy from a branch → `main` / `/docs`**. Pages
on a private repository needs a paid plan; on a public one it is free. `.nojekyll` is what
stops Pages trying to process the directory as a Jekyll project, which would otherwise ignore
`assets/` and mangle the Markdown.

### Design decisions worth knowing before editing

- **Generated HTML rather than Jekyll or a theme.** Jekyll cannot be run in this environment
  (no Ruby), so its first rendering would have been the published site. Generated files can be
  opened and read locally first. The trade is that the site is rebuilt by running the script —
  so if you change a `.md`, re-run it.
- **No external requests at all**: system fonts, an inline SVG favicon, one local stylesheet.
  Documentation gets read while something is broken, sometimes behind a proxy.
- **Heading ids match GitHub's slugger exactly**, including the two details that are easy to
  miss: each space becomes its own hyphen (`## Desktop — boot`, whose em dash is stripped,
  gives `desktop--boot`) and underscores survive (`_windowId` → `_windowid`). This is
  deliberate in both directions — a link written against GitHub keeps working here, and a link
  that is broken *there* stays broken here rather than being quietly repaired. It found one:
  `recipes.md` linked to `#a-multi-window-app-and-the-windowid-convention`, which never
  resolved on GitHub either.
- **Links above `docs/` go to GitHub**, not into the site: the analysis and specification
  documents at the repository root are not part of it.
- Light and dark are both first-class, from `prefers-color-scheme`. Tokens are defined for
  light on `:root` and redefined for dark, never the other way round, so a page always has a
  complete palette.

### Checking the output

`docs/` has no tests, but the site is mechanically checkable and worth checking — every
internal link and in-page anchor should resolve:

```bash
python3 - <<'PY'
import re
from pathlib import Path
docs = Path('docs')
for p in sorted(docs.glob('*.html')):
    s = p.read_text()
    ids = set(re.findall(r'id="([^"]+)"', s))
    for href in re.findall(r'href="([^"]+)"', s):
        if href.startswith('#') and href[1:] not in ids:
            print(f'{p.name}: dead anchor {href}')
        elif not href.startswith(('http', 'mailto', 'data:', '#')):
            target = href.split('#')[0]
            if target and target != 'assets/site.css' and not (docs / target).exists():
                print(f'{p.name}: dead link {href}')
PY
```
