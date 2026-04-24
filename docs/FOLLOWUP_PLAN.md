# Follow-up Plan — Appearance/Branding/UI Overhaul

Self-contained brief for a fresh session. Resolves the outstanding work
from the 5-phase Appearance/Branding/UI overhaul (merged to `main` as
commits `bd8aef4` and `a693578`) plus pre-existing items and net-new
scope items worth shipping.

## Context — what already shipped

The app's theming + branding + notifications + landing-page stack is
live on `main`. Semantic-token system under [resources/css/app.css](../resources/css/app.css)
plus a `derivePalette()` util at [resources/js/lib/derive-palette.ts](../resources/js/lib/derive-palette.ts)
drive brand changes throughout the app. See [DESIGN_TOKENS.md](DESIGN_TOKENS.md)
for the token taxonomy.

Hardcoded-colour count went from 2,586 → 113 (22 in intentional
exceptions). An ESLint `no-restricted-syntax` guardrail blocks new
regressions. Full details in the merge commits.

This plan addresses everything that was out of scope or deferred.

---

## Part 1 — Outstanding deferred work (in-scope)

### 1.1 Remaining ~91 hardcoded colour classes

**Where they live (top files):**
- `resources/js/pages/welcome.tsx` — marketing gradients
- `resources/js/pages/home.tsx` — landing page gradients
- `resources/js/pages/health-safety/dashboard.tsx` — risk heatmap hex colours
- `resources/js/pages/fleet-assets/dashboard.tsx` — gauge colours
- `resources/js/pages/control-room/map.tsx` — Leaflet marker colours
- `resources/js/pages/operations/clients/show.tsx` — ~10 residuals
- `resources/js/pages/sites/show.tsx` — site-type badges
- Several hr/* pages with per-record colour coding

**Why deferred:** These are per-pixel visual design decisions (chart
heatmap grading, marker disambiguation, marketing gradient aesthetics)
that don't cleanly map onto the 5-severity-token model.

**Approach for resolution:**

1. Find them:
   ```bash
   grep -rEn "(bg|text|border|ring|from|to|via)-(red|rose|pink|emerald|green|lime|amber|yellow|orange|blue|sky|cyan|teal|gray|zinc|slate|neutral|stone|violet|indigo|purple|fuchsia)-[0-9]+" \
       resources/js/pages resources/js/components
   ```

2. Classify each occurrence per these rules:
   - **Chart / heatmap / data viz** → swap to `var(--chart-1)`..`var(--chart-5)`. If you need more than 5 distinct series, extend `derivePalette.ts` to generate chart-6 through chart-10 via further hue rotations.
   - **Map markers / Leaflet pins** → build a fixed 6–8 colour palette in `resources/js/lib/map-markers.ts` with an inline `/* eslint-disable no-restricted-syntax */` block and a comment explaining why (Leaflet's external JS layer can't read CSS custom properties cleanly).
   - **Marketing page gradients** (welcome, home) → replace `from-violet-50 to-pink-50`-style gradients with `bg-primary/10` (already the behaviour for most). For multi-stop marketing gradients, convert to solid `bg-primary/5` or a two-colour tint using `from-primary/10 to-primary/5`.
   - **Per-record colour coding** (e.g. each site type has its own colour) → replace with `bg-category-*` tokens (ops/hr/compliance/incidents/governance/sites/fleet) if the category fits; otherwise add a new category token to `app.css` and document it in `DESIGN_TOKENS.md`.

3. Verify: rebuild (`npx vite build`) and spot-check each touched page via Chrome Preview.

**Files to create/modify:**
- `resources/js/lib/map-markers.ts` (new, if adopting the marker palette approach)
- `resources/css/app.css` (extend `--chart-*` if needed)
- `docs/DESIGN_TOKENS.md` (document any new category tokens added)
- The individual page files that hold the residuals

**Expected effort:** 2–3 hours.

---

### 1.2 `<button>` → `<Button>` targeted conversion

**Scope:** 336 raw `<button>` elements across ~50 files. Not a mechanical
rewrite — most are intentional custom layouts. The goal is converting
the ~80–100 that are obviously action buttons without breaking the rest.

**Approach:**

1. Identify the safe conversion targets:
   ```bash
   # Icon-only close/trash/menu buttons with no complex layout
   grep -rEn '<button[^>]*className="[^"]*(h-[678]|size-[678])[^"]*"[^>]*>\s*<\w+\s+className="[^"]*"\s*/>\s*</button>' \
       resources/js
   ```

2. Convert each match to `<Button variant="ghost" size="icon" onClick={…}>`. Preserve any `aria-label` / `title` attributes.

3. For text-only action buttons (e.g. "Delete", "Cancel" buttons built from raw `<button>`):
   - If destructive styling (`bg-destructive`, `bg-status-critical`, red tones) → `<Button variant="destructive">`
   - If outlined → `<Button variant="outline">`
   - If ghost (transparent with hover) → `<Button variant="ghost">`
   - If primary solid → `<Button>` (default variant)

4. **Do NOT convert:**
   - Option-card buttons with preview artwork inside (appearance.tsx theme cards, branding.tsx preset swatches)
   - Full-width row buttons with avatars + metadata (portal/messages.tsx conversation rows)
   - Carousel navigation arrows
   - Buttons inside custom Radix wrappers

5. Lint rule to surface new regressions:
   Add to [eslint.config.js](../eslint.config.js) `no-restricted-syntax`:
   ```js
   {
     selector: "JSXElement > JSXOpeningElement[name.name='button']:has(JSXAttribute[name.name='onClick'])",
     message: "Consider <Button> from @/components/ui/button. If the raw <button> is intentional (custom layout / selector card), add an inline disable comment with reason."
   }
   ```
   Set at `warn` level so existing legitimate raw buttons don't block builds.

**Files to modify:**
- [eslint.config.js](../eslint.config.js) — new rule
- Target files identified in step 1

**Expected effort:** 3–4 hours for a thorough pass, or 1 hour for just the icon-only conversions.

---

### 1.3 Raw `<div>` → `<Card>` sweep (~50 instances)

**Find:**
```bash
grep -rEn '<div[^>]*className="[^"]*rounded-(lg|xl|md)[^"]*border[^"]*(bg-card|bg-white|bg-background)' \
    resources/js/pages resources/js/components
