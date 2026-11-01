# DESIGN.md — OblivionFindings UI contract

This is the single entry point for how UI gets built in this app. Read it
before writing or changing any page, component, or style. It is deliberately
short: it tells you the rules, where the bounded primitives live, and which
deeper guide to open for the pattern you're building. Do not invent new
colours, type scales, spacings, or component variants — everything you need
already exists as a token or a primitive.

**Stack:** Laravel + Inertia + React/TypeScript, Tailwind v4 (CSS-first
config in `resources/css/app.css` `@theme`), shadcn/Radix primitives,
lucide-react icons, Recharts.

---

## Non-negotiables

1. **Semantic tokens only.** Never `bg-violet-600`, `text-emerald-500`, or
   inline hex. Use `bg-primary`, `text-status-success`, `bg-category-hr`,
   `var(--chart-1)`, etc. ESLint blocks raw palette classes
   (`eslint.config.js`). The whole UI retints when the admin changes the
   brand colour on Settings → Branding — a hardcoded colour breaks that.
2. **Dark mode is automatic.** Every semantic token has a `.dark` variant in
   `app.css`. If you use tokens you never write `dark:` colour pairs.
3. **Check the inventory before building.** `resources/js/components/ui/` is
   the primitive library; `resources/js/components/` holds shared app
   components. If something close exists, extend it — don't fork a one-off.
4. **Typography helpers, not ad-hoc sizes.** `.text-page-title`,
   `.text-section-title`, `.text-subtle`, `.text-caption` (defined in
   `app.css`). Don't compose `text-2xl font-semibold` inline.
5. **Accessibility floor.** WCAG 2.1 AA contrast (status token pairs are
   pre-verified); colour is never the only signal (pair with label/icon);
   `focus-visible:ring-2 focus-visible:ring-ring` on all interactive chrome;
   respect the global reduced-motion block; ≥44 px tap targets on frontline
   surfaces (`.frontline-tap`, or `.frontline-hit` where a compact control
   must keep its drawn size — row kebabs, pagination, tier-2 tabs, the
   header filter chip's clear ✕, the
   `Dialog`, `WizardShell` and `Sheet` ✕; the `Sheet` ✕ keeps a 16 px
   minimum inset so its 44 px target stays on screen).
   **Desktop geometry:** keep the drawn dimensions of the shared header,
   filter, pagination, switch and checkbox primitives. A larger hit area
   must not enlarge their visible shapes. Never apply blanket descendant
   `min-height` / `min-width` rules to page or dialog buttons: these also
   catch switches and turn compact controls into oversized circles.
   The current eMAR work is desktop web only; do not introduce phone
   layouts or replace its compact desktop controls with touch-sized ones.
6. **Safety colours are brand-independent.** Allergen/conflict/emergency
   surfaces use fixed `status-critical`/`status-warning` pairs, never
   brand-derived tints — an admin's brand hue must not be able to push a
   safety warning below AA contrast.
7. **Build every module from the kits.** A new module adds its own words
   and fields, never a new page shape, header, pop-up frame or way of
   writing (approved by Stephan 2026-10-20 → 26):
   - every page is one of the page kit's templates, built from its blocks
     (`design_styles/PAGE_KIT_STYLE_GUIDE.md`);
   - every header follows one-header-per-template
     (`design_styles/PAGE_HEADER_STYLE_GUIDE.md` §11);
   - every overlay is a pop-up kit type at a kit width
     (`design_styles/POPUP_STYLE_GUIDE.md`); long dropdowns are searchable;
   - every word follows `design_styles/WRITING_STYLE_GUIDE.md`;
   - before shipping, run the page kit's checklist (§12) and compare the new
     pages with an existing module's pages of the same template.
8. **No coloured edge lines on boxes — hard rule** (Stephan, 31 Oct 2026:
   "never look like this it is obvious a AI thing"). Cards, surfaces,
   panels, strips, list blocks, pop-ups and toasts never carry a coloured
   line, bar or gradient on any edge: no brand or status-tinted top border
   (`border-t-2/3/4` + a colour, a `::before`/`::after` bar, an "accent" or
   "horizon" line), and no coloured side or bottom border either. A box is a
   plain card: the neutral 1px `--border`, the soft shadow, white on the page
   ground. What a box means is said by its title, a `<StatusBadge>`, a ring
   or an icon, never by a coloured edge. Neutral dividers *inside* a box
   (row rules, the dashed list rule) and table total rules are fine.
   Enforced: ESLint `design/no-edge-accent-line` (coloured `border-t-*`,
   `border-l-*`, `border-r-*`, `border-b-*` widths with a colour class on the
   same element). See the named anti-pattern "Coloured edge lines".

---

## The Slate look (approved by Stephan 2026-10-20)

Stephan chose **D · Slate** for the whole app after the grey ground
(`#DFDFE2`) and the lavender option both "felt dated". It replaces the
grey-ground palette everywhere: every page, card, list and pop-up, light
and dark.

**Rollout status:** the rules below are approved; `resources/css/app.css`
still carries the old values until the Slate token change lands (tokens
first, then one module at a time). New work follows Slate. Visual
reference: the "Oblivion colours & look" canvas
(claude.ai/artifact/CPsdrsDkwjpYwscYf8jHbY): page 2 row D, plus pages
3–9 (templates, date & time, controls, cards, page layouts, every pop-up).
Decision notes, contrast table and inventory:
`docs/design-refresh-2026-10-19/`.

| Role | Light | Dark | Token |
|---|---|---|---|
| Page ground | `#F8FAFC` | `#0F172A` | `--background` |
| Cards, pop-ups, popovers | `#FFFFFF` | `#1E293B` | `--card`, `--popover` |
| Secondary surface — nested sections, inactive tabs and segments, grouped information, table headers, pop-up footers | `#F1F5F9` | `#273449` | `--muted`, `--secondary` |
| Hairline borders and inputs | `#E2E8F0` | `#334155` | `--border`, `--input` |
| Body text | `#0F172A` | `#F1F5F9` | `--foreground` |
| Muted text | `#475569` | `#94A3B8` | `--muted-foreground` |
| Sidebar + top bar (**the same in both modes**) | `#1E293B` | `#1E293B` | `--sidebar` |
| Sidebar text · selected item | `#94A3B8` · `#334155` + white | `#94A3B8` · `#273449` + white | `--sidebar-foreground`, `--sidebar-accent` |
| Brand (default `--primary`) | Journey purple `#7040D6` | `#7040D6` (fills clamp to L 0.50; brand text `#C3A8FF`) | `--primary` |
| Brand tint (selected, info) | `#F3EEFF` | `#2C2547` | `--accent`, `--status-info-bg` |

Rules that come with it:

- **Layering:** ground → white card (a `#E2E8F0` border plus a 1–3 px
  soft shadow does the lifting; white on `#F8FAFC` is only 1.05:1) →
  secondary `#F1F5F9` for anything nested inside a card. Never put a card
  colour straight on a card, and never use the old mid-grey.
- **Muted text floor:** `--muted-foreground` is slate-600 `#475569`
  (6.9:1 on `#F1F5F9`). Slate-500 `#64748B` fails AA on the secondary
  surface (4.34:1) and is not used for text.
- **Chrome:** the nav and top bar are slate-800 `#1E293B` in **both**
  modes (Stephan, 2026-10-20). In light mode it is the one dark element
  framing a light page; in dark mode it matches the cards, one step above
  the `#0F172A` ground.
- **Buttons:** Slate flattens the primary — a solid `--primary-fill` with
  a 1–2 px neutral shadow and a faint inner highlight, no gradient and no
  coloured glow (see `design_styles/BUTTON_STYLE_GUIDE.md`, Slate
  revision).
- **Unchanged:** the Event Horizon header sky (brand ramp), status pairs,
  calendar source colours, the 20 px gutter, pop-up widths (480 / 720 /
  900 / 1100), Instrument Sans and the type helpers.

---

## Colour tokens (full detail: `design_styles/DESIGN_TOKENS.md`)

| Family | Tokens | Use for |
|---|---|---|
| Core | `background/foreground`, `card`, `popover`, `primary`, `secondary`, `muted`, `accent`, `destructive`, `border/input/ring` | Everything structural |
| Status | `status-success/warning/critical/info/neutral` (+ `-bg`, `-foreground`) | Severity and lifecycle states — via `<StatusBadge>` |
| Live | `live` / `live-bg` | In-progress/running things (distinct from info) |
| Category | `category-ops/hr/compliance/incidents/governance/sites/fleet/finance` (+ `-bg`) | Module-level tinting, hue-rotated from brand |
| Charts | `--chart-1`…`--chart-5` | Recharts fills — `fill="var(--chart-1)"` |
| Calendar sources | `--src-event/inspection/compliance/…` (+ `-bg`, `-ln`); Governance calendar: `--src-meetings/decisions/obligations/policies`; Finance calendar: `--src-invoice-due/bill-due/payment-run/gst-due/payroll/period-close`; Referrals calendar: `--src-referral-follow-up/appointment/assessment/funding` | Shared SiteCalendar sources only (Sites + Governance + Finance + Referrals adapters) |
| Sidebar | `sidebar-*` | The app sidebar only |

Status → **always** `<StatusBadge>` (`components/ui/status-badge.tsx`) or
`getStatusColor()` from `@/lib/status-colors` — never a hand-rolled span.
New status key? Add it to `status-colors.ts` once; it's used on 50+ pages.

---

## Layout shells — pick the right one

