# G-04 — Language switcher placement

The language switcher lets visitors move between language versions of any page. Lingua Forge ships three entry points that cover every theme type: a **Gutenberg block** for Full Site Editing themes, a **shortcode** for classic and hybrid themes, and a **classic widget** for widget-area sidebars.

All three entry points share the same underlying renderer and support the same display options.

---

## Chapters

1. [Display options](#1-display-options)
2. [FSE block — Site Editor placement](#2-fse-block--site-editor-placement)
3. [Shortcode — classic and hybrid themes](#3-shortcode--classic-and-hybrid-themes)
4. [Classic widget](#4-classic-widget)
5. [Viewport-aware positioning](#5-viewport-aware-positioning)
6. [Styling and theming](#6-styling-and-theming)

---

## 1. Display options

All three entry points accept the same set of attributes:

| Attribute | Values | Default | Effect |
|---|---|---|---|
| `show` | `label`, `icon`, `icon-label`, `custom` | `label` | What appears on the toggle button |
| `direction` | `down`, `up` | `down` | Which direction the dropdown opens |
| `customLabel` | any string | `Language` | Button text when `show=custom` |
| `iconHtml` | SVG markup | globe icon | Custom icon when `show=icon` or `show=icon-label` |

`label` shows the current language name (e.g. "English"). `icon` shows only the globe icon. `icon-label` shows both. `custom` shows a fixed string of your choice.

---

## 2. FSE block — Site Editor placement

The switcher is registered as a Gutenberg block (`custom/lsflr-switcher`) and appears in the block inserter under the plugin's name.

**Placing it in the header:**

1. Go to **Appearance → Editor**.
2. Open your header template part.
3. Click **+** and search for "Language Switcher".
4. Insert and save.

**Placing it inside a Navigation block:**

The switcher block can be dropped inside a `core/navigation` block. This integrates it visually with the site's main menu links and keeps keyboard navigation consistent.

**Block attributes** are edited via the block inspector panel on the right side of the Site Editor. Changes apply immediately in the canvas preview.

---

## 3. Shortcode — classic and hybrid themes

Use `[lsflr_switcher]` anywhere shortcodes are supported: post content, page content, text widgets, theme template files via `do_shortcode()`.

```
[lsflr_switcher]
[lsflr_switcher show="icon-label" direction="up"]
[lsflr_switcher show="custom" customLabel="Switch language"]
```

**In a theme template file:**

```php
<?php echo do_shortcode( '[lsflr_switcher show="icon-label"]' ); ?>
```

---

## 4. Classic widget

The `Lsflr_Switcher_Widget` is registered automatically and appears in **Appearance → Widgets** under the name "Language Switcher".

Drag it into any widget area. The same display options (label, icon, direction) are available as widget form fields.

---

## 5. Viewport-aware positioning

The switcher dropdown includes a small JavaScript snippet that fires on load and on window resize. If the open dropdown would overflow the right edge of the viewport, the class `lsflr-auto-right` is applied automatically, shifting the menu to open left-aligned instead of right-aligned. No configuration is needed.

---

## 6. Styling and theming

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
