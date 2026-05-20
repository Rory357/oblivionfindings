# -*- coding: utf-8 -*-
"""
Builds a multi-page PDF architectural diagram of the Governance module
as it stands after the intensive audit + refactor on branch
feature/governance-module-intensive-audit.

Pages:
  1. Title / verdict / what changed (text)
  2. 6-group sidebar IA (boxes)
  3. Five-band Overview dashboard (boxes)
  4. Cross-module data flow (boxes + arrows)
  5. SpendApproval lifecycle (state machine)
  6. Audit trail + scheduled jobs

Output: docs/governance-architecture-diagram.pdf
"""

from reportlab.lib.colors import HexColor, white
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfgen import canvas
from reportlab.platypus import Paragraph

# ---- Colour palette (semantic, design-token-aligned) -----------------------
NAVY = HexColor("#1d2b48")
SLATE = HexColor("#475569")
MUTED = HexColor("#64748b")
LIGHT = HexColor("#f1f5f9")
BORDER = HexColor("#cbd5e1")
SUCCESS = HexColor("#16a34a")
SUCCESS_BG = HexColor("#dcfce7")
WARNING = HexColor("#d97706")
WARNING_BG = HexColor("#fef3c7")
CRITICAL = HexColor("#dc2626")
CRITICAL_BG = HexColor("#fee2e2")
INFO = HexColor("#2563eb")
INFO_BG = HexColor("#dbeafe")
ACCENT = HexColor("#7c3aed")
ACCENT_BG = HexColor("#ede9fe")
FINANCE = HexColor("#0d9488")
FINANCE_BG = HexColor("#ccfbf1")

PAGE_W, PAGE_H = landscape(A4)
MARGIN = 14 * mm


def header(c, title, subtitle):
    c.setFillColor(NAVY)
    c.rect(0, PAGE_H - 22 * mm, PAGE_W, 22 * mm, stroke=0, fill=1)
    c.setFillColor(white)
    c.setFont("Helvetica-Bold", 18)
    c.drawString(MARGIN, PAGE_H - 13 * mm, title)
    c.setFont("Helvetica", 10)
    c.setFillColor(HexColor("#cbd5e1"))
    c.drawString(MARGIN, PAGE_H - 18.5 * mm, subtitle)


def footer(c, page_num, total):
    c.setFont("Helvetica", 8)
    c.setFillColor(MUTED)
    c.drawString(MARGIN, 8 * mm, "Governance module — intensive audit & refactor (branch: feature/governance-module-intensive-audit)")
    c.drawRightString(PAGE_W - MARGIN, 8 * mm, f"Page {page_num} of {total}")


def box(c, x, y, w, h, fill, stroke, label_top=None, sublabels=None, label_color=None, body_color=None):
    c.setFillColor(fill)
    c.setStrokeColor(stroke)
    c.setLineWidth(0.6)
    c.roundRect(x, y, w, h, 2 * mm, stroke=1, fill=1)

    if label_top:
        c.setFillColor(label_color or NAVY)
        c.setFont("Helvetica-Bold", 9.5)
        c.drawString(x + 3 * mm, y + h - 5 * mm, label_top)

    if sublabels:
        c.setFillColor(body_color or SLATE)
        c.setFont("Helvetica", 8)
        for i, line in enumerate(sublabels):
            c.drawString(x + 3 * mm, y + h - (5 + 4 * (i + 1)) * mm, line)


def arrow(c, x1, y1, x2, y2, color=MUTED, label=None, dashed=False):
    c.setStrokeColor(color)
    c.setFillColor(color)
    c.setLineWidth(0.8)
    if dashed:
        c.setDash(2, 2)
    else:
        c.setDash()
    c.line(x1, y1, x2, y2)
    # arrowhead
    import math
    angle = math.atan2(y2 - y1, x2 - x1)
    ah_len = 2.5 * mm
    c.line(x2, y2, x2 - ah_len * math.cos(angle - math.radians(25)), y2 - ah_len * math.sin(angle - math.radians(25)))
    c.line(x2, y2, x2 - ah_len * math.cos(angle + math.radians(25)), y2 - ah_len * math.sin(angle + math.radians(25)))
    c.setDash()
    if label:
        c.setFont("Helvetica", 7.5)
        c.setFillColor(MUTED)
        mx, my = (x1 + x2) / 2, (y1 + y2) / 2 + 1.5 * mm
        c.drawString(mx, my, label)


