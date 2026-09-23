# Prompt Aid — Design System

**Concept: clinical dispatch.** The precision of a medical chart plus the
urgency of a control room. Derived from the actual logo — black ring, red
medical cross, yellow motion streak. High contrast, hairline rules,
near-square corners, tabular numerals, no soft blobby shadows.

Applies identically across the public website, the Filament admin panels,
and the Expo mobile app — one palette, one type system, one shape language.

## Colour

| Token | Hex | Use |
|---|---|---|
| `ink` | `#101012` | Body text, dark surfaces, footer, sidebar |
| `ink-soft` | `#3A3A3E` | Secondary text on light |
| `paper` | `#F5F2EC` | Page background (warm off-white, not grey) |
| `surface` | `#FFFFFF` | Cards, table rows, inputs |
| `line` | `#E0DACB` | All hairline borders (1px) |
| `muted` | `#6E6A62` | Labels, meta, placeholders |
| `signal` | `#D0211C` | **Primary action.** From the logo's cross. Buttons, links, active nav |
| `signal-ink` | `#9E1813` | Hover/pressed on signal; readable text paired with signal-wash |
| `signal-wash` | `#FBEDEC` | Tinted backgrounds, danger badge fill |
| `beacon` | `#F2C200` | **Accent.** From the logo's streak. Shuttle/motion, highlights, focus rings — never body text on light |
| `beacon-wash` | `#FEF6DA` | Warning badge fill |
| `go` | `#1F7A4C` | Paid / completed / active |
| `go-wash` | `#E7F3EC` | Success badge fill |
| `info` | `#1F4E7A` | Informational only — never a primary action |

Rules: `signal` is the only red; never use it decoratively. `beacon` is
reserved for the shuttle/ride layer and focus states. Two background
colours maximum on any screen (`paper` + `surface`, or `ink` + `surface`).
`signal` on `paper` is 5.4:1 contrast — safe for body text; `beacon` is a
fill/border/marker only, never a text colour on a light background.

## Type

**Lato** (300/400/700/900) throughout — headings and body both. Headings
are weight 900 with `letter-spacing: -0.02em`; all-caps eyebrow labels get
`+0.14em`. Numbers — prices, distances, ETAs, dosages — always
`font-variant-numeric: tabular-nums`.

Scale (web): 12 / 13 / 14 / 16 / 20 / 26 / 34 / 46 / 62. Line height
1.0–1.05 on headings, 1.5 on body.

## Shape, depth, motion

- **Radius:** `0` for structural containers and tables, `2px` for
  buttons/inputs/badges, `999px` only for avatars/pills. No 12–16px rounded
  cards.
- **Depth:** 1px `line` borders, not shadows. One shadow exists —
  `0 8px 24px -12px rgba(16,16,18,.35)` — for popovers, modals and dropdowns
  only.
- **Spacing scale:** 4 / 8 / 12 / 16 / 20 / 24 / 32 / 40 / 56 / 80.
- **Motion:** 140ms `ease-out` for state changes, 320ms for page-level
  reveals. No parallax, no bouncing.
- **Grid:** 1280px max content width, 40px gutters, 12 columns.

## Where this lives in the codebase

| File | Role |
|---|---|
| `backend/resources/css/promptaid.css` | Canonical tokens as CSS custom properties (`--pa-*`) and the `.pa-*` component classes — new pages use these directly |
| `backend/resources/css/app.css` | Tailwind `@theme` repointed to the same palette, so every existing utility class (`bg-primary-500`, etc.) inherits the new brand without markup changes |
| `backend/app/Providers/Filament/AdminPanelProvider.php` | `->colors()` + `->font('Lato')` for the Filament panels |
| `mobile/src/theme/index.ts` | `pa` (canonical tokens) plus compatibility aliases (`colors`, `spacing`, `font`, `radius`) so existing screens inherit the new brand; new screens use `pa` directly |

See `new-ui/*/HANDOVER.md` for the full rebuild specification (schema,
services, page builder, phased build order) and `new-ui/*/IMPORT-GUIDE.md`
for how each design package maps into this repo.
