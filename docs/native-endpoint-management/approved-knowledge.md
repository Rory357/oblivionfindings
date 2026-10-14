# Approved IT knowledge in Obli

Development implementation, 8 October 2026. This is one increment within the active Security & Devices programme, not release acceptance.

## Ownership and approval

The helper reuses IT knowledge articles, reviewer publication and encrypted revision snapshots. It creates no separate article store. A source must be currently published, genuinely recorded by the matching reviewer in a published revision, have a current review date, and match the immutable publication content. Legacy status-only rows, captured previous publications without genuine approval, overdue/retired/draft articles and changed publication metadata do not qualify.

Canonical IT permissions and current staff-session evidence apply. The personal helper additionally requires current approved-Site overlap for Site-restricted sources, even when the account has wider IT access. Staff cannot use helper endpoints to retrieve working copies, relationship records, files, revision history or management context. Drafting a new working copy does not invalidate the unchanged approved publication.

## Staff experience

Organisation guides is available from Ask Obli after loading chat and guides. Signed-in staff can search titles, page through results, preview a guide and open its canonical IT knowledge page. Basic anonymous quick guides remain local. Reading organisation guidance makes no AI request.

The native preview is bounded to 4,000 UTF-8 bytes of approved plain text from the body and selected staff-facing structured fields. It excludes embedded files, diagrams and linked records. A shortened preview directs the reader to the full article before following a procedure. The model is told it receives an excerpt that may omit steps.

Attach to my question selects the shown excerpt. The provider disclosure and consent still apply; attaching alone does not send anything. Removing a draft attachment is separate from starting a new chat. Shared ticket drafts include the selected message and selected diagnostics; guide excerpts are not silently copied into tickets.

Sign-out, Windows lock/logoff, expiry and loss/change of service authority clear private source previews along with chat. Session-bound asynchronous results cannot restore them after clearing. If a source changes or becomes unavailable during send or recovery, the helper clears source-bearing context and explains that an earlier provider request might still finish or incur a charge. It never automatically resends.

## API and provider boundary

- GET /api/obli/knowledge?q=&before= returns up to ten scoped summaries and a continuation cursor. Search is limited to 100 characters. Each request examines at most 200 eligible candidates; empty pages can retain a continuation when more candidates remain.
- GET /api/obli/knowledge/{id} returns a canonical link, publication/review dates, a plain-text excerpt, truncation flag and publication digest.
- User chat messages can include one knowledge binding: article_id and version only. The server resolves approved text and metadata itself. Client-supplied article content is rejected.
- Selected excerpts, diagnostics and conversation share the existing 12 KB rendered context limit. Excerpts are explicitly framed as untrusted reference, with no execution authority or new AI tools.
- Current source approval, version and audience are rechecked before dispatch, on cached replay, after provider IO and on read-only reply recovery. Encrypted bounded source bindings accompany the encrypted reply and are deleted with its existing request retention. Prompt text remains unpersisted by the gateway.
- knowledge_unavailable is a concealed 404 response. It is not proof that no provider request occurred, so the native client distinguishes it from definitive pre-send rejection.
- Missing publication or binding schema fails closed. No migration has been applied to production.

## Central configuration

Settings > Integrations > Obli has an Organisation guides switch, off by default, independent of the AI chat switch. The existing current-actor, permissions, versioned save, review and uncertain-save recovery remain.

Guides-only configuration can leave the AI model, token prices and provider key unset. Enabling AI still requires a valid model, positive configured prices and matching saved provider key. Daily/monthly budget and reply retention controls remain validated.

## Verification and limits

The native harness exercises title search, pagination failure, preview-only reading with AI disabled, attachment/provider consent, reference-only submission, source denial before/after delivery uncertainty, clearing and late responses. The actual application WPF tree is rendered at normal and 380px widths. Final verification:60 backend tests/534 assertions,41 native check groups with actual PHP responses, x64/ARM64 builds, full TypeScript, targeted lint and both browser settings flows pass. Separate provider-control and malformed-binding unit checks pass. Exact terminal evidence is recorded in CHECKPOINT.md.

The shared browser settings component is verified with fictional Inertia data and intercepted development responses, including guides-only setup without model/key/prices, review/save, permission/version payload, provider change, uncertain-save recovery and narrow layout. This is not a real administrative server transaction.

No billable provider request, actual staff account, production migration, installed service operation or real remote connection is part of this verification. Signed installed HTTPS/MFA acceptance and provider behaviour remain pending. Automatic semantic retrieval, non-IT policy catalogues, file/diagram previews, attachments, voice and the wider programme remain separate unfinished work.
