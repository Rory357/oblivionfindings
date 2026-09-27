from pathlib import Path
p=Path(__file__).with_name('check-pdf.py')
s=p.read_text(encoding='utf-8-sig').replace("'qa-full-return.pdf']","'qa-full-return.pdf','qa-vehicle-history.pdf','qa-macron.pdf']")
s=s.replace("(root/'qa-pdf.json').write_text", "check('Vehicle history export contains only the filtered leg','VT-902' in texts['qa-vehicle-history.pdf'] and 'VT-901' not in texts['qa-vehicle-history.pdf'])\ncheck('Site macron survives PDF export','K\\u014dwhai House' in texts['qa-macron.pdf'])\ncheck('All exports free of encoding corruption', all('\\u00c2' not in text and '\\u00e2\\u20ac' not in text for text in texts.values()))\n(root/'qa-pdf.json').write_text")
p.write_text(s,encoding='utf-8')
