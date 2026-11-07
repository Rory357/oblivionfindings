# Chat v3 boards (approved 22 October 2026)

These are the approved drawings for the Chat rebuild. Build each screen to them, view for view, and compare at 1440 px in light and dark (DESIGN.md: "Approximating an approved mockup").

- `boards/` holds the six published boards and their stylesheet, exactly as they appear on the "Chat v3 — proposed rebuild" page of the design viewer (https://claude.ai/artifact/S2FWVebvqtxrVL5LHahzHy). Open a board next to the viewer's `oc.css` and `oc-chat.css` to render it; add `#d` to the address for the dark version.
- `source/` holds the scripts that drew them. They import the shared chat helpers (`chat_work/lib.mjs`, `parts.mjs`, `tools/shellnav.mjs`) from the design session's scratchpad and write to a scratchpad folder. Those paths are temporary folders from 22 October, so set `DS` and `OUT` in `c3lib.mjs`, `build.sh` and `shot.sh` before rebuilding.
- `source/manifest-entry.json` is the viewer menu entry; `source/add-to-viewer.mjs` inserts it into a copy of the viewer's `index.html`.

| Board | What it shows |
|---|---|
| ChatV3-Workspace | Staff and family threads at 1440 × 1000, the conversation list, row states, rings |
| ChatV3-Compose | Emoji picker, reactions, stickers, GIF library, formatting, attach menu, inline voice notes, commands, @on-shift |
| ChatV3-Messages | One message menu, Message info, share, threads, pins, important and urgent, polls, links, states, multi-select |
| ChatV3-Oblivion | Care cards, turn into, privacy check before family, @on-shift reach, handover digest, read and confirm, send later, lone work |
| ChatV3-Calls | Calls page, incoming calls, video grid, screen share, docks, call record |
| ChatV3-Family | Family portal conversation, who's caring today, reply promise, seen by care team, urgent help |

The plan, audit and decisions behind them: [../2026-10-22-rebuild-plan.html](../2026-10-22-rebuild-plan.html).
