// Execute the candidate's actual callback and helpers; no application writes.
import fs from 'node:fs';
import process from 'node:process';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import ts from 'typescript';

const root = 'C:/Users/steph/.codex/worktrees/1eb2/oblivionfindings';
const paths = {
  calendar: 'resources/js/pages/fleet-assets/vehicles/fleet-calendar.tsx',
  time: 'resources/js/pages/fleet-assets/vehicles/calendar-time.ts',
  booking: 'resources/js/components/fleet-assets/vehicle-workspace/booking-time.ts',
  datetime: 'resources/js/lib/datetime.ts',
};
const sources = Object.fromEntries(Object.entries(paths).map(([key, path]) => [key, fs.readFileSync(`${root}/${path}`, 'utf8')]));
function module(source, dependencies = {}) {
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const exports = {};
  new Function('require', 'exports', output)(name => {
    if (!(name in dependencies)) throw new Error(`Unexpected import ${name}`);
    return dependencies[name];
  }, exports);
  return exports;
}
function declaration(name) {
  const tree = ts.createSourceFile('calendar.tsx', sources.calendar, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
  let found;
  function visit(node) {
    if ((ts.isVariableDeclaration(node) || ts.isFunctionDeclaration(node)) && node.name?.getText(tree) === name)
      found = ts.isVariableDeclaration(node) ? `const ${node.getText(tree)};` : node.getText(tree);
    ts.forEachChild(node, visit);
  }
  visit(tree);
  assert.ok(found, `Actual declaration ${name}`);
  return found;
}
const datetime = module(sources.datetime);
const booking = module(sources.booking, { '@/lib/datetime': datetime });
const time = module(sources.time, { '@/lib/datetime': datetime, '@/components/fleet-assets/vehicle-workspace/booking-time': booking });
const callback = ts.transpileModule(`${declaration('keyDate')}\n${declaration('move')}\nreturn move;`, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText;
const results = [];
for (const zone of ['UTC', 'Pacific/Auckland']) {
  process.env.TZ = zone;
  for (const [start, end, target, expectedEnd] of [
    ['2027-04-04T01:30:00+13:00', '2027-04-04T03:30:00+12:00', '2027-04-05', '2027-04-05T04:30'],
    ['2026-09-27T01:30:00+12:00', '2026-09-27T03:30:00+13:00', '2026-09-28', '2026-09-28T02:30'],
    ['2027-04-03T01:30:00+13:00', '2027-04-03T03:30:00+13:00', '2027-04-04', '2027-04-04T02:30'],
    ['2026-09-26T01:30:00+12:00', '2026-09-26T03:30:00+12:00', '2026-09-27', '2026-09-27T04:30'],
  ]) {
    const event = { id: 'probe', kind: 'booking', editable: true, start, end };
    let proposal;
    const deps = { canChange: true, eventByKey: () => event, ...datetime, ...booking, ...time,
      onEdit: (_event, value) => { proposal = value; }, setMoveNotice: () => {}, setMoveChoices: () => {} };
    const move = new Function(...Object.keys(deps), callback)(...Object.values(deps));
    move(event, new Date(`${target}T00:00:00`));
    assert.equal(proposal.end, expectedEnd);
    const elapsed = Date.parse(`${proposal.end}:00${proposal.endOffset}`) - Date.parse(`${proposal.start}:00${proposal.startOffset}`);
    assert.equal(elapsed, Date.parse(end) - Date.parse(start));
    results.push({ zone, start, target, proposal, elapsedMinutes: elapsed / 60000 });
  }
}
console.log(JSON.stringify({ candidate: '79ea01a561f7d2fa5affa592dc096f516d663635',
  hashes: Object.fromEntries(Object.entries(paths).map(([key, path]) => [path, crypto.createHash('sha256').update(sources[key]).digest('hex')])), results }, null, 2));
