# Prompt Aid — Design System (KiviCare-matched)

Prompt Aid's UI intentionally matches KiviCare's actual look and feel (KiviCare
Laravel is built on Iqonic Design's "Hope UI" kit) — same colors, same font,
same card-based/rounded/soft-shadow style — while keeping the Prompt Aid name
and logo (from `assets_demo/`).

## Font
- **Inter** (300, 400, 500, 600, 700) — Google Fonts. Used for everything:
  headings and body.

## Colors
| Token      | Hex       | Use |
|------------|-----------|-----|
| Primary    | `#3A57E8` | Buttons, links, active nav, primary actions |
| Secondary  | `#001F4D` | Sidebar background, headings, dark surfaces |
| Info/Accent| `#079AA2` | Teal accent — secondary buttons, badges, highlights |
| Success    | `#1AA053` | Paid/active/completed states |
| Warning    | `#F16A1B` | Pending states |
| Danger     | `#C03221` | Cancelled/error states |
| Gray-100   | `#F8F9FA` | Page background |
| Gray-300   | `#DEE2E6` | Borders |
| Gray-600   | `#6C757D` | Muted text |
| Gray-900   | `#212529` | Body text |

## Style
- Cards: white, `rounded-xl` (12px), soft shadow (`shadow-color: #8898AA` at low opacity)
- Buttons: rounded-full or rounded-lg, solid primary, outline secondary
- Sidebar: dark navy (`#001F4D`) vertical nav with icon + label, active item highlighted primary
- Topbar: white, search + notifications + profile dropdown
- Stat widgets: icon badge (tinted circle) + big number + label + trend
- Status badges: pill-shaped, tinted background matching status color
- Avatars: circular

This file is the single source of truth for the color/font tokens reused
across: Filament admin panel, Tailwind config (public website), and the
Expo React Native app theme.
