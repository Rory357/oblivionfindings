# H&S ease-of-use Part 2 — drawings (for approval before building)

Drawn as real React pages with the app's own components (Event Horizon `PageHeader`, the header rail, signature
blocks, `StatusBadge`, the pop-up kit) and made-up NZ example data. They are not routed or bundled: they live here,
outside `resources/js/pages`, and were rendered without a database by the page harness. Screenshots: `shots/`.

| Drawing | What it shows |
|---|---|
| `d1-overview-start.tsx` (3 shots) | The trimmed Overview header (title, one subline, Report something + Board reports, four numbers, Site and period) and the role-based start that replaces the "Lens" switch: support worker, team lead, health and safety lead |
| `d2-report-something.tsx` | One "Report something" chooser in plain words, on PopupForm (720): each choice says what happened, what the form will ask, and the button names the form it opens |
| `d3-accident-record.tsx` | One accident in one place: one progress line with the next step, a "What happens next" card with one main button, the linked parts (first aid, risk assessment, a hidden health record), people, one timeline; six tabs with the rest under "More" |
| `d4-hub-tabs.tsx` | A hub ("Risks and prevention") with its lists as sideways tabs instead of a card page, and the six-hub sidebar map |

After approval, each drawing becomes a build task page by page (the records and their privacy rules stay as they are).
