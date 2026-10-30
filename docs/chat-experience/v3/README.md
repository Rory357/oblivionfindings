# Chat v3 boards (approved 22 October 2026)

These are the approved drawings for the Chat rebuild. Stephan re-checked them on 30 Oct 2026: these are the right ones.

Build each screen to them, view for view. Compare board and build at 1440 px in light and dark (DESIGN.md: "Approximating an approved mockup").

## How to open them

| Folder | What's in it | How to open |
|---|---|---|
| `standalone/` | The six boards as single files, with every stylesheet and logo built in. | Double-click a file. It works anywhere and can be shared or attached as it is. Needs internet only for the Instrument Sans font. |
| `boards/` | The same six boards with the stylesheets (`oc.css`, `oc-chat.css`, `oc-chat3.css`) and `brand/` logos beside them. | Open a board in a browser from your checkout. Add `#d` to the address for dark mode. |
| `reference/` | Older Chat drawings, "Codex revision 2 in the Slate skin". | **Superseded look.** Use them only to see which settings, states and screens exist. See below. |
| `source/` | The scripts that drew the boards. | They import helpers from a temporary folder (22 Oct) that no longer exists. Treat them as reference. Set `DS` and `OUT` in `c3lib.mjs`, `build.sh` and `shot.sh` before trying to rebuild. |

The boards also appear on the "Chat v3 — proposed rebuild" page of Stephan's private design viewer (https://claude.ai/artifact/S2FWVebvqtxrVL5LHahzHy). `source/manifest-entry.json` and `source/add-to-viewer.mjs` add them to that viewer.

## The six approved boards

| Board | What it shows |
|---|---|
| ChatV3-Workspace | Staff and family threads at 1440 × 1000, the conversation list, row states, rings |
| ChatV3-Compose | Emoji picker, reactions, stickers, GIF library, formatting, attach menu, inline voice notes, commands, @on-shift |
| ChatV3-Messages | One message menu, Message info, share, threads, pins, important and urgent, polls, links, states, multi-select |
| ChatV3-Oblivion | Care cards, turn into, privacy check before family, @on-shift reach, handover digest, read and confirm, send later, lone work |
| ChatV3-Calls | Calls page, incoming calls, video grid, screen share, docks, call record |
| ChatV3-Family | Family portal conversation, who's caring today, reply promise, seen by care team, urgent help |

## Reference boards (superseded look)

Each board has a light file and a `-Dark` file.

| Board | Use it for |
|---|---|
| ChatSettings | The settings, personal preferences and states that Settings › Chat must cover. v3 has no settings board: draw one on the Fleet settings pattern and get it approved first. |
| ChatCalls | The older calls kit: call card, two-step call note. |
| ChatFamily | The older family portal Chat and the worker family inbox. |
| MessagesKit, Messages | The older staff parts kit and page scene. |
| Portal | The family portal page scene (uses `reference/oc-edge.css`). |
| Signature | The signature board: rings, horizon line, Journey line. It is not approved app-wide yet (D12). Health & safety's signature parts are on `main` in `resources/js/components/signature/`. |

## Screens with no v3 drawing yet

Draw these and get Stephan's approval before building them:
- Settings › Chat (C7.8);
- the investigator page and export pop-up;
- the Chat notification row (C7.7);
- the single-conversation side sheet (C7.3);
- every pop-up and state on the pop-up kit (C1.0e).

The plan, audit and decisions behind the boards are in [../2026-10-22-rebuild-plan.html](../2026-10-22-rebuild-plan.html).