| Surface | Shell |
|---|---|
| Admin/back-office pages | `layouts/app-layout.tsx` (sidebar) + `components/page-shell.tsx` for the title/description/actions header |
| Frontline/staff mobile pages | `layouts/staff-page-shell.tsx` (+ `staff-bottom-nav`, `.staff-shell-content`, `.frontline-sticky-footer`, `.frontline-header-inset` safe-area helpers from `app.css`) |
| Auth screens | `layouts/auth/auth-journey-layout.tsx` (the Journey half-and-half; see Recurring patterns) |
| Settings pages | `layouts/settings/layout.tsx` |
| Public website (the client's own site) | `layouts/marketing-layout.tsx` (`PublicSiteLayout`) with `components/public-site/` and `resources/css/journey-site.css` — see Recurring patterns |

**The global shell chrome (approved 2026-09-05).** The top bar +
sidebar follow `design_styles/APP_SHELL_STYLE_GUIDE.md` — the "Event
Horizon chrome": one continuous surface for header and sidebar
(slate-800 `#1E293B` in both modes since the Slate look, 2026-10-20),
"Oblivion Care" ring-O wordmark,
centred command search, Report incident / Clock in / Messages / bell /
avatar in the header (no Live chip), collapsible module groups, an
edge-tab whole-sidebar collapse, and the Slate page ground (`#F8FAFC`)
with white cards (see "The Slate look" above; the neutral grey ground
was retired 2026-10-20). **The shell owns a single 20px gutter** (approved
2026-09-05; 10px was tried and revised up the same day) between the
ink chrome (sidebar + top bar) and page content — `p-5` in
`app-sidebar-layout.tsx`'s `DEFAULT_CONTENT_CLASS`
— and nothing re-pads it: `PageLayout` defaults to `padding="none"`,
and pages must not add their own outer padding against the chrome.
Any request to build, restyle, or "redo" the sidebar/top bar/page
background implements that guide. Enforcement points: `app-header.tsx`,
`app-sidebar.tsx`, `layouts/app/app-sidebar-layout.tsx`, shell tokens
in `app.css`.

### Page headers — the Event Horizon header (approved 2026-09-05)

**The top of every page has ONE source of truth:**
`design_styles/PAGE_HEADER_STYLE_GUIDE.md`. Every index/list page and
every record profile page opens with the same fixed-rhythm "Event
Horizon" header band (meter-row revision approved 2026-09-06): a top
row of identity (ring mark, title + status chip, one fact subline)
left and scoped search + action cluster right; then **one full-width
METER ROW** — instrument blocks (stat / delta stat / bar meter / donut
/ sparkline, 4–6 per page) carrying the page's key numbers, **every
block a link to its view** (Hazards → open hazards, Occupancy → beds,
…); then the page-specific filter row — **all INSIDE the header**;
the page's main view tabs on the bottom edge as the Rule 1
connected-tab rail. Above the band, the shell renders its slim
breadcrumb strip: every page passes a full trail rooted at **Home
(`/dashboard`)** to `AppLayout breadcrumbs` (added 2026-09-06). Lists
render nothing between the header and their content;
individual/profile pages add one tier-2 sub-nav strip (ghost tabs,
positional tones) below it. Visual reference:
`public/eh-hero-variants.html`.

Implemented (2026-09-06, meter-row revision included) as **one
`PageHeader` component** — `components/page/page-header.tsx` (with the
`PageHeaderMeterBlock` family) plus the `.eh-header` / `.eh-mark-ring`
/ `.eh-meter` utilities in `app.css`. The sky is **always the branding colour's
own ramp** (approved 2026-09-06): whatever colour Settings → Branding
sets, a dark shade of `--primary` at the top fades into the brand, no
lighter than L 0.46, at the bottom (relative-colour oklch keeps the hue;
the floor keeps the rail's tab labels at 4.5:1 for every hue — amended
2026-10-02) — never a neutral/ink top, never a hand-picked palette.
Settings → Branding warns, without blocking, when a chosen colour still
can't keep header text at 4.5:1 (very light brands) and offers a darker
shade.

This supersedes the `PageHero` banner system
(`components/page/page-hero.tsx`), the Governance Hero Guide, the
hero-unification plans, and the client/site profile heroes as
references — all of those are migration targets (conformance probe
12), not precedents. Do not consult or extend them for new work.
Until a page migrates, its `PageHero` band follows the sky's contrast
rules (amended 2026-10-02): `.page-hero` in `app.css` paints the brand,
category or Site colour (`--hero-base`) floored to L ≤ 0.36 and
opaque; faint text on it is never below 70%; status tones on it use
the lifted `page-hero-tone-*` classes, never raw `text-status-*`.

---

## Recurring patterns — where the canonical version lives

- **Sign-in and account screens: the Journey half-and-half (approved
  2026-10-13).** Every sign-in, password, verification, invitation and
  signed-out screen uses `layouts/auth/auth-journey-layout.tsx`. It has a brand
  half (staff: ink; family: lilac, ink in dark mode) with the NZ islands
  `JourneyMark`, a two-line headline and the identity from `BrandLockup`, and a
  plain form half. Styles live in `resources/css/journey-auth.css`; colours
  are the `--journey-*` tokens, derived from `--primary` so
  Settings › Branding recolours them. Identity follows branding: no custom
  branding shows the Journey mark and "oblivion care"; an uploaded logo shows
  that logo (its "logo for dark backgrounds" version on ink); a custom name
  alone shows the name without our mark, with "Powered by oblivion care".
  **With client branding the brand half is the client's (Stephan, 21 Oct
  2026, mockup v2):** their website and sign-in photo from Settings ›
  Branding under two ink scrims (≥ 72 % behind the top band, ≥ 90 % behind
  the welcome block), or a plain brand-colour panel when there is no photo.
  The islands artwork and its journey animation are only Oblivion's own
  default, with no client branding at all. Over a photo the family headline
  is "Kia ora, whānau. / Good to see you." Both sign-in pages have "Back to
  website" at the top (at the top of the form on phones). Staff sign-in asks
  for the email first ("Your work email") and sends organisation domains to
  their provider. Family sign-in offers the emailed sign-in link first. The
  journey animation plays once, only while a sign-in is sent. Mockups: the
  "Sign-in redesign" canvas (claude.ai/artifact/TKAEX7134Dgt5zp7ehDuHn),
  boards marked "chosen"; `docs/referrals/mockup-v2/` for the photo half.

- **The public website is the client's own site (approved by Stephan
  23 Oct 2026, mockup v2 in `docs/referrals/mockup-v2/`).** It speaks as the
  provider ("we"), never as Oblivion Care. Every public page — home, Our
  support, Funding help, About us, Careers, Contact us, Make a referral,
  secure upload and the footer pages — uses `PublicSiteLayout`
  (`layouts/marketing-layout.tsx`): one ink band holding the site header and
  the page intro (`PageIntro`), and one footer. Header: Our support · Funding
  help · About us · Careers · Contact us, then Sign in and the primary "Make
  a referral"; the full nav from 1280 px, a menu with the horizon line below
  that. The footer carries one small "Powered by oblivion care" line when the
  site has client branding. Build pages from `components/public-site/`
  (`SitePhoto`, `Lens`, `RadioTiles` tile pickers, `Pathway`, `PlanPathway`,
  `SafeContactBand`, `EmergencyNote`) and the `.journey-site-*` classes in
  `resources/css/journey-site.css`. The provider's name comes from
  `usePublicSite()` (Settings › Branding, then Settings › Referrals) and is
  null rather than "Oblivion Care". Photos: the home hero and sign-in use the
  Settings › Branding photo; a client without one gets a plain brand-colour
  panel (`.journey-site-panel`). Our demo photos and the fictional provider's
  facts (`content.ts` `demo`) show only on a demo server
  (`config('demo.site')`, shared as `demoSite`). Public tap targets are 44 px
  (`.frontline-tap` or `min-h-[44px]`), and Make a referral is tile pickers
  on the page, then the 1100 px `WizardShell`.

- **Calendars — always the Site Calendar style** (approved 2026-09-13).
  `resources/js/pages/sites/calendar/SiteCalendar.tsx` and `_parts.tsx`
  are the canonical calendar UI for My Calendar, site calendars and
  module calendars. Reuse the component with a data adapter for a
  different module's feed; do not create a separate FullCalendar skin,
  toolbar, month grid, colour scheme or calendar header. Keep each
  module's permissions, data ownership and workflows intact. Follow
  `design_styles/CALENDAR_STYLE_GUIDE.md` for the shared views, date
  anchor, source filters and responsive checks. **The viewed day,
  month and year must be obvious in the header**, not only in a small
  date picker. Existing calendars using other designs are migration
  targets; new calendar work must use this standard.

- **Entity add/edit — the wizard dialog is the default.** Any add or edit of
  an entity record (site, client, staff, asset, incident, …) or any form with
  2+ sections uses the `WizardShell` modal from
  `components/wizard/shell.tsx`: stepper rail with icons + blurbs,
  completeness meter, "Step x of y" header, progress strip, review step
  (`ReviewCard`/`ReviewRow`), success pane (`WizardSuccessPane`). Reference
  implementations: `components/clients/add-client-dialog.tsx` (the original
  contract) and `components/sites/add-site-dialog.tsx`. Edit reuses the same
  wizard as Add, prefilled. Full anatomy and rules:
  `design_styles/POPUP_STYLE_GUIDE.md` § "Entity wizard dialogs".
- **Simple dialogs** (single-section forms, confirmations, detail viewers) —
  follow `design_styles/POPUP_STYLE_GUIDE.md` exactly (shell/body split,
  `_dialogs.tsx` co-location, width tokens, tile pickers).
