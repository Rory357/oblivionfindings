from pathlib import Path
import io, json, shutil, sys, zipfile
sys.path.insert(0, 'C:/Users/steph/AppData/Local/Temp/codex-asset-qr-validation')
import zxingcpp
from pypdf import PdfReader
import pypdfium2 as pdfium
from PIL import Image

folder = Path(__file__).parent
downloads = Path('C:/Users/steph/Downloads')
results = []
decoded_sets = []
for batch, expected_counts, dimensions in [(5, [1, 2], (210, 297)), (6, [1, 1, 1], (60, 50))]:
    source = downloads / f'asset-labels-{batch}.pdf'
    path = folder / f'bulk-labels-{batch}.pdf'
    shutil.copyfile(source, path)
    reader = PdfReader(path)
    document = pdfium.PdfDocument(path)
    assert len(reader.pages) == len(expected_counts)
    decoded = []
    pages = []
    for index, page in enumerate(reader.pages):
        size = [round(float(page.mediabox.width) * 25.4 / 72, 2), round(float(page.mediabox.height) * 25.4 / 72, 2)]
        assert all(abs(size[i] - dimensions[i]) < .1 for i in range(2)), size
        image = document[index].render(scale=3).to_pil()
        codes = zxingcpp.read_barcodes(image)
        assert len(codes) == expected_counts[index], (batch, index, len(codes))
        decoded.extend(code.text for code in codes)
        assert all(code.text.startswith('http://127.0.0.1:8905/assets/qr/') for code in codes)
        image.save(folder / f'bulk-labels-{batch}-page-{index+1}.png')
        pages.append({'page': index + 1, 'millimetres': size, 'decoded_labels': len(codes)})
    assert len(set(decoded)) == 3
    decoded_sets.append(set(decoded))
    results.append({'file': path.name, 'pages': pages})
assert decoded_sets[0] == decoded_sets[1]
with zipfile.ZipFile(downloads / 'asset-labels-6.zip') as archive:
    assert len(archive.namelist()) == 8
    manifest = json.loads(archive.read('manifest.json'))
    assert len(manifest['assets']) == 3
    for asset in manifest['assets']:
        stem = f"asset-{asset['id']}"
        image = Image.open(io.BytesIO(archive.read(stem + '.png')))
        codes = zxingcpp.read_barcodes(image)
        assert len(codes) == 1 and codes[0].text == asset['qr_url']
        assert b'data:image/png;base64' in archive.read(stem + '.svg')
        image.save(folder / f'bulk-archive-{stem}.png')
    assert {asset['qr_url'] for asset in manifest['assets']} == decoded_sets[0]
results.append({'archive': 'asset-labels-6.zip', 'png_decoded': 3, 'svg_logo_embedded': 3, 'manifest_matches_pdf': True})
(folder / 'bulk-label-verification.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
print(json.dumps(results))
