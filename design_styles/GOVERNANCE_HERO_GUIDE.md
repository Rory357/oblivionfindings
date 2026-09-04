# Governance Hero Guide

**Every governance page must use the full `PageHero` banner** — index, show,
create, edit, calendar, dashboard, settings. There is exactly one exception
(see "When to use compact" below) and it is rare. Companion to
[POPUP_STYLE_GUIDE.md](POPUP_STYLE_GUIDE.md).

Reference implementations:

- [resources/js/pages/Governance/Dashboard.tsx](../resources/js/pages/Governance/Dashboard.tsx)
- [resources/js/pages/Governance/Meetings/Show.tsx](../resources/js/pages/Governance/Meetings/Show.tsx)
- [resources/js/pages/Governance/CeoReports/Show.tsx](../resources/js/pages/Governance/CeoReports/Show.tsx)
- [resources/js/pages/Governance/Packs/Show.tsx](../resources/js/pages/Governance/Packs/Show.tsx)
- [resources/js/pages/Governance/Risks/Show.tsx](../resources/js/pages/Governance/Risks/Show.tsx)

## The contract

```tsx
import { PageHero, PageLayout } from '@/components/page';
import { ShieldAlert } from 'lucide-react';

<PageLayout
    hero={
        <PageHero
            category="governance"           // 1. dynamic accent — REQUIRED
            backHref="/governance/risks"    // 2. back link to the parent index
            icon={ShieldAlert}               // 3. module icon, big circle on the left
            title={                          // 4. plain string OR JSX with status badge
                <span className="flex flex-wrap items-center gap-3" dusk="risk-heading">
                    {risk.title}
                    <Badge>{risk.status}</Badge>
                </span>
            }
            description="One-line plain-English summary for non-technical board members."
            stats={[                         // 5. 3-4 board-friendly counters
                { label: 'Residual', value: risk.residual_score },
                { label: 'Treatments', value: risk.treatments.length },
            ]}
            actions={                        // 6. primary verbs — never generic 'Open'
                <Button onClick={...}>Add Treatment</Button>
            }
        />
    }
>
    {/* page body */}
</PageLayout>
```

## The six pieces, in order

| # | Prop          | Required? | What goes in it |
|---|---------------|-----------|-----------------|
| 1 | `category`    | **Yes**   | Always `"governance"`. Drives the dynamic brand accent — never hardcode a colour. |
| 2 | `backHref`    | Yes on show / create / edit | URL of the parent index. Renders the back link in the hero. |
| 3 | `icon`        | **Yes**   | Lucide icon matching the module (see "Module icons" below). Big rounded badge on the left of the hero. |
| 4 | `title`       | **Yes**   | The record name or page name. JSX is allowed for status badges next to the title. Always wrap in a `<span dusk="..." />` for E2E tests. |
| 5 | `description` | **Yes**   | One-line plain-English summary. No jargon, no acronyms a board member wouldn't know. |
| 6 | `stats`       | **Yes** on index + show | 3-4 KPI tiles on the right. Numbers, percentages, statuses — never long text. |
| 7 | `actions`     | When applicable | Primary verbs (`Approve minutes`, `Submit to board`, `Add evidence`). See [POPUP_STYLE_GUIDE.md](POPUP_STYLE_GUIDE.md) for verb library. |
| 8 | `badges`      | Optional  | Top-line chips with `tone="critical" \| "warning" \| "success" \| "info"`. Use for "Overdue", "Above appetite", etc. |
| 9 | `meta`        | Optional  | Secondary context line (chair name, period, deadline). Lower visual weight than stats. |

## Module icons

Use the same icon as the sidebar entry for that module — board members
recognise the icon → module mapping at a glance.

| Module | Icon (`lucide-react`) |
|--------|---------------------|
| Dashboard | `Landmark` |
| Meetings | `Calendar` / `CalendarDays` |
| Resolutions | `Gavel` |
| Risks | `ShieldAlert` |
| Compliance | `FileCheck` / `Shield` |
| Strategy | `Compass` / `Target` |
| Performance | `Target` |
| Budgets | `Wallet` / `DollarSign` |
| Spend Approvals | `HandCoins` / `DollarSign` |
| Board Packs | `FolderOpen` |
| Action Items | `ClipboardList` / `CheckSquare` |
| Policies | `BookOpen` |
| Documents | `FolderOpen` / `FileText` |
| CEO Reports | `FileText` |
| Interests Register | `ClipboardList` |
| Board Evaluations | `Star` |
| Clinical Governance | `HeartPulse` |
| Te Tiriti | `Landmark` |
| Audit Log | `History` |
| Settings | `Settings` |
| Board Admin | `Users` |

## Stats — what to put

Pick **3 to 4** counters that answer "what should the board care about at a
glance?" Common patterns:

- **Index pages**: Total, plus 1-2 status counts (Open / Submitted / Overdue).
- **Show pages**: a 4-tile snapshot of the record's most important metrics —
  for a Risk: Residual / Inherent / Appetite / Treatments; for a Meeting:
  Workflow / Quorum / Agenda / Resolutions; for a CEO Report: Period /
  Sections / Decisions / Matters.

Stats values can be numbers, strings, or short labels. Never long text.

## When to use `compact`

There is exactly **one** acceptable use of `variant="compact"`: when the page
is rendered **inside another page's layout** (e.g. a sub-tab, a partial
included by another show page). This is rare. Confirm there is no
`<PageLayout>` wrapping the content before reaching for `compact`.

**Wrong**: a top-level show / create / edit page using `variant="compact"`
because "the hero feels too big". Fix the stats or icon instead — the size is
the design.

## Checklist before merging a new page

- [ ] `<PageLayout>` wraps the content
- [ ] `<PageHero>` is in the `hero={}` prop, not the body
- [ ] `category="governance"` is set
- [ ] `icon` matches the sidebar icon for this module
- [ ] `title` has a `dusk` attribute for E2E
- [ ] `description` is one plain-English sentence
- [ ] 3-4 `stats` tiles render real numbers (not `undefined`)
- [ ] Primary `actions` use specific verbs (no "Open" / "View")
- [ ] On show pages: a `backHref` points at the parent index
- [ ] No `variant="compact"` on a top-level page

## Adding a new governance page

The fastest way to start:

```sh
cp resources/js/pages/Governance/CeoReports/Show.tsx \
   resources/js/pages/Governance/<NewModule>/Show.tsx
```

Then swap title / description / icon / stats. The hero contract is already
correct in that file.

## Anti-patterns

- ❌ Compact hero on a top-level page "to save space" — the page becomes
  visually inconsistent with every other governance page.
- ❌ Hardcoded gradient colours in the hero — always use `category="governance"`
  so a future re-brand reflows everything automatically.
- ❌ `actions` that say `Open` / `View` / `Details` — replace with the verb
  library entry from [POPUP_STYLE_GUIDE.md](POPUP_STYLE_GUIDE.md).
- ❌ Stats that read like sentences (`"3 risks above appetite"`). Use
  `{ label: 'Above appetite', value: 3 }` instead — the label and value have
  their own visual weights.
- ❌ Multi-line `description`. If you need more context, add a `meta[]` row,
  not paragraphs in the hero.
- ❌ Forgetting the back link on a show page. Without `backHref`, board
  members get stranded.

## When this guide changes

If you change the hero contract (new required field, new icon convention),
update this file in the same PR. Anyone running `git grep PageHero docs/`
should always find authoritative copy.
