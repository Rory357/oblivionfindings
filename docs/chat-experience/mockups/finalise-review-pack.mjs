import fs from 'node:fs/promises';
import {groups} from './src/screens.js';
const root=new URL('./',import.meta.url);
const scenarios=groups.reduce((n,g)=>n+g.variants.length,0);
const checks=JSON.parse(await fs.readFile(new URL('revision-2-checks.json',root),'utf8'));
if(checks.journeys.some(j=>!j.pass))throw Error('A review journey still fails');
if(checks.reviewPasses[1].finalScenarioSweep.length!==scenarios)throw Error('Scenario coverage mismatch');
const ledger=JSON.parse(await fs.readFile(new URL('approved-revisions.json',root),'utf8'));
const evidence=[
 'M03, M18; named-lead-to-Rohan call journey',
 'M10–M12, M19, M23; family role, isolated client switch and no staff record action after a family call',
 'M13; mobile queue → thread → back journey; four-width review',
 'M18, M25; existing draft and sent message shared with original thread during a call',
 'M18/history, M19/history; All calls / Missed / Callbacks filter actual preview entries',
 'M03/details, M04/membership, M05/manage, M11/details; members and owner come from selected thread',
 'M21/override; explicit affected service and unchanged Tōtara policy in scope-isolation journey',
 'M03, M06, M14, M16; per-actor/thread drafts, uploads, reply, search and scroll state; draft-return journeys',
 'M04/direct, group, error; existing direct reuse, new direct creation, named group, empty-name guard and retry',
 'M03/details → Manage conversation; favourite, unread, mute, archive/unarchive, owner rename, leave and transfer',
 'M06/actions; selected sender determines edit/remove; unavailable access blocks membership inspection',
 'M06/reply, edit, deleted; quoted-message navigation, edit cancellation and removed-original placeholder',
 'M03 composer @ picker; thread members only; Mentions inbox filter',
 'M06/actions; reaction, save and pin; saved selected-message journey',
 'M09/sending, sent, failed, checking; message-level progress, retry and outcome check',
 'M01, M11, M13; common unread count; per-role care-team/family receipt wording',
 'M08; messages/files/people/conversations, sender/date/thread filters, exact message highlight and return',
 'M03/history, typing; per-thread reading position, prepend offset and explicit jump to new activity',
 'M07/library; media scoped to eligible conversation membership; unavailable media state',
 'M07/attachments, uploading, failed, publish; file queue, captions, limits, scan, retry, remove and separate publication review',
 'M07/record, preview, play, permission, limit; pause/resume/discard, 2-minute proposed limit and visual playback',
 'M10/multi and M11; Sam/Elliot switch and separate Jo/Nina threads; tested family isolation',
 'M11/empty; M12/pending, accepted, declined, expired, removed; first-send disclosure, explicit history choice',
 'M13 filters, claim, assignment, resolved/reopened; tested ownership/count and mobile queue changes',
 'M10/unconfigured and M13; working-hours configuration, due/overdue examples, no promise for unconfigured service',
 'M13/handoff, M16/clock; review ownership and handover without blocking finish/clock-out',
 'M13/internal; dedicated staff composer/history; verified absence from family thread',
 'M13/callback, M19 callback lifecycle; preferred/agreed times, owner, attempts, complete/cancel and duplicate reuse',
 'M10 urgent contact, conversation concern review; configured service route and retained managed care contact',
 'M18 and M19 declined, cancelled/no-answer, ended; recovery and callback actions',
 'M25 all seven variants; global incoming card, busy-call switch, missed and answered elsewhere',
 'M18/group, invite, end; M19/group; eligible participants, ringing invite, host removal and leave/end distinction',
 'M25 card plus persistent controls; work-space reservation; cross-page call journey and four-width checks',
 'M18/preflight; M20 recovery variants; audio-device controls and truthful visual-only microphone test',
 'M25/family Review record; compact two-step Communication Record / optional shared Family Note; required wording and audience acknowledgement verified',
 'M21 general/access, M22 calls/files/privacy; effective-access review, eligibility and rollout settings',
 'M21/override; inherited/overridden values, field-level override merge and reviewed reset',
 'M21/conflict, failed and M22/validation; diff, scope impact, preserved failure draft and explicit concurrent-change review',
 'M22/investigator, audit, holds, offboarding; case/reason gate, separate audit domains, preservation and access expiry',
 'M22/relationships; approved relatives, named workers/cover, effective Chat policy link',
 'M23; visible text size, spacing, theme, sending behaviour, availability, quiet/sound and preview examples',
 'M22/health; messaging, realtime, push, uploads and voice with owner, timestamp and trend',
 'Shared v2.css; readable bubble typography, grouped senders, local dates, spacing and light/dark palette',
 'M03, M10, M11, M13, M18; person/client/audience/availability in consistent headers and cards',
 'M13; owner, due state and next action on each queue card; overdue styling',
 'Shared Thread, modal, buttons, disclosure and status primitives; same actions across full, embedded and call views',
 '100 four-width checks; M02 list/thread/details, M13 mobile queue and M24 illustrative keyboard; safe-area spacing',
 'Keyboard trap, Escape and focus return verified; 320px large-text/dark reflow and reduced-motion styles. Full zoom/device/assistive-technology acceptance remains implementation work',
 'M14–M17; original work and intended recipient retained; tested medication return, ticket public/internal draft separation and restricted source denial',
 'S01–S24 mapped by registry; M17 explicitly includes roster, recognition, services, legacy and technician; original source review/audience preserved',
 'Five guided review journeys; per-scenario review status and feedback export, independent from implementation approval'
];
ledger.status='All 51 approved refinements addressed in the isolated UI/UX mockup. Ready for user design review; no application implementation approval.';
ledger.latest='Revision 2 completed with two additional review-and-fix passes, 191 final scenario renders, 100 responsive checks and 29 passing interaction checks.';
ledger.items=ledger.items.map((item,i)=>({...item,status:'Addressed in mockup; awaiting user design review',evidence:evidence[i]}));
await fs.writeFile(new URL('approved-revisions.json',root),JSON.stringify(ledger,null,2)+'\n');
const esc=s=>s.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
await fs.writeFile(new URL('gallery.html',root),`<!doctype html><html lang="en-NZ"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Chat · Complete design review gallery</title><style>body{margin:0;background:#f4f2f7;color:#25202e;font:16px/1.65 system-ui}header,main{max-width:1320px;margin:auto;padding:32px 24px}header{padding-top:52px}h1{font-size:38px;line-height:1.2;margin:12px 0}a{color:#6931c2}nav{display:flex;flex-wrap:wrap;gap:8px}nav a,.launch{background:white;border:1px solid #ded8e7;padding:10px 14px;border-radius:9px;text-decoration:none}.launch{background:#6f32d3;color:white;display:inline-block}article{background:white;border:1px solid #e0dbe8;border-radius:16px;padding:22px;margin:25px 0;scroll-margin-top:20px}h2{margin:4px 0 8px;font-size:24px}small{color:#6c627a}.shots{display:grid;grid-template-columns:1fr 1fr;gap:18px}figure{margin:0}img{width:100%;height:auto;border:1px solid #e6e1eb;border-radius:8px}figcaption{font-size:13px;color:#6c627a}details{margin-top:16px}ul{columns:2;padding-left:20px}.notice{padding:16px 20px;border-radius:12px;background:#eae3f7}.extra{display:flex;flex-wrap:wrap;gap:15px}@media(max-width:760px){.shots{grid-template-columns:1fr}h1{font-size:28px}header,main{padding:24px 16px}ul{columns:1}}</style><header><small>OBLIVION CARE · REVISED MOCKUPS · 15 OCTOBER 2026</small><h1>The complete Chat experience</h1><p>25 screen groups · ${scenarios} scenarios · 51 approved improvements</p><p class="notice">Ready for UI/UX review. All actions use sample data. Web first; the native mobile app comes later. Product implementation has not started.</p><p><a class="launch" href="review.html#M01/default">Open interactive review</a></p><nav>${groups.map(g=>`<a href="#${g.id}">${g.id} ${esc(g.title)}</a>`).join('')}</nav><p class="extra"><a href="screenshots/compact-call-note-v2.jpg">Compact call-note dialog</a><a href="screenshots/compact-call-note-phone-v2.jpg">Compact phone dialog</a><a href="screenshots/M25-family-desktop-v2.jpg">Incoming family call</a><a href="screenshots/M24-keyboard-v2.jpg">Phone keyboard layout</a><a href="screenshots/M24-large-dark-v2.jpg">Large text / dark mode</a></p></header><main>${groups.map(g=>`<article id="${g.id}"><small>${g.id} · ${esc(g.section)}</small><h2>${esc(g.title)}</h2><p>${esc(g.note)}</p><p><a href="review.html#${g.id}/${g.variants[0].id}">Explore ${g.variants.length} interactive scenarios →</a></p><div class="shots"><figure><a href="screenshots/${g.id}-desktop.jpg"><img loading="lazy" src="screenshots/${g.id}-desktop.jpg" alt="${esc(g.title)} desktop mockup"></a><figcaption>Desktop</figcaption></figure><figure><a href="screenshots/${g.id}-phone-web.jpg"><img loading="lazy" src="screenshots/${g.id}-phone-web.jpg" alt="${esc(g.title)} responsive phone web mockup"></a><figcaption>Phone browser · Native app later</figcaption></figure></div><details><summary>Scenarios and inventory coverage</summary><ul>${g.variants.map(v=>`<li><a href="review.html#${g.id}/${v.id}">${esc(v.label)}</a></li>`).join('')}</ul><p>Inventory: ${g.surfaces.join(', ')}</p></details></article>`).join('')}</main></html>`);
for(const g of groups)for(const suffix of ['desktop','phone-web'])await fs.access(new URL(`screenshots/${g.id}-${suffix}.jpg`,root));
console.log(JSON.stringify({groups:groups.length,scenarios,approvedItems:ledger.items.length,screenshots:groups.length*2,journeys:checks.journeys.length,failedJourneys:checks.journeys.filter(j=>!j.pass).length}));
