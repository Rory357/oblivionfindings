from pathlib import Path
import re

root = Path(r'C:\Users\steph\.codex\visualizations\2026\09\12\01a094e0-dd40-7b73-b7c8-d0216a4a0e5c\myday-publish\docs\audits\2026-09-12-my-day\evidence')
for path in root.glob('*.txt'):
    text = re.sub(r'\x1b\[[0-?]*[ -/]*[@-~]', '', path.read_text(encoding='utf-8-sig'))
    text = '\n'.join(line.rstrip() for line in text.splitlines()).strip()
    path.write_text(text + '\n' if text else '', encoding='utf-8')
print('Normalised terminal colouring and trailing whitespace in publishing evidence.')
