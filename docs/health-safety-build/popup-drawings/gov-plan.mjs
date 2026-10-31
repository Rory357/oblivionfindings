// gov-plan.mjs — Governance, Company hub, Health & safety, Compliance, Privacy, Safeguarding, Incidents, Roadmap
// (governance-hs-hub.md rows GOV-01 … RDM-02). Merges keep every legal, safety and board-governance step.
import { readInventory, codeToNum } from './inventory.mjs';
import { loadSpecs, discardMerge, placeOnBoards, notDrawnFrom, patch } from './plan-lib.mjs';

const CODES = { GOV: 0, HUB: 100, HS: 200, CMP: 300, PRV: 400, SAF: 500, INC: 600, RDM: 700 };
const N = (c) => codeToNum(CODES, c);
export const inventory = readInventory('governance-hs-hub.md', 1, 999, CODES);
const S = loadSpecs('gv', 'abcdef');
for (const s of Object.values(S)) { s.invLabels = s.inv; s.inv = (s.inv || []).map(N); }
const g = (code) => S[`gv-${code}`];
const inv = (codes) => ({ inv: codes.map(N), invLabels: codes });

export const meta = {
    page: { id: 'pop-gov', name: '22 · Pop-ups · Governance, H&S & hub' }, insertAfter: 'pop-fin', prefix: 'Pop-Gov', title: 'Governance, H&S & hub',
    lead: 'Board governance (members, actions, budgets, CEO reports, meetings, resolutions, risks, policies, voting rules, spend approvals, strategy, Te Tiriti), the Company hub, Health & safety (events, hazards, injuries, first aid, drills, restraints, lone workers, PPE, safe work procedures, risk assessments, chemicals, worker participation), Compliance, Privacy, Safeguarding, Incidents and the Roadmap. The inventory lists %TODAY% pop-ups here, holding %FOUND% separate screens. On the kit they become %DRAWN% drawings. Every WorkSafe, Privacy Act, restraint, lone-worker, safeguarding and board-governance step stays exactly as it is; reporting an event, hazard, injury, restraint, incident or concern, and lone-worker check-ins, are drawn at frontline size (44 px).',
};

// ───────── merges ─────────
const merged = {};
merged.discard = discardMerge(S, 'gv', [], { example: "You've started a <b>New resolution</b> for the board. If you close now, what you entered is lost.", source: 'governance drafts · hub profile, navigation, hero and ideas' });
{ // the discard prompts are keyed by code, so build the list by hand
    const ids = ['GOV-29', 'GOV-61', 'GOV-72', 'GOV-91', 'GOV-93', 'HUB-06', 'HUB-19', 'HUB-25c', 'HUB-28'];
    const titles = new Set(ids.map((c) => (g(c) || {}).title).concat(((g('GOV-93b') || {}).title) || []).filter(Boolean));
    Object.assign(merged.discard, inv(['GOV-29', 'GOV-61', 'GOV-72', 'GOV-91', 'GOV-93', 'HUB-06', 'HUB-19', 'HUB-28']), {
        was: `${ids.length + 1} separate guards with ${titles.size} different titles (${[...titles].slice(0, 3).map((t) => `"${t}"`).join(', ')}…); the hub uses "Keep …" Cancel buttons and normal-looking discard buttons`,
    });
    for (const id of ['gv-GOV-93b', 'gv-HUB-25c']) delete S[id];
}
const m = (base, codes, extra) => patch(g(base), { ...inv(codes), ...extra });
merged.drill = m('HS-20', ['HS-02', 'HS-20'], { was: 'The dashboard report launcher has its own "Record emergency drill" beside Drills › "Schedule drill"', change: 'One drill form, opened from the launcher and from Drills' });
merged.restraint = m('HS-24', ['HS-03', 'HS-24'], { was: 'Two restraint recorders: the dashboard launcher (3 steps) and Restraints (7 steps); also opened from Respite stays', change: 'One Record a restraint event wizard — every step kept — opened from the launcher, Restraints and Respite stays' });
merged.injury = m('HS-15', ['HS-04', 'HS-15'], { was: 'Two injury recorders: the launcher ("Report injury → return-to-work") and Injuries ("Record workplace injury")', change: 'One Record workplace injury wizard; return to work stays on the injury record' });
merged.loneWorker = m('HS-29', ['HS-06', 'HS-29'], { was: 'Two ways to start a lone-worker session; the launcher one is titled "check-in" but starts a session', change: 'One Start lone-worker session form; check-ins are their own frontline form' });
merged.hazard = m('HS-12', ['HS-07', 'HS-12'], { was: 'Two hazard recorders: the launcher ("Log hazard + risk assessment") and Hazards ("Log hazard")', change: 'One Log hazard wizard' });
merged.rep = m('HS-53', ['HS-08', 'HS-53'], { was: 'Two "Add H&S representative" pop-ups (7 and 12 fields)', change: 'One form with every field' });
merged.boardReports = m('HS-11', ['HS-09', 'HS-11', 'HS-14', 'HS-52', 'HS-61'], { was: 'Five board-report menus; one says "Board safety summary", the others "Board summary"', change: 'One Board reports menu, one wording' });
merged.requirement = m('GOV-28', ['GOV-28', 'CMP-01'], { was: 'Two ways to add a compliance obligation: Governance › "Add requirement" and Compliance › "Log compliance obligation"', change: 'One form, opened from both' });
merged.evidence = m('CMP-02', ['GOV-30', 'CMP-02'], { was: 'Two compliance evidence uploads (Governance and Compliance)', change: 'One Record compliance evidence form' });
merged.complete = m('GOV-31', ['GOV-31', 'CMP-03'], { was: 'Marking an obligation complete is a form in Governance and a confirm in Compliance', change: 'One Mark requirement as done form' });
merged.dates = m('GOV-11', ['GOV-11', 'GOV-49'], { was: 'Two date-range popovers (audit log and meetings) with different buttons', change: 'One Filter by date picker with the approved range calendar' });
merged.done = m('GOV-07', ['GOV-07', 'GOV-76'], { was: 'Two "mark as done" pop-ups for actions (actions page and risk actions)', change: 'One Mark as done form' });
// the old privacy pages' browser prompts are the same actions as the privacy request / breach workspace (PRV-07 family)
merged.refuse = m('PRV-07', ['PRV-07', 'PRV-17'], { was: 'Refuse request exists in the workspace and as a browser prompt on the old request page', change: 'One Refuse request form; not red — the request is kept, refused' });
if (g('PRV-07b') && g('PRV-16')) { Object.assign(g('PRV-07b'), inv(['PRV-07', 'PRV-16'])); g('PRV-07b').change = `${g('PRV-07b').change || ''} Also replaces the old page's browser prompt (PRV-16).`.trim(); delete S['gv-PRV-16']; }
if (g('PRV-07c') && g('PRV-09')) { Object.assign(g('PRV-07c'), inv(['PRV-07', 'PRV-09'])); g('PRV-07c').change = `${g('PRV-07c').change || ''} Also replaces the old breach page's browser prompt (PRV-09), which records the OPC notification even when cancelled.`.trim(); delete S['gv-PRV-09']; }