# ---------------- PAGE 1: title + verdict --------------------------------
def page1(c, total):
    header(c, "Governance module — how it works now", "Architectural overview after the intensive audit + refactor")
    styles = getSampleStyleSheet()
    body = ParagraphStyle("body", parent=styles["Normal"], fontSize=10, leading=14, textColor=SLATE)
    h2 = ParagraphStyle("h2", parent=styles["Heading2"], fontSize=12, textColor=NAVY, spaceAfter=6)

    y = PAGE_H - 35 * mm

    intro = Paragraph(
        "The Governance module is the <b>oversight and accountability layer</b> across Oblivion Findings. "
        "It does not own operational data — it consumes signals from Finance, HR, Sites, Incidents, "
        "Health &amp; Safety, Clinical, Safeguarding, Assets, and Fleet, and surfaces what executives "
        "and board members need to <b>decide, approve, escalate, or attest to</b>.",
        body,
    )
    w, h = intro.wrap(PAGE_W - 2 * MARGIN, 30 * mm)
    intro.drawOn(c, MARGIN, y - h)
    y -= h + 8 * mm

    # Verdict band
    box(
        c, MARGIN, y - 22 * mm, PAGE_W - 2 * MARGIN, 22 * mm,
        SUCCESS_BG, SUCCESS,
        label_top="Verdict delivered: Option B  —  heavy refactor (not rebuild)",
        sublabels=[
            "Preserved: 19 controllers, ~50 models, 13 services, 60 Inertia pages, 31 permissions, 8 jobs",
            "Added: 3 controllers, 3 models, 3 commands, 8 policies, 7 Inertia pages, 24 audit-tracked models",
            "Removed: 0 user-facing features",
        ],
        label_color=SUCCESS,
        body_color=NAVY,
    )
    y -= 28 * mm

    # Three columns describing the three layers
    col_w = (PAGE_W - 2 * MARGIN - 8 * mm) / 3
    cols = [
        ("Frontend surface", LIGHT, [
            "6-group collapsible sidebar (was 18 flat items)",
            "5-band Overview dashboard with finance widgets",
            "Shared lib/governance-status.ts across 23 pages",
            "Typed permission tree — no (auth.can as any) casts",
            "EmptyList on index pages",
            "New pages: AuditLog, Settings, SpendApprovals, Packs Index, Attestations, Documents Show",
        ]),
        ("Domain layer", INFO_BG, [
            "Policies registered for 8 new entities",
            "AuditableChanges on 24 governance models",
            "GovernanceAuditService::log() at action paths",
            "Multi-step workflows wrapped in transactions",
            "GovernanceSetting drives escalation + thresholds",
            "ComplianceEngineService escalation now configurable",
        ]),
        ("Cross-module wiring", FINANCE_BG, [
            "ClientIncidentObserver -> IncidentGovernanceEscalation",
            "SafeguardingConcernObserver -> NotifiableIncident",
            "governance:sync-donor-fund-compliance (daily)",
            "governance:sync-site-risk-reviews (daily)",
            "governance:spawn-recurring-meetings (weekly)",
            "BudgetAllocation links annual -> monthly site budget",
        ]),
    ]
    for i, (title, fill, items) in enumerate(cols):
        x = MARGIN + i * (col_w + 4 * mm)
        ch = 65 * mm
        box(c, x, y - ch, col_w, ch, fill, BORDER, label_top=title, sublabels=items)
    y -= 70 * mm

    # Tests + scale
    c.setFillColor(NAVY)
    c.setFont("Helvetica-Bold", 10)
    c.drawString(MARGIN, y, "Verification:  " )
    c.setFont("Helvetica", 10)
    c.setFillColor(SLATE)
    c.drawString(MARGIN + 28 * mm, y, "106 governance tests / 615 assertions / Vite build green / 107 files changed in branch")

    footer(c, 1, total)


