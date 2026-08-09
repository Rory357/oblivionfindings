# HR "Documents" (Documents, Templates, Signatures & Policies) Redesign — PROMPT

> One prompt for the whole job. Paste to the build agent (Claude design — it can do everything in the UI). Follows our `*_FIX_PROMPT.md` loop: work in small verifiable passes; after each pass run the app, screenshot `/hr/documents` (and each `?tab=` + every modal open state) **and** the connected surfaces (`/hr/people/{id}` → Documents, `/hr/my/documents`), and diff against the gold‑standard pages/components before continuing. Start with the audit in §A, then build §B–§L. **Anything you discover that needs backend/data work goes into §K "Backend handoff for Claude Code" — append to it as you go so Chane has one clean hand‑off list when the design is done.**

**Page:** `https://oblivionfindings.com/hr/documents` (manager/HR lens — the document hub)
**Frontend (hub):** `resources/js/pages/hr/documents/{index,templates,upload,create-template,edit-template}.tsx` · `resources/js/pages/hr/documents/policies/{index,create,edit,show,attestations}.tsx` · **tabs:** `resources/js/components/hr/documents-tabs.tsx`
**Frontend (staff file — the richest, foldered surface):** `resources/js/pages/hr/employees/documents.tsx` (1,319 lines, already has folders/folder counts/upload+edit forms)
**Frontend (employee self‑service):** `resources/js/pages/hr/my/documents.tsx` · e‑sign dialog `resources/js/components/hr/my-hr-esign-dialog.tsx` · sign page `resources/js/pages/hr/signatures/{pending,sign}.tsx`
**Backend:** `app/Http/Controllers/Hr/HrDocumentController.php` · `ESignatureController.php` · `PolicyController.php` · `PolicyAttestationController.php` · `MyHrController.php` (`documents` / `downloadDocument` / `signDocument`) · routes in `routes/hr.php` (documents `:475‑491`, policies `:448‑470`, signatures `:972‑983`, my‑docs `:113‑115`, people‑docs `:230‑242`)
**Engine:** `app/Domain/Hr/Services/HrDocumentMergeService.php` (template merge — **HTML‑only today**) · `app/Domain/Hr/Services/ESignatureService.php` (capture signature — **does not complete the loop today**)
**Models:** `HrDocument`, `HrDocumentTemplate`, `HrDocumentSignature`, `HrCandidateDocument`, `HrPolicy`, `HrPolicyVersion`, `HrPolicyAttestation` (all `app/Domain/Hr/Models/`)
**Migrations:** `2026_02_12_100009_create_hr_documents_tables.php` · `2026_03_22_200002_create_hr_document_signatures_table.php` · `2026_03_22_600004_add_expiry_to_hr_documents.php` · `2026_03_31_000002_add_folder_to_hr_documents.php`
**Cross‑loop consumers:** recruitment candidate docs (`HrCandidateDocument`, `CandidateController::storeDocument` — a **separate table** that never flows into the employee file) · offers (`HrOffer` → template generate) · onboarding/offboarding (`OnboardingService` — only **text** "archive documents" tasks, no real collection) · employee profile show (`resources/js/pages/hr/employees/show.tsx`).
**Gold‑standard modal to clone:** `resources/js/components/clients/add-client-dialog.tsx` (built on `resources/js/components/wizard/primitives.tsx`). **Premium quality bar to match:** the `/hr/leave` "New Request" modal `resources/js/components/hr/leave-request-dialog.tsx`.

---

## 0. Mission

Turn `/hr/documents` into a **premium, end‑to‑end HR Documents surface** that feels identical in quality to our gold‑standard pages — **`/hr/people`**, **`/hr/leave`**, **`/meds/today`**, **`/my-day`** — and reuses their exact components and tokens. This is the **organisation‑wide HR + manager view** of every people document: the **document library** (contracts, certificates, letters, policies, payslips‑adjacent paperwork), **templates** (merge → generate), the **e‑signature pipeline** (request → sign → filed signed artifact), and **policies & attestations**. The employee‑scoped views live on `/hr/my/documents` and on each `/hr/people/{id}` profile — don't duplicate them, but **do** unify them onto one shared foldered kit (§J), because they currently diverge.

Today `/hr/documents` is functional but dated and **thin**: **four standalone "old view" pages** masquerading as tabs (Documents / Policies / Signatures / Templates, wired by `documents-tabs.tsx` doing `router.visit`), a **generic `PageHero`** (not the golden band), a **flat table with no folders** (even though the staff‑profile and self‑service surfaces already have folders), **two thin dialogs** (Generate‑from‑template = 3 fields, no preview; Send‑for‑signature = a checkbox list, no message/due‑date/order/reminders), **full‑page** upload + template‑create + policy‑create routes (should be modals), **no right‑click menus**, **no bulk actions, no export, no inline viewer**, and — most importantly — a **broken signing loop** and a **mis‑scoped Signatures tab** (§A). Bring it to parity: give it the **golden HR hero band (no clock, fitted to documents)**, swap every create/edit/generate/send flow to the **exact Add‑Client wizard pattern at the Leave‑modal quality bar**, introduce **real premium tabs with right‑click menus (tabs + rows)**, fold the divergent surfaces onto **one foldered document kit**, stand up a **manager‑side signature tracking inbox**, and **complete the e‑signature + document‑generation loops end‑to‑end** (§K). Result: documents that are **accurate, glanceable, auditable and premium** — not four grey tables.