export const notDrawn = notDrawnFrom(S, [], merged);
for (const x of notDrawn) x.inv = (x.inv || []).map((v) => (typeof v === 'number' ? v : N(v)));
export const mergesList = Object.values(merged);
export const boards = placeOnBoards({
    S, merged, inventory, lead: [merged.discard],
    wizardGroups: [
        ['Pop-Gov-Wizards-1', 'Governance', (n) => n < 100],
        ['Pop-Gov-Wizards-2', 'Health & safety', (n) => n >= 200 && n < 300],
        ['Pop-Gov-Wizards-3', 'Hub, safeguarding and incidents', (n) => (n >= 100 && n < 200) || n >= 300],
    ],
    groups: [
        { name: 'Pop-Gov-Board', title: 'Governance · board members, actions, budgets, CEO reports and compliance', test: (s, n) => n >= 1 && n <= 35 },
        { name: 'Pop-Gov-Meetings', title: 'Governance · interests, meetings, board packs, reviews, policies and resolutions', test: (s, n) => n >= 36 && n <= 70 },
        { name: 'Pop-Gov-Risk', title: 'Governance · risks, voting rules, spend approvals, strategy and Te Tiriti', test: (s, n) => n >= 71 && n <= 99 },
        { name: 'Pop-Gov-Hub', title: 'Company hub', test: (s, n) => n >= 101 && n <= 199 },
        { name: 'Pop-Gov-HS1', title: 'Health & safety · the report launcher, events, hazards and injuries', test: (s, n) => n >= 201 && n <= 217 },
        { name: 'Pop-Gov-HS2', title: 'Health & safety · first aid, drills and restraints', test: (s, n) => n >= 218 && n <= 228 },
        { name: 'Pop-Gov-HS3', title: 'Health & safety · lone workers, PPE and safe work procedures', test: (s, n) => n >= 229 && n <= 246 },
        { name: 'Pop-Gov-HS4', title: 'Health & safety · risk assessments, chemicals, worker participation and analytics', test: (s, n) => n >= 247 && n <= 299 },
        { name: 'Pop-Gov-Privacy', title: 'Compliance, privacy, safeguarding, incidents and roadmap', test: (s, n) => n >= 301 },
    ],
});
export const all = S;
