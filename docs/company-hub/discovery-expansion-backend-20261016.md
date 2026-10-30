# Company Hub discovery and saved history — 16 October 2026

The application remains one organisation across approved sites. All projections retain current-staff eligibility, role/permission checks, canonical record ownership and privacy rules. No tenant boundary, chat permission, public photo store or schema was added.

## Discovery contracts

- `GET /company-hub/discovery/recognition`: `search`, `team_id`, canonical category key, `recipient_type=person|team`, `sort=newest|oldest|most_appreciated`, `page`, `per_page`.
- `GET /company-hub/discovery/ideas`: `search`, visible `team_id`, category label, stage `status`, `sort=updated|newest|oldest|most_supported`, `page`, `per_page`.
- Responses are private/no-store: `{data, pagination:{page,per_page,has_more,next_page}, filters}`. Pages default to 20, are capped at 50, and use deterministic identifier tie breaks. Filters and ordering apply to the complete authorised source before the page boundary. Ideas no longer silently stop at 200; direct selected records remain independently readable. Recognition's canonical source and projection are implemented by the social owner.
- Existing `kudos` and `ideas` arrays remain first-page projections. `recognitionBrowse` and `ideaBrowse` carry continuation state. The browser uses debounced searches, explicit loading/retry/Show more controls, deduplicated appended rows and rejection of responses from earlier filters or actor/source props. Local showcase filtering stays local. Beyond-page-one ideas open their canonical selected route so action redirects retain the actual selected record.

## Saved events and people

- `savedEvents` resolves bookmarked event identities independently of the next-90-day `events` feed. A saved series selects its next surviving occurrence, including moved overrides, or its latest surviving historic occurrence after it ends. Archived exceptions suppress their original dates. Site/audience/archive checks still run; unavailable saves stay in the private bookmark store but their record content is not projected.
- Historic selection walks bounded windows for unlimited rules, avoiding the calendar's 20,000-output guard for long-lived ended series. Finite validated COUNT rules remain capped at 10,000 canonical dates.
- Each row records `extendedProps.savedSubjectId` for exact bookmark identity and uses the canonical calendar projector, registration/cancellation state and protected attachment URLs. Saved/HomeSaved use this pool and hide withdrawn projections.
- Directory and work-profile cards expose nullable `avatar_url` only through the existing protected HR photo route after the canonical storage service verifies an owned readable profile path. They expose recognition permission/recipient context. Viewer work grants are refreshed, and `chat_url` remains null because this change does not broaden chat entry.

## Feed work

- Reader page loading excludes never-published and archived pages, and never selects mutable draft bodies. Authors retain their own editable/recoverable drafts; edit-all remains the existing permission boundary.
- The current-release select is qualified for MySQL's `ofMany` join. Navigation reuses the already-authorised page summaries instead of loading the full page/revision pool twice more. File-block media are loaded once and matched back to their exact owning page.
- The focused regression fixture asserts one page materialisation and one media lookup for six file blocks, retaining all six file results. This replaces the former repeated navigation pools and one lookup per File block. Full published snapshots are still needed for canonical audience, navigation and content projections; this is not a claim that every initial Hub payload is now constant-size.

## Verification

- Owned frontend suites: 4 files / 41 cases passed (exit 0): `discovery-browse`, `idea-progress`, `idea-composer`, `home-saved`.
- The expanded `discovery-browse` suite then passed 5 cases (exit 0), adding active-comment draft protection to the four browsing cases. Search, category, team, team-only, order, Clear filters and Read spotlight stay unavailable until every active comment draft clears; Show more remains available. The card/conversation owner supplies the stable dirty-state callback. Frontend source was frozen for the root build after this check.
- Pure recurrence-history unit checks: 2 cases / 5 assertions passed (exit 0), including a century-long daily series and finite/suppressed dates. No application or database bootstrap is used by these unit cases.
- Targeted ESLint passed with zero warnings; owned PHP syntax and Pint checks passed. The normal runtime was required because the sandbox's Vite temporary-cache rename failed before tests could execute.
- The isolated backend suite passed **4 cases / 61 assertions, exit 0**, in 856.68 seconds. It covers 205 ideas, pagination/order/filter privacy, past/distant/ended/moved event saves, query bounds, private photo ownership and refreshed permissions. The measured fixture retained six file results with exactly one File-media lookup and one HubPage materialisation. The sole coordinated database slot was released immediately to the social/document verification owner.
- No operational application database writes or seeding, commits, pushes, deployment or live recognition/posts were performed by this work. Test data were created only by the process-isolated test harness.
