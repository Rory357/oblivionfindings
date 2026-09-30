/* MOCKUP INFRASTRUCTURE, not product code: answers the real report builder’s
 * requests (/report-builder/*) inside the preview with synthetic results, so
 * the REAL workspace (pages/reporting/workspace.tsx) can be drawn with the
 * proposed medication domain (Main, Q9). Nothing leaves the browser. The
 * domain’s real sources, scope and checks are README build note 9. */
import type { Definition, Group, Payload, Source } from '@/pages/reporting/model';
import { CD_EVENTS, ERRORS, HARM_LABEL, HOUSES, PEOPLE, PEOPLE_ORDER, PRN_DOSES, STOCK, TYPE_LABEL, orderOf, type PersonId } from './data';
import { RANGE_START, TODAY_ISO, daysLeft, roundsIn, ROUND_LABEL, slotsIn, time12 } from './model';

const f = (label: string, type: 'text' | 'number' | 'date', description: string, unit = '') => ({ label, type, unit, description });
/** The medication domain’s sources — controlled ones only for readers with controlled-medicine access. */
export function medicationSources(cd: boolean): Record<string, Source> {
    const s: Record<string, Source> = {
        dose_slots: {
            label: 'Scheduled doses',
            domain: 'medication',
            note: 'One row per scheduled dose whose window has ended — the same records as the Doses report.',
            fields: { person: f('Person', 'text', 'The person the dose was for'), house: f('House', 'text', 'Their house'), medicine: f('Medicine', 'text', 'Controlled medicines need controlled-medicine access'), due_date: f('Due date', 'date', 'NZ date'), due_time: f('Due time', 'text', 'As ordered'), outcome: f('Outcome', 'text', 'Given, refused, withheld, missed or not recorded'), recorded_late: f('Recorded late', 'text', 'Yes when recorded after the window'), recorded_by: f('Recorded by', 'text', 'Who recorded it') },
        },
        rounds: {
            label: 'Rounds',
            domain: 'medication',
            note: 'One row per round whose window has ended, worked out from its doses.',
            fields: { house: f('House', 'text', ''), date: f('Date', 'date', 'NZ date'), round: f('Round', 'text', 'Morning, midday, afternoon or evening'), result: f('Result', 'text', 'On time, late, not completed or not started'), doses: f('Doses', 'number', 'Doses in the round', 'doses') },
        },
        prn_doses: {
            label: 'As-needed doses',
            domain: 'medication',
            note: 'One row per as-needed dose given.',
            fields: { person: f('Person', 'text', ''), house: f('House', 'text', ''), medicine: f('Medicine', 'text', ''), given_date: f('Given', 'date', 'NZ date'), effect_recorded: f('Effect recorded', 'text', 'Yes or no') },
        },
        medication_errors: {
            label: 'Medication errors',
            domain: 'medication',
            note: 'One row per report — the facts only; accounts and notes never leave the report.',
            fields: { reference: f('MED number', 'text', ''), house: f('House', 'text', ''), occurred: f('Happened', 'date', 'NZ date'), what_went_wrong: f('What went wrong', 'text', ''), reached: f('Reached the person', 'text', 'Yes, no (a near miss) or not sure'), harm: f('Harm', 'text', ''), stage: f('Stage', 'text', '') },
        },
        stock: {
            label: 'Stock',
            domain: 'medication',
            note: 'One row per stock line, as at now.',
            fields: { medicine: f('Medicine', 'text', ''), house: f('House', 'text', ''), on_hand: f('On hand', 'number', '', 'units'), days_left: f('Days left', 'number', 'Empty for as-needed lines', 'days'), expires: f('Expires', 'text', '') },
        },
    };
    if (cd)
        s.controlled_register = {
            label: 'Controlled register',
            domain: 'medication',
            note: 'Controlled-medicine access only. Discrepancies, losses and destructions.',
            fields: { reference: f('Reference', 'text', ''), medicine: f('Medicine', 'text', ''), house: f('House', 'text', ''), date: f('Date', 'date', 'NZ date'), entry: f('Entry', 'text', '') },
        };
    return s;
}

