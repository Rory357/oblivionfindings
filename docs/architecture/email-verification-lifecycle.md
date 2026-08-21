# Email verification lifecycle

Oblivion Findings is a single-tenant application. Email verification is an account-assurance control; it does not replace Site access, role and permission checks, canonical record ownership, direct-object denial, or privacy rules.

## Required contract

Every `App\Models\User` implements Laravel's `MustVerifyEmail` contract. There is no role, Site, or global-permission bypass when a route uses the `verified` middleware. An authenticated user whose `email_verified_at` value is null is redirected to the existing verification prompt before the route handler runs.

Verification enforcement is route-based rather than account-type-based. Routes that declare `verified` require mailbox assurance for staff, administrators, and portal roles alike.

## Lifecycle

- Native registration dispatches Laravel's `Registered` event. The framework sends the initial verification notification while the account remains subject to the separate administrator-approval gate.
- System Users dispatches that same event only after an administrator-created portal account commits. Administrative email replacement clears the old verification and sends the native notification to the replacement address; name and role-only edits preserve verification.
- The existing verification prompt and throttled resend action remain available to authenticated unverified users.
- The signed verification route binds the authenticated user ID and email hash, enforces signature expiry, and marks the email verified once. Replaying an already-completed link is idempotent and does not emit a second `Verified` event.
- Changing the profile email clears `email_verified_at`. The auth-only profile page exposes the existing resend control so the user has a recovery path.
- HR intake and portal account creation use the existing password-reset invitation when requested. Creation itself remains unverified; completing the emailed reset or initial password-setup flow counts as mailbox control and verifies the address. An ordinary authenticated password change does not.
- Seeded system actors explicitly set `email_verified_at`; seeding does not generate verification mail. Legacy rows with an absent or malformed mailbox remain unverified and the resend action returns its normal non-disclosing response without attempting delivery.
- Approval, password authentication, two-factor authentication, and email verification remain independent controls. A suspended account is denied during authentication whether its mailbox is verified or not.

## Explicit auth-only flows

The verification prompt, resend, logout, password recovery, and profile settings must remain reachable while verification is pending. Client and next-of-kin portal routes also retain their existing `auth`-only contract in this remediation; their approval, portal-role, client-link, consent, ownership, and privacy checks continue to govern access. Any future decision to require `verified` on those routes is a separate route-policy change and must include a portal recovery design.

OAuth sign-in does not create a role-based bypass for routes that require `verified`. The current provider callbacks and portal routing continue unchanged.
