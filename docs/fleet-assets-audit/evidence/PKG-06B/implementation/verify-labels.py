from pathlib import Path
import json, sys
sys.path.insert(0, 'C:/Users/steph/AppData/Local/Temp/codex-asset-qr-validation')
import zxingcpp
from pypdf import PdfReader
import pypdfium2 as pdfium

folder = Path(__file__).parent
results = []
target = None
for number, counts, dimensions in [(1, [18], (210, 297)), (2, [1, 1], (210, 297)), (3, [1, 1], (60, 50))]:
    path = folder / f'labels-{number}.pdf'
    reader = PdfReader(path)
    assert len(reader.pages) == len(counts), (path.name, len(reader.pages), counts)
    document = pdfium.PdfDocument(path)
    pages = []
    for index, page in enumerate(reader.pages):
        size = [round(float(page.mediabox.width) * 25.4 / 72, 2), round(float(page.mediabox.height) * 25.4 / 72, 2)]
        assert all(abs(size[i] - dimensions[i]) < .1 for i in range(2)), size
        image = document[index].render(scale=3).to_pil()
        codes = zxingcpp.read_barcodes(image)
        assert len(codes) == counts[index], (path.name, index, len(codes), counts[index])
        for code in codes:
            target = target or code.text
            assert code.text == target and code.text.startswith('http://127.0.0.1:8905/assets/qr/'), code.text
        image.save(folder / f'labels-{number}-page-{index+1}.png')
        pages.append({'page': index + 1, 'millimetres': size, 'decodedLabels': len(codes)})
    results.append({'file': path.name, 'pages': pages})
(folder / 'label-verification.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
print(json.dumps(results))
