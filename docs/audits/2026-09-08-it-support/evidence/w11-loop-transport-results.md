# W11 loop markers and W12 transport preservation

11 September 2026. Partial W11/W12, F03/F05, B01/B02, E05/E10/E11. Full packages and release acceptance remain open.

The previous continuation completed the two-provider merged-reference browser journey. This continuation inspected the current565-entry dirty tree and preserved prior work. No test/browser runtime was active before starting the isolated run below.

## Confirmed gap and implementation

The existing MailChannel supplies the notification class in its internal MessageSending data. No application listener added Auto-Submitted to IT notification mail. Existing inbound parsing already rejects automated headers and delivery-report content; the missing outbound marker allowed app-generated mail to lose that classification if routed back into intake. This is a confirmed code gap, not a demonstrated production mail loop.

`RecordItEmailDelivery::prepareMessage`, explicitly registered once in the existing EventServiceProvider, applies Auto-Submitted: auto-generated and X-Auto-Response-Suppress: All only to notification classes implementing the canonical TracksItEmailDelivery contract. Repeated invocation replaces those fields instead of accumulating duplicates. Ordinary mail is unaffected. No recipient or audience permissions change. The existing mail and database delivery checks remain authoritative.

The existing MicrosoftGraphTransport also discarded headers, reply-to, MIME parts and all but the first To recipient, and returned normally when identity was missing or sendMail returned false. It now submits full MIME through MicrosoftGraphService::sendMimeMail and the existing bounded provider HTTP/token handling. Graph202 means accepted only; other responses throw sanitized transport failures and no automatic send retry occurs. All envelope recipients must match the message's recipients, otherwise sending fails before HTTP. Bcc is restored for Graph's MIME submission because Symfony normally removes it for SMTP's separate envelope; provider-side recipient privacy still requires approved live acceptance. The outgoing Message-ID matches Symfony's sent identity. This is not yet outbound ancestry persistence in the IT ledger.

Sources: [RFC3834 automated-message identification](https://www.rfc-editor.org/rfc/rfc3834.html), [Microsoft Graph MIME sendMail and202 semantics](https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0). Local framework code inspected: MailChannel::additionalMessageData, MessageSending, Symfony SentMessage and Message::getPreparedHeaders/toString. MIME content headers must remain in the top-level header block; no extra separator is inserted before the MIME body object's headers.

Changed files: `app/Listeners/It/RecordItEmailDelivery.php`, `app/Providers/EventServiceProvider.php`, `app/Mail/MicrosoftGraphTransport.php`, `app/Services/MicrosoftGraphService.php`, `tests/Feature/It/ItTicketNotificationOutboxTest.php`, `tests/Feature/It/ItOutboundLoopTransportTest.php`.

## Verification checkpoint

Pint exit0 and focused whitespace check passed. Initial isolated92194/tokenit_062dbb8c0e8e4d36 exited1/Pest2 with0assertions: new code called the unavailable Symfony Headers::removeAll method. Installed Headers supports remove/all; production and tests were corrected using the inspected source. All14postflight checks passed and that exact schema was absent.

Four-file rerun60517/tokenit_39bb76dd23e54c28 exited0: **59 tests /566assertions /180seconds**. Existing outbox, inbound content and provider-header regressions ran with the new transport cases. All14postflight checks passed and exact schema was absent. Evidence `w11-loop-transport-tests-final.txt` and token diagnostic JSONL. This includes the actual generated notification-to-ingestor round trip and no automatic second send after a lost HTTP response.

Subsequent review corrected outbound error wording inherited from the shared polling service: messages now describe mail submission/acceptance, permission or authorization and require delivery reconciliation for unconfirmed outcomes. Added assertions reject polling guidance and require reconciliation after a lost response. Focused final transport run57690/tokenit_d1ceaf6933fa4336 exited0: **14 tests /56assertions /172seconds**, all14postflight checks passed and exact schema absent (`w11-loop-transport-guidance-tests.txt`). These14tests overlap the59-test regression; counts are not additive. No new browser journey or live provider send was performed for this slice. All10protected design hashes unchanged (`w11-loop-protected-hashes.json`); all six final source hashes match `w11-loop-source-hashes.json`. No test/build/browser/server/import/cleanup remains active.

Verified tests exercise actual generated outbox mail fed back into the canonical ingestor, ordinary-mail noninterference, duplicate marker replacement, exact Graph MIME headers/recipients/parts, rejection status codes, absent/expired identity, mismatched envelope, a lost response and final outbound-specific recovery guidance.

## Remaining work

Full W11 loop acceptance, browser verification where applicable, and W12 integration remain open. Current application mail configuration does not register MicrosoftGraphTransport; this existing adapter's repair does not enable a production support identity. W12 still needs canonical provider selection/configuration, DP03 content policy, RFC Message-ID ancestry, accurate provider outcome/reconciliation and approved recipients/live acceptance. DP03 was asked asynchronously this continuation: default full public reply or link-only notification, with both supported and internal notes private. No answer has been received at this checkpoint; elapsed time is not a policy decision. No real communication/configuration/deploy, database migration or design-file edit occurred. DP04 settled outside-window policy remains pending; DP07 provider/scanner/retention/restore ownership remains external.
