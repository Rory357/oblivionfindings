from pypdf import PdfReader
from pathlib import Path
for name in ['qa-vehicle-history.pdf','qa-macron.pdf']:
 p=Path(__file__).with_name(name)
 print(name)
 def visit(t,cm,tm,fd,sz):
  if t.strip() and ('Times' in t or 'Route' in t or '07:48' in t):print(repr(t),cm,tm,sz)
 PdfReader(p).pages[0].extract_text(visitor_text=visit)
