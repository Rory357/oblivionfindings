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
