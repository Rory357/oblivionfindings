// Read-only execution of the submitted Fleet Month-move callback and its actual helpers.
import fs from 'node:fs';
import crypto from 'node:crypto';
import ts from 'typescript';

const root = 'C:/Users/steph/.codex/worktrees/1eb2/oblivionfindings';
const files = {
    calendar: 'resources/js/pages/fleet-assets/vehicles/fleet-calendar.tsx',
    wizard: 'resources/js/components/fleet-assets/vehicle-workspace/booking-wizard.tsx',
    datetime: 'resources/js/lib/datetime.ts',
};
const sources = Object.fromEntries(Object.entries(files).map(([key, path]) => [key, fs.readFileSync(`${root}/${path}`, 'utf8')]));
function declaration(source, name) {
    const tree = ts.createSourceFile('probe.tsx', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
    let found;
    function visit(node) {
        if ((ts.isVariableDeclaration(node) || ts.isFunctionDeclaration(node)) && node.name?.getText(tree) === name) {
            found = ts.isVariableDeclaration(node) ? `const ${node.getText(tree)};` : node.getText(tree).replace(/^export\s+/, '');
        }
        ts.forEachChild(node, visit);
    }
    visit(tree);
    if (!found) throw new Error(`Missing actual declaration ${name}`);
    return found;
}
async function load(source) {
    const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText;
    return import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);
}
const { toDatetimeLocal } = await load(sources.datetime);
const isolated = [declaration(sources.wizard, 'addLocalMinutes'), declaration(sources.calendar, 'keyDate'), declaration(sources.calendar, 'move')].join('\n');
const compiled = ts.transpileModule(isolated, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText;
const event = { id: 'booking:probe', vehicleId: 1, kind: 'booking', editable: true, start: '2027-04-04T01:30:00+13:00', end: '2027-04-04T03:30:00+12:00' };
const probes = [];
for (const timezone of ['UTC', 'Pacific/Auckland']) {
    process.env.TZ = timezone;
    let proposal;
    const move = new Function('canChange', 'eventByKey', 'toDatetimeLocal', 'onEdit', `${compiled}\nreturn move;`)(true, () => event, toDatetimeLocal, (_event, value) => { proposal = value; });
    move(event, new Date('2027-04-05T00:00:00'));
    probes.push({ timezone, proposal, actualDurationMinutes: (Date.parse(`${proposal.end}:00+12:00`) - Date.parse(`${proposal.start}:00+12:00`)) / 60000 });
}
console.log(JSON.stringify({
    candidate: '25b1a8f18728fb908a39de1f1dc63a34c10e4950',
    sources: Object.fromEntries(Object.entries(files).map(([key, path]) => [path, crypto.createHash('sha256').update(sources[key]).digest('hex')])),
    event, originalDurationMinutes: (Date.parse(event.end) - Date.parse(event.start)) / 60000,
    expectedProposal: { start: '2027-04-05T01:30', end: '2027-04-05T04:30' }, probes,
}, null, 2));
