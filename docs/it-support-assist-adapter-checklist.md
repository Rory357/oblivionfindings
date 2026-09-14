# IT & Support assistance — future adapter checklist (W25)

This application ships the **layout and contract** for IT assistance and keeps
execution **disabled**. Model execution belongs to the separate whole-web-app
AI programme. Any future adapter that wants to turn a capability on must
satisfy every item below; the contract tests in
`tests/Feature/It/ItAssistContractTest.php` and the panel tests encode the
non-negotiable parts.

## Contract source of truth

- `app/Domain/It/Services/ItAssistContractService.php` — `forTicket()` and
  `forArticle()` produce the only shapes the panels accept.
- Read-only endpoints: `GET /it/tickets/{ticket}/assist`,
  `GET /it/knowledge/{article}/assist`. **There is no execute or apply
  endpoint.** Do not add one; proposals are applied through the existing
  authorized, versioned commands (triage update, comment command, knowledge
  proposal/publish).

## Before enabling a capability

1. **Actor identity is server-side.** Never trust a browser payload for who
   is asking or what they may see; derive from the authenticated user and
   `ItWorkAccessService` / `ItKbAccessService` exactly as the contract does.
2. **Sources are descriptors until retrieval time.** The contract carries
   counts and keys, never content. Retrieval must re-apply the same view
   scope (`applyViewScope`, `canWork`, revision scope) at the moment content
   is read, and again before rendering or opening any reference.
3. **Audience first.** A reply draft has a declared audience (`public` or
   `internal`) chosen before generation; `internal` requires work access.
   Internal notes can never feed a public draft.
4. **Credentials are out of bounds.** No vault record, credential value,
   masked reference or reveal action may appear in any context object. The
   contract has no credential descriptor by construction — keep it that way.
5. **Untrusted content.** Email, document and conversation text is data.
   Instructions inside it cannot grant permission, change policy, select
   tools or authorize an action. Test this with hostile fixtures
   (instructions embedded in a comment, an email body and a document).
6. **Results carry provenance.** Every result must include: `operation_id`,
   the `record` `{type, id, version}` it was generated against, `sources`
   with `evidence_at`, `output` `{text, proposed_fields, uncertainty}` and an
   explicit unavailable/uncertain state. The panel must show generated time
   and "Draft suggestion" status; nothing is presented as fact.
7. **Stale is rejected.** Before offering Insert / Replace / Apply, compare
   the result's `record.version` with the live record; a newer draft or
   ticket version invalidates the suggestion instead of silently replacing
   newer text.
8. **Apply through existing commands only.** Proposed fields go through
   `ItTicketTriageService` (with `expected_version` and the usual reason
   rules), replies through `ItTicketInteractionService::addCommentCommand`,
   documents through the knowledge proposal flow. No assistant-specific
   bypass, no auto-send, no auto-publish.
9. **Never serialize a whole model** (`ItTicket`, `ItKbArticle`, provider
   payloads) into a generic context object. Project the minimum fields the
   capability needs.
10. **Provider policy.** Approved provider, data-handling terms, retention
    and region agreed and recorded before `it.assist.enabled` means anything;
    until then the reason stays `provider_not_integrated`.
11. **Evaluation set.** Keep a small sanitised local ticket/document set,
    including malicious-instruction and revoked-access cases, and run it
    before any rollout. Revoked access must remove the source from
    suggestions immediately.
12. **Daily service availability must not depend on the provider.** Every
    surface renders its unavailable state gracefully (the panels already do).

## Surfaces already prepared (disabled)

| Surface | Where | State shown today |
|---|---|---|
| Ticket summary / handover | Ticket detail → Assist → Summary | capabilities "Not enabled", permitted sources, record/version |
| Reply draft | Ticket detail → Assist → Reply draft | audience chooser, Insert / Replace / Discard disabled, never-sends note |
| Triage suggestion | Ticket detail → Assist → Triage | current category/priority/next action, "no suggestion", evidence explanation |
| Documentation assistance | Knowledge document → Assist | draft-from-resolution / summarise / completeness disabled, publication note |
| Credentials | — | **no surface, by design** |
