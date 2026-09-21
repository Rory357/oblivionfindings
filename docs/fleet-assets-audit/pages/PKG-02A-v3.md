# PKG-02A v3 — Client Location drawing and locate preview

Status: frozen synthetic design candidate for exact-version review; no production implementation is authorised.

Preview: http://127.0.0.1:4334/PKG-02A/v3/. Worktree 2b9f; branch codex/pkg-02a-client-location-design; baseline 2302ca33a95616e442a78e957ddce82d8db98669. Designer task 01a0be31-19ef-7d10-86a9-cbe968989a76. Main verified continuation 01a0c058-1a50-7553-b014-e16641679033 as gpt-6-astra/xhigh at 2026-09-20T19:43:14.624Z and released bounded v3 writes.

The user directly requested right-click options, easy safe-zone drawing/definition, Locate now in Tracking source, and a more complete experience. Main's PKG-02A-v3-design-direction revision 1 includes that request alongside the three v2 review corrections.

## Try these paths

- Safe zones & schedule → Draw safe zone. The first step is a drawing canvas. Circle centres and radii can be dragged; custom polygon corners can be placed, dragged or moved by keyboard. Undo, redo, clear, explicit completion and a keyboard starter rectangle are available. The next steps define name/purpose, weekdays, hours, overnight/date bounds/exception, response and review. Linking an existing place is optional. Drafts appear on the map and inspector as not monitoring; shared geometry is not overwritten.
- Right-click a boundary or empty map: draw a custom zone here, add a circle here, inspect the selected zone, edit a proposal or Locate now. Map actions and keyboard access expose the same options. View-only roles retain inspection only.
- Today → Tracking source → Locate now. A recorded reason and preview identity confirmation precede a local simulated request. Queued, acknowledged, received, timeout, offline and access-withdrawn examples are distinct. Acknowledgement never moves the observation. A received report appends to history; the battery sample keeps its own time. Closing/reopening retains request context and pending requests cannot be duplicated.
- Activity → From/To → Apply date range. Actual date filtering, type filtering, historical detail, loading, empty, failure/retry and access-ended states are restored. Future plans are explicitly separated from recorded observations/responses. Changing a range hides old results until applied.
- Review state retains all 20 privacy, authority, assignment, source quality and role examples. Loss of relevant access removes the workspace and pending protected interactions.

## Main review corrections

1. Date-range history restored and exercised, including real empty ranges, invalid dates, failed request/retry and access withdrawal.
2. Preview-local profile metric reflow fixes text overlap at 640×400. Both required desktop sizes pass. Actual 200% browser zoom is still unverified.
3. Actual DOM focus checks: Keep editing returns to Cancel, Discard draft returns to Draw safe zone, pristine Escape returns to Draw safe zone, menu Escape returns to Map actions.

## Evidence and limits

See ../evidence/PKG-02A/v3/qa-results.md, source-contract-delta.md, privacy-matrix.json, focus-checks.json, reduced-viewport.json, screenshots, preservation-check.json and artifact-manifest.json.

Fictional SVG map and scale only. No live map provider, real GPS, command dispatch, schedule evaluator, alert engine, database write, consent/grant change or production source edit. All draft and request state is local memory. Existing canonical geometry, client-specific rule ownership, governed device-command handling, Control Room response and privacy boundaries remain the implementation direction for one organisation across approved sites.

Frozen v1/v2 and their servers remain intact. Shared guides and application components remain read-only. Exact v3 approval, required PKG-01 closure, privacy/policy and technical prerequisites, concrete frontend/backend contracts and Main's later gates remain unchanged. No Sol assignment or extra task was created.
