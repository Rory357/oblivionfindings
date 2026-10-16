# Company Hub calendar-control correction — 16 October 2026

The user found native browser date/time fields in Events after the earlier Rory calendar repair. The earlier repair covered the Hub page directory but missed the shared HR calendar components used by event authoring. The previous whole-Hub completion statements were therefore too broad.

## Audited and corrected locations

| Surface | Fields | Current control |
| --- | --- | --- |
| Hub event page, create and edit | Starts, Ends | Compact shared DateTimeField; DatePicker for all-day events |
| Hub event page | Repeat until | Shared DatePicker with explicit Use date and Clear date |
| Hub event publication | Publish date and time | Shared DateTimeField with explicit calendar and clock confirmation |
| Calendar event wizard, create and edit | Starts, Ends | Same EventDateField adapter as the Hub page |
| Calendar event wizard | Until (optional) | Shared DatePicker; disabled for a single occurrence |

Seven field locations were changed across two components. Existing Hub poll deadlines, campaign dates, guide review dates, editorial planning, page publication/expiry and revision scheduling already use the shared HubDateField/HubDateTimeField controls. A production-source scan now finds no native date, time or datetime-local inputs in `resources/js/pages/company-hub`, `resources/js/components/hr/calendar` or `resources/js/components/recognition`. This is a Company Hub and shared event-editor audit, not a claim about every application module.

The adapter retains the existing controlled values and canonical saves. NZ wall times are converted only by the existing event-time helper. A partially selected date/time now receives “Choose both a date and a time” before conversion. Existing daylight-saving ambiguity/skipped-time handling remains unchanged. No database or permission changes.

## Verification

- Focused interaction/time selection: **26 tests passed, three files, actual exit0**. Includes both page and wizard date selection, all-day controls, repeat-until, scheduling only after a date and time, NZ summer/winter conversion and DST boundaries. Log: `storage/logs/company-hub-event-rory-tests-final-20261016.log`.
- First run found three stale test selectors for the earlier image-library → company-library label change; corrected only those selectors, then reran successfully. New Rory interaction cases passed in both runs.
- Scoped source lint and TypeScript checks passed. Temporary scoped TypeScript configuration removed.
- Local build34127 returned actual exit0 in8m. Fresh temporarytab20 on the intended Herd host served `app-BbmeO6Ez.js`. Log: `storage/logs/company-hub-event-rory-build-20261016.log`.
- Browser verified confirmed start-date selection, the working clock and retained09:00AM, all-day date-only controls, Weekly → Repeat until calendar, and Review invitation → Schedule for later → calendar/clock fields. Zero native date/time inputs, no horizontal overflow and no console errors. Evidence: [calendar](evidence/event-rory-calendar-20261016.png), [clock](evidence/event-rory-clock-20261016.png).
- One browser automation checkbox click hit the sidebar during layout movement; restored the prior expanded sidebar immediately and used the checkbox keyboard action successfully. No source defect was found. No events were saved or published, no files uploaded. Only temporarytab20 was closed; original editor1/calendar5/event19 remained untouched.