type Rec = Record<string, string | number | null>;
let viewer: { pids: PersonId[]; cd: boolean } = { pids: PEOPLE_ORDER, cd: false };
export const setBuilderViewer = (v: typeof viewer) => (viewer = v);
function records(source: string, from: string, to: string): Rec[] {
    const inR = (d: string) => d >= from && d <= to;
    const med = (orderId: string) => (orderOf(orderId).cd && !viewer.cd ? 'Controlled medicine' : orderOf(orderId).med);
    if (source === 'dose_slots')
        return slotsIn(from < RANGE_START ? RANGE_START : from, to > TODAY_ISO ? TODAY_ISO : to, viewer.pids).counted.map((x) => ({ person: PEOPLE[x.pid].legal, house: HOUSES[PEOPLE[x.pid].house], medicine: med(x.orderId), due_date: x.day, due_time: time12(x.time), outcome: x.outcome === 'notRecorded' ? 'Not recorded' : x.outcome.charAt(0).toUpperCase() + x.outcome.slice(1), recorded_late: x.late ? 'Yes' : 'No', recorded_by: x.by }));
    if (source === 'rounds') return roundsIn(from, to > TODAY_ISO ? TODAY_ISO : to, viewer.pids).rows.map((x) => ({ house: HOUSES[x.house], date: x.day, round: x.name, result: ROUND_LABEL[x.result], doses: x.slots }));
    if (source === 'prn_doses') return PRN_DOSES.filter((d) => inR(d.day) && viewer.pids.includes(orderOf(d.orderId).pid)).map((d) => ({ person: PEOPLE[orderOf(d.orderId).pid].legal, house: HOUSES[PEOPLE[orderOf(d.orderId).pid].house], medicine: med(d.orderId), given_date: d.day, effect_recorded: d.effect ? 'Yes' : 'No' }));
    if (source === 'medication_errors') return ERRORS.filter((e) => inR(e.occurredIso) && viewer.pids.includes(e.pid)).map((e) => ({ reference: e.id, house: HOUSES[PEOPLE[e.pid].house], occurred: e.occurredIso, what_went_wrong: TYPE_LABEL[e.type], reached: e.reached === 'no' ? 'No — near miss' : e.reached === 'yes' ? 'Yes' : 'Not sure', harm: e.reached === 'no' ? 'None' : HARM_LABEL[e.harm], stage: e.stage }));
    if (source === 'stock') return STOCK.filter((l) => viewer.pids.includes(orderOf(l.orderId).pid) && (!orderOf(l.orderId).cd || viewer.cd)).map((l) => ({ medicine: orderOf(l.orderId).med, house: HOUSES[PEOPLE[orderOf(l.orderId).pid].house], on_hand: l.onHand, days_left: daysLeft(l), expires: l.expires }));
    if (source === 'controlled_register' && viewer.cd)
        return [...CD_EVENTS.discrepancies.map((d) => ({ reference: d.id, medicine: orderOf(d.orderId).med, house: HOUSES[PEOPLE[orderOf(d.orderId).pid].house], date: d.day, entry: d.what })), ...CD_EVENTS.losses.map((d) => ({ reference: d.id, medicine: orderOf(d.orderId).med, house: HOUSES[PEOPLE[orderOf(d.orderId).pid].house], date: d.day, entry: d.what })), ...CD_EVENTS.destructions.map((d) => ({ reference: d.id, medicine: orderOf(d.orderId).med, house: HOUSES[PEOPLE[orderOf(d.orderId).pid].house], date: d.day, entry: d.what }))].filter((r) => inR(r.date));
    return [];
}
function value(op: string, field: string | null, list: Rec[]): number | null {
    if (op === 'count') return list.length;
    if (!field) return null;
    const nums = list.map((r) => r[field]).filter((v): v is number => typeof v === 'number');
    if (op === 'known') return list.filter((r) => r[field] !== null && r[field] !== '').length;
    if (op === 'distinct') return new Set(list.map((r) => r[field])).size;
    if (!nums.length) return null;
    if (op === 'sum') return nums.reduce((a, b) => a + b, 0);
    if (op === 'avg') return Math.round((nums.reduce((a, b) => a + b, 0) / nums.length) * 10) / 10;
    if (op === 'min') return Math.min(...nums);
    if (op === 'max') return Math.max(...nums);
    return null;
}
/** The builder groups by `date` by default: each medication source’s own date. */
const withDate = (r: Rec): Rec => ({ ...r, date: r.date ?? r.due_date ?? r.given_date ?? r.occurred ?? null });
function payloadFor(def: Definition): Payload {
    const list = records(def.source, def.date_from, def.date_to).map(withDate);
    const vals = (rows: Rec[]) => Object.fromEntries(def.measures.map((m) => [m.id, value(m.operation, m.field, rows)]));
    const byKey = new Map<string, Rec[]>();
    for (const r of list) {
        const k = JSON.stringify(def.groups.map((g) => r[g] ?? null));
        byKey.set(k, [...(byKey.get(k) ?? []), r]);
    }
    const groups: Group[] = def.groups.length ? [...byKey.entries()].map(([k, rows]) => ({ dimensions: JSON.parse(k), values: vals(rows), row_count: rows.length })).slice(0, def.limit || 50) : [];
    return {
        generated_at: '2026-09-28T09:12:00+13:00',
        definition_hash: 'p09-preview',
        source: { window: { from: def.date_from, to: def.date_to, timezone: 'Pacific/Auckland' }, watermark: { records: list.length }, coverage: 'complete' },
        result: { row_count: list.length, group_count: groups.length, missing: {}, totals: vals(list), groups, chart: groups, rows: list.slice(0, 200).map((r) => Object.fromEntries((def.columns.length ? def.columns : Object.keys(r)).map((c) => [c, r[c] ?? null]))) },
        comparison: null,
        preview_limit: 200,
    };
}

