# PKG-02A v2 synthetic preview

Standalone Client Location design. No application API or live records. All proposals are in-memory drafts.

From the repository root, build with the existing dependency runtime:

```powershell
& C:/Users/steph/.hermes/node/node.exe node_modules/typescript/bin/tsc --noEmit --project docs/fleet-assets-audit/previews/PKG-02A/v2/tsconfig.json
& C:/Users/steph/.hermes/node/node.exe node_modules/vite/bin/vite.js build --config docs/fleet-assets-audit/previews/PKG-02A/v2/vite.config.mjs --configLoader native
& C:/Users/steph/.hermes/node/node.exe docs/fleet-assets-audit/previews/PKG-02A/v2/serve.mjs
```

Open http://127.0.0.1:4333/PKG-02A/v2/. Do not replace or stop v1 on 4332 or PKG-01 on 4324. A frozen candidate's source/dist/evidence is hash recorded; further changes require another version. Shared node_modules is read-only. Preview-local .vite cache is excluded from evidence.

See ../../../pages/PKG-02A-v2.md for scope, gates and evidence. This version does not authorise implementation.
