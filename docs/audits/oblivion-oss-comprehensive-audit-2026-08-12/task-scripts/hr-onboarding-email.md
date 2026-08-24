# HR-ONBOARDING-EMAIL: Onboarding Email

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.onboarding.view`, `permission:hr.onboarding.manage`
- Owning module: Human resources
- Legacy family: `HR-ONBOARDING-EMAIL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.onboarding.view`, `permission:hr.onboarding.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.onboarding.view`, `permission:hr.onboarding.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST hr/onboarding/emails` (`hr.onboarding.emails.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/OnboardingEmailController.php:28-44`; FormRequest `app/Http/Requests/Hr/StoreOnboardingEmailRequest.php:17`; `template_name`, `subject`, `body`, `send_days_before_start`, `is_active`.
3. Invoke only the owning control for `DELETE hr/onboarding/emails/{email}` (`hr.onboarding.emails.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/OnboardingEmailController.php:61-70`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT hr/onboarding/emails/{email}` (`hr.onboarding.emails.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/OnboardingEmailController.php:49-56`; FormRequest `app/Http/Requests/Hr/StoreOnboardingEmailRequest.php:17`; `template_name`, `subject`, `body`, `send_days_before_start`, `is_active`.
5. Invoke only the owning control for `POST hr/onboarding/emails/{email}/test` (`hr.onboarding.emails.test`, action `test`). Source category: **mutation outcome source gap (test)**; controller `app/Http/Controllers/Hr/OnboardingEmailController.php:76-98`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-1567` at `app/Http/Controllers/Hr/OnboardingEmailController.php:28`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1568` at `app/Http/Controllers/Hr/OnboardingEmailController.php:61`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1569` at `app/Http/Controllers/Hr/OnboardingEmailController.php:49`; it is not runtime-observed.
- **mutation outcome source gap (test)** is applicable only to `test` / `ROUTE-1571` at `app/Http/Controllers/Hr/OnboardingEmailController.php:76`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1567` / `store`: FormRequest `app/Http/Requests/Hr/StoreOnboardingEmailRequest.php:17`; fields `template_name`, `subject`, `body`, `send_days_before_start`, `is_active`; success app/Http/Controllers/Hr/OnboardingEmailController.php:43 `return redirect()->back()->with('success', 'Onboarding email template created.');`.
- `ROUTE-1568` / `destroy`: success app/Http/Controllers/Hr/OnboardingEmailController.php:69 `return redirect()->back()->with('success', 'Onboarding email template deleted.');`.
- `ROUTE-1569` / `update`: FormRequest `app/Http/Requests/Hr/StoreOnboardingEmailRequest.php:17`; fields `template_name`, `subject`, `body`, `send_days_before_start`, `is_active`; success app/Http/Controllers/Hr/OnboardingEmailController.php:55 `return redirect()->back()->with('success', 'Onboarding email template updated.');`.
- `ROUTE-1571` / `test`: success app/Http/Controllers/Hr/OnboardingEmailController.php:97 `return redirect()->back()->with('success', "Test email sent to {$recipient}.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/OnboardingEmailController.php:33 `HrOnboardingEmail::create([`; app/Http/Controllers/Hr/OnboardingEmailController.php:67 `$email->delete();`; app/Http/Controllers/Hr/OnboardingEmailController.php:53 `$email->update($request->validated());`; responses app/Http/Controllers/Hr/OnboardingEmailController.php:43 `return redirect()->back()->with('success', 'Onboarding email template created.');`; app/Http/Controllers/Hr/OnboardingEmailController.php:69 `return redirect()->back()->with('success', 'Onboarding email template deleted.');`; app/Http/Controllers/Hr/OnboardingEmailController.php:55 `return redirect()->back()->with('success', 'Onboarding email template updated.');`; app/Http/Controllers/Hr/OnboardingEmailController.php:84 `return redirect()->back()->with('error', 'Your account has no email address to send a test to.');`; app/Http/Controllers/Hr/OnboardingEmailController.php:94 `return redirect()->back()->with('error', 'Could not send the test email: '.$exception->getMessage());`; app/Http/Controllers/Hr/OnboardingEmailController.php:97 `return redirect()->back()->with('success', "Test email sent to {$recipient}.");`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Hr/OnboardingEmailController.php:92 `Mail::to($recipient)->send(new \App\Mail\Hr\OnboardingTemplateMail($subject, $body));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST hr/onboarding/emails` — `hr.onboarding.emails.store` — `App\Http\Controllers\Hr\OnboardingEmailController@store` — `app/Http/Controllers/Hr/OnboardingEmailController.php:28` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `DELETE hr/onboarding/emails/{email}` — `hr.onboarding.emails.destroy` — `App\Http\Controllers\Hr\OnboardingEmailController@destroy` — `app/Http/Controllers/Hr/OnboardingEmailController.php:61` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `PUT hr/onboarding/emails/{email}` — `hr.onboarding.emails.update` — `App\Http\Controllers\Hr\OnboardingEmailController@update` — `app/Http/Controllers/Hr/OnboardingEmailController.php:49` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`
- `POST hr/onboarding/emails/{email}/test` — `hr.onboarding.emails.test` — `App\Http\Controllers\Hr\OnboardingEmailController@test` — `app/Http/Controllers/Hr/OnboardingEmailController.php:76` — middleware `web, auth, permission:hr.onboarding.view, permission:hr.onboarding.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/OnboardingEmailController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
