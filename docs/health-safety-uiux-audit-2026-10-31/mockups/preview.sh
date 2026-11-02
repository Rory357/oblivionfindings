#!/usr/bin/env bash
# Render one board at full size with headless Chrome: preview.sh m1 [height]
set -e
A="C:/Users/steph/AppData/Local/Temp/claude/C--Users-steph-Herd-oblivionfindings--claude-worktrees-eager-mahavira-c57cd4/44de8c80-1790-4d1f-9c4f-44b665efcc97/scratchpad/artifact"
id=$1; h=${2:-1300}
node -e "
const fs=require('fs'); const p='$A/index.html'; const html=fs.readFileSync(p,'utf8');
const head=html.split('<nav class=\"nav\"')[0];
const start=html.indexOf('<section class=\"board\" id=\"$id\"'); const end=html.indexOf('</section>', html.indexOf('frame-tools', start))+10;
let b=html.slice(start,end).replace('class=\"stage-frame\"','class=\"stage-frame full\"');
fs.writeFileSync('$A/prev-$id.html', '<!doctype html><meta charset=utf-8>'+head+'<body style=\"margin:0;padding:0 8px\">'+b+'</body>');
"
W=$(cygpath -w "$A/prev-$id.html"); O=$(cygpath -w "$A/prev-$id.png")
MSYS_NO_PATHCONV=1 "/c/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --disable-gpu --hide-scrollbars --window-size=1480,$h --screenshot="$O" "file:///$W" >/dev/null 2>&1
echo "$A/prev-$id.png"
