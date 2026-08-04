# Settings / System / Auth / Integrations — production-readiness plan

> Reference doc only. No code changes proposed beyond the surgical fixes
> enumerated below. Mirrors the structure of
> [`docs/control-room-readiness-plan.md`](control-room-readiness-plan.md) and
> [`docs/fleet-assets-security-devices-production-readiness-plan.md`](fleet-assets-security-devices-production-readiness-plan.md).

## Verdict

**Heavy scaffolding, gaps where the surface meets a real subsystem.** Auth and
the basic Settings surface (profile, password, appearance, 2FA, branding,
terminology, service contexts, notifications, templates, data, modules) are
well covered by [`tests/Feature/SettingsControllerTest.php`](../tests/Feature/SettingsControllerTest.php)
(~140 tests) and a healthy Browser smoke layer under
[`tests/Browser/Settings/`](../tests/Browser/Settings) and
[`tests/Browser/Auth/`](../tests/Browser/Auth).

But four production-critical seams are wired but not proven:

1. **`/settings/security` writes policy keys (`force_2fa`, `password_min_length`,
   `lockout_duration_minutes`, …) that nothing reads** — confirmed by `grep`
   across `app/`. Operators configure a security policy that has zero effect
   on the login flow.
2. **`/settings/api` webhooks and the `/webhooks/{provider}` receiver are
   decoupled** — `ApiSettingsController` writes JSON blobs into
   `app_settings`; [`WebhookReceiverController`](../app/Http/Controllers/Api/WebhookReceiverController.php)
   validates against `IntegrationTenantSecret` records. Configuring a key in
   Settings has no effect on what the receiver accepts.
3. **OAuth callbacks have zero test coverage and divergent semantics** —
   [`MicrosoftController`](../app/Http/Controllers/Auth/MicrosoftController.php)
   `abort_unless` 500s the request when `ORG_DOMAIN` is unset and `Auth::login`s
   immediately on success. [`GoogleController`](../app/Http/Controllers/Auth/GoogleController.php)
   stores the identity but requires manual approval. Both ship without a Feature
   test.
4. **`WebhookReceiverController` is the entire ingestion surface for ten
   provider parsers and has no Feature test** for auth, dedup, signature
   verification, or routing.

Product direction now resolves the integration ownership question:
**hardware integrations belong under Security & Devices.** Microsoft and
Google remain identity/SSO integrations under Auth/Settings; hardware vendors
such as UniFi, Hikvision, Queclink, Milesight, Gallagher, Axis, Paradox, DSC,
Bosch, and generic device webhooks should route through
`/security-devices/integrations` and `/webhooks/{provider}`, not through
Settings.

Two structural overlaps exist but **neither requires a redesign**:

