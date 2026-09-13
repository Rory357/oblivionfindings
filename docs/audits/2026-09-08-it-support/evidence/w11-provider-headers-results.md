# W11 provider header and sender boundary — 11 September 2026

Scope: partial W11/F03/E10. Whole W11 remains incomplete.

Confirmed source defects: Gmail keyed headers by lowercase name, silently retaining the last duplicate; Graph similarly overwrote References/In-Reply-To. Gmail's From regex selected the first angle-bracket address even when another sender followed. These are confirmed unsafe interpretation paths, not evidence of a real exploit.

New shared `ItEmailHeaders` bounds provider header collections at200 fields,65536 total name/value bytes and16384 bytes per value. It rejects duplicate canonical singleton fields, malformed names, controls, invalid UTF-8 and ambiguous sender syntax. Repeated trace fields are permitted. Its single-author boundary accepts quoted names, legal folding and bounded nested comments, then validates the extracted mailbox through the installed Symfony Address validator. It does not use Symfony's convenience display-name regex as a mailbox-list parser.

Existing Gmail/Graph `readMessage` use this parser. Graph also cross-checks raw From and Message-ID against provider fields. A typed header rejection settles the existing discovered inbound receipt as quarantined within the current mailbox claim; it stores no sender/body/subject/thread data. Acknowledgement follows the normal committed-receipt flow. Transport failures still use existing retry semantics. No new mailbox/credential/queue or production mock is introduced.

Sources: RFC5322 §3.6 limits originator and identity fields to one occurrence; §3.6.2 permits multi-author From with Sender. The application's stricter one-author-to-one-account requirement follows the implementation plan's ambiguity quarantine boundary, rather than treating the first author as authorized. Reference: https://www.rfc-editor.org/rfc/rfc5322#section-3.6 . RFC3834 was opened for subsequent automatic-response work; loop/bounce classification is not implemented in this slice.

Changed files: `ItEmailHeaders.php`, `ItMailboxInbox.php`, `InboundEmailIngestor.php`, existing `GoogleGmailService.php`/`MicrosoftGraphService.php`, unit `ItEmailHeadersTest.php` and feature `ItInboundProviderHeadersTest.php`.

Actual verification: first seven-file suite67678 terminal0,106passed706assertions187s; all14postflighttrue and exact schema it_577f3cad23ac41a4 absent. Final two-file suite86040 terminal0,48passed244assertions172s; all14postflighttrue and exact schema it_b894d93025d94dfb absent. Logs w11-provider-headers-tests.txt and w11-provider-headers-final-tests.txt. Scoped Pint/diffcheck passed. Source snapshot w11-provider-headers-source-hashes.json; all10 protected design files unchanged.

Review corrections implemented and verified by the final48cases: validate whitespace-only/control/oversized sender values before trim-to-empty; reject a bare address as an unquoted prefix before another mailbox. Browser preparation adds33synthetic messages/provider: previous31cases plus duplicate References and multiple senders. Actual browser slice passed: w11-headers-browser-results.md and reconciliation show four quarantines/provider,54tickets/two replies, matching audits, one read/message and ACK-only recovery. Bootstrap24568/cleanup29713 terminal0; independent exact schema/directory absence. Ownedtab21closed. Full W11 remains incomplete.
