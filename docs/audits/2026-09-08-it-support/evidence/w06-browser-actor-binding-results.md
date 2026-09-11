# W06 browser actor binding

Implemented optional originating-actor binding on canonical browser ticket create, edit, public resolution and create-command recovery. Newly updated clients send actor_user_id and require the returned data.viewer_user_id to match. Previously served forms without the field stay compatible; current authorization and locked canonical service checks remain independent. No schema or provider configuration changed.

Changed sources: new BindsItBrowserActor concern and RecoverItTicketCommandRequest; StoreItTicketRequest, UpdateTicketRequest, ResolveTicketRequest, ItProvisioningController; new ItBrowserActorBindingTest. Actor intent is not persisted as a duplicate ticket owner or added to the W02 private-content digest, preserving existing receipt replay.

Actual isolated run:43 tests,460 assertions,207.86s; wrapper exit0, schema suffixf6a181398dfb408a. Includes actor-change denial with draft recovery disabled, malformed actor inputs, matching create/edit/resolve/recover acknowledgements, existing receipt replay/backward compatibility, encrypted drafts and atomic consume regressions. Read w06-browser-actor-binding-tests.txt for exact output. Explicit-path Pint passed.

Client integration and actual stale-session browser journeys remain open. This server-side pass does not claim complete W06 verification.
