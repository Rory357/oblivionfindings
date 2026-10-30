from pathlib import Path
import json
from PIL import Image, ImageDraw
from reportlab.graphics.barcode.qr import QrCodeWidget

root = Path(__file__).resolve().parents[3] / 'previews' / 'PKG-06B' / 'v8'
target = 'https://example.invalid/assets/qr/demo-as-104'
qr = QrCodeWidget(target, barLevel='H', barBorder=4)
qr.qr.make()
matrix = [[1 if cell else 0 for cell in row] for row in qr.qr.modules]
(root / 'qr-matrix.ts').write_text('export const demoQrTarget = '+json.dumps(target)+';\nexport const demoQrMatrix: number[][] = '+json.dumps(matrix)+';\n', encoding='utf-8')
size = len(matrix)
image = Image.new('RGB', ((size+8)*12, (size+8)*12), 'white')
draw = ImageDraw.Draw(image)
for y,row in enumerate(matrix):
    for x,cell in enumerate(row):
        if cell: draw.rectangle(((x+4)*12,(y+4)*12,(x+5)*12-1,(y+5)*12-1),fill='black')
image.save(Path(__file__).parent/'reference-qr.png')
print(json.dumps({'target':target,'modules':size,'quietZoneModules':4,'encoder':'ReportLab QrCodeWidget, level H'}))
