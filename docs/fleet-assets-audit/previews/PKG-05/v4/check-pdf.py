import json, pathlib
from pypdf import PdfReader
root=pathlib.Path(__file__).parent
results=[]
def check(name, passed):
    results.append({'name':name,'pass':bool(passed)})
    if not passed: raise AssertionError(name)
texts={}
for name in ['qa-filtered-returns.pdf','qa-filtered-history.pdf','qa-full-return.pdf','qa-vehicle-history.pdf','qa-macron.pdf']:
    data=(root/name).read_bytes();r=PdfReader(root/name);text='\n'.join(p.extract_text() for p in r.pages);texts[name]=text
    check(name+' valid PDF',data.startswith(b'%PDF') and len(r.pages)>0)
    (root/(name+'.txt')).write_text(text,encoding='utf-8')
check('Return export includes only matching passenger','Casey Jones' in texts['qa-filtered-returns.pdf'] and 'Charlie Brown' not in texts['qa-filtered-returns.pdf'] and 'Sam Wilson' not in texts['qa-filtered-returns.pdf'])
check('History export obeys search','Journey departed' in texts['qa-filtered-history.pdf'] and 'Passenger journey returned' not in texts['qa-filtered-history.pdf'])
check('Full record export includes receipt and separate storage observation','Original' not in texts['qa-full-return.pdf'] and 'Key storage confirmed' in texts['qa-full-return.pdf'] and 'Vehicle return observations' in texts['qa-full-return.pdf'])
check('Unicode arrow and fraction remain intact','→' in texts['qa-full-return.pdf'] and '¾' in texts['qa-full-return.pdf'])
check('Vehicle history export contains only the filtered leg','VT-902' in texts['qa-vehicle-history.pdf'] and 'VT-901' not in texts['qa-vehicle-history.pdf'])
check('Site macron survives PDF export','K\u014dwhai House' in texts['qa-macron.pdf'])
check('All exports free of encoding corruption', all('\u00c2' not in text and '\u00e2\u20ac' not in text for text in texts.values()))
(root/'qa-pdf.json').write_text(json.dumps({'results':results,'pages':{n:len(PdfReader(root/n).pages) for n in texts}},indent=2),encoding='utf-8')
print(json.dumps({'results':results,'pages':{n:len(PdfReader(root/n).pages) for n in texts}}))
