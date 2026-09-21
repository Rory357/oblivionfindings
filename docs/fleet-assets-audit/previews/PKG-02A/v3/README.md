# PKG-02A v3 synthetic preview

Client Location design with direct circle/polygon drawing, map context actions, schedules, governed Locate now examples and date-range Activity. All records, geometry and requests are synthetic. Drafts are local memory only.

From the repository root, use the existing read-only dependency runtime:

```powershell
& C:/Users/steph/.hermes/node/node.exe node_modules/typescript/bin/tsc --noEmit --project docs/fleet-assets-audit/previews/PKG-02A/v3/tsconfig.json
& C:/Users/steph/.hermes/node/node.exe node_modules/vite/bin/vite.js build --config docs/fleet-assets-audit/previews/PKG-02A/v3/vite.config.mjs --configLoader native
& C:/Users/steph/.hermes/node/node.exe docs/fleet-assets-audit/previews/PKG-02A/v3/serve.mjs
```

Open http://127.0.0.1:4334/PKG-02A/v3/. Preserve v1 on 4332, v2 on 4333 and PKG-01 on 4324. Frozen source/dist/evidence are hash recorded; further changes need another version. Preview-local .vite cache is excluded. Do not write shared node_modules.

Start in Safe zones & schedule, choose Draw safe zone or right-click the map. Click to draw corners; Finish boundary closes the shape. Circle has draggable centre/radius handles. Keyboard adjustments and a starting rectangle are available. Continue to name, purpose, days/hours, date bounds, response and review. Saving creates an inactive draft.

Today → Tracking source → Locate now demonstrates the existing governed command concept. Supply a synthetic reason, confirm identity in the preview and choose a preview response. No unit is contacted. Activity uses From/To calendars and distinct observations/responses/plans.

See ../../../pages/PKG-02A-v3.md. This artifact is design evidence, not implementation approval.