```

**Rules:**
- Plain `<div className="rounded-lg border bg-card p-4">…</div>` → `<Card>…</Card>` (use `CardHeader` / `CardContent` if the content has structure).
- Don't convert divs inside `<Card>` already (double-wrap).
- Don't convert divs that use `rounded-full` (chips/pills).
- Don't convert divs with non-standard padding/shadow combinations that wouldn't survive the `<Card>` defaults — leave those as raw divs with a brief `/* intentional */` comment.

**Expected effort:** 2 hours.

---

### 1.4 Branding page full rewrite with tabs

**Current state:** [resources/js/pages/settings/branding.tsx](../resources/js/pages/settings/branding.tsx)
is ~1,100 lines in a single flat form. Phase 4 added the Essentials
card on top, but the page is still dense.

**Target structure:**

```
<Tabs defaultValue="essentials">
  <TabsList>
    <TabsTrigger value="essentials">Essentials</TabsTrigger>
    <TabsTrigger value="advanced">Advanced</TabsTrigger>
    <TabsTrigger value="terminology">Terminology</TabsTrigger>
    <TabsTrigger value="email-reports">Email & Reports</TabsTrigger>
  </TabsList>
  <TabsContent value="essentials">
    {/* Brand colour picker + radius slider + logo/favicon + name/tagline */}
  </TabsContent>
  <TabsContent value="advanced">
    {/* Individual CSS variable editors (moved from current mid-page) */}
  </TabsContent>
  <TabsContent value="terminology">
    {/* Existing terminology form */}
  </TabsContent>
  <TabsContent value="email-reports">
    {/* Email header colour, footer text, report font/logo position */}
  </TabsContent>
