// Proves P11 reuses the approved P00 v5 views unchanged.
// Usage (from the repository root): node docs/emar-design/P11/v1/reuse-check.mjs
// Reads P00 v5 from its pinned commit (ff3bff860) with git, then compares, declaration by declaration,
// the source of every approved view P11 calls. Also checks mockup.css is byte-identical.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const P00_COMMIT = 'ff3bff860';
const p00 = (f) => execFileSync('git', ['show', `${P00_COMMIT}:docs/emar-design/P00/v5/${f}`], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
const sha = (s) => createHash('sha256').update(s).digest('hex');

// Approved P00 v5 views and the data they render (Medication rules, second-person confirmation,
// controlled-drug witness and overrides, phone-instruction settings, account › Witness PIN, PIN co-sign fields).
const APPROVED = [
    'SAFETY_RULES', 'safetyRulesCard', 'openSafetyConfirm',
    'MATCH', 'RULES', 'RULE_HISTORY_SEED', 'ruleItems', 'ruleWhat', 'ruleNeeds', 'ruleSentence', 'ruleOverlaps', 'rulePreview', 'overlapWarn', 'medRulesCard',
    'RSTEPS', 'openRuleWizard', 'ruleLive', 'renderRuleWizard', 'ruleBody', 'ruleNext', 'ruleSave', 'openRuleToggle', 'openRuleHistory',
    'CDW_OPTS', 'CDW_LABEL', 'cdWitnessCard', 'openCdwConfirm', 'pinSummaryCard', 'medicationRulesView',
    'OVERRIDES', 'overridesList', 'OV_BADGE', 'overrideRow', 'requestRow', 'suggestionBanner', 'overridesView', 'openOverrideRequest', 'overrideRequestBody',
    'openOverrideGrant', 'grantParts', 'renderGrant', 'grantApprove', 'grantDecline', 'openOverrideDetail', 'openOverrideRevoke',
    'PIN_RULES', 'pinVal', 'pinRulesCard', 'staffPinsCard', 'secondPersonSettingsView', 'openPinConfirm', 'openPinReset', 'myPinCard', 'myPinPage',
    'STAFF_PINS', 'pinBadge', 'amountField', 'colleaguePicker', 'fallbackPanel', 'cosignFields',
];
// A declaration runs from its first line to the next top-level (4-space) declaration or section comment.
function block(src, name) {
    const lines = src.split('\n');
    const start = lines.findIndex((l) => new RegExp(`^    (?:function ${name}\\(|const ${name} = |let ${name} = )`).test(l));
    if (start < 0) return null;
    let end = start + 1;
    while (end < lines.length && !/^    (?:function |const |let |\/\*)/.test(lines[end]) && !/^\}\)\(\);/.test(lines[end])) end += 1;
    return lines.slice(start, end).join('\n').replace(/\s+$/, '');
}
const a = p00('mockup.js');
const b = readFileSync(path.join(here, 'mockup.js'), 'utf8');
let bad = 0;
for (const n of APPROVED) {
    const x = block(a, n), y = block(b, n);
    const same = x !== null && x === y;
    if (!same) bad += 1;
    console.log(`${same ? 'same   ' : 'CHANGED'}  ${n}${x === null ? ' (not found in P00 v5)' : y === null ? ' (missing in P11)' : ''}  ${x ? sha(x).slice(0, 12) : ''}`);
}
const cssSame = p00('mockup.css') === readFileSync(path.join(here, 'mockup.css'), 'utf8');
console.log(`${cssSame ? 'same   ' : 'CHANGED'}  mockup.css (whole file)`);
if (!cssSame) bad += 1;
console.log(bad ? `\n${bad} approved declarations differ from P00 v5 (${P00_COMMIT}).` : `\nAll ${APPROVED.length} approved declarations and mockup.css are byte-identical to P00 v5 (${P00_COMMIT}).`);
process.exit(bad ? 1 : 0);
