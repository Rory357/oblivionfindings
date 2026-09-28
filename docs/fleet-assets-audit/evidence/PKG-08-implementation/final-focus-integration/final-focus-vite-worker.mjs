import path from 'node:path';
import { pathToFileURL } from 'node:url';
const root = process.cwd();
// Vite's usual bundled loader supplies this to the existing repository configs.
// Supply the same root for the runner loader, which avoids writing through node_modules.
globalThis.__dirname = root;
const packet = path.join(root, 'docs/fleet-assets-audit/evidence/PKG-08-implementation/final-focus-integration');
const attempt = process.argv[3] ? `-${process.argv[3]}` : '';
const cacheDir = path.join(root, 'storage/framework/pkg08-map-regression/final-focus-qa-cache');
if (process.argv[2] === 'tests') {
  const { startVitest } = await import(pathToFileURL(path.join(root, 'node_modules/vitest/dist/node.js')).href);
  const ctx = await startVitest('test', [
    'resources/js/pages/fleet-assets/settings/maps-focus.test.tsx',
    'resources/js/pages/fleet-assets/settings/settings-draft.test.ts',
    'resources/js/components/wizard/shell-focus.test.tsx',
    'resources/js/test/dialog-focus-return.test.tsx',
  ], { run: true, config: 'vitest.config.ts', configLoader: 'runner', reporters: ['default', 'json'], outputFile: { json: path.join(packet, `tests${attempt}.json`) } }, { cacheDir });
  if (!ctx) throw new Error('Vitest did not initialize');
  await ctx.close();
} else if (process.argv[2] === 'build') {
  const { build } = await import(pathToFileURL(path.join(root, 'node_modules/vite/dist/node/index.js')).href);
  await build({ configFile: path.join(root, 'vite.config.ts'), configLoader: 'runner', cacheDir });
} else throw new Error('Unknown QA job');
