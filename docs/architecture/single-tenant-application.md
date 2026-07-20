# Single-tenant application boundary

Oblivion Findings is a single-tenant application for one operating organisation across all of its sites. It is not a multi-tenant SaaS product.

## Product and security rules

- Do not add tenant selection, tenant switching, cross-tenant workflows, tenant-authenticated transports, or multi-tenant acceptance criteria.
- Scope people and operational records through their canonical organisation relationships where an existing module requires that legacy field.
- Enforce access through roles and permissions, approved sites, canonical record ownership, direct-object denial, and privacy rules.
- The main application can see every configured site over the organisation's SD-WAN. A remote collector is scoped to approved sites, networks, and devices, never to a tenant.
- Tests use multiple sites, restricted roles, hidden records, unrelated clients/staff/devices, and forged direct identifiers to prove access boundaries. They do not create fictional tenants as the primary security test.

## Legacy schema names

Some mature tables contain `tenant_id` or `organization_id`. Those columns are compatibility and organisational-context details, not evidence of a multi-tenant product and not permission-model guidance.

Do not remove those columns casually: schema simplification requires a separately approved migration and full dependency audit. New work must not propagate them merely for hypothetical future multi-tenancy. When a canonical existing model still requires one, resolve the application's single organisation through the module's established relationship or resolver and keep authorization site/role based.

## Design and review gate

Every new plan, implementation, review, and acceptance suite must state the single-tenant boundary. Any proposed cross-tenant fixture or tenant-level product feature is a design defect unless the user explicitly changes this architecture decision.
