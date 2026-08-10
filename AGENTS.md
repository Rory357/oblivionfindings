# Repository instructions

## Single-tenant architecture

Oblivion Findings is a single-tenant application for one operating organisation across multiple sites. Do not design or implement it as multi-tenant SaaS.

- Do not add tenant selectors, tenant switching, cross-tenant workflows or fixtures, tenant-authenticated transports, tenant-scoped queues, or tenant-based product acceptance.
- Use roles and permissions, approved sites, canonical record ownership, direct-object denial, and privacy rules as the authorization boundary.
- Existing `tenant_id` and `organization_id` columns are legacy schema or organisational-context details. Do not propagate them into new work merely for hypothetical multi-tenancy, and do not remove them without a separately approved dependency audit and migration.
- Remote monitoring collectors are authenticated and scoped to approved sites, networks, devices, and capabilities—not tenants.
- Before executing a plan that contains tenant language, correct it to the single-tenant boundary in `docs/architecture/single-tenant-application.md`.
