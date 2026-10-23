#!/usr/bin/env bash
# shot.sh <Board> — measure height after fonts, then screenshot light + dark into shots/
set -uo pipefail
OUT=/c/Users/steph/AppData/Local/Temp/claude/C--Users-steph-Herd-oblivionfindings--claude-worktrees-zealous-ellis-c573d4/18533ef8-533b-4719-967d-e5b48eb35162/scratchpad/chat3
CH="/c/Program Files/Google/Chrome/Application/chrome.exe"
B=$1
cd $OUT/b
sed 's#</body>#<script>document.fonts.ready.then(function(){setTimeout(function(){document.title="H="+Math.ceil(document.querySelector(".oc-kit").getBoundingClientRect().height);},300);});</script></body>#' $B.html > _m-$B.html
WIN="$(pwd -W)"
H=$(MSYS_NO_PATHCONV=1 "$CH" --headless=new --disable-gpu --hide-scrollbars --user-data-dir="$WIN/../profile" --allow-file-access-from-files --virtual-time-budget=6000 --window-size=1600,1200 --dump-dom "file:///$WIN/_m-$B.html" 2>/dev/null | grep -o '<title>H=[0-9]*' | grep -o '[0-9]*$')
rm -f _m-$B.html
echo "$B height=$H"
echo "$B $H" >> $OUT/heights.txt
for m in l d; do
  HASH=""; [ $m = d ] && HASH="#d"
  MSYS_NO_PATHCONV=1 "$CH" --headless=new --disable-gpu --hide-scrollbars --user-data-dir="$WIN/../profile" --allow-file-access-from-files --virtual-time-budget=6000 --window-size=1600,$H --screenshot="$WIN/../shots/$B-$m.png" "file:///$WIN/$B.html$HASH" >/dev/null 2>&1
  ls -la $OUT/shots/$B-$m.png | awk '{print $5, $9}'
done