# ---------------- PAGE 2: sidebar IA --------------------------------------
def page2(c, total):
    header(c, "Information architecture — 6 collapsible groups",
           "GovernanceNav.tsx — was 18 flat items, now grouped by user task")

    # Old vs new side by side
    col_w = (PAGE_W - 2 * MARGIN - 10 * mm) / 2
    y_top = PAGE_H - 30 * mm
    h = 130 * mm

    # OLD
    box(c, MARGIN, y_top - h, col_w, h, CRITICAL_BG, CRITICAL,
        label_top="BEFORE  —  18 flat items (overwhelming)",
        sublabels=[
            "Dashboard", "Meetings", "Meeting Calendar", "Admin", "Risks", "Compliance",
            "Strategy", "Budgets", "Performance", "Resolutions", "Actions", "Policies",
            "CEO Reports", "Interests Register", "Board Evaluations", "Documents",
            "Clinical Governance", "Te Tiriti",
        ],
        label_color=CRITICAL, body_color=NAVY)

    # NEW
    x2 = MARGIN + col_w + 10 * mm
    groups = [
        ("Overview", [" - Dashboard (5-band Executive Cockpit)"]),
        ("Board & Meetings", [
            " - Meetings  (+ Calendar)",
            " - Resolutions & Voting",
            " - Action Items",
            " - Board Admin  (members, committees, interests, evaluations)",
        ]),
        ("Risk & Compliance", [
            " - Risk Register  (+ Heatmap, Trends)",
            " - Compliance Register  (+ Calendar)",
            " - Te Tiriti Obligations",
        ]),
        ("Policies & Evidence", [
            " - Policies  (+ Attestations)",
            " - Governance Documents",
            " - Audit Log",
        ]),
        ("Strategy & Performance", [
            " - Strategic Plan  (linked to Roadmap)",
            " - CEO Reports",
            " - Performance Reviews",
            " - Clinical Governance",
        ]),
        ("Financial Governance", [
            " - Budgets  (+ Allocations + Variance)",
            " - Spend Approvals  (above configurable threshold)",
        ]),
        ("Settings", [" - Governance Settings  (escalation paths, thresholds)"]),
    ]
    # Outer
    c.setFillColor(SUCCESS_BG); c.setStrokeColor(SUCCESS); c.setLineWidth(0.6)
    c.roundRect(x2, y_top - h, col_w, h, 2 * mm, stroke=1, fill=1)
    c.setFillColor(SUCCESS); c.setFont("Helvetica-Bold", 9.5)
    c.drawString(x2 + 3 * mm, y_top - 5 * mm, "AFTER  —  6 groups + Settings  (collapsible; remembers open state)")

    yy = y_top - 11 * mm
    for label, items in groups:
        c.setFillColor(NAVY); c.setFont("Helvetica-Bold", 9)
        c.drawString(x2 + 3 * mm, yy, "[+] " + label)
        yy -= 4.5 * mm
        c.setFillColor(SLATE); c.setFont("Helvetica", 8)
        for it in items:
            c.drawString(x2 + 6 * mm, yy, it)
            yy -= 4 * mm
        yy -= 1 * mm

    footer(c, 2, total)


# ---------------- PAGE 3: 5-band dashboard --------------------------------
def page3(c, total):
    header(c, "Overview dashboard — 5 horizontal bands",
           "GovernancePresenter::dashboard() — each band scannable in <5 seconds")

    bands = [
        ("Band 1  —  Workflow Center", "What needs my attention this week",
         INFO, INFO_BG,
         "Prioritised actions across meetings, decisions, risk, compliance, budgets, follow-through.  Critical / overdue / pending priority badges.  Role-filtered: only your work."),
        ("Band 2  —  Financial Governance", "How are we tracking financially",
         FINANCE, FINANCE_BG,
         "Budget vs actual  ·  Sites over budget  ·  Pending spend approvals  ·  Funding gaps  ·  Capex queue  ·  Financial-risk count.  Sourced from BudgetVarianceService + SpendApproval — no duplicated finance data."),
        ("Band 3  —  Operations & People", "How is the organisation performing",
         WARNING, WARNING_BG,
         "Open critical risks  ·  Overdue compliance  ·  Notifiable incidents pending external report  ·  Safeguarding escalations  ·  Workforce certification %  ·  Client safety score."),
        ("Band 4  —  Upcoming Governance", "What's on next",
         ACCENT, ACCENT_BG,
         "Next 3 meetings (RSVP + pre-read status)  ·  Pending resolutions + deadlines  ·  CEO report status for next meeting  ·  Board pack readiness  ·  Policy attestations outstanding."),
        ("Band 5  —  Recent Decisions & Changes", "What was decided",
         SUCCESS, SUCCESS_BG,
         "Last 5 resolutions carried (with amounts)  ·  Last 5 audit-log events with diffs  ·  Last 3 policies approved.  Click through to the source action."),
    ]

    y = PAGE_H - 32 * mm
    band_h = 26 * mm
    spacing = 3 * mm
    for title, sub, accent, bg, body in bands:
        c.setFillColor(bg)
        c.setStrokeColor(accent)
        c.setLineWidth(0.6)
        c.roundRect(MARGIN, y - band_h, PAGE_W - 2 * MARGIN, band_h, 2 * mm, stroke=1, fill=1)
        # accent strip
        c.setFillColor(accent)
        c.rect(MARGIN, y - band_h, 3 * mm, band_h, stroke=0, fill=1)
        # title
        c.setFillColor(accent)
        c.setFont("Helvetica-Bold", 10)
        c.drawString(MARGIN + 7 * mm, y - 5 * mm, title)
        c.setFillColor(MUTED)
        c.setFont("Helvetica-Oblique", 9)
        c.drawString(MARGIN + 7 * mm, y - 9 * mm, sub)
        # body wrapped
        styles = getSampleStyleSheet()
        body_style = ParagraphStyle("b", parent=styles["Normal"], fontSize=8.5, leading=11, textColor=NAVY)
        p = Paragraph(body, body_style)
        bw, bh = p.wrap(PAGE_W - 2 * MARGIN - 14 * mm, band_h - 10 * mm)
        p.drawOn(c, MARGIN + 7 * mm, y - 12 * mm - bh + 2 * mm)
        y -= band_h + spacing

    footer(c, 3, total)


