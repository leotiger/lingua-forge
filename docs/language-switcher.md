# G-04 — Language switcher placement

The language switcher lets visitors move between language versions of any page. Lingua Forge ships three entry points that cover every theme type: a **Gutenberg block** for Full Site Editing themes, a **shortcode** for classic and hybrid themes, and a **classic widget** for widget-area sidebars.

All three entry points share the same underlying renderer and support the same display options.

---

## Chapters

1. [Display options](#1-display-options)
2. [List style — dropdown vs. grid overlay](#2-list-style--dropdown-vs-grid-overlay)
3. [FSE block — Site Editor placement](#3-fse-block--site-editor-placement)
4. [Shortcode — classic and hybrid themes](#4-shortcode--classic-and-hybrid-themes)
5. [Classic widget](#5-classic-widget)
6. [Viewport-aware positioning](#6-viewport-aware-positioning)
7. [Styling and theming](#7-styling-and-theming)

---

## 1. Display options

All three entry points accept the same set of attributes:

| Attribute | Values | Default | Effect |
|---|---|---|---|
| `show` | `label`, `icon`, `icon-label`, `custom` | `label` | What appears on the toggle button |
| `direction` | `down`, `up` | `down` | Dropdown direction (ignored in overlay mode) |
| `customLabel` | any string | `Language` | Button text when `show=custom` |
| `iconHtml` | SVG markup | globe icon | Custom icon when `show=icon` or `show=icon-label` |
| `overlayMode` | `never`, `always`, `auto` | `never` | List style — see [Chapter 2](#2-list-style--dropdown-vs-grid-overlay) |

`label` shows the current language name (e.g. "English"). `icon` shows only the globe icon. `icon-label` shows both. `custom` shows a fixed string of your choice.

---

## 2. List style — dropdown vs. grid overlay

By default (`overlayMode=never`) the switcher renders as a compact dropdown list. For sites with six or more active languages the dropdown can become long or overflow its container. The `overlayMode` attribute switches to a grid panel that opens as an overlay dialog.

| Value | Behaviour |
|---|---|
| `never` | Standard dropdown or dropup list (default). |
| `always` | A trigger button opens a floating grid panel listing all languages. Recommended for 6+ languages. |
| `auto` | Grid overlay by default; when the container is wide enough (measured by `ResizeObserver`), all language links are shown inline without a trigger. |

**Overlay panel details:**

- Opens on click; closes on click-outside, close button, or Escape key.
- Languages are arranged in a responsive CSS grid: `auto-fill` columns of minimum 8 em, so the panel reflows automatically from 1 to 4+ columns depending on available width.
- The current language is shown in the grid but de-emphasised (`pointer-events: none`) so the visitor knows where they are.
- Fully keyboard-navigable: Tab cycles through the grid links and the close button; focus is trapped inside the open panel; focus returns to the trigger on close.
- ARIA attributes: trigger has `aria-haspopup="dialog"` and `aria-expanded`; panel has `role="dialog"` and `aria-modal="true"`; current language item has `aria-current="true"`.

**Shortcode usage:**

```
[lsflr_switcher overlayMode="always"]
[lsflr_switcher overlayMode="always" show="icon-label"]
[lsflr_switcher overlayMode="auto"]
```

---

## 3. FSE block — Site Editor placement

The switcher is registered as a Gutenberg block (`custom/lsflr-switcher`) and appears in the block inserter under the plugin's name.

**Placing it in the header:**

1. Go to **Appearance → Editor**.
2. Open your header template part.
3. Click **+** and search for "Language Switcher".
4. Insert and save.

**Placing it inside a Navigation block:**

The switcher block can be dropped inside a `core/navigation` block. This integrates it visually with the site's main menu links and keeps keyboard navigation consistent.

**Block attributes** are edited via the block inspector panel on the right side of the Site Editor. The **List style** control selects dropdown vs. overlay mode. Changes apply immediately in the canvas preview.

---

## 4. Shortcode — classic and hybrid themes

Use `[lsflr_switcher]` anywhere shortcodes are supported: post content, page content, text widgets, theme template files via `do_shortcode()`.

```
[lsflr_switcher]
[lsflr_switcher show="icon-label" direction="up"]
[lsflr_switcher show="custom" customLabel="Switch language"]
[lsflr_switcher overlayMode="always" show="icon-label"]
```

**In a theme template file:**

```php
<?php echo do_shortcode( '[lsflr_switcher show="icon-label"]' ); ?>
```

---

## 5. Classic widget

The `Lsflr_Switcher_Widget` is registered automatically and appears in **Appearance → Widgets** under the name "Language Switcher".

Drag it into any widget area. The same display options (label, icon, direction, overlay mode) are available as widget form fields.

---

## 6. Viewport-aware positioning

In `overlayMode=never` (dropdown), a JavaScript snippet fires on load and on `resize`. If the open dropdown would overflow the right edge of the viewport, the class `lsflr-auto-right` is applied automatically, shifting the menu to open left-aligned. No configuration is needed.

In `overlayMode=auto`, a `ResizeObserver` watches the switcher's parent container. When the container is wide enough to fit all language links inline, the trigger button is hidden and the panel is displayed as a flat inline grid instead.

---

## 7. Styling and theming

The switcher stylesheet (`assets/lsflr.css`) uses CSS custom properties that inherit from FSE design tokens automatically:

| Variable | Fallback |
|---|---|
| `--lsflr-bg` | `Canvas` (OS-aware white/dark) |
| `--lsflr-color` | `CanvasText` (OS-aware black/dark) |

The switcher respects `color-scheme: light dark`, so it adapts to both light and dark mode without extra configuration.

To override colours for a specific theme, define the variables in your theme's stylesheet or a `wp_add_inline_style` call:

```css
:root {
    --lsflr-bg: #1a1a2e;
    --lsflr-color: #eaeaea;
}
```
