# Drawing changes implied by the build

The design viewer is paused (Stephan, 23 Oct). When a page is built differently from its approved drawing — a
refinement, a rule from DESIGN.md, or data the drawing assumed but the code doesn't have — it is listed here so
the viewer can be brought up to date later. Newest last.

| Drawing | What the built page does differently | Why |
| --- | --- | --- |
| All lists (20, 20e, 44 …) | Every record row has a kebab menu (and the same items on right-click) | DESIGN.md: entity rows keep the kebab and the context menu; the drawings showed right-click only |
| 20, 20b–20e | "These measures cover records you can access. The full picture may be incomplete." sits under the header | The caveat was in the filter row as dark text on the band (unreadable); the drawings left it out |
| 20 | "Needs you now" lists WorkSafe notifications to make, overdue actions, reviews waiting for acceptance and expired register items | The drawing's lone-worker and "choose an owner" rows have no source on the overview yet (backend gap) |
| 20 | "Where incidents are up to" is hidden until the overview sends incident stage counts | No invented counts (backend gap: `incident_stages`) |
| 20, 20e | Site cards show the Site name and its two counts, without the "House" type line | The comparison doesn't carry the Site type |
| 20c | "Incidents with the injury rates" keeps the rate lines over the bars | The drawing showed bars only although its title names the rates |
| 20c | A "With injury" number appears when a restraint event recorded harm; with none, the calm line "Nobody was hurt in a restraint event" | The drawing showed only the zero case |
| 44 | Hub cards use the code's names ("Corrective action traceability"); descriptions are the drawing's | Keeps the hub and the report tabs named the same |
| 80, 44 | Register and report cards show their flag and facts only when the source sends them | No invented counts (backend gaps: `register_status`, `attention`, `hub_status`) |
| 20 | Each tab can be opened from a link (`?tab=leading` …) | Shareable links; not visible in the drawing |
| 24 | The two approval choices keep the code's names and wording ("Separate specialist decision", "Authorised reviewer decision") | The approval dialog uses that wording; who can approve a plan stays described exactly as the code enforces it |
| 24, 24c | "Good to know" lines keep the code's wording (ownership, consent, qualification) | Ownership and consent wording stays word for word; the drawing had plainer rewrites |
| 24c | Routing and independent review each keep their own "Good to know" and change log | They are separate settings with separate histories; the drawing merged them into one |
| 24c | Route lanes show a person icon, not initials | The lead's name is looked up from current access after the page loads |
| 24e | The consent caveat sits as a note under the time bars; the history has its own card | The drawing didn't show the caveat or the history |
| 24–24e | Each section can be opened from a link (`?view=routing` …) | Shareable links; not visible in the drawing |
| 45 | "This period" also counts incidents | Incidents are the first thing a manager looks for; the drawing listed them only under harm |
| 45 | "Incidents by type" keeps the clickable rows under the bars | The rows open the drill-in and its right-click menu |
| 45 (Sites) | The site league is titled "Sites side by side" and the heatmap "Where the load sits" | Same plain words as the overview; the drawing didn't show this tab |
| 1, 1b–1d, 40, 42 | Filters sit on the right of the header and the date chip reads "Period: all dates" | Shared header and date-filter behaviour on every page |
| 1 | The register views use the app's tier-two tabs (icons, filled active tab) | DESIGN.md lists the plain underline strip as an anti-pattern |
| 1, 40 | No "Columns" button | No column chooser exists yet |
| 1d | No "Take ownership" button ("Assign someone" opens the assign dialog); an "Open incident" button is added; the sub-line reads "Oldest first"; the age is days since it happened | Backend gaps (`ownership.can_take`, `owner_needed_since`); the queue covers every incident the viewer can see, not only "Sites you manage" |
| 2–2i | The owner card shows no role or Site; the manager review shows no due time | Backend gaps (`ownership.owner.role`/`site`, `review_due_at`) |
| 2 | "Minute by minute" lists recorded events only, not story steps such as "House and grounds searched" | No invented steps |
| 2c | Adds "Handover to Health & safety" and "Investigation" surfaces | The page already shows that information |
| 2, 2e | Safeguarding, evidence and privacy wording stays the code's ("This does not retrieve or preserve footage" …) | Word-for-word rule |
| 2f | Adds "File added" rows; no "safety review opened" or "safeguarding raised" rows | Those have no timestamps yet (backend gap) |
| 2j | The compact header that sticks when scrolling isn't built | Not built yet; the state only appears after a lost save response |
| 3, 4 | Type is a list of incident types (an older free-text type is kept as "(saved type)"); severity has "No default"; blank rows are dropped on save | Safer, consistent choices |
| 40 | "Where it's up to" uses the existing WorkSafe labels | Same labels as the safety review |
| 41, 41j, 41k | WorkSafe wording stays the code's ("Notification to WorkSafe NZ is still pending.", "Notify ASAP", "Keep records ≥ 5 years") | Word-for-word rule |
| 41b | "From the Control room" uses the shared linked-evidence block; the playbook shows as label–value rows | The playbook has no timestamps for a log |
| 41c | The lifecycle buttons (Submit for review …) sit under the stage line | They belong to the stage, not one block |
| 41 | No "Hand over" link on the Owner card | No route or permission to reassign a review owner yet (backend gap) |
| 41h | The title reads "Hazard — Aurora House" | The hazard's own name isn't sent (backend gap) |
| 41j, 41k | Cancel and submit stay at the bottom of each pane | The panes are shared with the dialog |
| 41d, 43 | Uploading, downloading and removing an action's evidence happens on that action's Evidence tab, not in the review's action table | One place per action; same permissions |
| 42 | The third number box is "Reviews in monitoring", not "On time 86%"; cards come from the current page and a note says how many the board shows | Backend gap (`on_time_rate_30d`, first cards per stage, `closed_30d`) |
| 43 | Linked records list only the safety review, not sibling actions; the due date has no weekday | Backend gap (`review.actions`); the app's date-only format is safe across time zones |
| 43g | "I have reviewed the current owner" sits in the warning note | Keeps the header's buttons for the page's own actions |
