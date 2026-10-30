"""Creates deterministic, fictional PDF bytes for the isolated browser workflow."""
from pathlib import Path
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas

target = Path(__file__).resolve().parent / "fixtures" / "fictional-workflow-evidence.pdf"
target.parent.mkdir(parents=True, exist_ok=True)
pdf = canvas.Canvas(str(target), pagesize=A4, invariant=1)
pdf.setTitle("FICTIONAL TEST EVIDENCE - no real authorization")
pdf.setAuthor("Referrals isolated QA fixture")
pdf.setFont("Helvetica-Bold", 17)
pdf.drawString(48, 780, "FICTIONAL TEST EVIDENCE ONLY")
pdf.setFont("Helvetica", 11)
lines = [
    "This document is a software test fixture. It is not an application,",
    "funding award, clinical assessment, consent or signed agreement.",
    "It contains no real personal information and must not be used for care.",
    "",
    "Scenario: Fictional Website Journey",
    "Purpose: test private upload, quarantine, review and evidence linking.",
    "Example service: supported living / home and community support.",
    "Example proposed support: 8 hours per week.",
    "Example test period: 18 October 2026 to 18 November 2026.",
    "",
    "All staff confirmations and external decisions in this scenario are",
    "explicitly fictional. No agency receives an application or message.",
    "No funding, service agreement, invoice or actual care is authorized.",
    "",
    "Use only in the disposable referrals browser preview database.",
]
for index, line in enumerate(lines):
    pdf.drawString(48, 744 - index * 20, line)
pdf.showPage()
pdf.save()
print(target)
