# S&D rebuild: how every new screen is built

For the S&D build session and its helpers. Plan v10 (claude.ai/artifact/SzP42kmjNSi3RWJu9UHYZw) and its 23 decisions are the source; this file only says how to build what they show, so screens built in parallel come out the same. **DESIGN.md comes first and wins.**

## The drawings (view for view, compare at 1440 px)

- Design kit, a plain folder outside git: `C:\Users\steph\Herd\oblivionfindings-design-kit\`.
- Page specs, which are the data behind each drawing: `pages/specs/sdr-a.json` (overview, devices, device pages), `sdr-b.json` (sites, alerts, found devices, setup), `sdr-d.json`, `sdr-e.json`, and `sdr-f.json` (round 3, **not approved yet**). Each page has `id` (pg-sdr-N), `label`, `route`, `template`, `layout`, `hero`, `main` (its blocks) and `also` (its other views).
- Pop-up specs: `popups/sdr-a.json` … `sdr-e.json`. Each pop-up has `id` (sdr-N), `template`, `size`, `title`, `body`, `fields` and `footer`.
- Built boards to look at: `site/b/Pg-SDR-*.html` and `site/b/Pop-SDR-*.html`.
- The plan in words: `sdr-plan/pack/plan.md`. The decisions: `sdr-plan/pack/decisions.md`.
- **Only build approved drawings.** Anything in `sdr-f.json` waits for Stephan. A screen with no drawing isn't built: draw it first (mockups first).
- **Kit faults not to copy.** The kit's CSS draws coloured edge lines: top lines on log/tally/quiet/know/next/places cards and the Review bar, a left strip on changed settings rows, and on red/amber device cards. Never copy them; DESIGN.md non-negotiable 8 is a hard rule.

## The six menu entries and their routes

| Menu | Route | Tabs (header rail) |
| --- | --- | --- |
| Overview | `/security-devices` | Overview · Trends · Reports · Activity |
| Devices | `/security-devices/devices` | All · Network · Cameras · Alarms & doors · Sensors · Trackers · Computers · Other · Upkeep |
| Sites | `/security-devices/sites` | All · Need attention · Not set up |
| Access | `/security-devices/access` | Doors · People · Visitors · Schedules · Clock-ins · Activity |
| Alerts | `/security-devices/alerts` | Open · Snoozed · Done · Rules & automations |
| Setup | `/security-devices/setup` | Overview · Hubs & networks · Services & logins · Alerts & checks · Computers & Obli · Doors & access · Change history |

- **Device pages:** `/security-devices/devices/{device}`. One page per kind; the kind decides the tabs.
- **Site pages:** `/security-devices/sites/{site}`.
- **Tabs in URLs:** first-level tabs are `?tab=`; second-level (Setup sections) are `/security-devices/setup/{section}?tab=`.
- **Old pages:** when a new page replaces one (plan: "What happens to today's 36 pages"), the old route redirects to it, and the old page file and its now-unused components go in the same commit. Old redirects listed in the plan stay.

## Where code goes

- **Pages:** `resources/js/pages/security-devices/{overview,devices,sites,access,alerts,setup}/…`. Page-only parts sit beside the page as `_part.tsx`.
- **Shared S&D components:** `resources/js/components/security-devices/`. Shared words and kinds: `resources/js/lib/security-devices/` (see below).
- **Server:** one presenter per screen in `app/Domain/SecurityDevices/Screens/{Menu}/…Presenter.php`. It returns exactly what the page draws, already scoped by `SecurityDevicesAccessService`. Controllers stay thin: `app/Domain/SecurityDevices/Screens/Http/…Controller.php`.
- **Hubs** (Phase B) live in `app/Domain/SecurityDevices/Hubs/`.

## Shared words: one name for one thing

`resources/js/lib/security-devices/device-words.ts` holds the device kinds, the device states and their StatusBadge tones. Use it; don't re-spell a state.
- **Online / Offline / Can't check / Not watched / Retired.** "Can't check" is grey (neutral), never red: the device sits behind something that is down, such as its router or hub.
- **Support level** (Supported devices): "Tested on real hardware" only where a walk proved it. Otherwise "Built from vendor docs" or "Planned"; "View only" means no control.
- **Never on screen:** "Not observed", "Observation freshness", "Permitted scope", "canonical", "collector" or other code words. Hubs are "hubs".

## Every page

- `AppLayout` with breadcrumbs: Home › Security & devices › menu › …
- One `PageHeader` (`components/page/page-header.tsx`): icon, title, one subline phrase with no full stop, actions on the right, and 4–6 meter blocks, each a link to its view. Tabs go in the `rail` (`PageHeaderRail`). A record page uses `variant="profile"` with `backHref`.
- The body uses only the page kit's blocks: table, cards, list, facts, chart, Tally, Ring, Journey, Log, Places, Quiet, Point. A number shows once per page.
- **Tables:** every row has a ⋮ menu, right-click opens the same menu, a click on the row opens the record, a pager after about 25 rows, and ticked rows bring up the selection bar.
- **States:** every page and block has loading, couldn't load (with Retry), no access and nothing yet. Missing data reads "Not available", never 0.
- **Pop-ups:** use the pop-up kit (`components/popup/`) at kit widths 480 / 720 / 900 / 1100. Cancel comes first and the main button is named for what it does. Unlock, disarm, remove and remote control always confirm the effect.
- **Words:** `design_styles/WRITING_STYLE_GUIDE.md`. Dates and times go through `lib/datetime.ts`, in NZ wall-clock time. Use sentence case.
- **Status:** always shown with `<StatusBadge>`.

## Permissions

- Read keys with `$user->canDo()`, never `->can()`.
- The rebuild's 17 keys are in `app/Domain/SecurityDevices/Support/SecurityDevicesRebuildPermissions.php`, with their grant migration. A new key needs Stephan's plan and a grant migration.
- Don't hide a button the server would refuse anyway, and don't show one it would refuse: the presenter sends `can` flags.

## Testing while building (Stephan, 1 Nov: test once at the end)

- **Quick checks only:** eslint on the files you changed, a type check, or the one test file you just added. No full suites.
- Each page gets one vitest file. Each presenter or route gets one Pest feature file covering scope (another Site's data never shows) and the main shape.
- Run Pest only through `bash ~/.claude/heavy-lock.sh "<label>" -- ~/.config/herd/bin/php84/php.exe vendor/bin/pest <file>`.
