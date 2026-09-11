# W12 support mailbox transports

11 September 2026. W12/E11/B02 outbound transport slice. Whole W12 and the release gate remain incomplete.

## Implementation

- `app/Services/GoogleGmailService.php`: bounded, single-attempt MIME submission through the existing provider/token boundary. Requires HTTP200 and a valid bounded provider ID. Empty/malformed acknowledgement, non-success and lost response cannot silently succeed.
- `app/Mail/GoogleGmailTransport.php`: Symfony transport preserving the submitted MIME and returning Gmail's opaque provider ID separately from the RFC header. Safe outgoing failure guidance, no original provider body or connection exception exposed, no automatic resend.
- `app/Mail/ApiMailMessage.php`: shared with the existing `MicrosoftGraphTransport`; validates the complete recipient envelope and preserves To/Cc/Bcc, reply headers, multipart content and files. This serializes already authorized content; it does not grant attachment access.
- `app/Mail/SupportMailboxSender.php`: binds an existing canonical mailbox ID, configuration version and account/mailbox scope. Re-reads the current primary record for every submission, checks connected state and sending consent, enforces the approved From/Reply-To/sender and refuses changed/deleted/disconnected records. Uses current stored credentials. Google uses the connected support account itself; Microsoft shared-mailbox submission requires shared-send consent. Provider-side Send As/Send on Behalf permissions still require actual authorization and acceptance.
- `app/Mail/MicrosoftGraphTransport.php`: retains its existing personal-identity path and MIME behavior, adds canonical support-mailbox handling and rejects a wrong-provider identity before HTTP. No provider registration or live mail configuration changed.

Single organisation and canonical connection ownership; no tenant selectors or duplicated credential stores. No DESIGN.md/design_styles changes. Backend-only slice; no new browser or UI verification claimed.

## Verification

- Focused transport/RFC regression: session89951/tokenit_c11321c5f7e449bc, `w12-support-transport-tests.txt`, terminal0. Diagnostic events prove 79 completed tests, 405 assertions, no failed/errored events, 187 seconds including bootstrap/teardown. All14 postflight checks passed and the exact disposable schema was absent.
- Initial canonical outbox session14929/tokenit_6f70f7b296c64079 terminal1: two tests/36 assertions, one failure at the accepted provider-ID assertion; lost-response case passed. All14 postflight checks passed and the exact schema was absent. Evidence `w12-gmail-outbox-tests.txt` and its diagnostic JSONL. This exposed a real recorder defect, not an HTTP fixture failure: Laravel's SentMessage forwards getMessageId dynamically, so method_exists on its wrapper returned false and discarded Gmail's provider ID.
- Corrected `ItEmailDeliveryService` to unwrap Laravel's actual response and retain valid provider IDs. If Symfony only returns the already submitted RFC ID (for example Graph's empty202), it stays exclusively in the RFC fields. Added an array-transport regression asserting no fabricated provider ID. Final14950/tokenit_214166e86ef0469a, `w12-gmail-outbox-tests-final.txt`: terminal0,29tests339assertions184s, all14postflight/exact schema absence. Actual Gmail acceptance retains the provider ID and encrypted RFC ID separately, records acceptance without delivery, and sends once; lost response stays uncertain with no automatic second send. Existing RFC/callback/retry regressions pass.
- Final protocol review corrected a valid consent combination: Microsoft Mail.Send.Shared also permits sending as the connected user. Added short and URI scope cases. Final support regression18450/tokenit_85b100d6ead24f5d, `w12-support-transport-tests-final.txt`: terminal0,54tests165assertions187s, all14postflight/exact schema absence. Earlier transport counts are superseded for this edited scope check and must not be added to the final run as distinct tests.
- The Gmail integration test uses test-local transport injection and fake provider HTTP. It does not claim that production settings are wired.
- Scoped Pint and diff checks passed. `w12-support-transport-source-hashes.json` records nine final source hashes and ten unchanged protected design hashes; all rechecked after final tests. No active process/runtime/browser/import/cleanup remains.

## Remaining acceptance

Wire the canonical outbound settings to IT notification delivery, add both public-content modes and their UI, and complete truthful provider capability and rejection/reconciliation behavior. Existing settings save a provider choice that active delivery does not consume; this is a confirmed integration gap. Current Microsoft mailbox OAuth consent requests read/write but not send permissions, so existing connections are not advertised as outbound-ready. No scopes were silently added.

Support connection changes during an in-flight provider request, real-process interrupted send/outer-transaction durability, partial-recipient failure, recipient/provider-observed Bcc privacy and RFC identity preservation need their full acceptance evidence. Local MIME serialization tests cannot prove remote mailbox behavior. DP03 default content mode and DP04 settled-ticket policy remain unanswered; DP07 live provider/recipients and operational ownership remain external.

## Primary protocol references

- [Gmail messages.send](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/send): supported OAuth scopes, recipient headers and response resource.
- [Gmail MIME sending guide](https://developers.google.com/workspace/gmail/api/guides/sending): base64url MIME in the raw field.
- [Microsoft sending from another user](https://learn.microsoft.com/en-us/graph/outlook-send-mail-from-other-user): shared-send consent, mailbox permissions and /me behavior.
- [Microsoft permission reference](https://learn.microsoft.com/en-us/graph/permissions-reference#mail-sendshared): Mail.Send.Shared includes sending as the signed-in user.
- Installed Symfony `SentMessage` source explicitly separates the transport-level ID from the original RFC Message-ID header.
