from pathlib import Path
import sys, json
sys.path.insert(0, 'C:/Users/steph/AppData/Local/Temp/codex-asset-qr-validation')
import zxingcpp
from PIL import Image
from pypdf import PdfReader
import pypdfium2 as pdfium

folder=Path(__file__).parent/'branded-exports'
target='https://example.invalid/assets/qr/demo-as-104'
results=[]
png=folder.parent/'exports'/'AS-104-qr-DEMO.png'
decoded=zxingcpp.read_barcodes(Image.open(png))
assert len(decoded)==1 and decoded[0].text==target
results.append({'file':png.name,'decodedTarget':decoded[0].text,'pixels':Image.open(png).size})
expected={'AS-104-a4-1-labels-DEMO.pdf':([1],(210,297)), 'AS-104-a4-3-labels-DEMO.pdf':([1,2],(210,297)), 'AS-104-single-2-labels-DEMO.pdf':([1,1],(80,50)), 'AS-104-single-1-labels-DEMO.pdf':([1],(60,40))}
for filename,(counts,dimensions) in expected.items():
    path=folder/filename
    reader=PdfReader(path)
    assert len(reader.pages)==len(counts),(filename,len(reader.pages))
    document=pdfium.PdfDocument(path)
    rows=[]
    for index,page in enumerate(reader.pages):
        size=[round(float(page.mediabox.width)*25.4/72,2),round(float(page.mediabox.height)*25.4/72,2)]
        assert all(abs(size[i]-dimensions[i])<.1 for i in range(2)),size
        image=document[index].render(scale=3).to_pil()
        codes=zxingcpp.read_barcodes(image)
        assert len(codes)==counts[index],(filename,index,len(codes))
        assert all(code.text==target for code in codes)
        image.save(folder/(path.stem+f'-page-{index+1}.png'))
        rows.append({'page':index+1,'millimetres':size,'decodedCodes':len(codes),'allMatch':True})
    results.append({'file':filename,'pages':rows})
(folder.parent/'branded-export-verification.json').write_text(json.dumps(results,indent=2),encoding='utf-8')
print(json.dumps(results))