# ---------------- PAGE 4: cross-module data flow --------------------------
def page4(c, total):
    header(c, "Cross-module data flow",
           "Governance consumes signals from operational modules; never duplicates their source data")

    # Central Governance hub
    cx, cy = PAGE_W / 2, PAGE_H / 2 - 5 * mm
    box(c, cx - 35 * mm, cy - 15 * mm, 70 * mm, 30 * mm, NAVY, NAVY,
        label_top="GOVERNANCE  (oversight layer)",
        sublabels=[
            "Dashboard  ·  Audit log  ·  Spend approvals",
            "Risk register  ·  Compliance register",
            "Board meetings + resolutions  ·  Policies",
            "Strategy  ·  CEO reports  ·  Settings",
        ],
        label_color=white, body_color=HexColor("#cbd5e1"))

    # Satellite modules with arrows pointing INTO governance
    satellites = [
        # (x, y, fill, stroke, label, [data], arrow_label)
        (MARGIN, PAGE_H - 55 * mm, FINANCE_BG, FINANCE, "Finance",
         ["BudgetVarianceService",
          "FinBill / FinPurchaseOrder",
          "FinDonorFund.next_report_due",
          "FinCostAllocation"],
         "site over-budget · pending bills · funding deadlines"),
        (MARGIN, PAGE_H / 2 - 30 * mm, INFO_BG, INFO, "HR",
         ["HrComplianceRequirement",
          "HrPolicyAttestation",
          "Training expiry"],
         "workforce %"),
        (MARGIN, 35 * mm, WARNING_BG, WARNING, "Incidents",
         ["ClientIncident (critical)",
          "IncidentEscalationService"],
         "auto-escalate"),
        (PAGE_W - MARGIN - 60 * mm, PAGE_H - 55 * mm, ACCENT_BG, ACCENT, "Roadmap",
         ["Initiative.strategic_goal_id (FK)",
          "QuarterlyRoadmapPlan",
          "InitiativeBudget"],
         "strategic alignment"),
        (PAGE_W - MARGIN - 60 * mm, PAGE_H / 2 - 30 * mm, CRITICAL_BG, CRITICAL, "Safeguarding",
         ["SafeguardingConcern",
          "(critical → NotifiableIncident)"],
         "regulator notification"),
        (PAGE_W - MARGIN - 60 * mm, 35 * mm, HexColor("#fce7f3"), HexColor("#be185d"), "Sites + H&S + Clinical",
         ["Site.risk_review_date",
          "SiteHazard",
          "ClinicalEvent → snapshots"],
         "review actions · governance indicators"),
    ]

    for x, y, fill, stroke, label, items, arrow_lbl in satellites:
        box(c, x, y, 60 * mm, 22 * mm, fill, stroke,
            label_top=label, sublabels=items, label_color=stroke)
        # arrow to centre
        if x < cx:  # left side
            arrow(c, x + 60 * mm, y + 11 * mm, cx - 35 * mm, cy, color=stroke, label=arrow_lbl)
        else:  # right side
            arrow(c, x, y + 11 * mm, cx + 35 * mm, cy, color=stroke, label=arrow_lbl)

    # Legend bottom-right
    c.setFillColor(MUTED)
    c.setFont("Helvetica-Oblique", 8)
    c.drawCentredString(PAGE_W / 2, 18 * mm,
                        "Each module remains the source of truth for its own data.  Governance subscribes, summarises, and surfaces — it does not duplicate.")

    footer(c, 4, total)


