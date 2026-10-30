from pathlib import Path
import json
from reportlab.pdfgen import canvas
from reportlab.lib.colors import HexColor, white
from reportlab.lib.utils import simpleSplit
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
import pypdfium2 as pdfium
from pypdf import PdfReader
from PIL import Image, ImageDraw, ImageFont

root = Path(__file__).resolve().parents[5]
target = root / 'docs/fleet-assets-audit/previews/PKG-06B/v9/files'
target.mkdir(parents=True, exist_ok=True)
font = Path('C:/Windows/Fonts/segoeui.ttf')
bold = Path('C:/Windows/Fonts/segoeuib.ttf')
pdfmetrics.registerFont(TTFont('UI', str(font)))
pdfmetrics.registerFont(TTFont('UIBold', str(bold)))
manifest = {}

def create(key, filename, title, version, date, paragraphs, second_page=None):
    output = target / filename
    c = canvas.Canvas(str(output), pagesize=(595.276, 841.89))
    c.setTitle(title + ' - synthetic preview')
    c.setAuthor('Oblivion Care - design sample')
    def page(number, heading, content):
        c.setFillColor(HexColor('#f5f3ff')); c.rect(0, 754, 596, 88, fill=1, stroke=0)
        c.setFillColor(HexColor('#5b34d6')); c.setFont('UIBold', 11)
        c.drawString(42, 805, 'OBLIVION CARE  /  ASSET DOCUMENTS')
        c.setFillColor(HexColor('#172033')); c.setFont('UIBold', 23)
        c.drawString(42, 772, heading)
        c.setFont('UIBold', 10); c.setFillColor(HexColor('#9a5700'))
        c.drawString(42, 725, 'DESIGN SAMPLE - NOT AN OPERATIONAL DOCUMENT')
        c.setFillColor(HexColor('#172033')); c.setFont('UI', 11)
        y = 694
        for label, value in [('Asset', 'AS-104 - Transfer hoist'), ('Site', 'Kōwhai House / Equipment room'), ('Version', f'{version} - {date}')]:
            c.setFont('UIBold', 10); c.drawString(42, y, label)
            c.setFont('UI', 10); c.drawString(120, y, value); y -= 23
        y -= 18
        for subheading, body in content:
            c.setFont('UIBold', 12); c.drawString(42, y, subheading); y -= 22
            c.setFont('UI', 11)
            for line in simpleSplit(body, 'UI', 11, 510):
                c.drawString(42, y, line); y -= 17
            y -= 24
        c.setStrokeColor(HexColor('#ddd8ed')); c.line(42, 60, 553, 60)
        c.setFont('UI', 9); c.setFillColor(HexColor('#596273'))
        c.drawString(42, 41, f'{filename}  |  Synthetic record')
        c.drawRightString(553, 41, f'Page {number} of {2 if second_page else 1}')
        c.showPage()
    page(1, title, paragraphs)
    if second_page: page(2, 'Document revision notes', second_page)
    c.save()
    document = pdfium.PdfDocument(output)
    pages = []
    for index in range(len(document)):
        name = output.stem + f'-page-{index + 1}.png'
        document[index].render(scale=1.5).to_pil().save(target / name)
        pages.append(name)
    reader = PdfReader(output)
    manifest[key] = {'filename': filename, 'mime': 'application/pdf', 'bytes': output.stat().st_size, 'pages': pages, 'text': [(p.extract_text() or '') for p in reader.pages]}

create('DOC-104-1:3', 'transfer-hoist-manual.pdf', 'Operating manual', 3, '12 Sep 2026', [
    ('About this file', 'This is a real PDF supplied only to demonstrate viewing, page navigation and downloading in the Asset Profile design. It is not a manufacturer manual and contains no operating or servicing instructions.'),
    ('Document identity', 'Document set DS-104-1. Added to the synthetic library by Mara Ellis. Version 3 is the current example; version 2 remains available in history.'),
    ('Source ownership', 'The asset document set owns this file. Checks and Maintenance retain their own submitted evidence. Updating this file does not replace an attachment on an earlier check.'),
], [('Version 3', 'Current sample dated 12 Sep 2026. A second page demonstrates that every page can be viewed in the document reader.'), ('Version 2', 'Earlier sample dated 4 Feb 2026. The library preserves its original file and version identity.'), ('Operational use', 'Use the approved manufacturer documentation and organisation procedures for real equipment. This synthetic PDF grants no approval and supplies no inspection or release criteria.')])
create('DOC-104-2:1', 'warranty-104.pdf', 'Purchase & warranty', 1, '18 Mar 2025', [
    ('Illustrative supplier record', 'Supplier: Harbour Equipment. Asset: AS-104 Transfer hoist. Purchase reference: BILL-104. These names and references are synthetic.'),
    ('Dates shown in the mockup', 'Illustrative purchase date: 18 Mar 2025. Illustrative warranty end: 18 Mar 2027. These dates are sample data, not actual coverage or contractual terms.'),
    ('Separate Finance source', 'The purchase invoice and fixed-asset record are linked in Finance. Downloading this document does not approve expenditure, post a journal or confirm payment.'),
    ('Document status', 'This preview attachment demonstrates a downloadable PDF. It is not a warranty agreement, proof of purchase or supplier-issued document.'),
])
create('DOC-104-6:2', 'transfer-hoist-manual-v2.pdf', 'Operating manual - archived', 2, '4 Feb 2026', [
    ('Archived version', 'This is the retained version 2 design sample. Version 3 is the current example. Archiving retains the original bytes and does not erase the earlier document identity.'),
    ('Why it is retained', 'The version history demonstrates how a user can read or download an earlier file without mistaking it for the current document.'),
    ('Design sample only', 'This PDF contains no operating instructions. It is not manufacturer evidence and is not suitable for real equipment use.'),
])

image = Image.new('RGB', (1400, 900), '#f3f0fb')
d = ImageDraw.Draw(image)
d.rounded_rectangle((70, 70, 1330, 830), radius=24, fill='white', outline='#d7d0e7', width=3)
d.rounded_rectangle((110, 115, 1290, 190), radius=12, fill='#fff0cc')
d.text((136, 135), 'SYNTHETIC IMAGE - NO REAL INSPECTION PHOTO', font=ImageFont.truetype(str(bold), 30), fill='#7a4800')
d.text((115, 240), 'Brake assessment attachment', font=ImageFont.truetype(str(bold), 48), fill='#172033')
for y, line in [(330, 'Asset: AS-104 - Transfer hoist'), (395, 'Original check: CHK-882'), (460, 'Sample date: 24 Sep 2026'), (560, 'This JPEG tests image viewing and downloading.'), (620, 'No condition, defect or completed inspection is evidenced.'), (735, 'Design sample only  /  Kōwhai House')]:
    d.text((115, y), line, font=ImageFont.truetype(str(font), 32 if y != 735 else 27), fill='#485369')
image.save(target/'brake-assessment.jpg', quality=94)
manifest['DOC-104-3:1'] = {'filename':'brake-assessment.jpg','mime':'image/jpeg','bytes':(target/'brake-assessment.jpg').stat().st_size,'pages':['brake-assessment.jpg'],'text':['Synthetic image fixture for AS-104 and CHK-882. No real inspection photo is present.']}
(target.parent/'document-manifest.json').write_text(json.dumps(manifest, indent=2, ensure_ascii=False), encoding='utf-8')
print(json.dumps({'pdfs':3,'image':1,'renderedPdfPages':4,'files':len(list(target.iterdir()))}))
