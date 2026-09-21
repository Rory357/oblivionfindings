# PKG-02A publication packaging

The publication contains application/test source, curated design and implementation evidence, and the explicitly released programme context. It excludes dependencies, credentials, `.pkg02a-*` runtime helpers/logs, local forced test configurations, application builds and generated mockup `dist` directories. `verify-isolation.php` is a worktree-specific local preflight, not a portable published command.

The historical v1/v2/v3 artifact manifests remain unchanged. Their nine generated `dist` entries describe locally preserved historical build evidence; those generated files are not shipped in Git. Source and screenshots are published. The original v1 page is preserved byte-for-byte at `../v1/PKG-02A-page-frozen.md`; `frozen-page-archive-map.json` records its original path and SHA256. The current programme page is supplied by MAIN. Historical approval/status text describes its original checkpoint, not current release status.

To reproduce a design preview in a fresh development checkout, install the repository's locked Node dependencies with `npm ci`, then use the relevant version's Vite configuration. For example, from the repository root:

```sh
node node_modules/typescript/bin/tsc --noEmit --project docs/fleet-assets-audit/previews/PKG-02A/v3/tsconfig.json
node node_modules/vite/bin/vite.js build --config docs/fleet-assets-audit/previews/PKG-02A/v3/vite.config.mjs --configLoader native
node docs/fleet-assets-audit/previews/PKG-02A/v3/serve.mjs
```

Use the README beside each frozen preview for its port and walkthrough. These previews are synthetic design artifacts, not the implemented Laravel page or a hardware simulator accepted for deployment. Rebuilding is not represented as reproducing historical bytes unless its hashes actually match. Do not rebuild over protected historical evidence in the review workspace.

Implemented application browser evidence is retained in `browser/` with its original small verification JSONs. New r8 desktop checks are recorded in `publication-handoff-r8.md`; older screenshots are not relabelled as r8 screenshots. Real hardware acceptance, the manual 200% browser check and production rollout remain separately recorded decisions.