</Tabs>
```

**Approach:**
1. Extract each current section of `branding.tsx` into a separate component file under `resources/js/pages/settings/branding/`:
   - `_essentials-tab.tsx`
   - `_advanced-tab.tsx`
   - `_terminology-tab.tsx`
   - `_email-reports-tab.tsx`
2. Parent `branding.tsx` becomes a ~150-line file that wires `useForm` + terminology `useForm` and delegates to tabs.
3. Share form state via props — each tab reads/writes into the same form object.
4. Save button in a sticky footer bar (visible regardless of active tab).

**Files:**
- `resources/js/pages/settings/branding.tsx` (rewrite → thin orchestrator)
- `resources/js/pages/settings/branding/_essentials-tab.tsx` (new)
- `resources/js/pages/settings/branding/_advanced-tab.tsx` (new)
- `resources/js/pages/settings/branding/_terminology-tab.tsx` (new)
- `resources/js/pages/settings/branding/_email-reports-tab.tsx` (new)

**Expected effort:** 4–5 hours.

---

### 1.5 SMS + push provider wiring

**Current state:** [DeliverBroadcastCommunicationJob](../app/Jobs/Notifications/DeliverBroadcastCommunicationJob.php)
fails gracefully with `status='failed'` and reason "SMS provider not
configured" / "Push provider not configured". The plumbing exists, the
transport doesn't.

**Approach (SMS):**

Most NZ providers are Twilio, MessageMedia, or ClickSend. Twilio is the
most common. Steps:

1. `composer require twilio/sdk`
2. Create `app/Services/Notifications/SmsProvider.php` interface:
   ```php
   interface SmsProvider {
       public function send(string $to, string $message): SmsSendResult;
   }
   ```
3. Create `app/Services/Notifications/TwilioSmsProvider.php` implementing the interface. Config values from `config('services.sms.twilio.*')` + `.env` entries:
   ```
   SMS_PROVIDER=twilio
   TWILIO_ACCOUNT_SID=...
   TWILIO_AUTH_TOKEN=...
   TWILIO_FROM=+64...
   ```
4. Register binding in `AppServiceProvider::register()`: switch on `config('services.sms.provider')` to resolve which implementation goes in the container.
5. Replace the stub in `DeliverBroadcastCommunicationJob::sendSms()` with an `app(SmsProvider::class)->send(...)` call.
6. Handle soft failures (invalid number, insufficient credit) by marking `status='failed'` with the provider's error. Hard failures (transport exception) let the job's retry kick in.

**Approach (push):**

Less critical — most web-push notifications overlap with in-app inbox.
If mobile native shells are in scope, use Expo's push service or
Firebase Cloud Messaging. Same provider-interface pattern as SMS.

**Testing:**
- Unit test: mock `SmsProvider` → assert the job calls `->send()` with the right args and updates status.
- Manual: send a broadcast to a test phone number.

**Files:**
- `app/Services/Notifications/SmsProvider.php` (new interface)
- `app/Services/Notifications/TwilioSmsProvider.php` (new)
- `app/Providers/AppServiceProvider.php` (bindings)
- `config/services.php` (config block)
- `.env.example` (documented env vars)
- `app/Jobs/Notifications/DeliverBroadcastCommunicationJob.php` (replace stub)
- `tests/Unit/DeliverBroadcastCommunicationJobTest.php` (new)

**Expected effort:** 6 hours for SMS end-to-end with tests. Push adds 4 more.

---

## Part 2 — Pre-existing items

### 2.1 14 failing unit tests

**Files with failing tests:**
- `tests/Unit/MedicationSafetyServiceTest.php`
- `tests/Unit/Operations/OperationalSnapshotServiceTest.php`
- Tests touching `app/Services/ShiftSafetyInvariantService.php`

**Symptoms seen:**
- `Mockery_3_App_Models_ClientMedication::administrations() must return HasMany, Mockery_5 returned` (Mockery type-hint mismatch)
- `Failed asserting that null is identical to 'Kauri House'` (snapshot test against expected relation data that isn't set up)
- `ValidationException` thrown when test expected normal return

**Approach:**

These failures were introduced in `00364a2` ("fix: Shifts backbone
audit remediation") — they're a drift between test mocks and the
production signature of the services.

1. Check out each failing test, run it alone:
   ```bash
   php artisan test --filter=MedicationSafetyServiceTest
   ```
2. Read the service signature vs the mock setup. For Mockery returning
   wrong type: update the mock to return the correct Eloquent relation
   instance (usually via `Mockery::mock(HasMany::class)` + `andReturn`).
3. For snapshot tests: check what the test sets up for relations —
   probably needs a `$client->setRelation('site', $site)` before the
   assertion.
4. For `ValidationException`: the service probably now validates input
   and throws — either the test data is invalid (fix test data) or the
   validation shouldn't apply to this case (fix service).

**Files:**
- Any of the three test files above
- Possibly the corresponding service if logic drifted

**Expected effort:** 2 hours total, maybe 3 if the service logic itself needs fixing.

---

### 2.2 Language localisation scaffolding

**Current state:** `Language` select on profile + appearance pages
shows "English (NZ)" disabled with "More coming soon". No Laravel
`lang/` directory, no Vue/React i18n library, no string catalog.

**Approach (minimum viable):**

1. Install `@inertiajs/react`-compatible i18n. Options:
   - `laravel-react-i18n` (tracks Laravel's `lang/` files server-side, syncs to client)
   - `i18next` (fully client-side, independent of Laravel)

   Recommendation: `laravel-react-i18n` — keeps server-rendered emails / PDFs using the same strings.

2. Scaffold:
   ```
   lang/
     en/
       app.php          # General UI strings
       validation.php   # Laravel default
       notifications.php
     mi/                # Māori — common in NZ support living
       app.php
       ...
   ```

3. Extract hardcoded English strings from UI into `lang/en/app.php` in batches. Start with:
   - Buttons (Save, Cancel, Edit, Delete, Create)
   - Common labels (Name, Email, Phone)
   - Status words (Active, Pending, Approved, Rejected)

4. Wire `__('app.save')` in Blade, `trans('app.save')` via `useTranslation()` hook in React.

5. Profile page language select: enable the dropdown, populate with available locales, wire to user's `locale` column (new migration).

**Expected effort:** 16+ hours for full rollout. For minimum-viable
(scaffolding + button strings), 4 hours.

---

### 2.3 Bundle size

**Current warning:** `app-*.js` is 508 KB (minified, before gzip).
Threshold is 500 KB — so it's marginal.

**Approach:**

1. Audit the biggest chunks with rollup-plugin-visualizer:
   ```bash
   npm i -D rollup-plugin-visualizer
   ```
   Add to `vite.config.ts` plugins: `visualizer({ open: true })`. Rebuild.

2. Likely culprits:
   - FullCalendar already split (vendor-calendar chunk)
   - Recharts already split (vendor-charts)
   - Leaflet + React-Leaflet already split (vendor-maps)
   - Radix primitives — each one is small but there are many
   - Lucide icons — `lucide-react` imports every icon by default in some setups

3. Fixes:
   - Lucide: verify the build is using per-icon imports (`import { X } from 'lucide-react'`) not `import * as Icons`.
   - Dynamic import heavy page components (`React.lazy`) — especially control-room/broadcast-show, operations/clients/show.
   - Move one-off third-party widgets behind `React.lazy` + `Suspense`.

**Expected effort:** 3 hours for analysis + the quick wins.

---

## Part 3 — New scope (worth doing later)

### 3.1 Visual regression baseline

**Goal:** Catch future token drift. If someone adds a hardcoded colour
in three months, the diff against baseline should surface it.

**Approach:**

1. Install `@playwright/test` (or reuse existing Dusk if it's set up):
   ```bash
   npm i -D @playwright/test
   npx playwright install chromium
   ```

2. Write `tests/visual/screenshot.spec.ts`:
   - Log in as admin
   - Navigate to: Dashboard, Operations, one Client show, Sites, HR, Control Room, each Settings page
   - `expect(page).toHaveScreenshot()` each one

3. Run baseline capture under the default theme (violet).

4. Rebrand to teal via tinker:
   ```php
   AppSetting::updateOrCreate(['key' => 'theme.light'], ['value' => derivePalette('#14b8a6')]);
   ```
   Re-run tests → expect diff on every page (confirms tokens propagate).

5. Revert, capture new baseline. CI runs on every PR; any visual diff
   fails the check.

**Files:**
- `package.json` (add Playwright)
- `playwright.config.ts` (new)
- `tests/visual/` directory
- `.github/workflows/visual.yml` (if GH Actions)

**Expected effort:** 6 hours for the initial suite, 1 hour to wire into CI.

---

### 3.2 Curated theme presets

**Goal:** Admins click "NZ Health Default" / "High Contrast" / "Warm" /
"Cool" — one click sets all the palette variables.

**Approach:**

1. Extend [derive-palette.ts](../resources/js/lib/derive-palette.ts)
   with a `PRESETS` map:
   ```ts
   export const BRAND_PRESETS = {
     'nz-health-default': { hex: '#7c3aed', label: 'NZ Health Default' },
     'high-contrast': { hex: '#000000', label: 'High Contrast', neutralTone: 'warm' },
     'warm': { hex: '#ea580c', label: 'Warm Orange' },
     'cool': { hex: '#0891b2', label: 'Cool Teal' },
     'forest': { hex: '#059669', label: 'Forest Green' },
   };
   ```
2. In the Branding page's Essentials tab, render a row of preset
   swatches above the colour picker. Clicking one calls
   `handleBrandChange(preset.hex)` — existing behaviour, zero new logic.
3. Also store the active preset key in `app_settings` so the Branding
   page highlights the currently-applied preset on load.

**Expected effort:** 1.5 hours.

---

### 3.3 Custom font support

**Goal:** Admins pick from 3–4 curated web fonts. Currently hardcoded
to Instrument Sans.

**Approach:**

1. Add to [app.css](../resources/css/app.css):
   ```css
   --font-sans-custom: var(--font-sans);  /* default = Instrument Sans */
   body { font-family: var(--font-sans-custom); }
   ```
2. Extend Branding Essentials tab with a font picker (3 options: Instrument Sans / Inter / Source Sans 3 / System).
3. On save, write `--font-sans-custom: 'Inter', …` into `theme.light`
   (or a new `branding.font` app_setting).
4. Ensure Google/Bunny Fonts loads the selected family — inline
   `<link>` in `app.blade.php` based on the setting.

**Expected effort:** 2 hours.

---

### 3.4 Dark-mode brand editing

**Goal:** Currently the light palette is derived and dark re-uses the
same hue. Some brands want darker / more saturated dark-mode accents.

**Approach:**

1. In Branding Essentials, add a "Dark mode hue shift" slider (-30° to +30°).
2. `derivePalette()` accepts an optional `darkHueShift` param and
   rotates `--primary` hue in the `dark` branch by that amount.
3. Save both `brand_colour_light` and `brand_colour_dark` derived
   palettes separately.

**Expected effort:** 2.5 hours.

---

### 3.5 `status_detail` surfacing in escalation history

**Goal:** Broadcast show page already displays `status_detail` per row.
The `/settings/notifications/escalations` history view doesn't — it
just shows counts. Admins can't see WHY a specific escalation failed.

**Approach:**

1. Extend the escalations history table to show a "Reason" column that
   displays `status_detail` (truncated with expand-on-hover).
2. Add a filter: "Only show skipped / failed" to help debug preference
   issues in bulk.

**Files:**
- `resources/js/pages/settings/notification-escalations.tsx`
- `app/Http/Controllers/Settings/NotificationEscalationsController.php` (maybe include the field in the response)

**Expected effort:** 2 hours.

---

## Execution order

Recommended priority if doing all of the above:

1. **2.1 — Fix 14 failing tests** (2 hrs). Unblocks reliable CI.
2. **1.1 — Remaining 91 hardcoded colours** (3 hrs). Finishes the design-system story.
3. **1.5 — SMS provider wiring** (6 hrs). Completes the broadcast feature end-to-end.
4. **1.4 — Branding page rewrite with tabs** (5 hrs). Polishes the most-visible admin page.
5. **3.2 — Theme presets** (1.5 hrs). Quick win on top of 1.4.
6. **1.2 — Button sweep** (1–4 hrs depending on scope).
7. **3.1 — Visual regression baseline** (7 hrs). Locks in everything above.
8. **2.3 — Bundle size** (3 hrs). Perf.
9. **1.3 — Card sweep** (2 hrs). Minor cleanup.
10. **3.3, 3.4, 3.5** — Nice-to-have polish (~7 hrs total).
11. **2.2 — Full localisation** (16+ hrs). Own project.

**Total estimated effort if shipping everything:** ~55 hours.

**Fast-path "ship what moves the needle":** steps 1–5 (~17 hrs).

---

## Verification notes for the new session

- Main is at `a693578` on GitHub; branch `claude/musing-shamir-db6113` has the same tip.
- Run `npm run build` at the start — confirms the foundation is intact.
- `phpunit.xml` already has `memory_limit=512M`, so `php artisan test` runs to completion.
- Chrome Preview tooling was flaky in the prior session — fall back to `php artisan serve --port=<free-port>` + direct curl / browser navigation if needed.
- Admin login: `admin@demo.test` / `password`.
- Key architectural docs:
  - [docs/DESIGN_TOKENS.md](DESIGN_TOKENS.md) — token taxonomy + component conventions
  - [resources/js/lib/derive-palette.ts](../resources/js/lib/derive-palette.ts) — palette derivation
  - [resources/js/hooks/use-appearance.tsx](../resources/js/hooks/use-appearance.tsx) — live-apply hook
  - [config/landing_routes.php](../config/landing_routes.php) — landing page registry
  - [app/Http/Responses/LoginResponse.php](../app/Http/Responses/LoginResponse.php) — post-login redirect

## Things NOT in this plan (intentional exclusions)

- Full PWA / offline build-out (big project, separate discovery).
- Automated visual regression integration with external services (Percy, Chromatic) — the self-hosted Playwright approach in 3.1 is sufficient.
- Design-token contribution guide for external teams — internal-only for now.
- Removing the recruitment kanban's 22 hardcoded colours — explicitly
  marked as an intentional exception; collapsing its 12 distinct stages
  onto 5 severity tokens would degrade the UX.
