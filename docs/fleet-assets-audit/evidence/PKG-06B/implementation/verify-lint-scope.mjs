import assert from 'node:assert/strict';
import { ESLint } from 'eslint';

const eslint = new ESLint();
const expected = {
    'docs/fleet-assets-audit/previews/PKG-06B/v9/app.js': true,
    'docs/fleet-assets-audit/previews/PKG-06B/v9/profile.tsx': true,
    'docs/fleet-assets-audit/evidence/PKG-06B/v8/qr-export-validation.cjs': true,
    'resources/js/components/assets/profile/workspace.tsx': false,
    'resources/js/components/assets/profile/custody-view.test.tsx': false,
    'resources/js/components/files/file-preview-dialog.tsx': false,
    'resources/js/components/fleet-assets/maintenance/date-picker.tsx': false,
    'resources/js/pages/fleet-assets/assets/labels.tsx': false,
};
const results = {};
for (const [path, ignored] of Object.entries(expected)) {
    results[path] = await eslint.isPathIgnored(path);
    assert.equal(results[path], ignored, path);
}
console.log(JSON.stringify({ passed: true, ignored: results }, null, 2));