# ---------------- PAGE 5: SpendApproval state machine ---------------------
def page5(c, total):
    header(c, "Spend approval lifecycle",
           "SpendApproval model + controller + policy.  Above-threshold spend requires a Resolution sign-off.")

    # State boxes in a row
    states = [
        ("DRAFT", LIGHT, BORDER, "Requestor editing", []),
        ("SUBMITTED", INFO_BG, INFO, "Awaiting decision", ["Notify approver"]),
        ("APPROVED", SUCCESS_BG, SUCCESS, "Resolution linked\nif requires_board=true", []),
        ("REJECTED", CRITICAL_BG, CRITICAL, "Reason recorded", []),
        ("EXPIRED", LIGHT, BORDER, "valid_until lapsed", []),
    ]
    y = PAGE_H - 60 * mm
    w = 38 * mm; h = 24 * mm
    gap = (PAGE_W - 2 * MARGIN - 5 * w) / 4
    xs = []
    for i, (label, fill, stroke, sub, _) in enumerate(states):
        x = MARGIN + i * (w + gap)
        xs.append(x)
        box(c, x, y, w, h, fill, stroke,
            label_top=label, sublabels=[sub], label_color=stroke if label != "DRAFT" else NAVY)

    # Transitions
    arrow(c, xs[0] + w, y + h / 2, xs[1], y + h / 2, INFO, "submit()")
    arrow(c, xs[1] + w, y + h / 2 + 3 * mm, xs[2], y + h / 2 + 3 * mm, SUCCESS, "approve()")
    arrow(c, xs[1] + w, y + h / 2 - 3 * mm, xs[3], y + h / 2 - 3 * mm, CRITICAL, "reject()")
    arrow(c, xs[2] + w / 2, y, xs[4] + w / 2, y - 12 * mm + h, MUTED, "valid_until past", dashed=True)

    # Threshold panel
    y2 = y - 35 * mm
    box(c, MARGIN, y2 - 26 * mm, PAGE_W - 2 * MARGIN, 26 * mm, FINANCE_BG, FINANCE,
        label_top="Configurable thresholds  —  /governance/settings  (governance_settings table)",
        sublabels=[
            "spend_approval.threshold.capex                NZ$5,000     (default)",
            "spend_approval.threshold.opex                 NZ$10,000    (default)",
            "spend_approval.threshold.supplier_contract    NZ$10,000    (default)",
            "spend_approval.threshold.donor_restricted     NZ$25,000    (default)",
        ],
        label_color=FINANCE, body_color=NAVY)

    # Integration panel
    y3 = y2 - 35 * mm
    box(c, MARGIN, y3 - 26 * mm, PAGE_W - 2 * MARGIN, 26 * mm, ACCENT_BG, ACCENT,
        label_top="Polymorphic source  —  any SpendApproval can attach to:",
        sublabels=[
            "FinBill        (above-threshold bills require sign-off before posting)",
            "FinPurchaseOrder      (new POs above threshold queue here)",
            "FinPaymentRun           (batched payments above threshold)",
            "(none)                  -- stand-alone future commitment / forward approval",
        ],
        label_color=ACCENT, body_color=NAVY)

    footer(c, 5, total)


