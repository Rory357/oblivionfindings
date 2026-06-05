# NZ Localisation — strip Australian & UK/EU items (Codex handoff)

**Goal:** this is a **New Zealand** supported-living / disability product. Remove every
Australian (NDIS, DSS) and UK/EU (GDPR, ICO, CQC, Care Act, Court of Protection,
"deputy") reference from code, copy, seed data and tests, and replace it with the
correct **NZ** equivalent. When done, the discovery grep below must return **zero**
results (other than this plan file and any deliberate "do not use NDIS/DSS" comments).

Scope discovered: **~51 files** in two clusters — (A) Australian funders, (B) UK/EU
privacy framing. `MSD` (Ministry of Social Development) and `ACC` are **NZ — keep them.**

---

## 1. Authoritative replacement mapping

| Foreign term | New Zealand replacement | Notes |
|---|---|---|
| **NDIS** (National Disability Insurance Scheme, AU) | **Whaikaha – Ministry of Disabled People** | NZ's disability funder (assumed from MOH in 2022) |
| **DSS** / "Disability Support Services" (AU) | **Whaikaha** (or **NASC** for allocation) | |
| **"NDIS Line Item Code"** | **Funding / contract reference** | NDIS line items are AU-only; NZ uses contract/PO/NASC refs |
| **DHB** (disestablished 1 Jul 2022) | **Te Whatu Ora – Health New Zealand** | |
| **"MoH" / Ministry of Health** (for *disability* funding) | **Whaikaha** | MoH no longer funds disability support |
| **GDPR** / "UK GDPR" / "EU GDPR" | **Privacy Act 2020** (+ **Health Information Privacy Code 2020** for health info) | |
| **ICO** (Information Commissioner's Office, UK) | **Office of the Privacy Commissioner (OPC)** | |
| **CQC** (Care Quality Commission, UK) | **HealthCERT** / **Ngā Paerewa (Health & Disability Services Standard NZS 8134:2021)** / **HDC** | pick by context (regulator vs standard vs complaints) |
| **Care Act 2014** (UK) | *(no NZ equivalent)* — reframe to **HDC Code of Rights** / provider safeguarding policy, or drop | |
| **DPIA** (Data Protection Impact Assessment) | **PIA (Privacy Impact Assessment)** | OPC term |
| **"Data Subject Request" / DSR** | **Privacy request** (access & correction under **IPP 6 / IPP 7**) | |
| **GDPR Article 33 / "72-hour ICO notification"** | **Notifiable privacy breach** — notify **OPC** (and affected people) where serious harm is likely, **as soon as practicable** | Privacy Act 2020, Part 6 |
| **GDPR Article 35** | PIA (above) | |
| **GDPR Article 6 / "lawful basis"** | **IPPs (Information Privacy Principles)** of the Privacy Act 2020 | |
| **DPO (Data Protection Officer)** | **Privacy Officer** | required under Privacy Act 2020 |
| **"deputy"** / Court of Protection (UK) | **welfare guardian** / **attorney under an EPOA** (PPPR Act 1988) | used in the consent / decision-maker model |
| **"ICO-registered", "UK-based servers", "Bank-grade…UK"** (marketing) | NZ Privacy Act 2020 compliant, NZ-hosted, registered with the OPC | fix factual claims on marketing pages |

**Reuse the canonical NZ funder list already in the repo:**
`app/Support/Respite/RespiteFundingSource.php` (Whaikaha · Carer Support · NASC-allocated ·
EGL/IF · ACC · Te Whatu Ora · MSD · Private · Other). Consider promoting it to a shared
location (e.g. `App\Support\Funding\NzFunder`) and driving the Add-client wizard, service
agreements and `fin_funding_streams.funder_type` from that one list (the NZ gap analysis
flagged "three divergent funder vocabularies" — collapse them, don't add a fourth).

---

## 2. Discovery (run first, and again at the end — must end empty)

```bash
rg -n --glob '!docs/nz-localisation-plan.md' \
  'NDIS|\bDSS\b|\bGDPR\b|\bICO\b|\bCQC\b|Care Act 2014|Court of Protection|Disability Support Services|UK GDPR|\bDPIA\b|\bDPO\b|deputy' \
  app resources database routes tests config
```

Also sweep for softer hits: `"Data Subject"`, `"data subject"`, `"72-hour"`, `"72 hour"`,
`"lawful basis"`, `"Article 6"`, `"Article 15"`, `"Article 33"`, `"Article 35"`, `"DHB"`,
`"Ministry of Health"`.

---

## 3. Cluster A — Australian funders → NZ

| File | What's there | Change to |
|---|---|---|
| `resources/js/pages/operations/service-agreements/Index.tsx` | funder map incl. `ndis:'NDIS'`, `dss:'DSS'` | NZ funders (whaikaha/te_whatu_ora/acc/nasc/msd/private) |
| `.../service-agreements/Edit.tsx` | `ndis:'NDIS'`, `dss:'DSS — Disability Support Services'`, placeholder `"DSS Residential Support 2026"`, `"Whaikaha / DSS funding type…"` | NZ funders + NZ placeholder/desc |
| `.../service-agreements/Show.tsx` | `"NDIS Line Item Code"` label/field | "Funding / contract reference" (or remove if AU-specific) |
| `.../service-agreements/Create.tsx` | funder options | NZ funders |
| `resources/js/pages/sites/compliance/Index.tsx` | `'DSS'` in a list | NZ regulator/funder |
| `database/seeders/OperationsDemoSeeder.php` | `funding_body => ['NDIS','MSD','Private','DSS','ACC']` | `['Whaikaha','MSD','Private','ACC','NASC']` |
| `resources/js/pages/operations/clients/_create-dialog.tsx` | `FUNDING_OPTIONS = [NDIS, 'Whaikaha (MoH)', ACC, 'DHB / Te Whatu Ora', Private, Other]` | the canonical NZ list (drop NDIS, fix `Whaikaha (MoH)`→`Whaikaha`, `DHB / Te Whatu Ora`→`Te Whatu Ora`) |
| `resources/js/pages/operations/clients/show.tsx` | any NDIS/DSS funder labels | NZ |
| `resources/js/pages/settings/service-contexts.tsx` | check funder/AU refs | NZ |
| `database/migrations/2026_03_25_200400_create_site_compliance_templates.php` | `DSS` in seeded template data | NZ regulator |
| `database/seeders/RbacSeeder.php` | any NDIS/DSS in descriptions | NZ |

**Data layer:** check `fin_funding_streams.funder_type` (enum), `service_agreements.funding_type`/
`funding_body`. If they enumerate `ndis`/`dss`, add a migration to migrate existing rows to NZ keys
(`ndis`→`whaikaha`, `dss`→`whaikaha`) and update the enum/seeders. Don't silently leave AU keys in the DB.

---

## 4. Cluster B — UK/EU privacy → NZ Privacy Act 2020

> **Decide the depth first.** Recommended: **relabel copy, descriptions, comments, seed data and
> legal references to NZ** (low risk), and **keep internal identifiers stable** — route names,
> permission keys (`privacy.processRequests`), table/column names, model class names — to avoid
> breaking references. A full structural rename (e.g. `DataSubjectRequest` → `PrivacyRequest`,
> renaming tables/permissions) is optional and much larger; if chosen, do it with migrations +
> a permissions seeder + update every reference + tests. **Get sign-off before renaming identifiers.**

| Area | Files | Change |
|---|---|---|
| Route docblocks | `routes/web.php`, `routes/privacy.php` | "GDPR Article 7/15-22/33/35" → Privacy Act 2020 (IPP 6/7, Notifiable Privacy Breach, PIA) |
| Privacy pages (~20) | `resources/js/pages/privacy/**` (dashboard, requests, breaches, dpia, legal-holds, retention, reports/compliance, `privacy.tsx`) | breadcrumb "Privacy & GDPR" → "Privacy"; "Data Subject Request" → "Privacy request"; "ICO" → "OPC"; "DPIA" → "PIA"; "Article 33 72-hour ICO" → "notify the OPC as soon as practicable"; "UK GDPR" → "Privacy Act 2020"; "Care Act 2014" example → drop |
| Consent | `database/seeders/ConsentTypesSeeder.php` (`'Data Processing (GDPR)'`), consent migration `2026_01_28_000002` | "GDPR" → "Privacy Act 2020"; relabel any "deputy" decision-maker → welfare guardian / EPOA attorney |
| Breach | `app/Http/Controllers/DataBreachController.php`, `app/Models/DataSubjectRequest.php`, migration `2026_01_28_000004` | ICO/72h → OPC notifiable-breach framing (UI/text; keep keys) |
| Fleet/telemetry | `database/seeders/FleetManagementSeeder.php`, `FleetDemoSeeder.php` (`legal_basis => 'GDPR Art 6'`), `resources/js/pages/smart-monitoring.tsx` | legal_basis → "Privacy Act 2020 (IPP)"; "GDPR-compliant"/"ICO-registered" → NZ |
| RBAC labels | `database/seeders/RbacSeeder.php` ("Process GDPR requests") | "Process privacy requests" (keep the `privacy.processRequests` **key**) |
| Training | `database/seeders/TrainingCoursesSeeder.php` (GDPR course) | "Privacy Act 2020 / Health Information Privacy Code" course |
| Settings/branding | `resources/js/pages/settings/branding.tsx` | NZ |

**Marketing pages (factual claims — must be NZ-true):** `resources/js/pages/terms.tsx`,
`about.tsx`, `pricing.tsx`, `privacy.tsx`, `smart-monitoring.tsx` — remove "UK GDPR", "ICO-registered",
"UK-based servers", "CQC"; state **Privacy Act 2020 compliant, hosted in New Zealand, registered with the OPC**,
audited against **Ngā Paerewa / HealthCERT**.

**Tests / e2e:** `tests/Feature/PrivacyControllerTest.php`, `tests/Feature/FleetTelemetryIngestTest.php`,
`tests/e2e/privacy-dsr-and-breach-lifecycle.spec.ts` — update any asserted GDPR/ICO/DSR strings to the
new NZ copy. Keep behaviour identical.

---

## 5. Verification

- `npx tsc --noEmit` → 0 errors.
- `npm run build` → exit 0 (use `NODE_OPTIONS=--max-old-space-size=8192`).
- Run touched PHP tests **non-parallel** (per-worker DBs aren't migrated here):
  `php artisan test tests/Feature/PrivacyControllerTest.php tests/Feature/FleetTelemetryIngestTest.php`
  and any service-agreement / consent tests.
- Re-run the **Section 2 discovery grep** — it must come back empty.
- If you added migrations (funder-key data migration), they auto-run on deploy; **no seeder is run on
  deploy**, so any seeded NZ data that must exist on the live server needs a manual
  `php artisan db:seed --class=... --force` after deploy.

## 6. Gotchas

- **Shared checkout** — stage files explicitly (`git add <path>…`), never `git add -A`/`-u`; verify the
  branch before committing; push to `main`.
- **Don't break identifiers** unless explicitly renaming: permission keys are **seeded, not migrated**,
  and deploys skip seeders, so a renamed permission key 403s on the server until its seeder is re-run.
- Keep `MSD` and `ACC` (both NZ).
- Health info has a stricter code — prefer **Health Information Privacy Code 2020** over the generic
  Privacy Act where the data is health/care related (clients, eMAR, incidents).
- The respite module already models NZ funding correctly (`RespiteFundingSource`) — mirror it; don't
  invent another funder list.

## 7. Done when

- Section 2 grep is empty, `tsc`/build green, touched tests green, and a spot-check of the Privacy
  dashboard, a service agreement, and the marketing footer shows NZ-only terminology.
