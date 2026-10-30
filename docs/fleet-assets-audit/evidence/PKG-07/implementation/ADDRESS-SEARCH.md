# Address search and location autofill

The user explicitly selected the existing free OpenStreetMap service after being told that public Nominatim prohibits type-ahead autocomplete. This follow-up reuses the canonical Site geocoder for submitted searches, rather than introducing another provider or credentials.

## Behaviour

- `BOUNDARY_ADDRESS_SEARCH_ENABLED=true` and `ADDRESS_SEARCH_AUTOCOMPLETE=false` are set only in this worktree's ignored preview environment. Other deployments keep their explicit opt-in configuration.
- Search addresses or Enter submits the typed query. Choosing a result fills the address, coordinates and initial circle, centres the map and resets geometry verification. Typing alone makes no provider request. A stale response cannot replace a newer query.
- Selecting an owning Site prefills its saved address and coordinates for an untouched new boundary. Manually entered locations, map-selected points and existing/copy geometry are retained. Use site address is an explicit replacement action, available only after actual Site details are loaded; an existing boundary's ownership label is not treated as a saved Site location. A Site without coordinates provides its address and requires the map position to be set and verified before saving.
- The existing shared database/Redis cache, provider-wide request lock and 1.05-second minimum interval remain in force across boundary, Site and Client lookup. Non-empty results are cached for 24 hours. Requests carry the application User-Agent, use NZ-first/global fallback and do not follow redirects. A configurable endpoint allows changing providers.
- Management permission is required before lookup and rechecked afterwards. Public Nominatim plus autocomplete enabled fails closed. Failed or empty searches leave manual entry available. OpenStreetMap attribution links to its copyright page.
- Only the submitted query and geocoder search parameters are sent. No person identifier, boundary record, telemetry or current location is appended. Public Nominatim must not receive personal/confidential information; its policy governs the operator's use: https://operations.osmfoundation.org/policies/nominatim/.

## Automated verification

- Seventeen focused frontend checks pass across address search, wizard autofill, map visibility, geometry, scale and rule policy (`address-frontend-tests.log`). New cases cover no request on typing, explicit submission, selection without a second search, stale responses, Enter submission, Site autofill, preservation of manual/map/copied locations, unloaded Site details and missing-position verification. The original seven geometry/policy checks also passed separately (`address-geometry-regression.log`).
- Six backend feature tests pass with 111 assertions (`address-backend-tests.log`), including public submitted result/coordinates, cached repeat query, management denial, disabled lookup and forbidden public autocomplete. Provider HTTP is faked in these checks. Tests use the existing rollback-only synthetic QA bootstrap.
- The historical limited TypeScript helper reported no implementation diagnostics (`address-types.log` / `typecheck.json`), but omitted tests and did not use the full repository compilation context. Main's later T07-01 finding and the authoritative repository check are recorded in `VERIFICATION.md`; the helper's graph diagnostics do not establish inherited repository failures. Targeted Pint and formatting pass.

## Live verification

The existing user dialog contains a draft, so it was left intact. A separate local preview tab is used for verification with public landmark queries only. `New Zealand Parliament Wellington` exercised the empty-results state. `Te Papa Wellington` returned the real Museum of New Zealand address at 55 Cable Street. Choosing it populated latitude -41.2903326 and longitude 174.7819275 and displayed the initial boundary on the map. No boundary was saved for this search check; no customer address was sent to the provider.

The first production asset build passed in 6m 21s (`address-build.log`). In that rebuilt UI, choosing synthetic Harbour House QA automatically populated its saved address and coordinates without pressing Use site address (`address-site-autofill.png`). Searching and choosing Te Papa populated both address inputs, coordinates and the map (`address-selected-osm.png`).

The final build passed in 5m 37s (`address-build-final.log`). Reloaded that build and opened Edit shared boundary for BG-130: the stored address and geometry remained intact, with neither an unhydrated Use site address action nor a false missing-Site-position message (`address-existing-boundary-preserved.png`). Closed the unchanged edit and left a fresh Create boundary dialog with enabled search in the new preview tab (`address-search-ready.png`). The original user draft stays untouched in its original tab. No browser console errors were reported. No boundary records were created or changed in this follow-up.