# ---------------- PAGE 6: audit + scheduled jobs --------------------------
def page6(c, total):
    header(c, "Audit trail + scheduled jobs",
           "Every governance write is auditable; cross-module reconciliation runs on schedule")

    # Top half: audit trail flow
    y = PAGE_H - 35 * mm
    c.setFillColor(NAVY); c.setFont("Helvetica-Bold", 11)
    c.drawString(MARGIN, y, "AUDIT TRAIL  -  unified read at  /governance/audit-log")
    y -= 6 * mm

    # Three sources -> two tables -> one page
    sources = [
        (MARGIN, y - 22 * mm, "Eloquent CUD on 24 governance models", INFO_BG, INFO,
         ["AuditableChanges trait", "(global audit_logs table)"]),
        (MARGIN + 65 * mm, y - 22 * mm, "Explicit action calls in controllers", ACCENT_BG, ACCENT,
         ["GovernanceAuditService::log()", "vote · approve · download · attest"]),
        (MARGIN + 130 * mm, y - 22 * mm, "AuditableChanges concern (legacy)", SUCCESS_BG, SUCCESS,
         ["NotifiableIncident, Safeguarding", "(also feeds audit_logs)"]),
    ]
    for x, yy, lbl, fill, stroke, items in sources:
        box(c, x, yy, 60 * mm, 22 * mm, fill, stroke, label_top=lbl, sublabels=items, label_color=stroke)

    # Arrows down to the unified store
    store_y = y - 50 * mm
    box(c, MARGIN + 50 * mm, store_y, PAGE_W - 2 * MARGIN - 100 * mm, 15 * mm,
        NAVY, NAVY, label_top="Unified audit stream",
        sublabels=["audit_logs (entity CUD)  +  governance_audit_log (actions)  +  governance_change_log"],
        label_color=white, body_color=HexColor("#cbd5e1"))
    for x, yy, *_ in sources:
        arrow(c, x + 30 * mm, yy, MARGIN + 50 * mm + (PAGE_W - 2 * MARGIN - 100 * mm) / 2, store_y + 15 * mm, MUTED)

    # Arrow to UI
    ui_y = store_y - 22 * mm
    box(c, MARGIN + 50 * mm, ui_y, PAGE_W - 2 * MARGIN - 100 * mm, 14 * mm,
        WARNING_BG, WARNING, label_top="/governance/audit-log   (Inertia page)",
        sublabels=["Filter by user · entity · action · date range. CSV export. Permission: governance.audit.view"],
        label_color=WARNING, body_color=NAVY)
    arrow(c, MARGIN + 50 * mm + (PAGE_W - 2 * MARGIN - 100 * mm) / 2, store_y,
          MARGIN + 50 * mm + (PAGE_W - 2 * MARGIN - 100 * mm) / 2, ui_y + 14 * mm, MUTED)

    # Bottom half: scheduled jobs
    y2 = ui_y - 18 * mm
    c.setFillColor(NAVY); c.setFont("Helvetica-Bold", 11)
    c.drawString(MARGIN, y2, "SCHEDULED JOBS  -  Pacific/Auckland timezone")
    y2 -= 4 * mm

    jobs = [
        ("06:00 Mon",    "governance:spawn-recurring-meetings",      "Spawn meetings from active templates"),
        ("06:15 daily",  "governance:sync-donor-fund-compliance",    "30 days before FinDonorFund.next_report_due"),
        ("06:30 daily",  "governance:sync-site-risk-reviews",        "14 days before Site.risk_review_date"),
        ("07:45 daily",  "governance:check-risk-reviews",            "Notify owners of risks due for review"),
        ("/15 min",      "governance:compliance-reminders",          "Process due compliance reminders (escalation level configurable)"),
        ("/15 min",      "SendBoardDigest job",                      "Weekly board digest emails"),
        ("hourly",       "SyncBudgetActualsJob",                     "Pull Finance actuals into BudgetLineItem"),
        ("00:20 daily",  "governance:sync-clinical-data",            "Refresh ClinicalGovernanceSnapshot"),
    ]
    row_h = 5 * mm
    table_top = y2
    for i, (when, cmd, desc) in enumerate(jobs):
        ry = table_top - (i + 1) * row_h
        c.setFillColor(LIGHT if i % 2 == 0 else white)
        c.rect(MARGIN, ry, PAGE_W - 2 * MARGIN, row_h, stroke=0, fill=1)
        c.setFillColor(INFO); c.setFont("Helvetica-Bold", 8)
        c.drawString(MARGIN + 2 * mm, ry + 1.5 * mm, when)
        c.setFillColor(NAVY); c.setFont("Courier", 8)
        c.drawString(MARGIN + 30 * mm, ry + 1.5 * mm, cmd)
        c.setFillColor(SLATE); c.setFont("Helvetica", 8)
        c.drawString(MARGIN + 105 * mm, ry + 1.5 * mm, desc)

    footer(c, 6, total)


# ---------------- main ----------------------------------------------------
def build(output_path):
    c = canvas.Canvas(output_path, pagesize=landscape(A4))
    c.setTitle("Governance Module - How It Works Now")
    c.setAuthor("Oblivion Findings")
    c.setSubject("Governance module architecture diagram, post-intensive-audit refactor")

    total_pages = 6
    pages = [page1, page2, page3, page4, page5, page6]
    for fn in pages:
        fn(c, total_pages)
        c.showPage()
    c.save()
    print(f"Wrote: {output_path}")


if __name__ == "__main__":
    import os, sys
    out = sys.argv[1] if len(sys.argv) > 1 else os.path.join("docs", "governance-architecture-diagram.pdf")
    os.makedirs(os.path.dirname(out) or ".", exist_ok=True)
    build(out)
