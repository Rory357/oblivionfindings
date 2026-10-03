# Eligibility competency wording

Base: 92466adc2dd321db5c3514ffa738db4a379a334b with Settings fix 15002cf0a1f6877311adc3879de7669b7f74268a. Branch: codex/emar-p11-settings-render-fix, owned P11 checkout.

My eligibility and the staff register describe competency requirements rather than unrestricted permission to record a dose. Refused, withheld and away have no competency assessment requirement; permission, access to the person and house, and any required roster, clock-in and dose checks still apply. Positive competency labels, status filters, renewal headings and empty-state wording use the same scope. No eligibility predicates, backend authority, policy or permission decisions were changed.

The existing Renewals navigation test now finds its renamed section by heading role, preserving the navigation assertion. Three touched TSX files passed scoped syntax checks and core formatting. No renderer or existing UI tests were launched after Main requested ownership of consolidated testing.

After integration, Main's focused command from its physical checkout:

    node node_modules/vitest/vitest.mjs run resources/js/pages/emar/settings/settings-navigation-render.test.tsx resources/js/pages/emar/eligibility/eligibility.test.tsx --maxWorkers=1

Production rebuild and browser acceptance remain pending in Main, including Settings initial navigation and an unrostered/unclocked worker's My eligibility notice.