- `/settings/users` + `/settings/access` overlap with `/system/users` +
  `/system/access/*` — both gated on the same `settings.access.manage`
  permission, both wired to `App\Http\Controllers\System\UsersController`.
  The page templates already share state via `isSystemView` flag in
  [`resources/js/pages/settings/users/show.tsx:232`](../resources/js/pages/settings/users/show.tsx#L232).
- `/settings/integrations` lists 5 hardcoded hardware providers (only UniFi
  clickable) while `/security-devices/integrations` owns the real hardware
  provider lifecycle via `IntegrationTenantSecret`. The Settings hub should
  become a compatibility redirect into Security & Devices, with Microsoft and
  Google handled separately as identity integrations.

**Recommendation: targeted hardening, not a restructure.** Estimated 8–10
PRs, none requiring schema changes.

---

## 1. Current State Map

### Settings

| Surface | Route file | Controller | React page | Tests |
|---|---|---|---|---|
| Profile (edit/update/photo/landing/destroy) | [`routes/settings.php:30-35`](../routes/settings.php#L30) | `Settings\ProfileController` | [`pages/settings/profile.tsx`](../resources/js/pages/settings/profile.tsx) | [`SettingsControllerTest`](../tests/Feature/SettingsControllerTest.php) (`profile_*` group), [`Settings/ProfileUpdateTest.php`](../tests/Feature/Settings/ProfileUpdateTest.php), [`Browser/Settings/ProfileSettingsInteractionTest.php`](../tests/Browser/Settings/ProfileSettingsInteractionTest.php) |
| Password | [`routes/settings.php:37-41`](../routes/settings.php#L37) | `Settings\PasswordController` | [`pages/settings/password.tsx`](../resources/js/pages/settings/password.tsx) | [`Settings/PasswordUpdateTest.php`](../tests/Feature/Settings/PasswordUpdateTest.php), throttling test in `SettingsControllerTest` |
| Appearance | [`routes/settings.php:43-48`](../routes/settings.php#L43) | `Settings\AppearanceController` | [`pages/settings/appearance.tsx`](../resources/js/pages/settings/appearance.tsx) | Browser smoke only |
| Two-factor | [`routes/settings.php:50-51`](../routes/settings.php#L50) | `Settings\TwoFactorAuthenticationController` (Fortify shim) | [`pages/settings/two-factor.tsx`](../resources/js/pages/settings/two-factor.tsx) | [`Settings/TwoFactorAuthenticationTest.php`](../tests/Feature/Settings/TwoFactorAuthenticationTest.php), [`Auth/TwoFactorChallengeTest.php`](../tests/Feature/Auth/TwoFactorChallengeTest.php) |
| Access (per-user roles + overrides + board members) | [`routes/settings.php:54-71`](../routes/settings.php#L54) | `Settings\AccessController` | [`pages/settings/access.tsx`](../resources/js/pages/settings/access.tsx) | `SettingsControllerTest` (`access_*` and `approve_*` groups, ~25 tests) |
| Roles (CRUD + permission sync) | [`routes/settings.php:74-88`](../routes/settings.php#L74) | `Settings\RolesController` | [`pages/settings/roles/{index,edit}.tsx`](../resources/js/pages/settings/roles/index.tsx) | `SettingsControllerTest` (`role_*` group) |
| Terminology | [`routes/settings.php:91-96`](../routes/settings.php#L91) | `Settings\TerminologyController` | [`pages/settings/terminology.tsx`](../resources/js/pages/settings/terminology.tsx) | `SettingsControllerTest` (`terminology_*` group) |
| Branding (logo + theme) | [`routes/settings.php:99-104`](../routes/settings.php#L99) | `Settings\BrandingController` | [`pages/settings/branding.tsx`](../resources/js/pages/settings/branding.tsx) | `SettingsControllerTest` (`branding_*` group, ~10 tests) |
| Service contexts | [`routes/settings.php:107-121`](../routes/settings.php#L107) | `Settings\ServiceContextController` | [`pages/settings/service-contexts.tsx`](../resources/js/pages/settings/service-contexts.tsx) | `SettingsControllerTest` (`service_context_*` group), [`Browser/Settings/ServiceContextInteractionTest.php`](../tests/Browser/Settings/ServiceContextInteractionTest.php) |
| Notifications (user prefs + role defaults + escalations) | [`routes/settings.php:124-149`](../routes/settings.php#L124) | `Settings\NotificationPreferencesController`, `Settings\NotificationEscalationsController`, `Settings\PushSubscriptionController` | [`pages/settings/notifications.tsx`](../resources/js/pages/settings/notifications.tsx), [`notification-defaults.tsx`](../resources/js/pages/settings/notification-defaults.tsx), [`notification-escalations.tsx`](../resources/js/pages/settings/notification-escalations.tsx) | `SettingsControllerTest` (`notification_*` and `escalations_*` groups) |
| Notification templates | [`routes/settings.php:158-172`](../routes/settings.php#L158) | `Settings\NotificationTemplateController` | [`pages/settings/templates.tsx`](../resources/js/pages/settings/templates.tsx) | `SettingsControllerTest` (`templates_*` permission tests, no workflow tests) |
| Email settings | [`routes/settings.php:175-183`](../routes/settings.php#L175) | `Settings\EmailSettingsController` | [`pages/settings/email-settings.tsx`](../resources/js/pages/settings/email-settings.tsx) | `SettingsControllerTest` (auth + permission only), [`Browser/Settings/EmailSettingsInteractionTest.php`](../tests/Browser/Settings/EmailSettingsInteractionTest.php) |
| Security policy | [`routes/settings.php:186-244`](../routes/settings.php#L186) | **Inline closure** (~60 LoC) | [`pages/settings/security.tsx`](../resources/js/pages/settings/security.tsx) | `SettingsControllerTest::test_security_settings_*` (auth + persistence only) |
| API & webhooks | [`routes/settings.php:247-264`](../routes/settings.php#L247) | `Settings\ApiSettingsController` | [`pages/settings/api.tsx`](../resources/js/pages/settings/api.tsx) | [`Browser/Settings/ApiSettingsInteractionTest.php`](../tests/Browser/Settings/ApiSettingsInteractionTest.php) only — **no Feature test** |
| Data & privacy | [`routes/settings.php:267-293`](../routes/settings.php#L267) | `Settings\DataSettingsController` | [`pages/settings/data.tsx`](../resources/js/pages/settings/data.tsx) | [`Browser/Settings/DataSettingsInteractionTest.php`](../tests/Browser/Settings/DataSettingsInteractionTest.php) only |
| Modules & features | [`routes/settings.php:296-301`](../routes/settings.php#L296) | `Settings\ModuleSettingsController` | [`pages/settings/modules.tsx`](../resources/js/pages/settings/modules.tsx) | [`Browser/Settings/ModuleSettingsInteractionTest.php`](../tests/Browser/Settings/ModuleSettingsInteractionTest.php) only |
| Users (read-only listing + show + approve/suspend/sessions) | [`routes/settings.php:304-324`](../routes/settings.php#L304) | **`System\UsersController`** | [`pages/settings/users/{index,show}.tsx`](../resources/js/pages/settings/users/index.tsx) | None (overlaps with `/system/users`) |
| SSO mapping page | [`routes/settings.php:327-337`](../routes/settings.php#L327) | **Inline closure** | [`pages/settings/sso-config.tsx`](../resources/js/pages/settings/sso-config.tsx) | `SettingsControllerTest::test_sso_*` (auth only) |
| SSO groups | [`routes/settings.php:338-352`](../routes/settings.php#L338) | `Settings\SsoGroupController` | [`pages/settings/sso-groups.tsx`](../resources/js/pages/settings/sso-groups.tsx) | None |
| Audit logs | [`routes/settings.php:355-360`](../routes/settings.php#L355) | `Settings\AuditLogSettingsController` | [`pages/settings/audit-logs.tsx`](../resources/js/pages/settings/audit-logs.tsx) | `SettingsControllerTest::test_audit_logs_*`, [`Browser/Settings/AuditLogsInteractionTest.php`](../tests/Browser/Settings/AuditLogsInteractionTest.php) |
| Integrations hub (Settings landing) | [`routes/settings.php:363-365`](../routes/settings.php#L363) | `Settings\IntegrationHubController` | [`pages/settings/integrations/index.tsx`](../resources/js/pages/settings/integrations/index.tsx) | None — should redirect hardware integrations to `/security-devices/integrations`; Microsoft/Google stay in Auth/SSO |
| `/settings/integrations/unifi` | [`routes/settings.php:370-371`](../routes/settings.php#L370) | 301 redirect → `/security-devices/integrations/unifi` | n/a | covered by browser smoke |

**Settings layout** [`resources/js/layouts/settings/layout.tsx`](../resources/js/layouts/settings/layout.tsx)
hardcodes nine nav sections and gates them via the `auth.can.settings.*`
camelCase tree exposed by [`HandleInertiaRequests:477-486`](../app/Http/Middleware/HandleInertiaRequests.php#L477).

### System

| Surface | Route file | Controller | React page | Tests |
|---|---|---|---|---|
| Access dashboard | [`routes/system.php:21-24`](../routes/system.php#L21) | `System\AccessControlController::dashboard` | [`pages/system/access/Dashboard.tsx`](../resources/js/pages/system/access/Dashboard.tsx) | [`Browser/System/SystemAccessDashboardInteractionTest.php`](../tests/Browser/System/SystemAccessDashboardInteractionTest.php) (1 spec) |
| Access roles (system + custom + permissions) | [`routes/system.php:27-41`](../routes/system.php#L27) | `System\AccessControlController::roles*` | [`pages/system/access/Roles.tsx`](../resources/js/pages/system/access/Roles.tsx) | Browser smoke only |
| Permissions matrix | [`routes/system.php:44-46`](../routes/system.php#L44) | `System\AccessControlController::matrix` | [`pages/system/access/Matrix.tsx`](../resources/js/pages/system/access/Matrix.tsx) | Browser smoke only |
| Assignments | [`routes/system.php:49-54`](../routes/system.php#L49) | `System\AccessControlController::assignments*` | [`pages/system/access/Assignments.tsx`](../resources/js/pages/system/access/Assignments.tsx) | Browser smoke only |
| Users CRUD + approve/suspend | [`routes/system.php:64-87`](../routes/system.php#L64) | `System\UsersController` | [`pages/system/users/{Index,Create}.tsx`](../resources/js/pages/system/users/Index.tsx) + [`pages/settings/users/show.tsx`](../resources/js/pages/settings/users/show.tsx) (shared via `isSystemView`) | [`Browser/System/SystemUsersInteractionTest.php`](../tests/Browser/System/SystemUsersInteractionTest.php) (3 specs) |
| Impersonate / stop | [`routes/system.php:57-61`](../routes/system.php#L57) | `System\UsersController::{impersonate,stopImpersonating}` | n/a | None |

There are **no `tests/Feature/System/*`** tests at all (verified via `Glob`).
All System verification is Browser-level page-load smoke or interaction
specs.

### Auth

| Surface | Route file | Controller | Tests |
|---|---|---|---|
| Fortify default routes (login, register, 2FA challenge, password reset, email verification, password confirmation) | n/a (registered by Fortify provider) | Fortify | [`tests/Feature/Auth/`](../tests/Feature/Auth) — 7 files, well covered |
| Google OAuth (redirect/callback, supports `?link=1` linking flow) | [`routes/auth.php:16-19`](../routes/auth.php#L16) | `Auth\GoogleController` | None |
| Microsoft OAuth (requires `ORG_DOMAIN`, auto-`Auth::login` on success, assigns `support_worker` on creation) | [`routes/auth.php:22-25`](../routes/auth.php#L22) | `Auth\MicrosoftController` | None |
| Disconnect provider | [`routes/auth.php:28-34`](../routes/auth.php#L28) | **Inline closure** (deletes all identities for provider, no audit) | None |
| Portal Google/Microsoft OAuth | registered in `routes/portal.php`, **not** `auth.php` | `Auth\PortalOAuthController` | None |

Browser auth coverage: [`tests/Browser/Auth/{Login,PasswordReset,Registration,TwoFactor}Test.php`](../tests/Browser/Auth/LoginTest.php)
— page loads + happy-path login + invalid credentials + redirect-to-login.

### Integrations

| Surface | Route file | Controller | Tests |
|---|---|---|---|
| `POST /webhooks/{provider}` (10 providers: unifi, queclink, milesight, hikvision, gallagher, axis, paradox, dsc, bosch, generic) | [`routes/integrations.php:27-30`](../routes/integrations.php#L27) | `Api\WebhookReceiverController` | **None** for the receiver itself |
| Legacy `/integrations/unifi` → 301 → `/security-devices/integrations/unifi` | [`routes/integrations.php:17`](../routes/integrations.php#L17) | n/a | covered indirectly by browser smoke |
| Legacy `/workers` → 301 → `/staff` | [`routes/integrations.php:23`](../routes/integrations.php#L23) | n/a | none |
| Real integrations hub (the one with provider lifecycle) | `routes/security-devices.php` | `SecurityDevices\IntegrationsHubController` | [`tests/Feature/SecurityDevices/IntegrationsHubTest.php`](../tests/Feature/SecurityDevices/IntegrationsHubTest.php) |

### Compatibility surfaces to preserve

- `/settings/notification-escalations` 301 → `/settings/notifications/escalations` ([`routes/settings.php:152`](../routes/settings.php#L152))
- `/settings/notification-roles` 301 → `/settings/notifications/roles` ([`routes/settings.php:154`](../routes/settings.php#L154))
- `/settings/integrations/unifi` 301 → `/security-devices/integrations/unifi` ([`routes/settings.php:370`](../routes/settings.php#L370))
- `/integrations/unifi` 301 → `/security-devices/integrations/unifi` ([`routes/integrations.php:17`](../routes/integrations.php#L17))
- `/workers` 301 → `/staff` ([`routes/integrations.php:23`](../routes/integrations.php#L23))
- `/settings` → `/settings/profile` redirect ([`routes/settings.php:28`](../routes/settings.php#L28))

---

## 2. Why This Was Marked Partial

Concrete repo evidence, separating **scaffolding exists** from
**production-ready workflow is complete**:

### Scaffolding exists, workflow incomplete

| Area | Evidence | Why partial |
|---|---|---|
| **`/settings/security` policy form** | Inline `PUT` route at [`routes/settings.php:207-244`](../routes/settings.php#L207) writes 9 keys (`force_2fa`, `password_min_length`, `password_require_uppercase`, `session_timeout_minutes`, `max_login_attempts`, `lockout_duration_minutes`, …) into `app_settings`. **No code reads them**: `grep "force_2fa\|password_min_length\|session_timeout_minutes" app/` returns zero non-route results. | Operators set a security policy that does not influence Fortify, the login flow, or session lifetime. Test [`SettingsControllerTest::test_security_settings_update_persists_values_without_group_column`](../tests/Feature/SettingsControllerTest.php) only verifies persistence, not enforcement. |
| **`/settings/api` outbound webhook config** | [`ApiSettingsController`](../app/Http/Controllers/Settings/ApiSettingsController.php) stores webhooks/keys as JSON in `app_settings` under `settings.api.keys` / `settings.api.webhooks`. | The actual hardware webhook receiver at [`WebhookReceiverController:38-57`](../app/Http/Controllers/Api/WebhookReceiverController.php#L38) authenticates against `IntegrationTenantSecret` model rows owned by Security & Devices. Settings API configuration must not be presented as hardware integration setup; if it remains, it should be explicitly non-hardware outbound/API configuration. |
| **OAuth callbacks** | [`MicrosoftController:57`](../app/Http/Controllers/Auth/MicrosoftController.php#L57) does `abort_unless($orgDomain !== '', 500, 'ORG_DOMAIN is not set.')` and [`MicrosoftController:96`](../app/Http/Controllers/Auth/MicrosoftController.php#L96) calls `Auth::login` immediately on success, bypassing the approval flow that [`GoogleController:69-71`](../app/Http/Controllers/Auth/GoogleController.php#L69) enforces. | A missing env var produces a hard 500 in production. Two OAuth providers have inconsistent approval semantics. Zero Feature tests exist for either callback path (verified: no `tests/**/Sso*`, no `tests/**/GoogleC*`, no `tests/**/MicrosoftC*`). |
| **`WebhookReceiverController` ingestion** | The single entry point for integration events, with provider-specific parsers for 9+ vendors and a routing service dispatch ([`receive:115`](../app/Http/Controllers/Api/WebhookReceiverController.php#L115)). | No Feature tests cover key auth, signature verification (`X-Webhook-Signature` HMAC), dedup via `source_event_id`, or the parsers. The HR-specific [`HrWebhookDeliveryTest`](../tests/Feature/Hr/HrWebhookDeliveryTest.php) is unrelated. |
| **Impersonation** | [`UsersController::impersonate`](../app/Http/Controllers/System/UsersController.php#L466) and `stopImpersonating` exist with `AuditLogger` calls, role-based blocks, and `canBeImpersonated` checks. | No Feature tests verify the start/stop, audit emission, or self/admin guard rails. |
| **`UsersController` destructive actions** | [`destroy`](../app/Http/Controllers/System/UsersController.php#L366), [`suspend`](../app/Http/Controllers/System/UsersController.php#L416), [`approve`](../app/Http/Controllers/System/UsersController.php#L389), [`terminateSession`](../app/Http/Controllers/System/UsersController.php#L436), [`terminateAllSessions`](../app/Http/Controllers/System/UsersController.php#L451), `store`, `update` all mutate state. | None call `AuditLogger`, while the parallel `Settings\AccessController` consistently does. Compliance gap. |

### Duplicated / overlapping ownership

- **`/settings/users` vs `/system/users`** — both gated on `settings.access.manage`, both wired to `App\Http\Controllers\System\UsersController` ([`routes/settings.php:304-324`](../routes/settings.php#L304) and [`routes/system.php:64-87`](../routes/system.php#L64)). The shared show page detects the mode via `page.url.startsWith('/system/users')` ([`pages/settings/users/show.tsx:232`](../resources/js/pages/settings/users/show.tsx#L232)). Side-effect: every `system.users.*` route name has a near-twin under `settings.users.*`.
- **`/settings/access` (per-user roles + overrides + board members)** vs **`/system/access/*`** (system roles dashboard + matrix + assignments). Functionally complementary but share one permission key, and the Settings layout sidebar links only to `/settings/users` + `/settings/roles` + `/settings/access`. The main `app-sidebar.tsx` only links to `/settings`, so `/system/*` is reachable only via the dashboard cards or direct URL.
- **`/settings/integrations` vs `/security-devices/integrations`** — Settings hub hardcodes 5 hardware providers (`unifi`, `queclink`, `hikvision`, `iot`, `generic_webhook`) at [`IntegrationHubController:16-22`](../app/Http/Controllers/Settings/IntegrationHubController.php#L16) but only renders a `Configure` button for UniFi, with everything else as `Coming Soon` ([`pages/settings/integrations/index.tsx:95-103`](../resources/js/pages/settings/integrations/index.tsx#L95)). The real hardware lifecycle (`providers_total`, `providers_live`, `providers_connected`, `providers_errored`, `events_24h`) lives at `/security-devices/integrations` per [`IntegrationsHubTest`](../tests/Feature/SecurityDevices/IntegrationsHubTest.php). Product decision: move hardware integrations to Security & Devices; keep Microsoft/Google as identity integrations.
- **`/settings/sso` vs `/settings/sso-groups`** — first is an inline closure rendering `pages/settings/sso-config.tsx`, second renders `pages/settings/sso-groups.tsx` via `SsoGroupController`. Both linked separately from the Settings layout's "Identity & SSO" section.

### Hygiene / capability map drift

- [`HandleInertiaRequests:484`](../app/Http/Middleware/HandleInertiaRequests.php#L484) exposes `can.settings.rbacManage` and `can.settings.sitesManage`, which map to seeded permissions `settings.rbac.manage` and `settings.sites.manage` ([`RbacSeeder.php:394`](../database/seeders/RbacSeeder.php#L394) and [`:396`](../database/seeders/RbacSeeder.php#L396)). Neither permission is referenced by any route's middleware (verified via `grep`). The `app-sidebar.tsx:1789` "Roles & Permissions" entry is the only consumer, and it never resolves.
- Inline route closures: `/settings/security` GET+PUT, `/settings/sso` GET, `/auth/{provider}/disconnect` POST. Each is testable but only as an HTTP route; not as a class. Inconsistent with the rest of the module.
- `Auth\PortalOAuthController` ([`PortalOAuthController.php`](../app/Http/Controllers/Auth/PortalOAuthController.php)) duplicates the Google/Microsoft Socialite flow but is registered in `routes/portal.php`, not `routes/auth.php`. No test coverage.

---

## 3. Production-Readiness Gaps

### P0 — must fix before production

#### P0-1 — `/settings/security` policy form has no enforcement
- **Evidence:** [`routes/settings.php:207-244`](../routes/settings.php#L207) inline closure persists 9 policy keys; `grep -r "force_2fa\|password_min_length\|session_timeout_minutes\|max_login_attempts\|lockout_duration_minutes" app/` returns zero non-route hits. [`SettingsControllerTest::test_security_settings_update_persists_values_without_group_column`](../tests/Feature/SettingsControllerTest.php) only verifies persistence.
- **Impact:** Operators are misled into believing they have configured a security policy. In a NZ supported-living context this is a genuine compliance and safety problem — staff turnover means lockouts, password rotation, and 2FA-mandate flips are expected to function.
- **Minimal fix:** Either (a) wire the keys into Fortify (`Features::twoFactorAuthentication`, `LoginRateLimiter`, `Password::min(...)`) and into a `LastActivityMiddleware` for session timeout, or (b) hide the form behind a feature flag with a banner reading "policy enforcement coming soon" until enforcement lands. Decide via Q1 below.
- **Files likely affected:** `routes/settings.php` (closure or new `Settings\SecurityPolicyController`); `app/Providers/FortifyServiceProvider.php`; possibly a new `app/Http/Middleware/EnforceSessionTimeout.php`. If hiding: `resources/js/pages/settings/security.tsx`.
- **Acceptance criteria:** A new Feature test asserts that, with `force_2fa=true`, a user without `two_factor_confirmed_at` is redirected to `/settings/two-factor` before reaching `/dashboard`; `password_min_length=12` rejects an 11-char password at register/reset; `lockout_duration_minutes` extends the rate-limiter window.

#### P0-2 — `/settings/api` webhooks do not connect to `/webhooks/{provider}`
- **Evidence:** [`ApiSettingsController::storeWebhook`](../app/Http/Controllers/Settings/ApiSettingsController.php#L132) writes to `app_settings.settings.api.webhooks`. [`WebhookReceiverController:38`](../app/Http/Controllers/Api/WebhookReceiverController.php#L38) reads `IntegrationTenantSecret`. Two storage paths, zero shared lookups. The "Test webhook" button in the Settings UI ([`testWebhook:179`](../app/Http/Controllers/Settings/ApiSettingsController.php#L179)) calls the configured outbound URL via `Http::post(...)` — it does **not** simulate the inbound `/webhooks/{provider}` flow.
- **Impact:** Misleading UX — operators believe configuring a Settings webhook makes the receiver accept it. In practice the receiver only honours secrets surfaced via `/security-devices/integrations`. This will be a root-cause for "we set up the webhook and nothing fires" support tickets.
- **Minimal fix:** Reframe `/settings/api` as **outbound**-only (egress webhooks for client-created/shift-completed events, etc., as the `AVAILABLE_EVENTS` list already implies) and rename the section heading + add an explanatory paragraph linking to `/security-devices/integrations` for inbound. Optionally hide the section behind `integrations.manage_secrets` until an outbound-dispatcher exists. Pair with documentation in the integrations hub.
- **Files likely affected:** `resources/js/pages/settings/api.tsx` (copy + section split); `app/Http/Controllers/Settings/ApiSettingsController.php` (perhaps remove the `testWebhook` ambiguity); `resources/js/pages/settings/integrations/index.tsx` (cross-link).
- **Acceptance criteria:** Settings/API page reads as outbound-only; a new Feature test verifies that the `/settings/api/webhooks` and `/webhooks/{provider}` stores are independent and documented as such.

#### P0-3 — `WebhookReceiverController` has zero Feature tests
- **Evidence:** No `tests/**/WebhookReceiver*.php` (verified via `Glob`). No `tests/**` greps for `webhooks.receive` (the route name) or `/webhooks/`.
- **Impact:** A regression in key validation, signature verification, dedup, or any of the 9 provider parsers (UniFi, Queclink, Milesight, Hikvision, Gallagher, Axis, Paradox, DSC, Bosch) ships silently. This is the entire ingestion surface for security-device events that drive Control Room alerts.
- **Minimal fix:** Add `tests/Feature/Integrations/WebhookReceiverTest.php` covering: (a) missing `X-Integration-Key` → 401; (b) wrong key → 401; (c) wrong provider for matching key → 401; (d) `X-Webhook-Signature` mismatch → 401; (e) duplicate `source_event_id` → 200 `status: duplicate`; (f) one happy-path Feature test per provider parser asserting normalised severity + `event_type`; (g) `AlertRoutingService` is invoked exactly once on accepted events (mock the service).
- **Files likely affected:** New `tests/Feature/Integrations/WebhookReceiverTest.php` (~250 LoC). Possibly `tests/Feature/Integrations/Parsers/{Unifi,Queclink,Milesight,...}ParserTest.php` if you split per-parser.
- **Acceptance criteria:** New tests pass with current `WebhookReceiverController`; coverage ≥ 80% on the controller per `php artisan test --coverage`.

#### P0-4 — Microsoft OAuth callback hard-500s when `ORG_DOMAIN` missing; no tests for either OAuth path
- **Evidence:** [`MicrosoftController:57`](../app/Http/Controllers/Auth/MicrosoftController.php#L57) — `abort_unless($orgDomain !== '', 500, 'ORG_DOMAIN is not set.')`. [`MicrosoftController:96`](../app/Http/Controllers/Auth/MicrosoftController.php#L96) auto-`Auth::login` on success while [`GoogleController:69`](../app/Http/Controllers/Auth/GoogleController.php#L69) requires manual approval. No Feature tests exist (verified via `Glob` for any test naming Google/Microsoft/Sso).
- **Impact:** Two failure modes — (1) deploying with missing env var produces a 500 at the callback, breaking SSO login; (2) inconsistent approval semantics between providers means an org admin who relies on the Google "approval-required" guarantee may be surprised that Microsoft auto-grants `support_worker` access on creation.
- **Minimal fix:** Replace the 500 with a flash redirect to `/login` carrying a clear "Microsoft SSO not configured" message and a 503 in a JSON context. Decide via Q3 below whether Microsoft should also gate on `approved_at`. Add Feature tests using Socialite mocks: success + new user, success + existing user, no email returned, ORG_DOMAIN mismatch, ORG_DOMAIN missing, link-mode flow.
- **Files likely affected:** `app/Http/Controllers/Auth/MicrosoftController.php`, `app/Http/Controllers/Auth/GoogleController.php`, new `tests/Feature/Auth/OAuth/{GoogleCallbackTest,MicrosoftCallbackTest}.php`.
- **Acceptance criteria:** New tests cover each branch; missing-env failure mode is a graceful redirect, not a 500.

#### P0-5 — `System\UsersController` mutations do not write audit logs
- **Evidence:** [`UsersController::destroy`](../app/Http/Controllers/System/UsersController.php#L366), `suspend`, `approve` (in System), `terminateSession`, `terminateAllSessions`, `store`, `update` — none call `AuditLogger`. By contrast `Settings\AccessController` and the security-settings closure both call it. Only `impersonate`/`stopImpersonating` log.
- **Impact:** Privacy/compliance gap. NZ Health Information Privacy Code requires demonstrable audit trails for admin actions on user accounts. Approve/suspend/delete are exactly the actions an auditor will sample.
- **Minimal fix:** Add `AuditLogger::log('user.created'|'user.updated'|'user.deleted'|'user.suspended'|'user.approved'|'user.session.terminated', $target, [...])` in each method. Reuse the pattern from `Settings\AccessController::approve`.
- **Files likely affected:** `app/Http/Controllers/System/UsersController.php` (~12 lines added).
- **Acceptance criteria:** Each mutation has an `audit_logs` row asserted via Feature test (introduced in P1-3).

### P1 — should fix before public launch

#### P1-1 — Two parallel user-management surfaces (`/settings/users` ↔ `/system/users`)
- **Evidence:** Both gated on `settings.access.manage`; both wired to `System\UsersController`; show page is shared via `isSystemView` flag at [`pages/settings/users/show.tsx:232`](../resources/js/pages/settings/users/show.tsx#L232). The `/settings/users` index renders `pages/settings/users/index.tsx` while `/system/users` renders `pages/system/users/Index.tsx` — two index pages backed by the same controller method ([`UsersController::index`](../app/Http/Controllers/System/UsersController.php#L25)).
- **Impact:** Maintenance overhead (two pages to update for the same data), operator confusion, and double the test surface.
- **Minimal fix:** Pick `/system/users` as canonical (it has create/destroy + impersonate, the broader feature set). Convert `/settings/users` GET to a 301 redirect; preserve `/settings/users/{target}` POST/PUT/DELETE route names that JS may still reference (verify via grep before removing). Update Settings layout's "User Management" section to link to `/system/users`.
- **Files likely affected:** `routes/settings.php` (lines 304-324: replace controller routes with `Route::redirect`); `resources/js/layouts/settings/layout.tsx:75` (update href); optionally delete `resources/js/pages/settings/users/index.tsx`.
- **Acceptance criteria:** Browser smoke `/settings/users` → 301 → `/system/users`; `php artisan route:list | grep users` shows no duplicate route names; existing `Browser/System/SystemUsersInteractionTest.php` continues to pass.

#### P1-2 — Hardware integrations are incorrectly presented from Settings
- **Evidence:** [`IntegrationHubController:16-22`](../app/Http/Controllers/Settings/IntegrationHubController.php#L16) hardcodes 5 providers; [`pages/settings/integrations/index.tsx:95-103`](../resources/js/pages/settings/integrations/index.tsx#L95) renders all non-UniFi as "Coming Soon". The real `SecurityDevices\IntegrationsHubController` exposes 3 providers with a real `IntegrationTenantSecret` lifecycle, tested by [`IntegrationsHubTest`](../tests/Feature/SecurityDevices/IntegrationsHubTest.php).
- **Impact:** UI promises hardware setup from Settings even though the live lifecycle belongs to Security & Devices. Increases support load and splits ownership.
- **Minimal fix:** Convert `/settings/integrations` to a 301 redirect to `/security-devices/integrations` and remove the Settings-only hardware hub controller/page. Keep Microsoft/Google out of this redirect because they are identity/SSO integrations, not hardware integrations. Preserve the existing legacy redirect for `/settings/integrations/unifi`.
- **Files likely affected:** `routes/settings.php:363-371` (replace with redirect); `resources/js/layouts/settings/layout.tsx:101` (point Hardware Integrations link at `/security-devices/integrations`); delete `app/Http/Controllers/Settings/IntegrationHubController.php` and `resources/js/pages/settings/integrations/index.tsx`.
- **Acceptance criteria:** `Browser/Settings/SettingsTest.php` "integrations settings page loads" expects a 301 to `/security-devices/integrations`; `IntegrationsHubTest` still passes; Microsoft/Google OAuth links remain in Auth/SSO surfaces.

#### P1-3 — Zero Feature tests for any `/system/*` route
- **Evidence:** `Glob "tests/Feature/System/**"` returns nothing. Browser coverage exists but is shallow: 6 page-load tests + 4 interaction tests across `Browser/System/{SystemTest,SystemUsersInteractionTest,SystemAccessDashboardInteractionTest}`.
- **Impact:** RBAC dashboard, role CRUD, permissions matrix, user assignments, impersonate/stop are unprotected against backend regressions.
- **Minimal fix:** Add `tests/Feature/System/{AccessControlControllerTest,UsersControllerTest,ImpersonationTest}.php` mirroring the structure of `tests/Feature/SettingsControllerTest.php`: auth required → permission denied for support_worker → happy paths → validation → side effects (legacy `users.role` sync, audit log write, system-role guard on destroy).
- **Files likely affected:** New tests only.
- **Acceptance criteria:** ≥ 30 new Feature tests; each `System\*` controller method has at least one happy-path test and one negative test.

#### P1-4 — `SsoGroupController` (and `fetchGroups` external-IdP call) has no tests
- **Evidence:** No `tests/**/Sso*` file. [`routes/settings.php:350-352`](../routes/settings.php#L350) registers `POST /settings/sso-groups/fetch` which presumably hits Microsoft Graph or Google Directory APIs.
- **Impact:** SSO group → role mapping is the auth boundary for org-managed users. Untested.
- **Minimal fix:** Add `tests/Feature/Settings/SsoGroupControllerTest.php` — auth gate, permission gate, mapping CRUD, `fetchGroups` happy path with a mocked HTTP client + error handling for IdP failures.
- **Files likely affected:** New test file; possibly minor hardening to `SsoGroupController::fetchGroups` for testability (inject HTTP factory).
- **Acceptance criteria:** ≥ 8 new Feature tests; mocked external call asserted.

#### P1-5 — `Auth\PortalOAuthController` duplicates the Google/Microsoft flow
- **Evidence:** [`PortalOAuthController.php`](../app/Http/Controllers/Auth/PortalOAuthController.php) — `redirectGoogle`/`callbackGoogle`/`redirectMicrosoft`/`callbackMicrosoft`. Registered in `routes/portal.php` (not the auth-domain route file). No tests.
- **Impact:** Two parallel OAuth code paths drift independently.
- **Minimal fix:** Either consolidate into the main `Auth\GoogleController`/`MicrosoftController` with a portal-flag query parameter, or document why the portal flow needs its own controller (different role assignment, different post-login redirect). Pair with Feature tests like P0-4.
- **Files likely affected:** `app/Http/Controllers/Auth/PortalOAuthController.php` (delete or document); `routes/portal.php` (update); new tests.
- **Acceptance criteria:** Either one OAuth controller per provider, or both controllers have explicit "scope: web vs portal" docblocks.

#### P1-6 — Inline route closures should become controllers
- **Evidence:** `/settings/security` GET+PUT (~70 LoC inline at [`routes/settings.php:186-244`](../routes/settings.php#L186)); `/settings/sso` GET (inline closure with DB queries at [`routes/settings.php:327-337`](../routes/settings.php#L327)); `/auth/{provider}/disconnect` POST (inline at [`routes/auth.php:28-34`](../routes/auth.php#L28)).
- **Impact:** Closures are harder to extend, harder to test in isolation, and inconsistent with the rest of the module.
- **Minimal fix:** Promote each to a controller method (`Settings\SecurityPolicyController`, `Settings\SsoConfigController`, `Auth\IdentityDisconnectController`) without changing route URLs or names.
- **Files likely affected:** `routes/settings.php`, `routes/auth.php`, three new thin controllers.
- **Acceptance criteria:** No `Route::get|put|post('...', function (...) {...})` in any of the four route files (`auth.php`, `integrations.php`, `settings.php`, `system.php`).

#### P1-7 — Capability-map drift: seeded permissions never used
- **Evidence:** [`HandleInertiaRequests:484`](../app/Http/Middleware/HandleInertiaRequests.php#L484) exposes `can.settings.rbacManage` and `can.settings.sitesManage`. [`RbacSeeder:394`](../database/seeders/RbacSeeder.php#L394) and [`:396`](../database/seeders/RbacSeeder.php#L396) seed `settings.sites.manage` and `settings.rbac.manage`. No route uses either as middleware. The only consumer is `app-sidebar.tsx:1789` "Roles & Permissions".
- **Impact:** Either the nav entry is dead, or the routes are missing the permission gate.
- **Minimal fix:** Decide: if "Roles & Permissions" should link to `/system/access/roles`, swap the gate to `settings.access.manage` and remove the unused seeded permissions. Otherwise wire them properly.
- **Files likely affected:** `app/Http/Middleware/HandleInertiaRequests.php`, `database/seeders/RbacSeeder.php`, `resources/js/components/app-sidebar.tsx`.
- **Acceptance criteria:** No seeded permissions without a referencing route or capability check.

#### P1-8 — Impersonation has no Feature tests
- **Evidence:** `grep "impersonate" tests/Feature` returns no matches. Only Browser smoke implicitly exercises this via interaction tests.
- **Impact:** Impersonation is a privileged action with audit obligations; lack of test coverage is a compliance risk.
- **Minimal fix:** Add `tests/Feature/System/ImpersonationTest.php` covering: (a) requires `settings.access.impersonate`; (b) cannot impersonate self; (c) cannot impersonate admin; (d) writes `user.impersonate.start` audit; (e) `stopImpersonating` writes `user.impersonate.stop` audit and redirects; (f) `canBeImpersonated` guard.
- **Files likely affected:** New test file.
- **Acceptance criteria:** ≥ 6 tests passing.

### P2 — nice-to-have

#### P2-1 — `/settings/sso` and `/settings/sso-groups` overlap
- Combine into a single `/settings/sso` page with two tabs (mappings and groups).
- Files: `routes/settings.php` (one inline closure + 5 controller routes consolidate); `pages/settings/sso-config.tsx` and `pages/settings/sso-groups.tsx` merge.

#### P2-2 — Centralise audit logging for user mutations
- After P0-5 lands, consider an `Eloquent observer` on `User` for create/update/delete to avoid scattering `AuditLogger::log` calls. Optional.

#### P2-3 — Settings/Access overlaps with System/Access/Assignments
- Once P1-1 redirects `/settings/users` to `/system/users`, evaluate whether `/settings/access` (per-user overrides + board members) should also move under `/system/access/` for symmetry. Board-member management arguably belongs under Governance.

#### P2-4 — `Browser/Settings/SettingsTest.php` page-load duplicates
- Each "page loads" test in `SettingsTest.php` re-asserts what `SettingsControllerTest.php` already covers via `Inertia\Testing\AssertableInertia`. Keep them as smoke but consider lower-priority Playwright migration if/when Dusk is deprecated.

---

## 4. What Not To Change

These behaviours are correct and should be preserved:

- **Fortify-driven login, register, 2FA challenge, password reset, email
  verification, password confirmation** — all routes auto-registered by
  `Laravel\Fortify\FortifyServiceProvider`, well covered by
  [`tests/Feature/Auth/`](../tests/Feature/Auth) (7 files), and stable.
- **Existing route names** — many are referenced from JS via the
  `@/routes/profile`, `@/routes/two-factor`, `@/routes/user-password` import
  paths in [`layouts/settings/layout.tsx:2-5`](../resources/js/layouts/settings/layout.tsx#L2).
  Renaming any of `profile.edit`, `profile.update`, `user-password.edit`,
  `user-password.update`, `two-factor.show` would silently break these
  imports.
- **`settings.access.manage` as the umbrella RBAC gate** — used by both
  Settings and System routes; cleanly separates RBAC admin from end-user
  settings.
- **Compatibility redirects** — `/settings/notification-escalations`,
  `/settings/notification-roles`, `/settings/integrations/unifi`,
  `/integrations/unifi`, `/workers`, and the `/settings` → `/settings/profile`
  default. All preserved as 301s; preserve them.
- **Google OAuth approval-required behaviour** — [`GoogleController:69-71`](../app/Http/Controllers/Auth/GoogleController.php#L69)
  is the safer default. **Do not flip Google to auto-login** to "match"
  Microsoft. The opposite direction (Microsoft also requiring approval) is
  the safer reconciliation if Q3 is answered that way.
- **`WebhookReceiverController` parsers for the 9 supported providers** —
  they are working ingestion logic. Add tests, do not rewrite.
- **`AccessController::approve` and `update` audit-log calls** — the
  reference pattern for P0-5 audit additions.
- **`canBeImpersonated`, `hasRole('admin')`, and `id !== self` impersonation
  guards** at [`UsersController::impersonate:467-472`](../app/Http/Controllers/System/UsersController.php#L467) — keep
  intact; add tests.
- **`X-Integration-Key` header + optional `X-Webhook-Signature` HMAC** at
  [`WebhookReceiverController:29-66`](../app/Http/Controllers/Api/WebhookReceiverController.php#L29) — keep the
  contract; just test it.
- **`tests/Feature/SettingsControllerTest.php`** — comprehensive (~140
  tests, 2235 LoC). Do not rewrite; only extend with `templates_*` workflow
  tests if templates change.

---

## 5. Implementation Plan

PR-sized, ordered low → high blast radius.

### PR1 — `WebhookReceiverController` Feature tests *(P0-3)*
- Add `tests/Feature/Integrations/WebhookReceiverTest.php`.
- Pure additive — no controller or route changes.
- ~250 LoC of tests.

### PR2 — System Feature test pack *(P1-3, P1-8, supports P0-5)*
- Add `tests/Feature/System/{AccessControlControllerTest,UsersControllerTest,ImpersonationTest}.php`.
- Pure additive.
- ~400 LoC of tests; baseline coverage for the System module before mutating it.

### PR3 — `System\UsersController` audit logging *(P0-5)*
- Add `AuditLogger::log` calls in 7 mutation methods.
- Extend PR2's tests to assert the audit rows.

### PR4 — Microsoft OAuth graceful degradation + OAuth Feature tests *(P0-4)*
- Replace `abort_unless($orgDomain)` with a flash redirect.
- Add `tests/Feature/Auth/OAuth/{GoogleCallbackTest,MicrosoftCallbackTest}.php` using Socialite mock.
- Decide via Q3 whether to gate Microsoft on `approved_at`. Default: yes (safer).

### PR5 — `/settings/security` enforcement OR feature flag *(P0-1)*
- Decide via Q1.
- If wiring: bind `force_2fa` to `EnforceTwoFactorMiddleware`, `password_min_length` to a Fortify password rule, `session_timeout_minutes` to a `LastActivityMiddleware`. Add Feature tests.
- If hiding: gate the route + nav link behind `feature_flags.security_policy_enforcement`.

### PR6 — `/settings/api` non-hardware outbound rebrand *(P0-2)*
- Update `pages/settings/api.tsx` copy + section split so it cannot be mistaken
  for hardware integration setup.
- Add a clear link to `/security-devices/integrations` for hardware providers
  and device webhooks.
- Pair with PR7.

### PR7 — Hardware integrations redirect *(P1-2)*
- Convert `routes/settings.php:363-365` to `Route::redirect('settings/integrations', '/security-devices/integrations', 301)`.
- Delete the Settings hardware hub controller/page.
- Update Settings layout link to label the destination as hardware/device integrations.
- Preserve `/settings/integrations/unifi` redirect untouched.
- Keep Microsoft/Google in the identity/SSO/Auth surfaces.

### PR8 — `/settings/users` → `/system/users` consolidation *(P1-1)*
- Convert `routes/settings.php:304-324` GET to redirect; keep mutation routes only if any JS still posts to them (verify via grep first).
- Update Settings layout "User Management" link to `/system/users`.

### PR9 — Promote inline closures to controllers *(P1-6)*
- New `Settings\SecurityPolicyController` (after PR5), `Settings\SsoConfigController`, `Auth\IdentityDisconnectController`. Existing inline closures move 1:1.
- No URL or route-name changes.

### PR10 — `SsoGroupController` Feature tests + fetch hardening *(P1-4)*
- Inject `Http::factory()` for testability; add tests for the fetch path with mocked Graph/Directory responses.

### PR11 — `PortalOAuthController` consolidation review *(P1-5)*
- Either delete and route portal callbacks through a flag on `Auth\GoogleController`/`MicrosoftController`, or document the split with explicit scope docblocks.
- Pair with Feature tests.

### PR12 — Cleanup unused capability keys *(P1-7)*
- Remove `settings.rbac.manage` and `settings.sites.manage` (or wire them).
- Remove the corresponding keys from `HandleInertiaRequests`.
- Repoint `app-sidebar.tsx:1789` "Roles & Permissions" entry.

### PR13 — P2 polish (optional, batched)
- SSO page merge, audit observer, browser-smoke dedupe.

---

## 6. Verification Plan

### Feature tests (Pest/PHPUnit)

```bash
php artisan test --testsuite=Feature \
  --filter "SettingsController|Auth\\\\|Settings\\\\|System\\\\|WebhookReceiver|Sso|Integration|Impersonation"
```

Specifically:
- [`tests/Feature/SettingsControllerTest.php`](../tests/Feature/SettingsControllerTest.php) (existing, ~140 tests).
- [`tests/Feature/Settings/{Profile,Password,TwoFactor}*Test.php`](../tests/Feature/Settings) (existing).
- [`tests/Feature/Auth/*.php`](../tests/Feature/Auth) (existing, 7 files).
- [`tests/Feature/SecurityDevices/IntegrationsHubTest.php`](../tests/Feature/SecurityDevices/IntegrationsHubTest.php) (existing).
- New (per PR plan): `tests/Feature/Integrations/WebhookReceiverTest.php`,
  `tests/Feature/System/{AccessControlController,UsersController,Impersonation}Test.php`,
  `tests/Feature/Auth/OAuth/{GoogleCallback,MicrosoftCallback}Test.php`,
  `tests/Feature/Settings/SsoGroupControllerTest.php`.

### Type / build

```bash
npm run types
npm run build
```

Watch for breakages caused by any deletion of `pages/settings/integrations/index.tsx`
(PR7) or `pages/settings/users/index.tsx` (PR8).

### Browser / Playwright

Existing relevant suites — keep green:
- [`tests/Browser/Settings/{SettingsTest,SettingsAccessRestrictionsTest,ProfileSettingsInteractionTest,ServiceContextInteractionTest,AuditLogsInteractionTest,ModuleSettingsInteractionTest,EmailSettingsInteractionTest,ApiSettingsInteractionTest,DataSettingsInteractionTest}.php`](../tests/Browser/Settings).
- [`tests/Browser/System/{SystemTest,SystemUsersInteractionTest,SystemAccessDashboardInteractionTest}.php`](../tests/Browser/System).
- [`tests/Browser/Auth/{LoginTest,RegistrationTest,PasswordResetTest,TwoFactorTest}.php`](../tests/Browser/Auth).

After PR8: update `Browser/Settings/SettingsAccessRestrictionsTest.php` so
its `/settings/users` assertion expects a 301 to `/system/users`.

After PR2: consider migrating `Browser/System/SystemTest.php` page-load tests
to e2e specs once Feature equivalents land.

### Permission setup for deterministic tests

[`Database\Seeders\RbacSeeder`](../database/seeders/RbacSeeder.php) is the
canonical seeder; both `SettingsControllerTest` and `SecurityAccessControlTest`
already use it. New System / OAuth / Webhook tests should follow the same
pattern:

```php
$this->seed(\Database\Seeders\RbacSeeder::class);

$admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
$admin->roles()->attach(Role::where('name', 'admin')->first());
```

For Browser tests, [`Database\Seeders\DuskDatabaseSeeder`](../database/seeders/DuskDatabaseSeeder.php)
already creates the standard `admin@test.com` and `staff@test.com` users
referenced across the existing Browser suite.

For `IntegrationsHubTest` and any new SecurityDevices-adjacent webhook tests,
also seed [`SecurityDevicesPermissionsSeeder`](../database/seeders/SecurityDevicesPermissionsSeeder.php).

---

## 7. Open Questions / Risks

### Open questions (need product confirmation)

- **Q1 — `/settings/security` policy enforcement.** Is the form a
  forward-looking placeholder (we plan to wire enforcement later) or an
  existing capability that regressed? Answer drives PR5: wire vs hide.
- **Q2 — Non-hardware outbound webhooks.** Hardware providers and inbound
  device webhooks are no longer open: they belong to Security & Devices. Does
  the product still need non-hardware outbound webhooks (e.g. notify a
  tenant's Slack on `client.created`)? Answer drives PR6 scope: keep
  `/settings/api` as outbound-only with a real dispatcher, or remove it.
- **Q3 — Microsoft OAuth approval semantics.** Should Microsoft callback
  also require manual approval like Google, or is the
  org-domain-restricted auto-login the intended single-tenant trust model?
  Answer drives PR4.
- **Q4 — Canonical RBAC home.** Should `/system/*` be the canonical home
  with `/settings/users` + `/settings/access` redirecting to it, or
  vice versa? PR8 assumes System wins (broader feature set: create, destroy,
  impersonate); confirm before merging.
- **Q5 — Portal OAuth.** Is the Portal OAuth surface (next-of-kin /
  family logging into `/portal` via Google or Microsoft) an active product
  or legacy from earlier portal exploration? Answer drives PR11: consolidate
  vs document-the-split.

### Risks

- **Renames break JS link helpers.** The `@/routes/profile`, `@/routes/two-factor`,
  `@/routes/user-password` imports in [`layouts/settings/layout.tsx:2-5`](../resources/js/layouts/settings/layout.tsx#L2)
  are auto-generated from route names. Do not rename the underlying routes
  in PR9 (controller promotion); only relocate the closure body.
- **Removing Settings/Integrations hardware cards is required.** "Hikvision",
  "IoT Sensors", "Generic Webhook", and similar hardware/device providers are
  owned by Security & Devices; the old Settings tiles are inert "Coming Soon"
  placeholders
  ([`pages/settings/integrations/index.tsx:101-103`](../resources/js/pages/settings/integrations/index.tsx#L101)).
  Verify no marketing pages reference them before PR7. Microsoft and Google
  are not part of this move because they are Auth/SSO identity providers.
- **`PortalOAuthController` deletion needs a portal-side review.** Before
  PR11, verify with the portal team that no portal-only routing depends on
  that controller's specific `Auth::login`-with-`portal`-role behaviour.
- **`/settings/api` rebrand could surprise existing operators.** Minor — the
  feature isn't enforced today (PR6 acceptance includes a copy update),
  but flag it in the release note.
- **Microsoft OAuth gating change (PR4) flips a security default.** If Q3
  resolves to "Microsoft must require approval", existing test/staging users
  who previously auto-logged-in will be locked out until an admin approves
  them. Coordinate with deployment.