const json = (body: unknown) => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
let installed = false;
const runs = new Map<string, Definition>();
export function installBuilderStub() {
    if (installed) return;
    installed = true;
    const real = window.fetch.bind(window);
    window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
        const path = url.replace(/^https?:\/\/[^/]+/, '');
        if (!path.startsWith('/report-builder/')) return real(input, init);
        const method = (init?.method ?? 'GET').toUpperCase();
        const body = init?.body ? JSON.parse(String(init.body)) : {};
        if (path.startsWith('/report-builder/targets'))
            return json({ targets: viewer.pids.map((p, i) => ({ id: i + 1, label: PEOPLE[p].legal })), sites: [...new Set(viewer.pids.map((p) => PEOPLE[p].house))].map((h, i) => ({ id: i + 1, label: HOUSES[h] })) });
        if (path === '/report-builder/validate') return json({ definition: body.definition });
        if (path === '/report-builder/runs' && method === 'GET') return json({ runs: [] });
        if (path === '/report-builder/runs' && method === 'POST') {
            const id = `run-${runs.size + 1}`;
            runs.set(id, body.definition);
            return json({ id, status: 'queued' });
        }
        const m = /^\/report-builder\/runs\/(run-\d+)$/.exec(path);
        if (m && runs.has(m[1])) return json({ id: m[1], status: 'completed', payload: payloadFor(runs.get(m[1])!) });
        if (/\/export$/.test(path)) return new Response(new Blob(['Synthetic preview — nothing is exported.'], { type: 'text/plain' }), { status: 200 });
        if (path.startsWith('/report-builder/reports')) return json({ report: { id: 1, name: body.definition?.name ?? 'Medication report', source: body.definition?.source, version: 1, definition: body.definition, archived_at: null, folder: body.folder ?? null, favourite: !!body.favourite } });
        return json({});
    };
}
