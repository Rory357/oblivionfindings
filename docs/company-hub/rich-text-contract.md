# Company Hub formatted text contract

Text blocks may carry optional `rich_text`. Existing `body` strings remain valid and unchanged when this field is absent. A null `rich_text` explicitly clears formatting and retains the supplied plain `body`.

```ts
type Run = { text: string; bold?: boolean; italic?: boolean; href?: string };
type Node =
  | { type: 'paragraph'; runs: Run[] }
  | { type: 'heading'; level: 2 | 3 | 4; runs: Run[] }
  | { type: 'bullet_list' | 'ordered_list'; items: Run[][] }
  | { type: 'table'; header: boolean; rows: Run[][][] };
type RichText = { version: 1; blocks: Node[] };
```

`HubRichText` validates this semantic JSON. Unsupported keys, node types, attributes, embeds and malformed lists are rejected. It accepts only current `HubContent::safeUrl` destinations: HTTPS links without credentials, or an internal application path beginning with one slash. Link length is at most 2,000 characters. Text runs are UTF-8 strings; marks are strict booleans. No HTML is interpreted or stored as executable markup. Render strings as text nodes and only the declared semantic elements and attributes.

Limits are exported as constants in `HubRichText`: 20,000 plain-text characters including generated separators, 100 nodes, 1,000 cumulative text runs, 100 items per list, 20 rows and 10 columns per table, and 200,000 encoded JSON bytes. Tables must have equal nonzero cell counts in each row. Empty documents, lists, cells and `rows: []` are safe unfinished drafts; visible empty formatted content, including whitespace-only content, cannot be released.

On every validated rich-text save, the server derives `body` from the document. It joins runs directly, paragraphs/nodes with two newlines, list items and table rows with one newline, and table cells with a tab. Search, reading-time estimates and older plain-text consumers therefore use the same authored text. Client `body` cannot override a non-null formatted document. The saved semantic tree stays in the complete draft/revision snapshot and uses the existing hidden-section/block reader boundary, audience and authorisation checks.

Formatting belongs only to text blocks. File/video descriptions, callouts, buttons and featured summaries retain their existing plain-string fields. No database migration or editor dependency is required.

Verification: `HubRichTextTest.php` and `HubReadinessTest.php` exercise shape, limits, safe URLs, legacy snapshots, hidden content and readiness without starting the application or using a database. `HubRichGuideTest.php` covers application persistence, published-reader/search separation, denied writes, malformed hidden drafts and explicit plain-text clearing; its execution is recorded separately when an isolated database worker is available.
