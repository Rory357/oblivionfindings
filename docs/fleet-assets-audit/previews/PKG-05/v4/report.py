"""Local-only preview PDF renderer. JSON in, PDF bytes out. No operational storage."""
import io,json,sys,html
from reportlab.pdfgen import canvas
from reportlab.platypus import SimpleDocTemplate,Paragraph,Spacer,Table,TableStyle,KeepTogether
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet,ParagraphStyle
from reportlab.lib.enums import TA_LEFT
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.lib.pagesizes import A4

data=json.loads(sys.stdin.buffer.read().decode('utf-8'))
pdfmetrics.registerFont(TTFont('Segoe','C:/Windows/Fonts/segoeui.ttf'))
pdfmetrics.registerFont(TTFont('SegoeBold','C:/Windows/Fonts/segoeuib.ttf'))
styles=getSampleStyleSheet()
styles.add(ParagraphStyle(name='BodyExport',fontName='Segoe',fontSize=9,leading=13,textColor=colors.HexColor('#202633'),spaceAfter=5))
styles.add(ParagraphStyle(name='SmallExport',fontName='Segoe',fontSize=8,leading=11,textColor=colors.HexColor('#535d70')))
styles.add(ParagraphStyle(name='HeadingExport',fontName='SegoeBold',fontSize=12,leading=16,spaceBefore=17,spaceAfter=8,textColor=colors.HexColor('#35239c'),keepWithNext=True))
styles.add(ParagraphStyle(name='TitleExport',fontName='SegoeBold',fontSize=21,leading=26,spaceAfter=11,textColor=colors.HexColor('#241b69')))
styles.add(ParagraphStyle(name='TableHeadExport',fontName='SegoeBold',fontSize=8,leading=11,textColor=colors.white))
def p(text,style='BodyExport'):
    return Paragraph(html.escape(str(text)[:10000]).replace('\n','<br/>'),styles[style])
story=[p('OBLIVION FINDINGS / TRANSPORT','SmallExport'),Spacer(1,9),p(data.get('title','Transport export'),'TitleExport'),p('DESIGN PREVIEW · Synthetic records · Pacific/Auckland','SmallExport'),Spacer(1,12)]
for line in data.get('scope',[])[:15]: story.append(p(line,'SmallExport'))
count=data.get('count',0)
story.extend([Spacer(1,8),p(f"{count} matching {'record' if count == 1 else 'records'}. This export reflects the selected preview scope.",'SmallExport')])
for section in data.get('sections',[])[:20]:
    story.append(p(section.get('title','Details'),'HeadingExport'))
    for line in section.get('paragraphs',[])[:50]:story.append(p(line))
    columns=section.get('columns',[])
    if columns:
        rows=section.get('rows',[])[:300]
        if not rows:story.append(p('No records in this section.','SmallExport'));continue
        widths=[(A4[0]-84)/len(columns)]*len(columns)
        content=[[p(c,'TableHeadExport') for c in columns]]+[[p(row[i] if i<len(row) else '') for i in range(len(columns))] for row in rows]
        table=Table(content,colWidths=widths,repeatRows=1,hAlign='LEFT')
        table.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,0),colors.HexColor('#35239c')),('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),8),('RIGHTPADDING',(0,0),(-1,-1),8),('TOPPADDING',(0,0),(-1,-1),8),('BOTTOMPADDING',(0,0),(-1,-1),8),('ROWBACKGROUNDS',(0,1),(-1,-1),[colors.HexColor('#f4f5f9'),colors.white]),('LINEBELOW',(0,1),(-1,-1),0.4,colors.HexColor('#d9dce5'))]))
        story.append(table)
def footer(c,doc):
    c.setStrokeColor(colors.HexColor('#d9dce5'));c.line(42,41,A4[0]-42,41)
    c.setFont('Segoe',8);c.setFillColor(colors.HexColor('#535d70'));c.drawString(42,27,'Synthetic design preview · No operational or clinical record changed');c.drawRightString(A4[0]-42,27,f'Page {doc.page}')
output=io.BytesIO()
doc=SimpleDocTemplate(output,pagesize=A4,rightMargin=42,leftMargin=42,topMargin=38,bottomMargin=57,title=str(data.get('title','Transport export')),author='Oblivion Findings - design preview')
doc.build(story,onFirstPage=footer,onLaterPages=footer)
sys.stdout.buffer.write(output.getvalue())