---

## 1. Non‑negotiables

1. **Introduce a real tab model.** The current four **separate Inertia pages** wired by `documents-tabs.tsx` are the "old views" Chane means — replace them with a proper in‑page **`DocumentsTabs`** shell (same tab language as `/hr/people`'s `HrTabs`), reflected in a `?tab=` query param, per‑tab counts as badges, **right‑click tab menu** (§I). The page gets a **standardised tab system**, not four route hops. **You propose the final tab set during the §A audit and get Chane's sign‑off before building** (recommended set in §C).
2. **One unified, foldered document kit** (Chane's decision). The thin flat hub table, the rich foldered staff‑profile page (`hr/employees/documents.tsx`) and the self‑service page (`hr/my/documents.tsx`) must share **one** document‑library component + **one** upload modal + **one** folder taxonomy. The hub gains folders/expiry/notes to match the profile. Never let the three drift again (§J).
3. **Complete the e‑signature loop end‑to‑end** (Chane's decision: **full audit‑grade**). Signing must flip the document's signed state, **burn the signature + an audit block into a generated signed PDF**, use a **real drawn signature pad** (not a typed name), and give managers a **sender‑side tracking inbox**. Today none of this happens (§A‑bugs, §E, §K).
4. **Generate real documents** (Chane's decision: **PDF + live preview**). "Generate from template" must produce a **downloadable PDF** with a **preview step** before saving — not the raw `.html` file it writes today (§G, §K).
5. **Reuse the kit — never hand‑roll a primitive we already have** (§2). Every hero, modal, badge, status colour, context menu, empty state, folder tile and toast comes from the shared kit. **No new bespoke widgets, no raw hex** (ESLint blocks it — colours come from design tokens).
6. **Web‑only desktop app.** No phone frames, **no clock** in the hero. Design for mouse + keyboard: hover states, **right‑click menus**, keyboard shortcuts. Responsive down to a small laptop is fine. (A dedicated mobile app comes later — not now.)
7. **Information‑gathering = modals.** Every upload/generate/send‑for‑signature/template‑edit/policy‑edit flow becomes a **wizard dialog** cloning the Add‑Client shell (§2.2 / §F), **not** an inline form and **not** a full‑page route. Reading a document's detail/history/preview may use a dialog/sheet/inline viewer.
8. **Locale & statute stay NZ.** NZD / `en-NZ` formatting and `en-NZ` dates. Document categories, retention and signing language stay NZ‑appropriate (Holidays Act / Privacy Act 2020 / Health & Disability sector record‑keeping). Do **not** switch to GBP/US formats.
9. **Respect scoping & permissions.** Everything tenant‑scoped via `ResolvesHrTenant`. Library view gated by `hr.documents.view`, manage by `hr.documents.manage`; policies by `hr.policies.{view,manage,attest}`; signatures by `hr.signatures.manage|hr.documents.manage`; profile docs by `hr.employees.{viewAny,manage}`. **Enforce `is_restricted`** on download (today the hub download checks only `hr.documents.view` — restricted docs aren't actually gated; §A‑bugs). Hide manager‑only UI when the user lacks the gate.
10. **Verify each pass:** clean `npm run build`, `npm run types` (no TS errors), `npm run lint`; screenshot the changed surface; confirm it matches the reference page's hero/modal/menu. Don't move on with a broken pass.

---

## A. Audit & benchmark first (do this before building)

Study `/hr/people`, `/hr/leave`, `/meds/today`, `/my-day` and **interact** with them — they are the parity bar. Then study the three patterns you must clone:

- **Golden hero** → `resources/js/components/hr/my-hr-hero.tsx` (the gradient band: `HERO_STYLE` brand‑gradient, `HeroStat` label+big tabular value clickable, `QuickAction` icon+label, the on‑gradient "needs you" chip + `NeedsDot` pattern) and `people-hero.tsx` (admin/manager lens, **no clock**, right‑rail toggle persisted to `localStorage`). **Documents follows the People hero shape (manager lens, no clock).** If a shared `resources/js/components/hr/hero-kit.tsx` exists by the time you build, build `DocumentsHero` on it; otherwise lift `HERO_STYLE`/`HeroStat`/`QuickAction` out of `my-hr-hero.tsx` into that shared kit so My HR, People, Leave and Documents share one hero spine (the standardisation win). Note: `hr-hero.tsx` today is only a thin `PageHero` wrapper defaulting `category="hr"` — that is the generic fallback, **not** the golden band.
- **Gold‑standard modal** → `resources/js/components/clients/add-client-dialog.tsx` (full‑height bespoke shell: **left stepper rail + completeness meter + per‑step validation + server‑error→step mapping + Save & add another + `SuccessPane`**), built on `@/components/wizard/primitives`. Markers to match (verified): `Dialog`+`DialogContent` with `[&>button]:hidden`, `flex h-[min(92vh,860px)]`, a `STEPS` array (`{key,label,icon,blurb}`), `validateStep()`, `stepForError()`, completeness meter in the rail foot, "Step X of N" + top progress bar, "Save & add another", `SuccessPane`, `forceFormData: true` for uploads. **This is the modal to replicate for every create/edit/generate/send flow (§F),** at the **premium polish of the Leave "New Request" modal** (`leave-request-dialog.tsx`).
- **Tab strip + right‑click** → `resources/js/components/rostering/tab-strip.tsx` (`TabStrip`: `role="tablist"`, keyboard arrows/Home/End, `onItemContextMenu`, `decorations` per tab, `trailing` slot) wrapped by `resources/js/components/hr/hr-tabs.tsx` (`HrTabs` + `useHrTab`). `documents-tabs.tsx` today only `router.visit`s between four pages — rebuild it as a real in‑page `HrTabs` shell.

Then audit `/hr/documents` (and its connected surfaces) against this **best‑in‑class HR‑document/e‑sign checklist** (mark each **Present / Partial / Missing**, then close gaps in §B–§L). Benchmarks: **BambooHR** (employee files with e‑signature, document templates, signing‑status tracking, new‑hire packets), **Rippling / Deel / HiBob** (template merge fields, bulk send, immutable audit trail, retention), **DocuSign / PandaDoc / Dropbox Sign** (drawn‑signature pad, signing order, reminders, certificate of completion, audit hash), **Personio / Factorial / Employment Hero** (folders, expiry tracking, acknowledgement vs signature, policy attestation), **NZ care‑sector** record‑keeping (right‑to‑work, police vetting, practising certificates with expiry).

**Checklist (fill this in as the first pass and paste back the results):**

- **Hero:** golden brand band • document stats that matter (total on file • **awaiting signature** • **expiring ≤60 days** • **generated this month** / templates) • quick actions (Upload / Generate from template / Send for signature / New template) • live alert badges (docs **awaiting signatures you sent**, **expiring/expired** credentials, **declined** signatures) with drill‑down • **no clock**.
- **Tabs:** real `DocumentsTabs` (not four route pages) • per‑tab counts • **right‑click tab menu** (set default, open, pin) • `?tab=` deep‑link.
- **Library:** **folders** (contracts / compliance / certificates / letters / payslips / policies / other) • filter by category/folder/employee/expiry/restricted + search • bulk select (download / move / delete / send for signature) • **export** • per‑row: type chip via `StatusBadge`, **signature status**, **expiry badge**, **restricted** lock, related‑employee link **to their profile**, version • real empty/skeleton states • **inline viewer / preview** • **right‑click row menu**.
- **Signatures (manager SENDER side — NEW):** a true tracking inbox of documents **you/HR sent** — segments *Awaiting signature · Signed · Declined · All* • who‑signed‑when, **signing order**, **due date**, **reminders/nudge** • **drawn‑signature** captured (not typed) • a **signed‑PDF artifact** downloadable • single + **bulk** send • decline reason visible • right‑click row menu. (Today's "Signatures" tab wrongly shows the **current user's own pending** signatures — signer side — which belongs on `/hr/my`.)
- **Templates:** list + CRUD via modal • **merge‑field picker** with insertable tokens + a **rich content editor** • **live preview** against a chosen employee • **Generate → PDF** • version bump on content change • active toggle • approval‑required workflow (the column exists; wire it) • right‑click menu.
- **Policies & attestations:** keep the policy subsystem (CRUD + **versions** + **attestations** + download) but standardise its chrome to the new tabs/hero/modals; surface **attestation status** (who's attested / overdue) and **publish a new version** via modal.
- **End‑to‑end:** every visible action has a wired route + toast; no dead buttons; **upload/generate/send all reflect on the staff profile and the employee's `/hr/my/documents`**; signing produces a **filed signed PDF** and flips the document's signed badge everywhere; recruitment candidate docs **flow into the employee file on hire**.

> **Known gaps the audit already surfaced** (confirm, then fix):
> - **The signing loop is broken end‑to‑end.** `ESignatureService::sign()` writes `signature_data`/`signed_at`/`ip_address`/`user_agent` onto the `HrDocumentSignature` row **only**. It **never** sets `HrDocument.signed_by_employee` / `signed_at`, **never** writes `signed_document_path`, and **never generates a signed artifact**. So the "Signed" badge on `/hr/my/documents` (which reads `signed_by_employee`) **can never light up** from the e‑sign flow, and there is no signed PDF to download. Worse, `my-hr-esign-dialog.tsx` signs by **typing a name** (`signature_data: name.trim()`) even though the column is documented as a base64 PNG/SVG — there is **no drawn signature pad**. (Decision: build the **full audit‑grade loop** — §E/§K.)
> - **`sent_to_employee` / `sent_at` are dead columns** — never set anywhere. There is no "share to employee" action and no sent‑state tracking.
> - **The "Signatures" tab is mis‑scoped.** On a manager **Documents hub** it routes to `/hr/signatures/pending` = *documents awaiting the current user's own signature* (signer side). A manager needs the **sender side**: "documents I sent — who's signed / declined / pending." Re‑scope the hub tab to sender‑side tracking (§E); leave signer‑side pending on `/hr/my`.
> - **Generate writes raw HTML, no preview.** `HrDocumentMergeService::generateDocument()` stores a `.html` file as the "document"; `preview()` exists but **has no route**; there is **no PDF/DOCX**. Contracts/offers aren't real documents. (Decision: **PDF + live preview** — §G/§K.)
> - **Three divergent document surfaces.** The hub (`documents/index.tsx`) is a **flat table, no folders**; the staff profile (`employees/documents.tsx`, 1,319 lines) has **folders, folder counts, edit form, category filter**; self‑service (`my/documents.tsx`) has **folder tiles + context menu + e‑sign**. They use **different upload forms with different fields** — the hub `store()` validates only `employee_profile_id/title/category/file/is_restricted`, while `storeForProfile()` also takes `folder/expires_at/notes`. (Decision: **unify on one foldered kit** — §J.)
> - **`is_restricted` isn't enforced on download.** `HrDocumentController::download()` checks only `hr.documents.view` + tenant — a restricted doc is downloadable by any viewer. Add a restricted‑access gate.
> - **Recruitment → employee file loop is broken.** `HrCandidateDocument` is a **separate table**; on hire nothing copies CV / right‑to‑work / references / vetting into the employee's `HrDocument` file. Onboarding/offboarding only carry **text** "archive documents" tasks (`OnboardingService` ~`:609`/`:622`) — no real collection or signing.
> - **Document expiry has UI but maybe no reminder.** `expires_at` + `expiry_reminder_sent` exist and the UI computes expiry client‑side, but confirm whether `SendExpiryRemindersJob` (scheduled daily 08:00 in `routes/console.php:287`) actually covers `HrDocument.expires_at` — it appears aimed at credentials/training/vetting. If not, documents silently expire with no nudge.
> - **No right‑click menus, no bulk actions, no export, no inline viewer** on the hub. **Full‑page** upload (`documents/upload.tsx`), template‑create/edit and policy‑create/edit/show — all should be modals.
> - **Thin modals.** Generate = 3 fields (template/employee/title), no merge‑field editing, no preview, no offer linkage in the UI (the backend supports `offer_id`). Send‑for‑signature = a flat checkbox list, no message, no signing order, no due date, no reminders.

---

## 2. The shared kit you MUST reuse (exact imports)

**2.1 Hero** — copy the gradient treatment from `resources/js/components/hr/my-hr-hero.tsx` / `people-hero.tsx`: `HERO_STYLE` (the `linear-gradient` over `--primary` + `boxShadow`; re‑themes per tenant), `HeroStat` (label + big tabular value, clickable / `href`), `QuickAction` (icon + label), and the on‑gradient "needs you" chip + `NeedsDot` pattern. Build `DocumentsHero` on the shared `hero-kit.tsx` if present (else refactor it out of `my-hr-hero.tsx` first). Generic fallbacks live in `@/components/page` (`PageHero`, `PageHeroStats`, `PageHeroQuickActions`) — fallback only. Tokens: `--primary`, `--primary-foreground`, `--category-hr`, `--hr-amber`.

**2.2 Modals / wizards** — `@/components/wizard/primitives`: `Field`, `FieldErr`, `Segmented`, `ChipMulti`, `SelectInput`, `StepHead`, `SubHead`, `InfoCard`, `TilePicker`, `Ring`, `IconType`, `WIZARD_RAIL_CLASS`, `WIZARD_PROGRESS_TRACK_CLASS`, `WIZARD_PROGRESS_BAR_CLASS`, `WIZARD_FOOTER_CLASS`. **Reference to clone: `resources/js/components/clients/add-client-dialog.tsx`** at the **polish of `resources/js/components/hr/leave-request-dialog.tsx`**. For the employee/recipient picker reuse `@/components/hr/people-picker` (`PeoplePicker`, `PersonOption`). Base shadcn in `@/components/ui/`: `dialog`, `sheet`, `popover`, `dropdown-menu`, `alert-dialog`, `command`. **Note the two wizard toolkits that currently coexist** — Add‑Client uses `wizard/primitives`; Leave uses `wizard/shell` (`WizardShell`/`useWizard`/`WizardSuccessPane`). **Standardise documents on the Add‑Client `primitives` shell** (Chane's stated gold standard) and flag the convergence in §K.

**2.3 Right‑click menus + hover actions** — reuse the existing pattern, don't invent one. Closest references: `@/components/rostering/shift-context-menu` (`ShiftContextMenu`, `ShiftCtxItem`, `ShiftCtxState` — portal‑rendered, viewport‑flipping, Esc/outside‑click close, icon+label+`kbd`+tone — **already used in `my/documents.tsx`**) and `@/components/emar/mar/dose-context-menu`. Build a `DocumentContextMenu` in the same mould; wire `onContextMenu={(e) => onCtx(e, row)}`.

**2.4 Cards / states / badges / viewer** — **`@/components/ui/status-badge` (`StatusBadge`) everywhere** for signature status (Pending / Signed / Declined), expiry (Valid / Expiring / Expired) and type chips — do not hand‑map colours (the hub's current `typeColors` map and one `bg-muted-foreground/80/10` typo must go). Also `@/components/ui/card`, `avatar`, `badge`, `empty-state` (`EmptyState`, `EmptyList`, `EmptySearch`), `error-state`, `loading-state`, `skeleton-card`, `@/components/ui/laravel-pagination`. Reuse the **folder tile** + **doc row** treatment already built in `my/documents.tsx` (`FOLDER_ICON` map, `folderMeta`, expiry→`StatusBadge`) as the basis for the shared kit. For the inline viewer, prefer a `Sheet`/`Dialog` with an `<iframe>`/embed for PDFs; no new heavy viewer library.

**2.5 Tokens & flourishes** — tokens only in `resources/css/app.css`: `--status-{success,warning,critical,info,neutral}` (+`-bg`/`-foreground`), `--category-hr`, `--primary`, `--hr-amber`, `--shadow-hero`/`--shadow-float`. Tailwind v4 utilities (`bg-status-success-bg`, `text-status-critical`). `cn()` from `@/lib/utils`. **Toasts: sonner** (`<Toaster>` mounted in `resources/js/app.tsx`) — `toast.success/error` on **every** action. Success flourish: `fireConfetti` from `@/lib/confetti` on send/sign/generate (as Leave + e‑sign dialog already do). Animations: `tailwindcss-animate` (`animate-in`, `fade-in-0`, `zoom-in-95`, `slide-in-from-*`) with `motion-reduce:*` guards.

---

## B. Hero rethink — the golden band (NO clock, fitted to documents)

**Current:** `documents/index.tsx`, `templates.tsx`, `policies/index.tsx` each use a generic `PageHero category="hr"` with a single "Total" stat and two header buttons. Not the golden band; inconsistent across the four pages.

**Do:** build a **`DocumentsHero`** (in `resources/js/components/hr/documents/documents-hero.tsx`) using the **same gradient + `HeroStat` + `QuickAction` language as `people-hero.tsx`/`my-hr-hero.tsx`**, sized to this page. **No clock.** Compose:

- **Left column:** title **"Documents"** + one‑line context ("Generate, sign and file every {tenant} people document in one place"). Small icon medallion (`FolderOpen` / `FileSignature`).
- **Glanceable `HeroStat`s** (each click‑filters or deep‑links a tab): **On file** (total → Library) • **Awaiting signature** (`--hr-amber` if >0 → Signatures tab) • **Expiring ≤60 days** (→ Library filtered by expiry) • **Templates** (→ Templates tab). Use tabular figures.
- **`QuickAction`s:** **Upload** (opens upload wizard §F‑1) • **Generate from template** (§F‑2) • **Send for signature** (§F‑3) • **New template** (§F‑4, gated `hr.documents.manage`).
- **Live alert badges** (drill‑down popover, like People/My‑HR chips): "{n} awaiting signature", "{n} declined ⚠️", "{n} expiring/expired ⏰". Reuse the chip + `NeedsDot` pattern.
- **Right column (where My HR puts the clock):** since there's **no clock**, fill it with a page‑appropriate cluster — a compact **"Recently filed"** stack (last 3–4 documents with type icon + person) **or** a small **signature‑completion `Ring`** (signed vs outstanding). Persist any toggle to `localStorage` (`hrDocuments.heroRight`) like People does. Keeps the band balanced without a clock.

---

## C. Tabs — replace the four pages with a real `DocumentsTabs` shell

Replace the four standalone pages with a standardised in‑page tab strip (mould of `HrTabs`), `?tab=` deep‑linked, per‑tab counts as badges, **right‑click menu on the tab strip** (§I). **Propose the final set to Chane in the §A audit and get sign‑off before building.** Recommended starting set:

1. **Library** (default) — the foldered document store (§D): folder rail/tiles + filter toolbar (category / folder / employee / expiry / restricted / search), bulk actions, export, real `EmptyState` + `skeleton-card`. Each row: type chip via `StatusBadge`, related employee (→ their profile), **signature status**, **expiry badge**, **restricted** lock, version, created‑by/date, **right‑click menu**, hover actions.
2. **Signatures** (re‑scoped to manager **sender side**, §E) — the tracking inbox of documents you/HR sent for signature. Segments: *Awaiting signature · Signed · Declined · All*. Per row: document, signer(s) + status, sent/due dates, signing order, **nudge/resend**, download **signed PDF**. Single + bulk send via the review modal. This is the headline re‑scope.
3. **Templates** (§G) — template list + CRUD via modal, merge‑field picker + content editor, **live preview**, **Generate → PDF**, version, active toggle, approval‑required workflow.
4. **Policies** (§H) — keep the policy subsystem (CRUD + versions + attestations + download), restyled to the new chrome; surface attestation status and "publish new version" via modal. (If Chane prefers, Policies can live one level up as its own hub — confirm placement; default is to keep it as a Documents tab since `documents-tabs.tsx` already groups it here.)

> Per tab: shared list/card + `StatusBadge` chips; real **empty state** (icon + line + CTA) and **skeleton**; every create/edit/generate/send flow is a **modal** (§F); every row has a **right‑click menu** (§I) + hover actions; **toast** every result.

---

## D. Library tab — the foldered document store (bring folders to the hub)

The hub is the only surface without folders. Build the shared library here (and reuse it on the profile + self‑service, §J).

- **Folder model:** reuse the `my/documents.tsx` `FOLDER_ICON`/`folderMeta` taxonomy (contracts, compliance, policy, payslips, certificates, other) as **folder tiles** with counts; a folder rail or breadcrumb like `employees/documents.tsx` (`currentFolder` state, `FolderPlus` to create). One taxonomy across all three surfaces.
- **Filter toolbar:** category, folder, employee (`PeoplePicker`), **expiry** (valid / expiring ≤60d / expired), **restricted**, plus search (title / original name / employee). Replace the current bare `Input`+`Select`.
- **Rows:** employee avatar + name (→ `/hr/people/{id}` profile), title, type chip via `StatusBadge`, **signature status** (Pending/Signed/Declined), **expiry** `StatusBadge`, **restricted** lock icon, version, created‑by + date. Hover actions: Preview · Download · Send for signature · ⋯. **Right‑click** = full menu (§I).
- **Bulk:** select rows → Download (zip) · Move to folder · Send for signature · Delete (confirm via `alert-dialog`). **Export** the filtered list (CSV/Excel) gated `hr.documents.manage`.
- **Inline viewer:** click a row → `Sheet`/`Dialog` preview (PDF embed / image / text) with metadata, signature history and download — no full‑page hop.
- **States:** `EmptyState` (icon + "No documents yet" + Upload CTA), `EmptySearch` when filtered, `skeleton-card` while loading. Retire the plain "No documents found." cell.

---

## E. Signatures tab — the manager sender‑side tracking inbox (headline re‑scope)

This resolves the mis‑scoped tab and completes the loop's visibility. Build a real **sender‑side** inbox (today's tab shows the user's own pending — that's signer side, keep it on `/hr/my`).

- **Segments** (a `Segmented`): **Awaiting signature · Signed · Declined · All**. Counts as badges.
- **Server‑driven**, ordered by urgency (overdue/oldest first). Each request shows: document title + type, **signer(s) with per‑signer status + avatars**, requested‑by, **sent date**, **due date**, **signing order** (sequential/parallel), and for signed rows the **signed‑PDF download** + audit (when/IP). For declined rows, the **reason** inline.
- **Actions:** **Send for signature** (single + **bulk**, via §F‑3 modal) • **Nudge / resend** a pending signer • **Cancel request** • **Download signed PDF** • **Open document** • right‑click row menu. Every write hits a real route (§K) + toast.
- **Reminders:** a "nudge" sends a reminder notification; optionally an automatic reminder N days before due (scheduled job, §K). Surface "last reminded" per signer.

> The **signer‑side** experience (employee reviewing & signing) stays on `/hr/my/documents` + `resources/js/pages/hr/signatures/sign.tsx` — but upgrade the **signature capture to a real drawn pad** there and in `my-hr-esign-dialog.tsx` (§K). Both sides read the same `HrDocumentSignature` records.

---

## F. Modals = exact Add‑Client wizard pattern (full workflows, not thin forms)

Every create/edit/generate/send flow clones `resources/js/components/clients/add-client-dialog.tsx`: same **full‑height bespoke shell** (`Dialog` + `DialogContent [&>button]:hidden`, `flex h-[min(92vh,860px)]`, left **stepper rail** `w-[248px] bg-sidebar` with per‑step icons + blurbs + check‑on‑complete, a **completeness meter** at the rail foot, header "Step X of N", **top progress bar**, scroll‑contained body, footer Back / Cancel / **Save & add another** / primary), same **engine** (Inertia `useForm`, client‑side `validateStep`, `stepForError` to jump to the offending step, `SuccessPane`, `resetAll()` for Save & add another, `forceFormData: true` for uploads), from `@/components/wizard/primitives` — at the **premium quality of `leave-request-dialog.tsx`**. **No thin single‑screen forms.**

1. **Upload document** — replace the full‑page `documents/upload.tsx`. Steps: **Who & what** (employee via `PeoplePicker`, title, **category** `TilePicker`, **folder**) → **File** (drag‑drop upload, accepted types, size guard — `forceFormData`) → **Details** (**expiry date**, **restricted** toggle, **notes**) → **Review & file**. Posts to a unified store (§K). Confetti + sonner. (This single modal also serves the profile + self‑service surfaces, §J.)
2. **Generate from template** — rebuild the 3‑field dialog. Steps: **Template** (`TilePicker`/searchable list, show category) → **Recipient** (employee via `PeoplePicker`; if an **offer** category, optional `offer_id` linkage) → **Merge & preview** (resolve merge fields; **live PDF preview** via the new preview route §K; show any unresolved tokens; let HR fill `extra` custom fields) → **Generate** (title default = template name; produces a **PDF** §K). Confetti + sonner; offer **"Generate & send for signature"** as the success‑pane next step.
3. **Send for signature** — rebuild the checkbox list into a real workflow. Steps: **Document** (preselected or pick) → **Signers** (`PeoplePicker` multi; **signing order** sequential/parallel via `Segmented`) → **Options** (message to signer, **due date**, reminder cadence) → **Review & send**. Supports the **bulk** path (one modal, N documents/signers). Posts to the e‑sign request endpoint (§K). Confetti + sonner.
4. **New / edit template** — replace full‑page create/edit. Steps: **Basics** (name, category) → **Content** (rich editor + **merge‑field picker** that inserts tokens; show `availableMergeFields` for the category) → **Settings** (approval‑required, active) → **Preview** (live merge against a sample employee). Version bumps on content change (already in `updateTemplate`).
5. **Edit document** (metadata) — title / category / folder / expiry / restricted (mould of the profile `editForm`) as a compact wizard or single‑step dialog.
6. **Policy create / edit / publish version** (§H) — replace the full‑page policy forms with the same shell: **Basics** → **Content/Document** → **Version & publish** (attestation‑required toggle).

> Wire each modal from the page like today (`open` state + `<UploadDocumentDialog … />` etc.), opened from the hero `QuickAction`s, tab CTAs and row/context menus. Reuse `my-hr-esign-dialog.tsx`'s premium feel for the signer dialog.

---

## G. Templates tab — merge, preview, generate **PDF**

- **List + CRUD** via the §F‑4 modal; show category, version, active, approval‑required, last updated; right‑click menu (Edit · Duplicate · Preview · Toggle active · Delete).
- **Merge‑field picker:** surface `HrDocumentMergeService::getAvailableFields(category)` as insertable token chips in the content editor; the existing `MERGE_FIELDS` set (employee, site, offer, org tokens) is the source of truth.
- **Live preview + Generate → PDF** (Chane's decision): add a **preview route** over the existing `mergeService->preview()` (no route today) and make `generateDocument()` render a **PDF** instead of a raw `.html` file (§K). Show unresolved tokens as warnings.
- **Approval‑required:** the column exists but nothing enforces it — wire a light approval step before a generated doc is sendable (confirm scope with Chane; may defer to §K).

---

## H. Policies tab — standardise the subsystem chrome

The policy subsystem (`PolicyController`, `HrPolicy`/`HrPolicyVersion`/`HrPolicyAttestation`, pages `documents/policies/*`) is comparatively deep already (CRUD + versions + attestations + download). Keep the engine; **restyle to the new hero/tabs/modals** so it stops looking like an "old view":

- Policies **list** with attestation status (attested / outstanding / overdue), version, owner; right‑click menu.
- **Create / edit / publish‑new‑version** via the §F‑6 modal (not full‑page).
- **Attestations** view: who's attested, who's overdue (reuse `PolicyAttestationController`); a "request attestation" / nudge action.
- Keep the **separate permission gate** (`hr.policies.*`) — it's intentionally different from `hr.documents.*`.

---

## I. Right‑click everywhere (rows **and** tabs)

Chane explicitly wants right‑click options "under tabs etc." Build a `DocumentContextMenu` (mould of `ShiftContextMenu`, already used in `my/documents.tsx`) and wire `onContextMenu` on:

- **Library rows:** **Preview** · **Download** · **Send for signature…** · **Generate related…** · **Move to folder…** · **Edit details…** · **View employee profile** · **Copy link** · (manager) **Delete** (confirm via `alert-dialog`). Gate destructive/manager items; show `kbd` hints.
- **Signature rows:** **Open document** · **Nudge signer** · **Resend** · **Download signed PDF** · **Cancel request** · **View audit**.
- **Template rows:** **Edit** · **Duplicate** · **Preview** · **Generate from this** · **Toggle active** · **Delete**.
- **Policy rows:** **Open** · **Publish new version** · **Request attestation** · **View attestations** · **Download**.
- **The tab strip itself:** right‑click a tab → **Set as default view**, **Open**, **Pin**. Persist "default tab" + pins to `localStorage` (`hrDocuments.defaultTab`, allowed) so it survives reloads; render a `decorations` star/pin on the chosen tab.

Every menu action fires a toast and, where it writes, hits a real route (§K). No dead items.

---

## J. Unify the three surfaces onto one foldered kit

Chane's decision: **one** document component + **one** upload modal + **one** folder taxonomy across the hub, the staff profile and self‑service. Don't leave three diverging implementations.

- **Shared component** `resources/js/components/hr/documents/document-library.tsx` (folder tiles/rail + filter toolbar + rows + inline viewer + context menu) consumed by: `hr/documents/index.tsx` (Library tab, org/all‑staff scope), `hr/employees/documents.tsx` (single‑profile scope — replace its bespoke 1,319‑line implementation), and `hr/my/documents.tsx` (read‑only self scope, keeps the e‑sign dialog).
- **Shared upload modal** = §F‑1, used everywhere (manager hub, profile, and — if self‑upload is allowed — self‑service). The hub `store()` must accept the **same fields** as `storeForProfile()` (folder/expires_at/notes) so they stop diverging (§K).
- **Profile ↔ hub:** a document filed from the hub for employee X must appear on X's profile Documents tab and on X's `/hr/my/documents`; a document uploaded on the profile must appear in the hub Library. Verify both directions after the unify pass.
- **Employee profile show** (`hr/employees/show.tsx`): ensure the Documents tab/section links into this shared component and shows the signature/expiry signals.

---

## K. Backend handoff for Claude Code (append as you go)

Anything below is **backend/data work** to hand to Claude Code. Keep a running checklist in `docs/HR_DOCUMENTS_GAP_ANALYSIS.md` (create it) and tick items as they land. **Append anything new you discover during the build.**

**Complete the e‑signature loop (full audit‑grade — Chane's decision):**
1. **Finish `ESignatureService::sign()`** so that, on the **last required** signature, it sets `HrDocument.signed_by_employee = true` + `signed_at`, and writes the **generated signed PDF** to `signed_document_path`. Burn into the PDF: the **drawn signature image**, signer name, timestamp, **IP + user‑agent**, and a **document hash / certificate of completion** page. Keep per‑signer rows in `HrDocumentSignature` as the audit trail.
2. **Drawn signature pad:** replace typed‑name signing in `my-hr-esign-dialog.tsx` and `signatures/sign.tsx` with a real canvas pad producing a base64 PNG; keep a "type to sign" fallback that still renders to an image. Backend already stores `signature_data`.
3. **Signing order + due + reminders:** add `signing_order` / `due_at` / `reminder_sent_at` (or a small `hr_document_signature_requests` parent) so sequential signing and nudges work; a scheduled reminder job (mould of `SendExpiryRemindersJob`, register in `routes/console.php`).
4. **Sender‑side queries** for the §E inbox (documents I/HR sent, grouped by status) + `nudge`, `resend`, `cancel` endpoints + `downloadSigned`.
5. **Wire `sent_to_employee` / `sent_at`** (dead today): set on send‑for‑signature and on an explicit "share to employee" action; expose a "Share to employee" path so a filed doc reaches `/hr/my/documents` without necessarily requiring a signature.

**Document generation (PDF + preview — Chane's decision):**
6. **Render generated documents to PDF.** Check `composer.json` for an existing renderer (e.g. dompdf / `barryvdh/laravel-dompdf` / spatie browsershot) before adding one. `generateDocument()` should output a PDF (keep the merged HTML as the source). Store `mime_type=application/pdf`.
7. **Preview route** over `HrDocumentMergeService::preview()` (exists, unrouted) for the §F‑2 / §G live preview; return rendered HTML/PDF for an iframe. Support `extra` custom merge values and report unresolved tokens.
8. **Approval‑required workflow** for templates (`approval_required` column is unused): optional gate before a generated doc is sendable. Confirm scope with Chane.

**Unify + parity:**
9. **Hub upload field parity:** add `folder`, `expires_at`, `notes` to `HrDocumentController::store()` validation + create (match `storeForProfile()`); collapse the two upload paths onto one service method feeding the §F‑1 modal.
10. **Bulk + folders + move/rename + export:** endpoints for bulk download (zip), move‑to‑folder, bulk delete, and a filtered export (CSV/Excel). A document **versioning** path (re‑upload supersedes, keep history) if Chane wants it (confirm).
11. **Enforce `is_restricted`** in `download()` (and the new viewer/preview): require `hr.documents.manage` (or an explicit grant) for restricted docs — today any `hr.documents.view` user can pull them.

**Expiry + lifecycle:**
12. **Confirm/extend `SendExpiryRemindersJob`** to cover `HrDocument.expires_at` (set `expiry_reminder_sent`); if it doesn't, add a documents pass so certificates/right‑to‑work/practising certs actually nudge before expiry. Surface on the hero "expiring" stat.

**Recruitment & onboarding loop:**
13. **Candidate → employee file on hire:** copy/move relevant `HrCandidateDocument` records (CV, right‑to‑work, references, vetting) into the employee's `HrDocument` file when an application is hired; or at minimum surface candidate docs on the profile. Wire onboarding/offboarding's "archive documents" tasks to the real document store instead of plain text.

**Hygiene / convergence:**
14. **Wizard‑toolkit convergence:** documents standardise on `@/components/wizard/primitives` (Add‑Client). Note `wizard/shell` (Leave) coexists — flag for a future single‑idiom pass; don't fork a third.
15. **Audit trail surfacing:** `HrDocument` uses `AuditableChanges` — expose a per‑document history (uploaded/sent/signed/edited) in the inline viewer.
16. List any **dead routes / stray files** you find (mirror how the Leave prompt flagged stray root‑level model copies).

---

## L. Verify each pass (don't skip)

After **every** pass:
1. `npm run build` clean · `npm run types` (zero TS errors) · `npm run lint` (zero — raw hex/`no-restricted-syntax` will fail the build).
2. Run the app and **screenshot**: `/hr/documents` at each `?tab=`, every modal open state (each step), each right‑click menu, the inline viewer, plus the connected surfaces `/hr/people/{id}` (Documents) and `/hr/my/documents`. Diff against the gold‑standard pages (`/hr/people`, `/hr/leave`, `/meds/today`).
3. Confirm the loop end‑to‑end on real data: **upload → appears on hub + profile + self‑service**; **generate → PDF + preview**; **send → signer sees it → drawn‑sign → filed signed PDF + signed badge flips everywhere → sender inbox shows Signed**; **decline → reason visible to sender**; **expiry → reminder + hero stat**; **restricted → blocked for non‑managers**.
4. Keep `docs/HR_DOCUMENTS_GAP_ANALYSIS.md` updated; nothing in §K silently dropped.
5. For a high‑stakes pass (the signing loop), add/extend a feature test (sign flips document state + writes a signed artifact; restricted download is gated).

> Build order suggestion: **§A audit + tab sign‑off → §2 kit/hero (§B) → §C tabs shell → §D Library + §J unify → §F modals → §E Signatures inbox + §K e‑sign loop → §G Templates/PDF → §H Policies → §I right‑click → §L hardening.** Ship each as its own verifiable pass.