- **The pop-up kit — `components/popup/` (approved by Stephan 2026-10-23;
  Control Room is the first module on it).** One shared frame so every
  pop-up looks and behaves the same: `PopupForm` (480 / 720 / 900),
  `PopupConfirm` (480: plain, destructive, warning, alert), `PopupViewer`,
  `PopupSelectionBar`, and the one `DiscardDraftConfirm` ("Discard this
  draft?" · Keep editing · Discard draft) that Esc, ✕ and Cancel all ask
  when typing would be lost. Wizards stay on `WizardShell` (1100). Every
  pop-up has the same top: a round icon **lens** (`.popup-lens`, never a
  rounded square), the title, one line, ✕; no coloured line on its top edge
  (the `.popup-horizon` line was removed 31 Oct 2026, non-negotiable 8); the
  record it came from as a plain context line;
  a grey footer band with Cancel first and the main button last, named for
  what it does. Red only when data is destroyed or a safety check is
  loosened. Consequential actions keep a review step (Control Room: resolve,
  report incident, confirm sensor report). A lost response shows "Result not
  confirmed" with a way to check, never an invitation to submit twice.
  Feature code passes icon, title, fields and the action — never widths,
  paddings, colours or footers. Source drawings: the "Oblivion color scheme
  and design system" session's `docs/design-refresh-2026-10-19/POPUP-SYSTEM.md`
  (its Slate colours are not in `app.css` yet; the kit uses today's tokens).
  Other modules move onto the kit one at a time.
- **Premium attachment uploads** (approved by Stephan 2026-09-20) — invoice,
  evidence, photo and document fields reuse `FileDropzone` + `StagedFileCard`
  from `components/ui/file-dropzone.tsx`. Keep browse and keyboard access
  alongside drag/drop, show the owning module's file limits and truthful
  staged/uploading/saved/error states, and retain entries on failure.
  `AttachmentUploader` is for compatible existing-record endpoints; shared
  upload chrome does not supply storage, permissions or business approval.
  Full contract: `design_styles/POPUP_STYLE_GUIDE.md` § "Premium attachment uploads".
- **Searchable record selection in dialogs** (approved by Stephan 2026-09-20) —
  growing directories such as assets, vehicles, people and sites use a
  searchable picker, not a long unsearchable dropdown. Reuse shared
  `Popover` / `Command` primitives and an appropriate existing domain picker;
  show names with useful references/context and retain the canonical ID.
  Large lists use scoped server search with bounded results and clear
  loading/empty/error states. Small fixed choices keep their existing control;
  locked parent context stays locked. Full contract:
  `design_styles/POPUP_STYLE_GUIDE.md` § "Searchable record selectors".
- **Calendar date and range selection** (approved by Stephan 2026-09-20) —
  operational planning forms use the same visible calendar-selection pattern
  and chosen-date/range summary, including Report a problem and Plan appointment.
  Inspect `components/hr/leave-calendar-range.tsx` for the existing interaction;
  keep HR entitlement/hours/holiday policy with HR. Required dates stay required;
  offer Not known yet only where the owning workflow permits it. For timed
  appointments, place separate start/end times with a visible timezone below
  the dates. Preserve local-date meaning, validation and drafts. Full contract:
  `design_styles/POPUP_STYLE_GUIDE.md` § "Calendar date and range selection".
  The single-date variant (approved by Stephan 2026-09-20) also covers observation
  dates and deadlines: reuse the calendar interaction with one selected day,
  local Cancel/Use date and preservation of the paired time. Keep date ranges
  only where the owning workflow needs a range; see that guide's single-date
  clarification.
- **Clock and manual time entry** (approved by Stephan 2026-09-20) — operational
  date/time fields pair a consistent hour/minute clock picker with obvious manual
  entry and AM/PM controls. Accept every exact minute without rounding; keep
  local edits pending until Use time, and preserve the prior value on Cancel or
  Escape. Show the applicable timezone, keep controls labelled and keyboard
  operable, and preserve parent interval validation and recovery. Canonical
  `HH:mm` values stay separate from display; real scheduling requires explicit
  daylight-saving handling. Reuse shared primitives/tokens; the design prototype
  is not an existing production time component. Full contract:
  `design_styles/POPUP_STYLE_GUIDE.md` § "Clock and manual time entry".
- **Ticket-style work/record details** (approved by Stephan 2026-09-20) —
  suitable work orders, support issues and other owned-action records share a
  consistent body hierarchy: concise summary and linked sources, compact notes,
  prominent next action/progress, ownership, evidence and guarded completion.
  Keep the existing PageHeader/navigation and modal contracts. Each module owns
  terminology, fields, tabs, statuses, permissions and lifecycle; do not rename
  every record Ticket or copy Maintenance's release process into other modules.
  Full contract: `design_styles/WORK_RECORD_STYLE_GUIDE.md`.
- **Buttons — the "soft depth" treatment (approved 2026-09-04; flattened
  by the Slate look 2026-10-20: solid fill, neutral 1–2 px shadow, no
  gradient or glow — the guide's Slate revision wins).** Primary
  (`default`) = gradient-lit primary with inner highlight + violet glow;
  secondary (`outline`) = card surface with soft shadow and
  purple-tinting border on hover; both lift 1px on hover, press 0.5px,
  and use the 45%-mix ring focus. Full spec and implementation notes:
  `design_styles/BUTTON_STYLE_GUIDE.md`. The single enforcement point is
  `buttonVariants` in `components/ui/button.tsx` — never restyle
  primary/secondary buttons per page.
- **Destructive confirmation** — `components/confirm-dialog.tsx`; destructive
  buttons use `<Button variant="destructive">` and stay readable.
- **Empty / loading / error states** — `components/ui/empty-state.tsx`,
  `loading-state.tsx`, `error-state.tsx`, and the `skeleton-*` set. Never a
  bare "No data" `<p>`. **Skeleton vs loader:** when the incoming layout is
  known, use the matching skeleton (`skeleton-table`, `skeleton-card-list`,
  `skeleton-card`) so the page doesn't jump; the Event Horizon loader (next
  bullet) is for boots, transitions, and indeterminate section loads where
  no layout can be promised.
  **Background-loaded panels (approved 2026-10-30).** A slow part of a page
  loads after the page shows: the server sends it with
  `Inertia::defer(fn () => …, '<group>')` and the page wraps it in
  `<DeferredPanel>` (`components/deferred-panel.tsx`). The panel shows the
  matching skeleton while it loads, an `ErrorState` with "Try again" if the
  load fails or never arrives, and the last data it showed (for the same
  person) during a page refresh instead of flashing back to the skeleton. A
  loading or failed panel never reads as "0", "nothing overdue" or an empty
  list; a payload that says its own read failed (`unavailable: true`) shows
  the error too. Counts in the chrome follow the same rule: the sidebar's
  All Tasks count shows a dash until it is known. Keep cheap, urgent safety
  facts in the first response. In the controller, give every other prop as a
  closure (`'overview' => fn () => …`): the background request runs the
  controller again, and only closures let it skip what it did not ask for.

  ```tsx
  <DeferredPanel<{ myTasks: MyDayMyTasks }>
      data="myTasks"
      label="Other assigned work"
      fallback={<SkeletonCard rows={3} />}
      failed={({ myTasks }) => !!myTasks.unavailable}
  >
      {({ myTasks }) => <MyTasksCard tasks={myTasks} />}
  </DeferredPanel>
  ```
- **Loading spinner — the "Event Horizon" brand loader (approved
  2026-09-04).** Page, transition, and section loading use the animated
  Oblivion wordmark (the O is a black-hole accretion ring in `--primary`
  that devours the letters tail-first); buttons and other small inline
  spots use its **ring-only** variant — never the animated word at small
  sizes. Tokens-only (retints with branding), transform/opacity/blur-only
  motion, static wordmark under reduced motion. Full spec + reference CSS:
  `design_styles/LOADER_STYLE_GUIDE.md`; delivered through
  `components/ui/loading-state.tsx`, not ad-hoc per page.
- **Toasts** — `components/flash-toaster.tsx` (server flash) and
  `undo-toast.tsx` (undoable actions).
- **Two-tier section navigation (approved 2026-09-04).** Tier-1 group
  rails inside a hero use the **"connected tab"**: the active tab takes
  the page `--background`, `--primary` text, `12px 12px 0 0` radius, and
  sits flush with the hero's bottom edge (the merge is the affordance);
  inactive pills stay translucent primary-foreground. Tier-2 sub-tab
  strips under an open section are **neutral at rest, toned only when
  active**, with tones assigned by position (`index % 5`:
  violet → teal → green → amber → rose) — never hand-picked. Full spec:
  `design_styles/NAVIGATION_STYLE_GUIDE.md`. Enforcement points:
  `GroupPillRail` + `TierTwoTabs` (`components/page/grouped-profile-nav.tsx`)
  and the rostering `TabStrip` (`components/rostering/tab-strip.tsx`).
- **Index view tabs — main tabs live in the hero (corrected 2026-09-05).**
  A page's main view/filter tabs (e.g. All sites / At risk / …) render in
  the hero footer as the Rule 1 **connected-tab** rail
  (`design_styles/NAVIGATION_STYLE_GUIDE.md`): the active tab takes the
  page `--background` and sits flush with the hero's bottom edge — never
  as a tinted-pill strip below the hero. Counters follow the counter
  state-colour rule. Reference implementation: `PageHeaderRail` in
  `components/page/page-header.tsx`.
  `components/page/page-tabs.tsx`'s tinted-pill
  strip remains only for secondary in-page tab rows that have no hero
  rail to live in.
- **Count/warning pills on tabs and nav items follow their tab's state
  colour — never a fixed colour.** Active tab → `bg-primary/15 text-primary`
  (on an active connected tab, whose surface is the page background, the
  counter switches to tone-on-light pairs — never `text-primary-foreground`);
  inactive → `bg-muted text-muted-foreground`; alert counts →
  `bg-status-critical-bg text-status-critical`. A counter that keeps one
  colour across states goes invisible when its tab activates (the hero
  "Overview 7" white-on-white bug in `grouped-profile-nav.tsx`
  `WarningPill onHero`).
- **Filters** — a page's primary filters live in its header's filter
  row (`design_styles/PAGE_HEADER_STYLE_GUIDE.md` §5), never in a bar
  below the header; `components/filter-bar.tsx` remains only for
  secondary tables inside cards/sections.
- **Entity list surfaces — cards + tables (approved 2026-09-06).**
  Every listable record (sites, clients, incidents, staff, assets, …)
  renders through the two Event Horizon list contracts in
  `design_styles/LIST_STYLE_GUIDE.md`: the entity card (status
  meridian, identity row, fact chips, one optional metric slot, alert
  chips, utility footer) and the entity table (one shell, identity
  cell first, kebab last, columns picked from the cell library via a
  per-entity column spec). Both keep the kebab menu AND the
  right-click context menu on every card/row, fed by one `MenuItem[]`.
  No readiness/onboarding displays. Implemented (2026-09-06) in
  `components/lists/` (`EntityCard`, `EntityTable`, cell library,
  shared `entity-menu.tsx`, `ListCaption`).
- **Data tables & pagination** — table markup uses the
  `components/ui/table.tsx` primitives; server-paginated lists use
  `components/ui/laravel-pagination.tsx` (the canonical paginator — 64+
  pages already do); loading state is `skeleton-table`. Wide tables scroll
  inside their own `overflow-x-auto` container — the page body never
  scrolls horizontally. Entity list tables additionally follow the
  table contract in `design_styles/LIST_STYLE_GUIDE.md`.
- **Scroll surfaces** — custom scroll areas use `.scrollbar-pretty` (thin,
  themed); `.scrollbar-none` is reserved for the icon sidebar rail.
  `.nice-scroll` is a meal-planner-era duplicate of `.scrollbar-pretty` —
  don't spread it further (consolidation candidate).
- **Icons** — lucide-react only, no second icon set and no emoji-as-icon.
  Sizes: `size-4` (16px) in buttons/menus/inputs, 15px in nav pills,
  `size-3.5` in dense chrome; default stroke. Icon-only controls always
  carry an `aria-label`. **Emoji are content, not icons** (clarified
  2026-10-22, Chat): they belong in messages, reactions, the emoji picker
  and stickers, drawn with the computer's own emoji font, and never as a
  button label, menu icon, status marker or decoration ("👍 Acknowledge",
  "🎙️ Voice note" are the anti-pattern). ESLint `design/no-emoji-icon`
  checks the chat folders.
- **Stat/KPI cards** — `components/ops-stat-card.tsx` / `fleet-stat-card.tsx`.
- **Health & safety pages: one strip and signature blocks (approved by
  Stephan 2026-10-23 with the H&S drawings, design viewer v35).** The
  `PageHeader` carries at most ONE strip under its identity row
  (`components/page/page-header-strip.tsx`): `<PageHeaderNumbers>` — up to
  four equal number boxes, number first, every box a link — for hubs and
  lists, or `<PageHeaderStages>` — a record's stage line with its next step
  ("who does what next") on top — for records with a lifecycle; record facts
  sit above it as `<PageHeaderFacts>` chips. One banner: a record's next step
  lives in the header, never in a second band under it; a fifth number goes
  in the page body (a `Tally`), never a fifth box. Page bodies use the
  signature blocks (`components/signature/blocks.tsx`,
  `resources/css/signature-blocks.css`): the plain surface (no coloured edge
  line, non-negotiable 8),
  rings read as state (`Ring`, `RingCard`, `Checks`), the Journey line,
  `Story`, `Log` (time first), `PeopleCards`/`PlaceCards`, `Tally` (zeros
  fold into one sentence), `Flow`, `RiskMap` (the H&S bands), `DutyClock`
  (the law's wording kept as it is), `Queue`, `StageDots` in tables, and the
  settings pictures `Choices`, `Routes`, `EvidenceWindows`, `KnowList`. Port
  a page view for view from its drawing; colours stay on the current app
  tokens (the paused colour refresh does not apply).
- **Role overview pilot (approved by Stephan 2026-10-13).** The six-role
  Overview (`features/overview/`) is the reference for editable dashboards:
  block cards (title + subtitle, ⋯ menu, footer note + "View details ↗"),
  the drag/resize block grid, the block library and read-only detail
  panels (a row with facts opens a 480px panel whose one action links to
  the module). Light mode uses the app tokens. Two overview-only choices
  are piloted there and are candidates for the whole app — Stephan
  decides: the **graphite dark palette** (superseded 2026-10-20 by the
  app-wide Slate dark: `#0F172A` ground, `#1E293B` cards) and the **calm
  categorical chart palette** (both as oklch tokens at the top of
  `workspace.css`), and the
  **comfortable 38px header dropdowns** (`PageHeaderFilterSelect
  size="comfortable"`, PAGE_HEADER_STYLE_GUIDE.md §6). Status colours
  there always use the app's verified pairs. The seventh role, **Business
  support** (finance, compliance, safety, fleet, risk and audit staff),
  builds each block from its module's own service and permission, and
  opens with blocks chosen from the areas the person can see. Money on
  the overview is whole NZ dollars: charts set `unit: 'NZD'` (monthly
  series add `interval: 'month'`), and rows, breakdowns and bars carry a
  formatted `display` value such as "$1,960" instead of a raw number.
- **Chat — the Chat v3 boards (approved by Stephan 2026-10-22).** Chat,
  the family portal conversation, calls and every place chat appears are
  built to the six boards in `docs/chat-experience/v3/` (the "Chat v3 —
  proposed rebuild" page of the design viewer), view for view. The rules
  behind them:
  - `/chat` is the page kit's **Workspace** template at full height with
    the breadcrumb strip only: no Event Horizon band (a deliberate
    exception to the page header contract, decision D14). Side sheets and
    drawers show **one** conversation, never the two-pane workspace.
  - **Messages own the screen.** Above them sit the conversation header
    (photo with ring, name, one status line, Chat · Files · Pinned · Notes
    tabs, call, video, search, people) and at most one slim context bar
    (family: who can read it, owner, reply due, hand over). Status notices
    share one slot; nothing else stacks above the messages.
  - **Bubbles:** your own on the right in the brand-fill tint, others on
    the left on the card surface; consecutive messages from one sender
    grouped under one photo and name; time inline; reactions under the
    bubble; photos as pictures (grid with "+N"); voice notes as a waveform
    player; Oblivion links as care cards.
  - **Message actions** come from one `MenuItem` set offered by a hover
    bar, the ⋯ button, right-click and Shift+F10, never a pop-up per
    action. Destructive items use the destructive tone.
  - **Composer:** one auto-growing line with + (attach menu), emoji,
    stickers and GIFs, "Aa" formatting, and a microphone that turns into
    Send; voice notes record inline. Compact desktop controls, with
    `.frontline-hit` where a bigger target is needed.
  - **Read status:** staff see per-person read rings and Message info;
    family see only "Seen by care team" (D8). Delivery rings always carry
    a word (Sending, Sent, Delivered, Read).
  - **Calls:** one click to start; Answer in the primary colour with a
    phone icon, Decline in the destructive red (D13, D15).
  - Plain NZ English everywhere: no "audience", "publication",
    "eligible", "policy scope" or send-checking jargon on screen.
- **Multi-step flows** — `WizardShell` (see entity add/edit above).
  `components/wizard-stepper.tsx` is **legacy** — do not use it in new work.
- **Forms** — shadcn `<Input>`/`<Select>`/`<Textarea>`; dense native-element
  forms may use the `.input`/`.select`/`.textarea` utility classes from
  `app.css` (shifts-dialog pattern). Errors via `components/input-error.tsx`.
- **Density & motion** — user preference drives `html[data-density]` and
  `html.reduce-motion`; new chrome must not fight these. Motion budget:
  micro-interactions run 150–300ms (buttons 160ms, wizard panes 300ms)
  and animate transform/opacity/blur only — no layout properties; longer
  or looping motion is reserved for the brand loader and must be
  neutralised by the reduce-motion blocks.

---

## Named anti-patterns

These are recurring mistakes. Recognise and avoid them; each has burned us
before.

- **Coloured edge lines** (corrected 2026-10-31, Stephan on the H&S Overview:
  "remove these lines everywhere … never look like this it is obvious a AI
  thing") — a thin brand or status-coloured line on the top edge of a card,
  surface, strip, panel or pop-up (the old `.sg-surface` "horizon line", the
  `.popup-horizon` gradient, `border-t-4 border-t-primary-fill`,
  `border-t-[3px] border-t-primary`), or the same trick on a side or bottom
  edge. All removed 31 Oct 2026; never add one back, in any colour, on any
  box. Non-negotiable 8; ESLint `design/no-edge-accent-line`.

- **Generic sign-in screens** (corrected 2026-10-13: "this does not look
  unique to us") — sign-in or account pages built from stock auth layouts, a
  photo background, plain token cards or the shell's ring wordmark. Brand-facing
  screens use the Oblivion Care Journey identity through
  `auth-journey-layout.tsx` (Recurring patterns). Don't add a giant stacked
  wordmark (rejected as Option D) or looping decorative motion. The one photo
  allowed is the client's own website and sign-in photo, which the layout
  draws in its brand half under the approved scrims (Stephan, 21 Oct 2026).
- **Oblivion identity on a client's site** (corrected 20–23 Oct 2026, client
  demo website) — "Oblivion Care" as a fallback provider name, an
  `@oblivioncare.co.nz` placeholder, our demo photos, or the NZ islands art on
  a site or sign-in page that carries a client's branding. The public website
  and the sign-in pages are the client's: the name comes from Settings ›
  Branding or Settings › Referrals and reads neutrally when unset; a client
  without a photo gets a plain brand-colour panel; the islands are only
  Oblivion's own default; "Powered by oblivion care" is the one mention.
  ESLint (`design/no-oblivion-fallback`) blocks an "Oblivion Care" string in
  the public site and sign-in layout files (the screen-reader text of the
  Powered by line is JSX text, which it allows).
- **Hard-coded logos or brand colours.** Corrected 2026-10-20 (Stephan): "the clients will need to add their own logos … also their colours". Every client sets its own logos (one for light and one for dark backgrounds) and one brand colour in Settings › Branding.
  - Logo: every logo spot reads the brand slot (`components/brand/brand-lockup.tsx`). This covers the top bar, sign-in, family portal, print and email headers. The slot shows:
    - by default, the Journey mark + "oblivion care";
    - if the client uploaded a logo, their logo with a small "Powered by oblivion care";
    - if they set only a name, the name without our mark.
  - Never point a component at a logo image path. Never crop a client logo to a fixed shape.
  - Colour: every brand-coloured surface derives from `--primary`. No `#7c3aed` / `#7040D6` literals in components, CSS, Blade, PDFs or email.
  - Status and safety colours never follow the brand.
  - Check new UI with a second brand colour. Known offenders are listed in `docs/design-refresh-2026-10-19/GOOD-CATCHES.md` §6, including the app default `#7c3aed` in `derive-palette.ts`.
  - ESLint already catches classNames. Hex values in CSS, Blade and PDFs need review.
- **A page header that changes shape between pages of the same kind.** Corrected 2026-10-21 (Stephan): "hero banners and pages are starting to look inconsistent".
  - The header (`PageHero`, the sky) belongs to the page's template, not to the page:
    - **Size:** full on hubs, lists and records (title row, up to 6 numbers, tabs); compact everywhere else. A compact page's numbers lead the body as one tally.
    - **Avatar:** an initials avatar only on a person's record and their own My HR pages. Every other page shows its icon.
    - **Back arrow:** only on a record, a page that belongs to one record, a document or a full-page form.
    - **Status chip:** only on records.
    - **Header search:** only on hubs, lists and workspaces.
    - **Filters:** at most 4 filter chips in the header, then "More filters".
    - **Numbers:** a number the tab rail already counts never repeats as a header number.
    - **Sub-line:** one line under the title. Privacy, safety and legal wording stays even if it wraps.
  - The full rules (H1–H11): `design_styles/PAGE_HEADER_STYLE_GUIDE.md` §11.
- **The same thing written different ways across modules.** Found 2026-10-22 in the cross-module audit of the page drawings:
  - "Add agreement" here and "New agreement" there.
  - Dates as 2026-10-27, 27/10/2026 and 27 October 2026.
  - 24-hour times beside "9:30 am".
  - Breadcrumbs without Home, and Title Case labels.
  - The rules:
    - **Make a record:** "Add …". "New …" is only for a message or conversation; "Create …" only when something is built from other things (a report, a pack, a pay run). Incidents are always "Report incident".
    - **Dates and times:** always through `lib/datetime.ts` (`formatDate`, `formatTime`, `formatDateTime`) — never a raw ISO string, a slash date or a 24-hour clock on screen. Durations and countdowns are not clock times.
    - **Money:** "$84.60", never "NZD".
    - **Te reo:** with macrons (whānau, Māori).
    - **Breadcrumbs:** start at Home.
  - Full list: `design_styles/WRITING_STYLE_GUIDE.md`.
- **A long dropdown you have to scroll through.** Corrected 2026-10-26 (Stephan): "if there is a dropdown and alot of items the search option should be there also the dropdown look should be modern and searchable".
  - Any dropdown with 8 or more options, or a list that can grow, is a searchable dropdown: a search box at the top of the open list, grouped options, a tick on the choice, and a quiet "No matches".
  - Every dropdown has the modern look: the same height, radius and focus ring as a text field, and a popover list that scrolls inside.
  - Built once on the shared `Popover` + `Command` primitives; new work never uses a native `<select>`.
  - Full rules: `design_styles/POPUP_STYLE_GUIDE.md` › Dropdowns.
  - No ESLint check yet: 132 files still use a native `<select>`. Add it once the modules have moved onto the kit.
- **Generic, stock-SaaS screens.** Corrected 2026-10-20 (Stephan): "everything is mostly generic and nothing original for Oblivion".
  - A screen built only from plain tokens and rounded-square icon tiles, with nothing of the Journey brand (islands, pathway, lowercase wordmark) or the Event Horizon (ring, sky), is not finished.
  - The proposed signature that fixes this is in `docs/design-refresh-2026-10-19/SIGNATURE.md`. It awaits Stephan's approval; until then, follow its intent.
- **Raw palette classes / inline hex** — bypasses rebranding; ESLint blocks it.
- **Hardcoded hues inside CSS utilities** — ESLint only sees classNames, so
  hex hiding in `app.css` rules or `style={{}}` props escapes it. Known
  offender: `.icon-gradient-bg` (sidebar hover) hardcodes an
  indigo→purple→pink hex gradient — it won't retint with branding.
  Utility CSS uses tokens/`color-mix` like everything else.
- **`<SelectItem value="">`** — crashes Radix Select. Use
  `value={data.field || undefined}` for optional selects.
- **Hand-rolled status pills** — diverge from the verified-contrast token
  pairs; use `<StatusBadge>`.
- **Recolouring a primary button with `bg-*` classes** (fixed 2026-09-30) —
  the default variant's unlayered `.btn-soft-primary` gradient paints over
  `bg-destructive` / `bg-status-critical`, so the button stays purple. Pick
  the tone with `variant="destructive"` on `<Button>` or `<AlertDialogAction>`;
  conditional tones use `variant={danger ? 'destructive' : 'default'}`.
  ESLint (`design/no-recoloured-primary-button`) blocks it. `unstyled`
  Buttons are exempt because they skip the variant classes. Success,
  warning and info recolours (`bg-status-success` / `-warning` / `-info`,
  `bg-white`) render purple too, and there are deliberately **no** tone
  variants for them (user decision 2026-09-30; white text on the dark
  theme's status fills fails AA). Affirmative actions (Approve, Accept,
  Complete) stay the default primary; a call to action inside an amber or
  green status banner uses `variant="outline"`; info is already the primary
  tone. On a hero or the brand sky, a default `<Button>` recoloured
  `bg-primary-foreground` rendered purple text on purple (the "white
  primary" was invisible; fixed 2026-10-01, 46 sites): the white primary is
  `PageHeaderPrimaryButton` and a glass action is `PageHeaderGlassButton`
  (`components/page/page-header.tsx`; both take `asChild` for a `<Link>` or
  `<a>`, never a `<Button>` nested in an `<a>`). ESLint covers all of these
  classes.
- **Brand-filled text on `bg-primary`** (fixed 2026-10-02) —
  `bg-primary text-primary-foreground` puts the brand sky's text colour
  (white for mid-light brands) on the raw brand: 3.6:1 on orange, teal and
  green brands, and 3.46:1 for the default in dark mode. A solid brand fill
  that carries text uses `bg-primary-fill text-primary-fill-foreground`
  (≥ 4.5:1 for any brand in both modes — `design_styles/DESIGN_TOKENS.md`
  "Primary fills and foregrounds"); its hover shade is `bg-primary-fill/90`
  and a matching border `border-primary-fill`. ESLint
  (`design/no-primary-foreground-on-fill`) blocks the pairing in one class
  string; 311 pairs in 185 files were migrated. A fill whose text colour is
  set on a child element isn't caught — pair those by hand.
- **A light panel painted bg-sidebar** (fixed 2026-10-03) — the app
  sidebar has been near-black since 7 Sep, so `bg-sidebar` is a dark
  surface. Wizard rails, cards and table headers that used it as a light
  panel (the shared `WIZARD_RAIL_CLASS` and 13 other places) turned dark
  with dark labels (1.0:1). A light panel uses `bg-muted/30` with
  `border-border`, like the WizardShell rail; rail steps hover
  `bg-muted` and mark the active step `bg-primary-fill/10` (`bg-accent`
  is purple in dark mode and `bg-primary/10` follows a light brand, both
  under 4.5:1 for the step text). `bg-sidebar` is only for real sidebar
  surfaces, paired with `text-sidebar-foreground` in the same class
  string (ESLint `design/no-sidebar-bg-without-foreground`).
- **Accent as an active surface** (fixed 2026-10-14, role Overview axe
  audit) — the shared `Toggle` / `ToggleGroup` marked its on state
  `bg-accent text-accent-foreground`: 4.03:1 in light mode, and a purple
  block in dark mode. Selected, pressed and active states use the
  brand-fill tint with mode-aware brand text, `bg-primary-fill/10
  text-primary` (≥ 4.5:1 for every sRGB brand in both modes, measured
  14 Oct), which `components/ui/toggle.tsx` now owns — don't restyle the
  on state per page. A feature palette with its own lighter dark surfaces
  re-declares `--text-color-primary` in its scope (the Overview's graphite
  does). ESLint (`design/no-accent-active-state`) blocks
  `bg-accent` behind `data-[state=on|active|checked]` and
  `aria-pressed|selected|checked`; an open menu trigger
  (`data-[state=open]`) is not a selection.
- **Focus ring on an element that never takes focus** (fixed 2026-10-14)
  — the header dropdown chip put `focus-visible:ring` on its wrapper
  `<div>` while the real trigger `<button>` was `outline-none`, so
  keyboard users saw nothing. The ring goes on the focusable element.
  In the header filter row it is inset (the 23px / 38px chips keep their
  drawn size) and toned by what it sits on: band white on the glass chip,
  `ring-primary-strong` on the white active chip (a white ring on a white
  fill is invisible). Both measured ≥ 4.7:1 for every brand.
- **Band text from the brand** (fixed 2026-10-03) — text on the floored
  dark bands (the `.eh-header` sky and the PageHero band) is
  `--band-foreground`, always white, never `--primary-foreground`, which
  turns ink for a very light brand (1.3:1 on the band). The band
  components use `text-band-foreground` / `bg-band-foreground/10` (ESLint
  `design/band-text-token`); both bands also redefine
  `primary-foreground` inside themselves, so page content in their slots
  reads white too. Brands are never blocked for this.
- **Fill foregrounds off their fill** (fixed 2026-10-13) — `*-foreground`
  tokens are for text on their solid fill, not on cards: the destructive
  `<Alert>` used `text-destructive-foreground` (white in light mode) on a
  card and rendered white on white. Error text on a light surface uses the
  critical status pair (`bg-status-critical-bg` +
  `text-status-critical-foreground`, icon `text-status-critical`), as the
  `Alert` destructive variant now does. ESLint
  (`design/no-destructive-foreground-off-fill`) requires `bg-destructive`
  in the same class string.
- **Ad-hoc `text-2xl`/`text-xl` headings** — use the typography helpers.
- **`dark:` colour pairs on token-styled elements** — redundant and drifts.
- **Pinning a fixed hue to a module** (e.g. `bg-purple-500` for HR) — use
  `bg-category-hr` so rebrand cascades.
- **Unconditional animation** — must resolve safely under reduced-motion
  (transform-only, or covered by the global reduce block in `app.css`).
- **Ad-hoc `pb-[env(safe-area-inset-bottom)]` sprinkles** — use the
  `.safe-area-*` / frontline helpers.
- **Brand-tinted safety surfaces** — safety uses fixed status tokens (see
  non-negotiable #6).
- **Rebuilding an existing primitive** — check the inventory first.
- **Full-page create/edit wizards** — entity add/edit is a `WizardShell`
  modal opened from the index page, not a routed page. Legacy holdouts are
  migration targets, not precedents.
- **Hand-rolled multi-step chrome** — bespoke steppers, progress bars, or
  success screens inside a dialog; compose `WizardShell` and its companions
  instead.
- **Legacy two-tier nav styling** — white-pill active states on hero
  rails, or underline-only sub-tab strips; both are superseded by the
  connected tab (Rule 1) and toned strip (Rule 2) in
  `design_styles/NAVIGATION_STYLE_GUIDE.md`.
- **Main view tabs below the hero** (corrected 2026-09-05) — a page's
  primary view/filter tabs rendered as a separate tinted-pill/underline
  strip on the page ground (the old `ViewTabs`/`PageTabs` row); they
  belong in the hero footer as the Rule 1 connected-tab rail.
- **Drop shadow on a connected-rail hero** (corrected 2026-09-05) — any
  `shadow-*` on a hero that hosts the Rule 1 connected-tab rail. The
  shadow darkens the page ground along the hero's bottom edge, so the
  active tab's `--background` fill reads white beside it and the merge
  breaks. Shadow-parting tricks (erasing it only under the tab) were
  tried and rejected — the ground beside the tab stays tinted. A
  connected-rail hero casts no shadow (see NAVIGATION_STYLE_GUIDE.md
  "Merge seam").
- **Conversational page headers** (corrected 2026-09-05) — greeting
  titles ("Kia ora … your sites at a glance"), live/sync eyebrows, or
  underlined phrases in a page header. Headers follow the Event
  Horizon contract (`design_styles/PAGE_HEADER_STYLE_GUIDE.md`): plain
  title + one `<StatusBadge>` chip, a one-line fact subline, the
  clickable meter-block row, and a white-primary + glass-secondary
  action cluster on the brand sky.
- **Missing or non-Home-rooted breadcrumbs** (reinforced 2026-09-07) —
  EVERY page on the Event Horizon header MUST pass a full trail rooted
  at **Home (`/dashboard`)** to `AppLayout breadcrumbs` — no exceptions,
  and it is a required step of every page migration (verify it before
  ticking the page off). `Home → Sites`, `Home → Sites → Aurora House`,
  `Home → Sites → Reports → Houses`. Two failure modes both count as the
  bug: (a) no `breadcrumbs` prop at all, and (b) a trail that starts at
  the section instead of Home. Watch the single-crumb trap: the shell
  **hides** a one-crumb trail (it would duplicate the band title), so a
  page below the top level that passes only `[{ Calendar }]` renders NO
  strip — it must be `[{ Home }, { Calendar }]`. The band never renders
  its own breadcrumbs; the strip is shell chrome
  (PAGE_HEADER_STYLE_GUIDE.md §2).
- **Dead or decorative meter blocks** (meter-row revision 2026-09-06) —
  a header block that doesn't navigate anywhere, or one whose trend/
  fraction data has no real backend source. Every block links to the
  view where its number lives; a block without live data is dropped,
  not faked. **Type-enforced (2026-10-02):** `PageHeaderMeterBlock`
  takes `PageHeaderMeterTarget` — an `href` or an `onClick`, so a block
  with neither fails `tsc`. Pass a target that can't be both missing as
  ONE conditional spread (`{...(ok ? { href } : { onClick })}`), not
  two parallel ternaries; a wrapper component takes the same type. When
  the viewer can't open the list (permission), point the block at the
  in-page section that breaks the number down — never leave it
  un-linked (reference: the Finance dashboard).
- **Numbers-only meter blocks** (corrected 2026-09-06; budget case
  corrected 2026-09-07) — a header meter block rendered as a bare big
  number when the metric has an honest visual form with a live source:
  capacity → bar meter, share of a whole → donut, daily series →
  sparkline, people → avatar stack (photos/initials with hover name
  cards). **Spend against a configured budget/cap is the bar-meter form
  too** (the guide's "funding" case): the money amount STAYS the block's
  focal 20px number, with the budget-consumed bar rendered beneath it
  and "$X left of $Y" in the caption (warning tone + "$X over" when
  exceeded) — never a bare money stat while a budget exists, and never
  the amount demoted to the small head value (reference: the Meal
  Planner Week-cost block, corrected 2026-09-07).
  Graph-first: the visual carries the block and the count moves to the
  head value; a plain stat is the fallback only when no real visual form
  exists — e.g. the same money figure with NO budget configured
  (PAGE_HEADER_STYLE_GUIDE.md §5).
- **Filterless rail tabs** (corrected 2026-09-08) — a header whose
  filter row is populated on one rail view and empty on another: the
  band collapses a row and the rhythm jumps between tabs. EVERY rail
  view supplies real filter pills for its own content (queue filters,
  a report range, a catalogue category, a board priority) — and never
  a decorative pill that filters nothing (PAGE_HEADER_STYLE_GUIDE.md §6).
- **Rail without the Find chip** (corrected 2026-09-08) — every rail
  ends with the ghost "⌕ Find" chip. `PageHeaderRail` now renders it BY DEFAULT with a
  built-in palette over its own views; record profiles pass `onFind`
  for their richer grouped `TabSearchPalette` (`/` opens it there —
  elsewhere `/` belongs to the header's scoped search). Suppressing
  the chip is a migration gap, not a variant — only pages with no rail
  at all (leaf record details) are exempt
  (PAGE_HEADER_STYLE_GUIDE.md §7).
- **Wrapping rail (two-line view tabs)** (corrected 2026-09-10) — a rail
  whose tabs wrap onto a second line because the page declares more views
  than the viewport fits (first seen: Rostering's 10 views + Recurring).
  The rail is ONE line, always. `PageHeaderRail` now handles overflow
  itself (priority+): it measures its tabs against the available width
  and collapses the trailing views into a ghost "⋯ More" pill with a
  popover, keeping two invariants — the ACTIVE view is always a visible
  tab (promoted out of the overflow if needed, because the flush
  page-ground merge is the affordance), and alert counters never
  disappear (overflowed alert counts sum onto the More pill in the fixed
  critical pair). Do not "fix" a crowded rail per page with wrapping,
  horizontal scrolling, or icon-only tabs — and prefer ≤ 8 declared
  views; beyond that the overflow pill is the designed behaviour, not a
  defect (PAGE_HEADER_STYLE_GUIDE.md §7).
- **Sunken active-rail labels** (corrected 2026-09-06, re-verified
  2026-09-07) — the Rule 1 connected active tab is taller than its
  inactive pills, so an uncompensated label sits ~3px below their shared
  text line. The active tab carries bottom padding (6px in the Event
  Horizon rail) so every rail label's centreline sits **exactly 23px
  above the band edge** — one optical line, active and inactive alike,
  on every page. Re-solve the centreline equation (and re-measure)
  whenever rail geometry changes. The inverse anti-pattern is just as
  bad: **do NOT eyeball-"fix" the active label** — the dark label on the
  white active tab reads as slightly high next to the light labels on
  the sky, but that is an optical impression (measured Δ = 0.00px), and a
  per-page margin/translate nudge breaks the shared `PageHeaderRail` for
  every page. The alignment lives ONLY in that shared component
  (PAGE_HEADER_STYLE_GUIDE.md §7), never per page.
- **Sub-nav strip wrapped in a card** — the tier-2 strip is a bare flex
  row on the page background: no border, card fill, shadow, or container
  padding.
- **Pinned sub-tabs** (removed 2026-09-06) — pin/unpin buttons (or any
  per-tab management affordance) on the tier-2 sub-tab strip. Sub-tabs
  are NEVER pinnable; the strip is tabs only. `TierTwoTabs` has no pin
  API by design — do not grow one back.
- **Hand-picked or semantic sub-tab tones** — tier-2 tones come from the
  positional cycle (`index % 5`), never chosen per tab; and tones must
  not appear on inactive tabs (neutral at rest).
- **Fixed-colour counters on stateful nav** — a count badge that doesn't
  change colour with its tab/pill's active state (goes invisible when the
  active state inverts the colours, e.g. white counter on a white active
  hero pill).
- **Generic spinners on loading surfaces** — ad-hoc `animate-spin` circles
  or bare `Loader2` for page/section loading instead of the Event Horizon
  loader (via `<LoadingState>`); and the full animated word at inline
  sizes where only the ring variant belongs.
- **Per-page button restyling** — custom backgrounds, shadows, or hover
  transforms on `default`/`outline` buttons (via `className` or raw
  elements) instead of the soft-depth treatment owned by
  `components/ui/button.tsx` (see `design_styles/BUTTON_STYLE_GUIDE.md`).
- **One module dropping the header band** (corrected 2026-10-23, Control
  Room) — redesign drawings replaced the purple Event Horizon header with a
  plain white title, a search row and underline tabs for one module only.
  Stephan chose the standard header for Control Room: consistency across
  modules wins. Fix a header that looks wrong by keeping it compact (title,
  ≤ 4 linked numbers, filters and view tabs inside it), not by giving one
  module its own header.
- **A live board that reads like a generic admin list** (corrected
  2026-10-24, Control Room Live response: "looks so generic … hard to
  understand"). A list of reference numbers, device names, small pills and
  bare dates, with the same words repeated on every row, a "no target"
  block per target and empty "—" fields, does not tell an operator who
  needs help or how long is left. Live work leads with the **person and
  the house**, says **what happened** in one sentence, shows **who is on
  it** (or "No one on it yet"), and shows **time as age or countdown**
  ("raised 3 min ago", "due in 4 min", "missed by 12 min") — never a bare
  time without its date. Reference numbers stay
  small and last. Hide empty fields; say a thing once; plain tab names.
- **Page tops that bypass the Event Horizon header** — a page opening
  with a plain heading, a one-off banner, a legacy `PageHero`, or a
  search/filter bar sitting BELOW the header, instead of the fixed
  contract in `design_styles/PAGE_HEADER_STYLE_GUIDE.md` (search and
  filters live in the header; lists get no sub-bar; individuals add
  the tier-2 sub-nav strip). The one exception is `/chat`, a full-height
  Workspace (see "Chat — the Chat v3 boards").
- **Bespoke entity cards or list tables** — hand-rolled card
  anatomies, per-page table styling, or invented table cells instead
  of the contracts in `design_styles/LIST_STYLE_GUIDE.md` (status
  meridian + slot anatomy for cards; identity-first/kebab-last +
  cell library for tables). Includes dropping either actions entry
  point — every card/row keeps the kebab AND the right-click context
  menu with the full existing `MenuItem` set — and reintroducing
  readiness/onboarding displays (removed 2026-09-06).
- **One sidebar link per register** (corrected 2026-09-14, Governance) —
  a module sub-panel that lists every register as its own link (Governance
  had 26 for chairs/secretaries). Group sibling registers into hubs: the
  sidebar shows ONE entry per hub and the siblings become the hub's
  connected-tab header rail, each keeping its canonical URL so deep links
  and server authorisation are unchanged. Keep the config in one place, with
  the sidebar entry staying lit across the hub. Don't orphan a module
  that has no other entry, and never use hidden nav as the security
  boundary. Someone who can't open the hub keeps a direct entry instead.
- **Auditor and developer language in member-facing copy** (corrected
  2026-09-14, Governance: "for a normal person it is difficult to
  understand") — reference codes used as names (`RES-2026-004` as a title
  or breadcrumb), raw enum values (`full_board`, `no_quorum`,
  `charitable_trust`), formulas (`floor(N/2)+1`), Title Case, US spelling,
  and words like immutable, frozen, snapshot, electorate, recuse,
  attestation, fingerprint or "bound". Write for a volunteer (`design_styles/WRITING_STYLE_GUIDE.md`):
  sentence case, NZ English, the record title first with the code as a
  muted suffix, every enum through a labels map, a short hint for any term
  that needs explaining, "Not available" instead of a green 0 when data is
  missing, a confirm dialog that states the effect for consequential
  actions, and visible text saying why something is blocked and who can
  unblock it. Server-generated titles, flash and validation messages
  count as UI copy.
- **A global "Live"/sync chip in the top bar** — removed from the
  approved shell (2026-09-05); live/sync status belongs on the page
  surfaces that need it, not in the global chrome.
- **Off-palette page grounds** (corrected 2026-10-20: the grey ground and
  then the lavender option "felt dated") — the light `--background` is
  Slate `#F8FAFC` with white cards on top, and dark is `#0F172A` with
  `#1E293B` cards ("The Slate look" above). Don't bring back the
  `#dcdde0` mid-grey, violet/lavender-tinted grounds, warm paper, a
  charcoal ground, or a hardcoded per-page ground.
- **Chrome that flips between modes** (corrected 2026-10-20) — a sidebar
  or top bar that is the card colour in one mode and darker than the page
  in the other. The nav and top bar are the same slate-800 `#1E293B` in
  both modes.
- **Muted text on secondary surfaces below 4.5:1** (found 2026-10-20) —
  slate-500 `#64748B` on `#F1F5F9` measures 4.34:1. Muted text is
  `--muted-foreground` (slate-600 `#475569`), never a lighter slate.
- **Mockups or pop-ups with plain browser date / time boxes** (caught by
  Stephan 2026-10-19) — operational dates and times use the approved
  calendar and clock pickers (`POPUP_STYLE_GUIDE.md`); the 240 `date`, 58
  `datetime-local` and 28 `time` inputs still in the code are migration
  targets, not precedents. A mockup set is incomplete without the
  picker states (canvas page 4). The ESLint guardrail lands with that
  migration: a rule today would break the zero-warning lint gate on
  326 existing inputs.
- **Invented pop-up widths** (caught 2026-10-19) — the only pop-up widths
  are 480 / 720 / 900 / 1100 (wizards 1100). A mockup proposed 440 / 600 /
  760 and had to be redrawn; more than 20 widths exist in the code today.
- **Harmless confirms drawn in red** (found 2026-10-19) — `ConfirmDialog`
  defaults to the destructive tone, so 34 of 53 eMAR confirms (e.g.
  "Resolve chart alert") look like delete warnings. Pass `variant`
  explicitly; red is only for actions that remove or can't be undone.
- **Stacked chrome-to-content gutters** — the app shell owns ONE 20px
  gutter between the ink chrome (sidebar + top bar) and page content
  (`p-5` in `app-sidebar-layout.tsx`; approved 2026-09-05, replacing
  a 56/64px stack of layout + PageLayout padding; 10px was tried and
  revised up the same day). Never re-pad it: no
  outer `p-*`/`px-*`/`py-*` on a page's root wrapper, no `padding`
  value on `PageLayout` inside the shell (its default is `none`), no
  `contentClassName` overrides that change the gutter. Surfaces outside
  the shell (marketing, staff, auth) are exempt.
- **Off-scale gaps between sections and cards** — the 20px rule
  (approved 2026-09-05) also governs the space BETWEEN things on a
  shell page: section-to-section stacks (hero → tabs → content —
  `PageLayout`'s `gap-5` is the enforcement point) and gaps between
  cards in a grid/list are `gap-5` (20px), not `gap-4`/`gap-6`/
  `gap-[15px]`/`space-y-6`. Spacing INSIDE a card (its own
  padding and micro-layout) is unaffected. Existing pages migrate via
  conformance sweep probe 17.
- **Page-scoped token forks** (corrected 2026-10-13, role Overview) — a
  feature stylesheet that redefines `--background`, `--card`,
  `--foreground`, `--border`, the `--status-*` pairs or the font with hex
  values inside its own scope (the Overview shipped 50 of them). The page
  silently stops following theme, dark-mode and contrast fixes, and its
  status badges lose the verified pairs. A feature may add its own
  derived tokens (`--ov-soft: color-mix(… var(--primary) …)`), but core
  and status tokens come from `app.css`; a deliberate palette pilot is
  written as oklch tokens, documented here, and approved by Stephan.
  ESLint cannot see CSS, so check `*.css` files for `#` colours by hand.
- **Pixel font sizes in feature CSS** (corrected 2026-10-13, role
  Overview) — `font-size: 11px` ignores the user's text-size setting
  (`--base-font-size`, 13/14/16px), and 8–10px text was unreadable on the
  Overview. Use the typography helpers, or `calc(Nrem / 14)` in a
  stylesheet (N px at the default size), never below the `.text-caption`
  size (10.5px at default). Shared chrome too: the header meter labels
  were `text-[10px]` / `text-[9px]` and are now `text-xs` (2026-10-14).
- **Non-existent colour tokens** — utilities like `bg-warning/20` or
  `text-warning-foreground` reference a `warning` token that isn't in the
  `@theme` registry, so they silently render as nothing. The real tokens
  are `status-warning` / `status-warning-bg` / `status-warning-foreground`.
  If a utility's token isn't in `app.css` `@theme`, it doesn't exist.

---

- **Section captions inside a sidebar module** (corrected 2026-09-16:
  "the left nav bar has headings in them which is not easy to
  differentiate") — small uppercase captions ("Fleet", "Assets",
  "Payroll") splitting a module's sub-links into sections, in either the
  expanded rail or the collapsed-rail flyout. They read as more nav rows
  and compete with the links. Each module renders ONE continuous list of
  links (`flattenSidebarGroups` in `app-sidebar.tsx`), with no caption
  and no extra gap between the former sections; keep the group builders
  for ordering only. The module header rows themselves stay.
- **Sidebar rail that snaps to the top on every click** (corrected
  2026-09-16) — pages render their layout inline, so the shell remounts on
  each Inertia visit and a fresh scroll container starts at offset 0.
  `preserveScroll` on the links does not cover this. The rail's offset is
  remembered across mounts (`persistScrollTop`/`readStoredScrollTop` in
  `app-sidebar.tsx`) and restored in a layout effect; any new scrollable
  chrome that survives a visit needs the same treatment.
- **Browser `prompt()` / `confirm()` as a form** (corrected 2026-09-16,
  Finance) — collecting a reference, a reason or any other evidence through
  a chain of `window.prompt()` calls. There is no validation, no labels, no
  cancel semantics past the first step, and the sequence aborts silently
  half-way. Evidence and references are collected in a dialog with real
  fields and validation; the gate itself is
  `components/confirm-dialog.tsx`.
- **Hero numbers repeated as body KPI cards** (corrected 2026-09-16,
  Finance) — the same figures rendered in the header meter row and again in
  a card grid below it (and, on one page, a third time in a table footer).
  A number lives once, in the meter row, where it links to the list it
  came from. Residual in-body stats use `components/ops-stat-card.tsx`.
- **Page-local counts labelled as totals** (corrected 2026-09-16, Finance)
  — meter or stat blocks reading "Posted (this page)" / "Active (this
  page)", counted from the current page of a paginated list. Either the
  controller returns the real total for the current filter, or the block
  goes; a label that admits the number is wrong is not a fix.
- **Approximating an approved mockup** (corrected 2026-09-23, PKG-02B
  vehicle profile) — an approved design is built "in spirit": sections
  re-laid-out, tables turned into tiles, extra cards added, or whole views
  left as restyled legacy "interim" content. Every
  approved view is built to the mockup, section for section. Before
  calling a UI change done, open the mockup and the build side by side at
  the same width and walk every view, then record the comparison. Views
  not yet built stay unreleased; don't substitute interim content for them.
- **Module calendars that fork the shared calendar chrome** (corrected
  2026-09-24, PKG-02B vehicle calendar) — a module calendar that rebuilds
  its own header, date anchor, filter row, source legend, view switcher,
  Today rail or entry menus instead of using
  `pages/sites/calendar/_parts.tsx` (`CalendarContextMenu`,
  `CalendarSourcePills`, `JumpToDate`, `TodayRail`, the shared views) inside
  `PageLayout`. A module calendar supplies its data through an adapter and
  adds its own actions to the shared menus; it never ships a second menu or
  legend style. `design_styles/CALENDAR_STYLE_GUIDE.md` is the checklist.
  ESLint refuses new `@fullcalendar/*` imports outside the listed legacy
  migration targets.
- **Dropping approved context actions** (corrected 2026-09-24, PKG-02B
  vehicle calendar) — shipping a calendar or list with only some of the
  approved right-click and kebab actions. Every context action in the
  approved design is built with a real backend and offered the same way
  everywhere: right-click, the entry's actions button, the keyboard
  (context-menu key or Shift+F10) and a long press on touch. Destructive
  items use the destructive tone and offer Undo where a compensating action
  exists. Actions with no backend are hidden, never stubbed, and are listed
  in the handoff.
- **Settings pages that copy Fleet's parts but not its structure**
  (corrected four times, 2026-09-29, eMAR P11 Settings v1–v3). Stephan
  rejected settings built as option-list cards, prose tables, plain lists
  or static info cards. Settings follow `pages/fleet-assets/settings/`:
  - one PageHeader with a short rail, sub-tabs, and a sticky save bar
    ("Review changes") with an unsaved-draft guard;
  - every view opens on an **Overview** of `ReviewCard`s, each with a
    "Review … ↗" `<Button variant="link">`;
  - settings sit in **titled groups** (icon plus caption) of compact rows;
  - every on/off choice is a `Switch` showing its On/Off word; a segmented
    control is used only for three or more real options, or for a choice
    between two named modes that isn't on/off (e.g. "Block | Co-signer with
    witness PIN"), shown under the `Switch` that turns the setting on;
  - anything that notifies people gets **In-app / Email** switch columns
    (plus Push where it applies) and a separate delivery or channels tab
    with real controls, never badge-only cards;
  - dialogs use the Fleet `Modal` layout, `WizardShell` or `ConfirmDialog`;
  - a change that loosens a safety check is flagged on review, and its save
    uses the destructive variant.

  Compare every view with the live Fleet Settings at 1440 px before
  calling it done.
- **Tap targets sized in rem** (corrected 2026-09-30, eMAR P01 v2 Q-v2-1).
  The root font is `--base-font-size`: 14 px by default, 13 or 16 px by
  user preference. So `2.75rem`, `min-h-11` and `h-11` render at 38.5 px,
  not 44 px. `.frontline-tap` had this bug until it was changed to
  `max(44px, 2.75rem)`. Give frontline controls `.frontline-tap` rather
  than a Tailwind size class. In dense desktop layouts (tables, pagination,
  tab strips) use `.frontline-hit`: an invisible 44 px target around a
  control that keeps its size. Keep 44 px between the centres of
  neighbouring targets, and keep the area inside any `overflow: hidden`
  ancestor. Check any tap target by measuring the rendered px
  (`getBoundingClientRect`, or `elementFromPoint` 21 px from the centre
  for `.frontline-hit`), not by its class name or a "44px" comment.
  The same 14 px root put the dialog ✕ (`right-4` = 14 px) so its 44 px
  area ended exactly on the dialog edge, and every scrolling dialog showed
  a 1 px sideways scrollbar (fixed app-wide 2026-10-24, Stephan: the ✕ now
  sits at 16 px). Keep a `.frontline-hit` control's area at least 2 px
  inside a scrolling container's edge.
  Guardrail: `design/no-rem-tap-floor` rejects `min-h-11` / `min-w-11` in
  the medication screens (`pages/emar`, `components/emar`, `pages/meds`;
  EA-210, 2026-10-10).
- **Personal contact details copied into work fields** (corrected
  2026-10-02, Settings › Profile). A generic "Phone number" field saved the
  user's own number and also copied it into the HR work phone, which the
  My HR directory shows to every staff member. Self-service contact fields
  say whose number they are ("Personal mobile"), state who can see them, and
  never write HR's work contact fields (`work_phone`, `work_email`) or fall
  back to them on read-back. The sign-in email can be personal too, so it is
  not copied into `work_email` either — not by Settings, System › Users or
  the staff edit form. Work contact details are edited only on HR's screens
  (HR People › Add employee and › Edit, which shows the sign-in email
  read-only and labelled "Sign-in email"), with HR's permissions, and the HR
  CSV import writes them only from its `work_email` column. Staff-facing directories and contact cards show
  `work_email` only: someone without one shows no email, never the sign-in
  address as a fallback (2026-10-02). "No work email" is a real state
  (`work_email` is nullable): a recruitment hire whose offer has no work
  email signs in with their personal address but gets no work email, and
  any page showing `work_email` renders nothing when it is null.
- **Bare `<Deferred>` from `@inertiajs/react`** (decided 2026-10-30 with the
  background-loading work). Inertia's own `<Deferred>` shows its fallback
  forever when the load fails, and drops back to the fallback on every full
  refresh (My Day refreshes every 60 s). Use `<DeferredPanel>` (see "Skeleton
  vs loader"). ESLint blocks importing `Deferred` from `@inertiajs/react`.

- **A conversation buried under its own chrome** (corrected 2026-10-22,
  Chat: "the UI looks really bad"). On a 1440 × 900 screen the family
  conversation gave its messages 191 px: a privacy banner, an owner row
  with three buttons, a reply-status row and a floating Callbacks button
  stacked above them, a 200 px composer below, and each one-line message
  took about 150 px (name row, bordered bubble, time row, an always-shown
  ⋯ row). Follow "Chat — the Chat v3 boards": one slim context bar, one
  notice slot, grouped bubbles with inline times, a one-line composer.
- **A pop-up for every message action** (corrected 2026-10-22, Chat).
  Reacting, replying, pinning and saving each opened a 480 px dialog of
  outline buttons. Message actions are a hover bar plus one menu
  (⋯, right-click, Shift+F10) fed by one `MenuItem` set; pop-ups are for
  work that needs a form (share, turn into a task, report a concern).
- **A fake emoji button** (corrected 2026-10-22, Chat: "limited
  emoticons"). The composer's smiley appended " 🙂" and the only
  reaction was 👍. An emoji control opens the shared emoji picker (search,
  categories, recent, skin tones); reactions take any emoji, with a quick
  bar of six.
- **Blanket descendant tap sizes in chat dialogs** — `[&_button]:min-h-[44px]`
  on a dialog (non-negotiable 5) turned every control, including
  switches, into 44 px blocks on a desktop-only screen. ESLint
  `design/no-blanket-descendant-size` checks the chat folders; the three
  existing chat dialogs are rebuilt in the Chat rebuild.

## File viewing and downloading (approved by Stephan 2026-09-27)

Use the shared `FilePreviewDialog` in `components/files/file-preview-dialog.tsx`
for an in-context PDF or image. A metadata cover is not a file preview and an
"Available" badge must not promise bytes that cannot be retrieved. Reuse its
page navigation, zoom, accessible page text, loading/error/retry states and
original-file Download action. Preserve the exact file/version identity.
Unsupported formats offer a labelled download rather than a false preview.
The source module rechecks access and file state on every request; the dialog
does not supply authorization or certify a malware check. Full contract:
`design_styles/FILE_PREVIEW_STYLE_GUIDE.md` and the popup guide's file viewer rule.

## Conformance sweep (run on request)

When asked to "check everything conforms", audit the codebase against this
file. Concrete, mechanically-checkable probes:

1. **Legacy wizards** — any import of `components/wizard-stepper.tsx`
   and any routed full-page create/edit wizard. Target state: a
   `WizardShell` add/edit dialog on the index page, used for both add and
   edit.
2. **Bespoke multi-step dialogs** — dialogs with their own step state that
   don't import `components/wizard/shell.tsx`. Also audit the parallel
   wizard helper modules (`components/wizard/primitives.tsx`,
   `components/hr/wizard.ts`, `components/finance/wizard.ts`) — they must
   wrap/re-export the shell, not fork its chrome.
3. **Add/edit flows for entity records** still using simple dialogs or plain
   pages when the record has 2+ sections of fields.
4. **Raw palette classes / inline hex** — ESLint catches new ones; sweep for
   grandfathered ones, plus the places ESLint can't see: hex in
   `style={{…}}` props and in CSS rules (known: `.icon-gradient-bg` in
   `app.css` — migrate to token-based `color-mix` or drop it).
5. **Hand-rolled status pills** — spans styled with `status-*` tokens that
   should be `<StatusBadge>`, and duplicated tone maps (the known
   `BADGE_TONE` offender left with the client profile hero, deleted
   2026-09-07).
6. **Ad-hoc heading sizes** — `text-2xl`/`text-xl font-semibold` composed
   inline instead of the typography helpers.
7. **`dark:` colour pairs** on token-styled elements.
8. **Safe-area sprinkles** — raw `env(safe-area-inset-*)` outside the
   helpers in `app.css`.
9. **Tab strips** — (a) two-tier nav conformance per
   `design_styles/NAVIGATION_STYLE_GUIDE.md`: `GroupPillRail` (Rule 1
   connected tab) and `TierTwoTabs` (Rule 2 toned strip) were restyled and
   `--tone-teal` added 2026-09-05; the rostering `TabStrip` still needs
   its tones aligned to the positional cycle. Flag hero rails still using
   white-pill active states, carded/underline-only sub-strips,
   hand-picked tones, or toned inactive states. (b) pages whose main view tabs still render below
   the hero as tinted-pill strips — migrate to the
   connected-tab hero rail (reference: `PageHeaderRail` in
   `components/page/page-header.tsx`).
10. **Nav counters** — count/warning pills that don't follow their tab's
    state colour. (The known `WarningPill onHero` offender in
    `grouped-profile-nav.tsx` — `text-primary-foreground` on the active
    pill plus the ghost `warning` token — was fixed 2026-09-05; it now
    uses the verified `status-warning-bg`/`status-warning` pair in all
    states.)
11. **Ghost tokens** — grep utility classes against the `@theme` registry
    in `app.css`; any `bg-*/text-*/border-*` naming a token that isn't
    registered renders as nothing and must be re-pointed at a real token.
12. **Page header coverage** — enumerate index and record show/profile
    routes and flag any whose page top doesn't follow the Event
    Horizon contract (`design_styles/PAGE_HEADER_STYLE_GUIDE.md`):
    legacy `PageHero` usages, hand-rolled banners, missing header
    slots, search/filter bars below the header, list pages with
    sub-bars, group navs whose counters disappear when active.
13. **Button conformance** — apply the soft-depth spec
    (`design_styles/BUTTON_STYLE_GUIDE.md`): (a) update `buttonVariants`
    `default`/`outline` in `components/ui/button.tsx` if not yet done;
    (b) hand-rolled primary/secondary buttons (raw elements styled with
    `bg-primary` / `border bg-card`); (c) `className` overrides on
    default/outline `<Button>`s that fight the treatment; (d) `unstyled`
    usages reimplementing a standard button look.
14. **Loader conformance** — apply
    `design_styles/LOADER_STYLE_GUIDE.md`: (a) implement the loader
    (keyframes/classes in `app.css` incl. `html.reduce-motion`
    neutralisation, `components/ui/oblivion-loader.tsx`, wire into
    `loading-state.tsx`); (b) migrate ad-hoc `animate-spin`/`Loader2`
    page- and section-loading spinners to `<LoadingState>`; (c) switch
    inline/button spinners to the ring-only variant; (d) flag the full
    animated word at inline sizes and any hardcoded hues.
15. **Tables, pagination & scroll surfaces** — hand-rolled `<table>`
    styling that bypasses `components/ui/table.tsx`; paginated lists not
    using `laravel-pagination.tsx`; wide tables without their own
    `overflow-x-auto` wrapper; scrollbar utilities other than
    `.scrollbar-pretty` on new scroll areas (`.nice-scroll` usages are
    consolidation targets).
16. **Icons** — non-lucide icon usages (other sets, inline SVGs
    duplicating lucide glyphs, emoji-as-icon); icon-only buttons/links
    missing `aria-label`; off-scale icon sizes in standard chrome.
17. **20px spacing rule** (approved 2026-09-05) — on app-shell pages:
    (a) anything re-padding the shell's single `p-5` gutter (outer
    padding on page root wrappers, `PageLayout padding=` values inside
    the shell, `contentClassName` gutter overrides); (b) off-scale gaps
    between sections or between cards (`gap-4`, `gap-6`+, `gap-[Npx]`,
    `space-y-*` on section stacks and card grids/lists — should be
    `gap-5`). Card-internal spacing is out of scope. Migrate one
    module at a time.
18. **List contract coverage** (approved 2026-09-06) — enumerate
    listable-entity index views and flag any not on the
    `design_styles/LIST_STYLE_GUIDE.md` contracts: hand-rolled entity
    cards, per-page table styling or invented cells, cards/rows
    missing the kebab or right-click context menu (or with diverged
    `MenuItem` sets between the two), dishonest empty states, and any
    remaining readiness/onboarding displays.
19. **Attachment and selector coverage** (approved by Stephan 2026-09-20) —
    flag plain visible file inputs or bespoke upload chrome for document /
    invoice / evidence fields instead of the shared premium pattern; missing
    type/size/count guidance or false "uploaded" states; and growing record
    directories rendered as unsearchable selects. Check keyboard access,
    error/retry/selection retention and scoped search against
    `design_styles/POPUP_STYLE_GUIDE.md`. These are conformance targets,
    not a claim that every existing caller already meets them.
20. **Planning-date consistency** (approved by Stephan 2026-09-20) — check
    operational date/range forms against the shared calendar-selection pattern,
    visible range summary, module-specific required/optional states, separate
    times/timezone where applicable, error focus and retained entries in
    `design_styles/POPUP_STYLE_GUIDE.md`. Flag copied HR policy, silent date
    shifts and a calendar selection presented as a confirmed booking.
21. **Time-entry consistency** (approved by Stephan 2026-09-20) — check operational
    time fields for clock and manual entry, exact-minute retention, correct
    noon/midnight conversion, labelled keyboard controls, invalid-input feedback,
    Cancel/Apply and picker-first Escape/focus. Check timezone/interval rules,
    Review/Back and failed-save retention, and a visible action footer against
    `design_styles/POPUP_STYLE_GUIDE.md`. A styled picker does not prove DST,
    persistence or scheduling conformance, or authorise a shared-component edit.
22. **Work-record detail consistency** (approved by Stephan 2026-09-20) —
    check suitable owned-action detail pages against
    `design_styles/WORK_RECORD_STYLE_GUIDE.md`: stable identity/source links,
    summary/action hierarchy, compact recoverable notes, accountable ownership,
    module-owned progress and an obvious guarded completion path. Flag copied
    domain statuses, forced Ticket terminology, hidden essential blockers and
    parallel task/evidence identities. This is not a bulk migration instruction.

23. **File-viewer consistency** (approved by Stephan 2026-09-27) — check that
    View opens real content, Download returns the original permitted version,
    long names remain identifiable, and missing/blocked/unsupported files,
    expired access, slow loading and retry have truthful outcomes. Check
    keyboard focus, text alternatives, bounded zoom/page controls, request
    cancellation and private response headers against
    `design_styles/FILE_PREVIEW_STYLE_GUIDE.md`. Mockup fixtures must stay
    labelled; they are not production document storage.

Report findings grouped by pattern with file:line references; fix only when
asked, and migrate one pattern at a time.

## Keeping this document alive

This file only works if it reflects reality. The loop:

1. When a design mistake is corrected **twice**, name it in *Named
   anti-patterns* above (one line, why it's wrong, what to do instead).
2. If the mistake is mechanically detectable, also encode it as an ESLint
   `no-restricted-syntax` rule in `eslint.config.js` — the rule's message
   should point back here.
3. New tokens/primitives: add to `app.css` / `components/ui/`, document in
   `design_styles/DESIGN_TOKENS.md`, and add a one-line pointer here if it's a
   pattern others will reach for.
4. Keep this file short. Detail belongs in the linked guides; this is the
   map, not the territory.

## Deeper guides

- `design_styles/DESIGN_TOKENS.md` — full token reference, charts, adding tokens
- `design_styles/APP_SHELL_STYLE_GUIDE.md` — the global shell: Slate header + sidebar chrome, collapse behaviour, page ground
- `design_styles/PAGE_KIT_STYLE_GUIDE.md` — the page kit: 13 templates, 6 layouts, the blocks (incl. the Oblivion blocks), tabs, rows, long pages, states, the new-module checklist
- `design_styles/POPUP_STYLE_GUIDE.md` — the pop-up kit (8 types, 21 blocks, 4 widths), dialog anatomy, dropdowns and searchable selectors
- `design_styles/WRITING_STYLE_GUIDE.md` — one way to write dates, times, money, case, button verbs and labels
- `design_styles/CALENDAR_STYLE_GUIDE.md` — calendars and date selection
- `design_styles/FILE_PREVIEW_STYLE_GUIDE.md` — file preview and download
- `design_styles/MAP_GEOFENCING_STYLE_GUIDE.md` — maps, boundaries and geofencing
- `design_styles/WORK_RECORD_STYLE_GUIDE.md` — ticket-style work/issue detail hierarchy with module-owned terminology and lifecycle
- `design_styles/BUTTON_STYLE_GUIDE.md` — the soft-depth button spec (primary/outline)
- `design_styles/NAVIGATION_STYLE_GUIDE.md` — two-tier section nav (connected tab + toned strip)
- `design_styles/LOADER_STYLE_GUIDE.md` — the Event Horizon brand loader (+ ring-only inline variant)
- `design_styles/PAGE_HEADER_STYLE_GUIDE.md` — the Event Horizon page header (index + profile variants, in-header search/filters, rail, sub nav)
- `design_styles/LIST_STYLE_GUIDE.md` — the Event Horizon list contracts (entity card grid + entity table, cell library, kebab/context-menu rules)
- `resources/css/app.css` — the tokens themselves (source of truth)
- `resources/js/lib/status-colors.ts` — status → class map
- `resources/js/lib/derive-palette.ts` — brand colour → derived palette
