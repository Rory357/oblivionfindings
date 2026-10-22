"""Deterministic simulated agreement evidence for the disposable QA journey only."""
from pathlib import Path
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas

target = Path(__file__).resolve().parent / "fixtures" / "fictional-agreement-001.pdf"
pdf = canvas.Canvas(str(target), pagesize=A4, invariant=1)
pdf.setTitle("FICTIONAL QA - simulated agreement 001")
pdf.setAuthor("Referrals isolated QA fixture")
pdf.setFont("Helvetica-Bold", 16)
pdf.drawString(48, 790, "FICTIONAL QA - NO REAL SIGNATURE")
pdf.setFont("Helvetica", 10)
lines = [
    "This is a software test fixture. It is not a real signed agreement, consent,",
    "funding award or authority to provide care. Use only in the disposable preview.",
    "",
    "Person: Fictional Website Journey (preview person 2)",
    "Agreement: Fictional initial-week agreement (preview agreement 1)",
    "Reference: FICTIONAL-QA-AGREEMENT-001; linked proposal version 2",
    "Service: Fictional initial-week daily living support (line 1)",
    "Period: 18-24 October 2026, one initial week only",
    "Quantity: 8 hours; unit price NZD60 excluding GST",
    "Total: NZD480 excluding GST; fictional 15% GST NZD72; gross NZD552",
    "Funding reference: FICTIONAL-QA-AWARD-001; allocation 8 native hours",
    "",
    "SIMULATED acceptance: Fictional Website Journey, self, in person.",
    "Scenario response time: 18 October 2026 at 13:40 NZDT (+13:00).",
    "The fictional person agrees to the exact initial-week service and price above.",
    "Scenario witness: Preview Author. No actual signing or conversation occurred.",
    "",
    "Simulated orientation: plain-language review of support, choice, complaints,",
    "safe contact (weekdays, no voicemail/messages) and planned worker introduction.",
    "No real orientation, worker introduction or delivery of support occurred.",
    "",
    "This fixture supports testing evidence review and scheduling only. Actual",
    "commencement is a separate test action; no invoice or real care is authorised.",
]
for index, line in enumerate(lines):
    pdf.drawString(48, 752 - index * 22, line)
pdf.showPage()
pdf.save()
print(target)
