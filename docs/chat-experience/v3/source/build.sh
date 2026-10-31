#!/usr/bin/env bash
# build.sh — rebuild all six boards, then measure + screenshot light and dark
OUT=/c/Users/steph/AppData/Local/Temp/claude/C--Users-steph-Herd-oblivionfindings--claude-worktrees-zealous-ellis-c573d4/18533ef8-533b-4719-967d-e5b48eb35162/scratchpad/chat3
DS=/c/Users/steph/AppData/Local/Temp/claude/C--Users-steph-Herd-oblivionfindings--claude-worktrees-oblivion-colors-design-5ff161/d677bdbc-7298-4571-8731-e52cdcdf60ea/scratchpad
cd $DS && node "$(cygpath -w $OUT/build.mjs)" || exit 1
: > $OUT/heights.txt
for B in Workspace Compose Messages Oblivion Calls Family; do bash $OUT/shot.sh ChatV3-$B | head -1; done
