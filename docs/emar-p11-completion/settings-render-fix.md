# Settings renderer fix

Base: `92466adc2dd321db5c3514ffa738db4a379a334b`, branch `codex/emar-p11-settings-render-fix`, owned P11 checkout. Main reported React error 130 at the initial Settings navigation with production assets from e52054efc.

The integrated Records & reporting and Error triage tabs lacked icons in the Settings map. TierTwoTabs renders every supplied icon unconditionally, so the Records tab crashed even with Overview selected; Alerts had the same triage fault. Both icons are now defined. The unchanged tab/icon map is colocated with the navigation schema and supplied by settingsSectionTabs, shared by the page and regression. Existing counts/warnings, visible sections, permissions, policy, controlled/emergency guards and integrated editors are preserved.

Regression: resources/js/pages/emar/settings/settings-navigation-render.test.tsx uses the real Sections/TierTwoTabs/Button renderer, checks initial Records and triage selection, and renders all declared sections across five views. It does not mock the tab renderer or icons. Scoped configuration applies the same React Compiler Babel plugin as production. No full build or backend suite was launched.

Scoped syntax: three files passed. Renderer execution will be recorded after the serialized focused check; Main owns the production rebuild and browser acceptance.
