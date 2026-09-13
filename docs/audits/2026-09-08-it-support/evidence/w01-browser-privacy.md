# W01 in-app browser privacy verification

9 September 2026, exact local checkout `C:\Users\steph\Herd\oblivionfindings`, `https://oblivionfindings.test`, Codex in-app browser tab1. Initial assets remain the captured baseline manifest; these privacy fixes are server projections. No provider communication or production fixtures were used.

Synthetic identities/records: see w01-browser-fixtures.json. Technician230 has it.request/view/manage and approved sites A9403/B9404, without sensitive/organisation-wide permissions. Site C9405 is unapproved. Requester231 has only it.request and A/B. Existing historical work was not modified.

## Observed technician journeys

- Ticket12, technician as requester on sensitive work: desktop1440 view displayed one public comment, public file7 and one safe activity. No internal-note control, assignment/resolve/merge controls, internal comment, file8 or private activity reason. Switching Conversation → Activity displayed only the public workflow transition.
- Direct `/it/attachments/8` returned the application's404 page.
- Ticket13, technician as requested-for participant at unapproved C: desktop1440 and narrow390×844 displayed the public participant projection; no internal file10, note or work controls. Direct `/it/attachments/10` returned404.
- On ticket13 at390px, DOM innerWidth390/document width375, no document overflow; Tab from the activity control reached the public file link. This verifies a keyboard path and audience behavior, not full layout conformance.
- Direct ticket11 (another requester, unapproved C, no participation) returned404.
- Ticket14, permitted technician work at approved B: narrow390 and desktop1440 retained both public and internal comments/files11/12 and work controls. Internal note, Resolve, Merge, Assign, work tasks and scoped properties remained visible. This is the positive audience check.

## Outstanding visual and role checks

The narrow ticket header visibly clips the long title and action row. This is confirmed remaining W06 work; no E04 or whole W01 completion is claimed. Screenshot inspected in-app, not saved with private data.

Requester231 signed in successfully after technician logout; own ticket9 navigation started. Remaining: own9 public projection, other requester same-site10 denial, scoped knowledge discovery/reader (six separate synthetic KB fixtures now available), and request creation/recovery UI after the new asset build. No final release acceptance yet.
