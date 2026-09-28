// Read-only reproduction against the submitted Transport helper, not a copied implementation.
import fs from 'node:fs';
import { Buffer } from 'node:buffer';
import process from 'node:process';
import crypto from 'node:crypto';
import ts from 'typescript';

const sourcePath = 'C:/Users/steph/.codex/worktrees/b9b9/oblivionfindings/resources/js/components/fleet-assets/transport/calendar-actions.ts';
const source = fs.readFileSync(sourcePath, 'utf8');
const javascript = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
}).outputText;
const { proposedCalendarWindow } = await import(`data:text/javascript;base64,${Buffer.from(javascript).toString('base64')}`);

// This canonical Auckland booking crosses the autumn clock change: three actual hours.
const canonicalStart = '2027-04-04T01:30:00+13:00';
const canonicalEnd = '2027-04-04T03:30:00+12:00';
const durationMinutes = (Date.parse(canonicalEnd) - Date.parse(canonicalStart)) / 60000;
const probes = [];
for (const browserTimezone of ['UTC', 'Pacific/Auckland']) {
    process.env.TZ = browserTimezone;
    // calendar.tsx converts the canonical instants to Auckland wall strings before decorate().
    const actual = proposedCalendarWindow(
        new Date('2027-04-04T01:30:00'),
        new Date('2027-04-04T03:30:00'),
        new Date('2027-04-05T00:00:00'),
    );
    probes.push({ browserTimezone, actual, expected: { start: '2027-04-05T01:30', end: '2027-04-05T04:30' } });
}
console.log(JSON.stringify({
    candidate: 'a285d4fe7dd1139e02a9bfb48c227946b754e199', sourcePath,
    sourceSha256: crypto.createHash('sha256').update(source).digest('hex'),
    canonicalStart, canonicalEnd, durationMinutes, probes,
}, null, 2));
