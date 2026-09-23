import React, { useState } from "react";
import { createRoot } from "react-dom/client";
import { EventHorizonWordmark } from "@/components/event-horizon-wordmark";
import {
  PageHeader,
  PageHeaderGlassButton,
  PageHeaderMeterBlock,
  PageHeaderMeterBig,
  PageHeaderMeterCaption,
  PageHeaderRail,
  PageHeaderSearchTrigger,
  PageHeaderStatusChip,
} from "@/components/page/page-header";
import { TierTwoTabs } from "@/components/page/grouped-profile-nav";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { TooltipProvider } from "@/components/ui/tooltip";
import {
  Command,
  CommandInput,
  CommandList,
  CommandItem,
  CommandEmpty,
} from "@/components/ui/command";
import {
  Car,
  Home,
  Building2,
  Users,
  CalendarDays,
  Wrench,
  ClipboardCheck,
  ShieldCheck,
  ShieldAlert,
  Gauge,
  ArrowUpRight,
  ArrowLeft,
  ArrowRight,
  FileText,
  History,
  Check,
  Clock,
  MapPin,
  Search,
  Bell,
  MessageSquare,
  PanelLeftClose,
  PanelLeftOpen,
  Satellite,
  LockKeyhole,
  ChevronDown,
  Plus,
  Settings2,
  Activity,
  HelpCircle,
  Upload,
} from "lucide-react";
import { Badge, Notice, Panel, Row, Modal } from "./ui";
import {
  CheckFlow,
  ReportFlow,
  UploadFlow,
  RunDetail,
  originalRun,
  type Run,
} from "./flows";
import "./styles.css";

const views = [
  { key: "overview", label: "Overview", icon: Car },
  { key: "compliance", label: "Service & compliance", icon: ShieldCheck },
  { key: "checks", label: "Checks & inspections", icon: ClipboardCheck },
  { key: "maintenance", label: "Maintenance", icon: Wrench },
  { key: "calendar", label: "Calendar", icon: CalendarDays },
];
const scenarios = [
  ["hold", "Active restriction"],
  ["ready", "Ready · current example evidence"],
  ["unknown", "Unknown applicability & missing evidence"],
  ["overdue", "Overdue service & check"],
  ["awaiting", "Work completed · awaiting release"],
  ["stale", "Stale evidence · no tracker"],
  ["empty", "First use · empty history"],
  ["readonly", "View-only role"],
  ["reportonly", "Report-only staff"],
  ["denied", "Role/site access denied"],
];
const subviews: Record<
  string,
  { key: string; label: string; icon: typeof Car }[]
> = {
  overview: [
    { key: "summary", label: "Readiness", icon: ShieldCheck },
    { key: "details", label: "Vehicle details", icon: Car },
    { key: "tracking", label: "Tracking", icon: Satellite },
  ],
  compliance: [
    { key: "summary", label: "Evidence & due dates", icon: FileText },
    { key: "history", label: "Service history", icon: History },
    { key: "mileage", label: "Mileage", icon: Gauge },
  ],
  checks: [
    { key: "summary", label: "Recent checks", icon: ClipboardCheck },
    { key: "templates", label: "Templates", icon: FileText },
  ],
  maintenance: [
    { key: "summary", label: "Open work", icon: Wrench },
    { key: "history", label: "Historical work", icon: History },
  ],
  calendar: [{ key: "summary", label: "Upcoming context", icon: CalendarDays }],
};
const defaultEntries = [
  {
    kind: "restriction",
    day: "Now",
    title: "Vehicle use restricted",
    sub: "Active from 21 Sep · 8:20 am · No end recorded",
    ref: "RST-DEMO-12",
    tone: "critical",
  },
  {
    kind: "service",
    day: "24 Sep",
    title: "Routine service appointment",
    sub: "9:00–11:00 am · Internal appointment",
    ref: "APT-DEMO-28",
    tone: "info",
  },
  {
    kind: "estimate",
    day: "24–25 Sep",
    title: "Estimated maintenance window",
    sub: "Advisory dates · No provider confirmation",
    ref: "WO-0264",
    tone: "warning",
  },
  {
    kind: "booking",
    day: "25 Sep",
    title: "Busy",
    sub: "10:00 am–12:00 pm · Booking details restricted",
    ref: "Busy-only",
    tone: "neutral",
  },
  {
    kind: "compliance",
    day: "08 Oct",
    title: "Registration reminder",
    sub: "Due date reminder · Does not reserve a day",
    ref: "REG-DEMO-14",
    tone: "warning",
  },
];
function App() {
  const [scenario, setScenario] = useState("hold"),
    [view, setView] = useState("overview"),
    [sub, setSub] = useState("summary"),
    [attention, setAttention] = useState(false),
    [collapsed, setCollapsed] = useState(false),
    [dialog, setDialog] = useState(""),
    [detail, setDetail] = useState(""),
    [fault, setFault] = useState("none"),
    [dark, setDark] = useState(false),
    [run, setRun] = useState<Run>(originalRun),
    [runs, setRuns] = useState<Run[]>([originalRun]),
    [linked, setLinked] = useState<Record<string, string>>({}),
    [createdReport, setCreatedReport] = useState(false),
    [reportFromCheck, setReportFromCheck] = useState(false),
    [work, setWork] = useState(""),
    [workReturn, setWorkReturn] = useState("maintenance"),
    [note, setNote] = useState(""),
    [noteSaved, setNoteSaved] = useState(false),
    [noteError, setNoteError] = useState(false),
    [notesOpen, setNotesOpen] = useState(false);
  const denied = scenario === "denied",
    ready = scenario === "ready",
    empty = scenario === "empty",
    unknown = scenario === "unknown" || empty,
    stale = scenario === "stale",
    overdue = scenario === "overdue",
    awaiting = scenario === "awaiting",
    readonly = scenario === "readonly",
    reportOnly = scenario === "reportonly";
  const hold = ["hold", "awaiting", "readonly", "reportonly"].includes(
    scenario,
  );
  const uncertain =
    unknown || stale || overdue || (!hold && runs[0]?.outcome !== "Passed");
  const readiness = hold
    ? "Not ready"
    : uncertain
      ? "Needs assessment"
      : "Ready";
  const nav = (v: string, s = "summary") => {
    setView(v);
    setSub(s);
    setAttention(false);
    setWork("");
    location.hash = `/fleet-assets/vehicles/14/${v}${s !== "summary" ? `/${s}` : ""}`;
  };
  const show = (name: string) => {
    setDetail(name);
    setDialog("detail");
  };
  const openWork = (id = "WO-0264") => {
    setWorkReturn(view);
    setWork(id);
    setNote("");
    setNoteSaved(false);
    setNoteError(false);
    setNotesOpen(false);
    location.hash = `/fleet-assets/maintenance/work-orders/${id === "WO-0264" ? "264" : id === "WO-0268" ? "268" : id === "WO-0188" ? "188" : "269"}?return=vehicle-14`;
  };
  const closeWork = () => {
    setWork("");
    nav(workReturn);
    setTimeout(
      () =>
        document.querySelector<HTMLButtonElement>("[data-work-link]")?.focus(),
      0,
    );
  };
  const evidence = [
    {
      title: "Odometer",
      sub: empty
        ? "Observation date not recorded"
        : stale
          ? "Last observed 2 Sep 2026 · 4:15 pm"
          : "Observed 21 Sep 2026 · 8:10 am",
      value: empty ? "Not recorded" : "82,460 km",
      status: empty ? "Unknown" : stale ? "Stale evidence" : "Recorded",
      tone: empty || stale ? "warning" : "neutral",
      source: empty
        ? "No observation available"
        : "Inspection CHK-0182 · Manual reading",
      attention: empty || stale,
    },
    {
      title: "Next service",
      sub: "Date and distance are separate schedule fields",
      value: empty
        ? "Not scheduled"
        : overdue
          ? "18 Sep 2026 / 82,000 km"
          : "24 Sep 2026 / 85,000 km",
      status: empty ? "Unknown" : overdue ? "Overdue" : "Due soon",
      tone: overdue ? "critical" : "warning",
      source: empty ? "No schedule available" : "SCH-DEMO-07 · Routine service",
      attention: true,
    },
    {
      title: "WoF",
      sub: unknown
        ? "Evidence date not recorded"
        : "Evidence recorded 9 Apr 2026",
      value: unknown ? "Not recorded" : "09 Apr 2027",
      status: unknown ? "Unknown" : "Current evidence",
      tone: unknown ? "warning" : "success",
      source: unknown
        ? "Applicability and evidence need review"
        : "WOF-DEMO-14 · Certificate record",
      attention: unknown,
    },
    {
      title: "Registration",
      sub: unknown
        ? "No source document recorded"
        : "Evidence recorded 8 Jul 2026",
      value: unknown ? "Not recorded" : "08 Oct 2026",
      status: unknown ? "Unknown" : "Due soon",
      tone: "warning",
      source: unknown
        ? "Expiry has not been verified"
        : "REG-DEMO-14 · Licence record",
      attention: true,
    },
    {
      title: "RUC",
      sub: ready
        ? "Evidence recorded 15 Sep 2026"
        : "Vehicle-specific applicability",
      value: ready
        ? "80,000–90,000 km"
        : unknown
          ? "Applicability unknown"
          : "Evidence not recorded",
      status: ready ? "Current evidence" : "Needs assessment",
      tone: ready ? "success" : "warning",
      source: ready
        ? "RUC-DEMO-14 · Illustrative applicable licence"
        : "No claim of applicability or exemption",
      attention: !ready,
    },
    {
      title: "CoF",
      sub: ready
        ? "Example applicability record · 15 Sep 2026"
        : "Vehicle-specific applicability",
      value: ready ? "Not applicable" : "Applicability not verified",
      status: ready ? "Applicability recorded" : "Unknown",
      tone: ready ? "neutral" : "warning",
      source: ready
        ? "APP-DEMO-14 · Synthetic decision, not legal guidance"
        : "Confirm the applicable compliance evidence",
      attention: !ready,
    },
  ];
  const openItems = empty
    ? []
    : [
        {
          id: "WO-0264",
          title: "Condition concern",
          status: awaiting
            ? "Completed · awaiting release"
            : "Awaiting assessment",
          note: "Original check CHK-0182 · Kōwhai House Coordinator",
          tone: awaiting ? "warning" : "critical",
        },
        {
          id: "WO-0268",
          title: "Routine service",
          status: "Awaiting scheduling",
          note: "Schedule SCH-DEMO-07 · 24 Sep / 85,000 km",
          tone: "info",
        },
      ].filter((item) => !ready || item.id !== "WO-0264");
  if (createdReport)
    openItems.unshift({
      id: "WO-DEMO-0269",
      title: "Reported vehicle concern",
      status: "Awaiting assessment",
      note: "New report · Kōwhai House Coordinator",
      tone: "warning",
    });
  const entries = empty
    ? []
    : defaultEntries.filter(
        (e) =>
          (e.kind !== "restriction" || hold) &&
          (!ready || e.kind !== "estimate"),
      );
  const actionDisabled = readonly || denied;
  const serviceWork = work === "WO-0268";
  const historicalWork = work === "WO-0188";
  const workCheckRuns = runs.filter(
    (item) =>
      (work === "WO-0264" && item.id === originalRun.id) ||
      linked[item.id] === work,
  );
  const upcoming = (
    <div className="agenda">
      {entries.length ? (
        entries
          .filter(
            (e) => !attention || ["restriction", "compliance"].includes(e.kind),
          )
          .map((e) => (
            <button
              className={`agenda-row ${e.kind}`}
              key={e.ref}
              onClick={() => {
                setDetail(e.kind);
                setDialog("event");
              }}
            >
              <span className="agenda-date">{e.day}</span>
              <span className="agenda-info">
                <strong>{e.title}</strong>
                <small>{e.sub}</small>
                <span className="ref">{e.ref}</span>
              </span>
              <ArrowUpRight size={16} />
            </button>
          ))
      ) : (
        <div className="empty">
          <CalendarDays />
          <strong>No upcoming records</strong>
          <p>
            Source-owned bookings, appointments and reminders will appear here.
          </p>
        </div>
      )}
    </div>
  );
  const table = (
    <div className="evidence-list">
      {evidence
        .filter((e) => !attention || e.attention)
        .map((e) => (
          <Row
            key={e.title}
            title={e.title}
            sub={`${e.sub} · ${e.source}`}
            value={<strong>{e.value}</strong>}
            badge={<Badge tone={e.tone as any}>{e.status}</Badge>}
            action={() => show(e.title)}
          />
        ))}
    </div>
  );
  const checkRows = runs
    .filter((r) => !attention || r.outcome !== "Passed")
    .map((r, i) => (
      <button
        className="record-row"
        key={r.id}
        onClick={() => {
          setRun(r);
          setDialog("run");
        }}
      >
        <span className="record-icon">
          <ClipboardCheck />
        </span>
        <span className="record-main">
          <strong>{r.template}</strong>
          <small>
            {r.id} · {r.version} · {r.submitted} · Alex Morgan
          </small>
        </span>
        <Badge
          tone={
            r.outcome === "Failed"
              ? "critical"
              : r.outcome === "Passed"
                ? "success"
                : "warning"
          }
        >
          {r.outcome}
        </Badge>
        <ArrowUpRight size={16} />
      </button>
    ));
  return (
    <TooltipProvider>
      <div className="preview-bar">
        <strong>PKG-02B · v1</strong>
        <span className="preview-label">
          Synthetic design · nothing is sent
        </span>
        <label>
          Scenario{" "}
          <select
            aria-label="Preview scenario"
            value={scenario}
            onChange={(e) => {
              setScenario(e.target.value);
              setWork("");
              setDialog("");
              setRuns(
                e.target.value === "ready"
                  ? [
                      {
                        ...originalRun,
                        answers: ["No issue recorded", "No issue recorded"],
                        outcome: "Passed",
                        notes: "Example assessment records no concern.",
                      },
                    ]
                  : e.target.value === "empty"
                    ? []
                    : [originalRun],
              );
              setLinked({});
              setCreatedReport(false);
              setNote("");
              setNoteSaved(false);
              setNoteError(false);
              nav("overview");
            }}
          >
            {scenarios.map(([id, label]) => (
              <option key={id} value={id}>
                {label}
              </option>
            ))}
          </select>
        </label>
        <label>
          Recovery{" "}
          <select
            aria-label="Preview recovery"
            value={fault}
            onChange={(e) => setFault(e.target.value)}
          >
            <option value="none">Normal</option>
            <option value="save">First save interrupted</option>
            <option value="search">Search failure</option>
            <option value="upload">Partial upload failure</option>
          </select>
        </label>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => {
            setDark(!dark);
            document.documentElement.classList.toggle("dark");
          }}
        >
          {dark ? "Light" : "Dark"} theme
        </Button>
      </div>
      <header className="chrome">
        <div className="wordmark">
          <EventHorizonWordmark />
        </div>
        <span className="chrome-search">
          <Search size={16} />
          Search Oblivion Care <kbd>Ctrl K</kbd>
        </span>
        <div className="chrome-right">
          <span>Mon, 21 September</span>
          <Button
            variant="ghost"
            className="chrome-action"
            onClick={() => show("Global actions")}
          >
            Report incident
          </Button>
          <button aria-label="Messages" onClick={() => show("Messages")}>
            <MessageSquare size={18} />
          </button>
          <button
            aria-label="Notifications"
            onClick={() => show("Notifications")}
          >
            <Bell size={18} />
          </button>
          <span className="avatar">AM</span>
        </div>
      </header>
      <div className={`workspace ${collapsed ? "collapsed" : ""}`}>
        <aside className="sidebar">
          <nav aria-label="Main navigation">
            {[
              [Home, "Home"],
              [Activity, "My day"],
              [Users, "Clients"],
              [Building2, "Sites"],
            ].map(([Icon, label]) => (
              <button key={String(label)} onClick={() => show(String(label))}>
                {React.createElement(Icon as typeof Home, { size: 18 })}
                <span>{String(label)}</span>
              </button>
            ))}
            <div className="module-label">
              <Car size={18} />
              <span>Fleet & assets</span>
              <ChevronDown size={14} />
            </div>
            {[
              "Overview",
              "Vehicles",
              "Bookings",
              "Maintenance",
              "Assets",
              "Reports",
            ].map((t) => (
              <button
                key={t}
                className={t === "Vehicles" ? "selected" : ""}
                onClick={() => (t === "Vehicles" ? nav("overview") : show(t))}
              >
                <span className="nav-dot" />
                <span>{t}</span>
              </button>
            ))}
            <button className="settings-link" onClick={() => show("Settings")}>
              <Settings2 size={18} />
              <span>Settings</span>
            </button>
          </nav>
          <button
            className="sidebar-toggle"
            aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"}
            onClick={() => setCollapsed(!collapsed)}
          >
            {collapsed ? (
              <PanelLeftOpen size={17} />
            ) : (
              <PanelLeftClose size={17} />
            )}
          </button>
        </aside>
        <main className="main">
          <nav className="breadcrumbs" aria-label="Breadcrumb">
            <button onClick={() => show("Home")}>Home</button>
            <span>›</span>
            <button onClick={() => show("Fleet & assets")}>
              Fleet & assets
            </button>
            <span>›</span>
            <button onClick={() => show("Vehicle register")}>Vehicles</button>
            <span>›</span>
            <span>
              {denied ? "Restricted record" : "Kōwhai van"}
              {work ? " › Maintenance" : ""}
            </span>
          </nav>
          {denied ? (
            <div className="denied panel">
              <LockKeyhole size={32} />
              <h1 className="text-page-title">
                This vehicle is not available to you
              </h1>
              <p>
                Your role or approved site access does not allow this record.
                Vehicle details and actions are hidden.
              </p>
              <Button onClick={() => show("Vehicle register")}>
                Back to permitted vehicles
              </Button>
            </div>
          ) : (
            <>
              <PageHeader
                variant="profile"
                wrapTitle
                mark={
                  <div className="identity-mark">
                    <button
                      aria-label={
                        work ? "Back to vehicle profile" : "Back to vehicles"
                      }
                      className="hero-back"
                      onClick={() =>
                        work ? closeWork() : show("Vehicle register")
                      }
                    >
                      <ArrowLeft size={16} />
                    </button>
                    <div className="eh-mark-ring">
                      {work ? <Wrench size={23} /> : <Car size={25} />}
                    </div>
                  </div>
                }
                title={
                  work
                    ? work === "WO-0188"
                      ? "Previous routine service"
                      : serviceWork
                        ? "Routine service"
                        : "Condition concern"
                    : "Kōwhai van"
                }
                titleChip={
                  <PageHeaderStatusChip
                    variant={
                      work
                        ? "info"
                        : hold
                          ? "critical"
                          : uncertain
                            ? "warning"
                            : "success"
                    }
                  >
                    {work
                      ? work === "WO-0188"
                        ? "Completed"
                        : serviceWork
                          ? "Awaiting scheduling"
                          : awaiting
                            ? "Completed"
                            : "Open"
                      : readiness}
                  </PageHeaderStatusChip>
                }
                subline={
                  work ? (
                    <>
                      {work} · Kōwhai van · VH-014
                      <br />
                      Maintenance · Original record context
                    </>
                  ) : (
                    <>
                      KWH014 · VH-014 · Kōwhai House
                      <br />
                      Toyota Hiace · Diesel · Community transport
                    </>
                  )
                }
                actions={
                  <>
                    <PageHeaderSearchTrigger
                      placeholder="Find in this vehicle…"
                      onOpen={() => setDialog("search")}
                    />
                    <PageHeaderGlassButton onClick={() => nav("calendar")}>
                      <CalendarDays size={16} />
                      Calendar
                    </PageHeaderGlassButton>
                    <button
                      className="hero-primary"
                      disabled={actionDisabled}
                      onClick={() => setDialog("check")}
                    >
                      <ClipboardCheck size={16} />
                      Start check
                    </button>
                  </>
                }
                meters={
                  <div className="meter-grid">
                    {(work
                      ? [
                          ["Vehicle", "VH-014", "Kōwhai van", "overview"],
                          [
                            "Source",
                            serviceWork || historicalWork
                              ? "SCH-DEMO-07"
                              : (workCheckRuns[0]?.id ?? "Manual report"),
                            serviceWork || historicalWork
                              ? "Original service schedule"
                              : workCheckRuns.length
                                ? "Original submitted check"
                                : "No check source attached",
                            serviceWork || historicalWork
                              ? "compliance"
                              : "checks",
                          ],
                          [
                            "Restriction",
                            hold ? "Active" : "Review",
                            "Separate release decision",
                            "overview",
                          ],
                          [
                            "Evidence",
                            "2 files",
                            "Original records & notes",
                            "maintenance",
                          ],
                        ]
                      : [
                          [
                            "Odometer",
                            empty ? "Unknown" : "82,460 km",
                            empty
                              ? "No observation available"
                              : stale
                                ? "Last observed 2 Sep"
                                : "Manual reading · 21 Sep",
                            "compliance",
                          ],
                          [
                            "Next service",
                            empty ? "Unknown" : overdue ? "Overdue" : "24 Sep",
                            "Date and distance schedule",
                            "compliance",
                          ],
                          [
                            "Last check",
                            !runs.length
                              ? "No record"
                              : overdue
                                ? "Overdue"
                                : runs[0].outcome,
                            !runs.length
                              ? "No submitted checks"
                              : `${runs[0]?.id} · ${runs[0]?.version}`,
                            "checks",
                          ],
                          [
                            "Maintenance",
                            empty
                              ? "No history"
                              : hold
                                ? "Hold active"
                                : "View work",
                            empty
                              ? "First-use example"
                              : awaiting
                                ? "Repair complete · release pending"
                                : "Original issues and work",
                            "maintenance",
                          ],
                        ]
                    ).map(([label, value, caption, dest]) => (
                      <PageHeaderMeterBlock
                        key={label}
                        label={label}
                        tone={
                          label === "Maintenance" && hold ? "critical" : "brand"
                        }
                        onClick={() =>
                          nav(
                            dest,
                            label === "Odometer" ? "mileage" : "summary",
                          )
                        }
                      >
                        <PageHeaderMeterBig>{value}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                          {caption}
                        </PageHeaderMeterCaption>
                      </PageHeaderMeterBlock>
                    ))}
                  </div>
                }
                filters={
                  <div className="header-filters">
                    <span>As at 21 Sep 2026 · 9:30 am · Pacific/Auckland</span>
                    <button
                      aria-pressed={attention}
                      onClick={() => {
                        setSub("summary");
                        setAttention(!attention);
                      }}
                    >
                      {attention ? "Needs attention" : "All evidence"}
                      <ChevronDown size={12} />
                    </button>
                  </div>
                }
                rail={
                  <PageHeaderRail
                    items={views}
                    value={view}
                    onSelect={(v) => nav(v)}
                    onFind={() => setDialog("search")}
                  />
                }
              />
              {readonly && (
                <Notice title="View-only access">
                  You can review permitted evidence. Reporting, checks and
                  updates need additional authority.
                </Notice>
              )}
              {work ? (
                <>
                  <div className="subnav-return">
                    <Button variant="ghost" onClick={closeWork}>
                      <ArrowLeft size={16} />
                      Back to vehicle profile
                    </Button>
                    <span className="muted">
                      Bounded Maintenance destination preview
                    </span>
                  </div>
                  <div className="content-grid work-grid">
                    <div className="stack">
                      <Panel
                        title={
                          work === "WO-0188"
                            ? "Previous service record"
                            : serviceWork
                              ? "Planned service"
                              : "Reported condition"
                        }
                        sub="Original work and check remain linked"
                      >
                        <p className="body-copy">
                          {work === "WO-0188"
                            ? "Routine service completed on 24 March 2026 at 74,800 km. The service record retains its original job reference and evidence."
                            : serviceWork
                              ? "Routine service is due on the recorded schedule. The appointment is an internal plan; provider confirmation is still outstanding."
                              : "A vehicle condition concern needs assessment. Review the original observations and evidence before deciding the next action."}
                        </p>
                        {(serviceWork || historicalWork) && (
                          <Button
                            variant="outline"
                            onClick={() => show("Next service")}
                          >
                            <ClipboardCheck size={16} />
                            Service schedule · SCH-DEMO-07
                          </Button>
                        )}
                        {workCheckRuns.map((sourceRun) => (
                          <Button
                            key={sourceRun.id}
                            variant="outline"
                            onClick={() => {
                              setRun(sourceRun);
                              setDialog("run");
                            }}
                          >
                            <ClipboardCheck size={16} />
                            Check evidence · {sourceRun.id}
                          </Button>
                        ))}
                        {!serviceWork &&
                          !historicalWork &&
                          !workCheckRuns.length && (
                            <p className="body-copy">
                              Manual report · No checklist source attached.
                            </p>
                          )}
                        <div className="source-strip">
                          <Car size={16} />
                          <button onClick={closeWork}>
                            Kōwhai van · VH-014
                          </button>
                          <span>Same vehicle record</span>
                        </div>
                      </Panel>
                      <Panel
                        title="Notes & updates"
                        sub="Attributed notes keep their original author and time"
                        action={
                          <Button
                            variant="ghost"
                            onClick={() => setNotesOpen(!notesOpen)}
                            aria-expanded={notesOpen}
                          >
                            {notesOpen ? "Collapse" : "Expand"}
                          </Button>
                        }
                      >
                        <p className="body-copy">
                          <strong>Latest:</strong> Assessment requested by the
                          site Coordinator.{" "}
                          <span className="muted">21 Sep · 8:25 am</span>
                        </p>
                        {notesOpen && (
                          <>
                            <Input
                              aria-label="Search notes"
                              placeholder="Search notes…"
                              onChange={(e) => setDetail(e.target.value)}
                            />
                            <p className="body-copy">
                              {"Assessment requested by the site Coordinator."
                                .toLowerCase()
                                .includes(detail.toLowerCase())
                                ? "Alex Morgan · 21 Sep · 8:25 am — Assessment requested by the site Coordinator."
                                : "No matching notes."}
                            </p>
                          </>
                        )}
                        <div className="field">
                          <label htmlFor="work-note">Add note</label>
                          <textarea
                            id="work-note"
                            value={note}
                            disabled={readonly}
                            onChange={(e) => {
                              setNote(e.target.value);
                              setNoteSaved(false);
                            }}
                            placeholder="Add an update for the next person…"
                          />
                        </div>
                        {noteError && (
                          <Notice title="Note not saved" tone="critical">
                            Your draft is kept. Retry to save this demo note.
                          </Notice>
                        )}
                        {noteSaved && (
                          <Notice title="Note saved in this preview">
                            No operational work record was changed.
                          </Notice>
                        )}
                        <Button
                          variant="outline"
                          disabled={!note.trim() || readonly}
                          onClick={() => {
                            if (fault === "save" && !noteError) {
                              setNoteError(true);
                              return;
                            }
                            setNoteError(false);
                            setNoteSaved(true);
                          }}
                        >
                          Save note
                        </Button>
                      </Panel>
                      <Panel
                        title="Evidence"
                        sub="Original files remain attached to their owning records"
                        action={
                          <Button
                            variant="outline"
                            disabled={readonly}
                            onClick={() => setDialog("upload")}
                          >
                            <Upload size={16} />
                            Add evidence
                          </Button>
                        }
                      >
                        <Row
                          title="Condition evidence"
                          sub="EV-DEMO-0182 · Original check · 21 Sep · Alex Morgan"
                          value="PDF"
                          action={() => show("Original evidence")}
                        />
                        <Row
                          title="Service record"
                          sub="EV-DEMO-0188 · WO-0188 · 24 Mar · Demo provider record"
                          value="PDF"
                          action={() => show("Service evidence")}
                        />
                      </Panel>
                    </div>
                    <div className="stack">
                      <Panel title="Work details">
                        <div className="next-action">
                          <small>Next action</small>
                          <strong>
                            {historicalWork
                              ? "Review original completed service evidence"
                              : awaiting
                                ? "Independent authorised release review"
                                : serviceWork
                                  ? "Confirm the service provider and appointment"
                                  : "Coordinator to assess the reported condition"}
                          </strong>
                        </div>
                        <Row
                          title="Progress"
                          value={
                            <Badge tone="warning">
                              {historicalWork || awaiting
                                ? "Completed"
                                : serviceWork
                                  ? "Awaiting scheduling"
                                  : "Awaiting assessment"}
                            </Badge>
                          }
                          action={() => show("Progress")}
                        />
                        <Row title="Owner" value="Kōwhai House Coordinator" />
                        <Row title="Target" value="Not yet assigned" />
                        <Button
                          variant="outline"
                          className="w-full mt-4"
                          disabled={historicalWork || readonly}
                          onClick={() => show("Complete work")}
                        >
                          {historicalWork ? "Work completed" : "Complete work"}
                        </Button>
                      </Panel>
                      <Notice
                        title={
                          hold
                            ? "Restriction remains active"
                            : "Release is a separate decision"
                        }
                        tone="warning"
                      >
                        {awaiting
                          ? "Repair completion does not release the vehicle. Required evidence, retest, custody and an independent authorised reviewer remain outstanding."
                          : "This profile does not provide a shortcut around Maintenance release or Finance approval."}
                      </Notice>
                    </div>
                  </div>
                </>
              ) : (
                <>
                  <TierTwoTabs
                    tabs={subviews[view]}
                    activeTab={sub}
                    onTab={(s) => setSub(s)}
                    testIdPrefix="vehicle"
                    ariaLabel="Vehicle sections"
                    panelId="vehicle-content"
                    renderLink={(tab, className, inner, a) => (
                      <button
                        key={tab.key}
                        className={className}
                        {...a}
                        onClick={() => setSub(tab.key)}
                      >
                        {inner}
                      </button>
                    )}
                  />
                  <div id="vehicle-content" className="content" role="tabpanel">
                    {view === "overview" && sub === "summary" && (
                      <div className="content-grid">
                        <div className="stack">
                          <section
                            className={`panel readiness ${hold ? "blocked" : uncertain ? "uncertain" : "ready"}`}
                          >
                            <div className="readiness-heading">
                              {hold ? (
                                <ShieldAlert size={27} />
                              ) : (
                                <ShieldCheck size={27} />
                              )}
                              <div>
                                <span className="eyebrow">
                                  Vehicle readiness
                                </span>
                                <h2 className="text-page-title">
                                  {hold
                                    ? awaiting
                                      ? "Repair complete. Release still pending."
                                      : "Not ready for use"
                                    : uncertain
                                      ? "More evidence is needed"
                                      : "Ready on current evidence"}
                                </h2>
                                <p>
                                  {hold
                                    ? "A restriction remains active for this vehicle."
                                    : uncertain
                                      ? "Missing or old evidence cannot confirm readiness."
                                      : "The example assessment records no active restriction."}
                                </p>
                              </div>
                            </div>
                            <div className="readiness-reasons">
                              {hold && (
                                <Row
                                  title={
                                    awaiting
                                      ? "Awaiting authorised release"
                                      : "Condition concern requires assessment"
                                  }
                                  sub="RST-DEMO-12 · Active since 21 Sep, 8:20 am · No end recorded"
                                  badge={
                                    <Badge tone="critical">
                                      Active restriction
                                    </Badge>
                                  }
                                  action={() => openWork()}
                                />
                              )}
                              <Row
                                title={
                                  overdue
                                    ? "Service and check are overdue"
                                    : ready
                                      ? "Applicable evidence reviewed"
                                      : empty
                                        ? "No readiness evidence yet"
                                        : stale
                                          ? "Odometer observation is old"
                                          : "Compliance evidence needs review"
                                }
                                sub={
                                  ready
                                    ? "Assessment ASMT-DEMO-14 · 21 Sep, 9:20 am · Fictional rules"
                                    : overdue
                                      ? "Service due 18 Sep / 82,000 km · Expected check has no current record"
                                      : empty
                                        ? "Add approved source records before any readiness decision"
                                        : stale
                                          ? "Last reading 2 Sep · No tracker is assigned"
                                          : "RUC evidence and CoF applicability have not been verified"
                                }
                                badge={
                                  <Badge tone={ready ? "success" : "warning"}>
                                    {ready ? "Reviewed" : "Needs assessment"}
                                  </Badge>
                                }
                                action={() => nav("compliance")}
                              />
                            </div>
                            <div className="readiness-footer">
                              <span>
                                Readiness is assessed again for each booking and
                                checkout.
                              </span>
                              <Button
                                variant="outline"
                                onClick={() =>
                                  hold ? openWork() : nav("compliance")
                                }
                              >
                                {hold
                                  ? "Review maintenance"
                                  : "Review evidence"}
                                <ArrowRight size={16} />
                              </Button>
                            </div>
                          </section>
                          <Panel
                            title="Evidence & next due dates"
                            sub="Original sources and observation dates stay visible"
                            action={
                              <Button
                                variant="ghost"
                                onClick={() => nav("compliance")}
                              >
                                View all
                                <ArrowRight size={16} />
                              </Button>
                            }
                          >
                            {table}
                          </Panel>
                          <Panel
                            title="Latest inspection"
                            sub="Submitted answers stay with the version used"
                            action={
                              <Button
                                variant="ghost"
                                onClick={() => nav("checks")}
                              >
                                All checks
                                <ArrowRight size={16} />
                              </Button>
                            }
                          >
                            {!runs.length ? (
                              <div className="empty">
                                <ClipboardCheck />
                                <strong>No checks recorded</strong>
                                <p>
                                  Start with the applicable approved template.
                                </p>
                              </div>
                            ) : (
                              checkRows.slice(0, 1)
                            )}
                          </Panel>
                        </div>
                        <div className="stack">
                          <Panel
                            title="Upcoming"
                            sub="Pacific/Auckland · Source-owned dates"
                            action={
                              <Button
                                variant="ghost"
                                aria-label="Open calendar context"
                                onClick={() => nav("calendar")}
                              >
                                <CalendarDays size={18} />
                              </Button>
                            }
                          >
                            {upcoming}
                            <p className="panel-footnote">
                              A reminder or estimate does not reserve the
                              vehicle.
                            </p>
                          </Panel>
                          <Panel title="Vehicle context">
                            <div className="vehicle-tile">
                              <Car size={40} />
                              <div>
                                <strong>Toyota Hiace</strong>
                                <span>KWH014 · VH-014</span>
                              </div>
                            </div>
                            <Row title="Home site" value="Kōwhai House" />
                            <Row title="Seating" value="8 recorded seats" />
                            <Row
                              title="Equipment"
                              value="Passenger lift"
                              action={() => show("Equipment")}
                            />
                            <Row
                              title="Tracking"
                              value={
                                <Badge>
                                  {stale || empty
                                    ? "No tracker"
                                    : "Last seen 8:12 am"}
                                </Badge>
                              }
                              action={() => setSub("tracking")}
                            />
                            <Button
                              variant="outline"
                              className="w-full mt-3"
                              disabled={actionDisabled}
                              onClick={() => {
                                setReportFromCheck(false);
                                setDialog("report");
                              }}
                            >
                              Report a problem
                            </Button>
                          </Panel>
                        </div>
                      </div>
                    )}
                    {view === "overview" && sub === "details" && (
                      <Panel
                        title="Vehicle details"
                        sub="The same vehicle and asset identity across Fleet and Maintenance"
                      >
                        <div className="details-grid">
                          {[
                            ["Registration", "KWH014"],
                            ["Asset reference", "VH-014"],
                            ["Make / model", "Toyota Hiace"],
                            ["Home site", "Kōwhai House"],
                            ["Fuel type", "Diesel"],
                            ["Capacity", "8 recorded seats"],
                            [
                              "Accessibility",
                              "Passenger lift recorded; readiness requires applicable equipment evidence",
                            ],
                            [
                              "Ownership",
                              "Organisation-owned · synthetic record",
                            ],
                            ["VIN", "Not recorded in this example"],
                            [
                              "Lifecycle",
                              "Active register record; use restriction assessed separately",
                            ],
                          ].map(([label, value]) => (
                            <Row key={label} title={label} value={value} />
                          ))}
                        </div>
                        <Notice title="Equipment and capacity are recorded facts">
                          A booking still needs to match the actual passenger,
                          driver and equipment requirements.
                        </Notice>
                      </Panel>
                    )}
                    {view === "overview" && sub === "tracking" && (
                      <Panel
                        title="Tracking"
                        sub="An optional source of observation, independent of readiness"
                      >
                        <Notice
                          title={
                            stale || empty
                              ? "No tracker assigned"
                              : "Last observation · 21 Sep 2026, 8:12 am"
                          }
                        >
                          {stale || empty
                            ? "This vehicle remains manageable through manual readings, evidence and checks."
                            : "Telemetry indicates a last-seen time only. It does not establish safety, current custody or availability."}
                        </Notice>
                        <div className="empty">
                          <Satellite size={32} />
                          <strong>
                            {stale || empty
                              ? "Manual records remain available"
                              : "Location detail is outside this mockup"}
                          </strong>
                          <p>
                            No device command, location lookup or tracking
                            action is performed.
                          </p>
                          <Button
                            variant="outline"
                            onClick={() => nav("compliance", "mileage")}
                          >
                            View mileage evidence
                          </Button>
                        </div>
                      </Panel>
                    )}
                    {view === "compliance" && sub === "summary" && (
                      <Panel
                        title="Service & compliance"
                        sub="Recorded evidence, applicability and next actions"
                      >
                        {table}
                        <Notice title="Dates do not decide applicability">
                          These are synthetic source records. Applicable
                          WoF/CoF, registration and RUC requirements must come
                          from approved vehicle-specific rules.
                        </Notice>
                      </Panel>
                    )}
                    {view === "compliance" && sub === "history" && (
                      <Panel
                        title="Service history"
                        sub="Work and schedule records retain their original references"
                      >
                        {empty ? (
                          <Empty
                            title="No service history"
                            text="Completed source records will appear here."
                          />
                        ) : (
                          <>
                            <button
                              className="record-row"
                              onClick={() => openWork("WO-0188")}
                            >
                              <span className="record-icon">
                                <Wrench />
                              </span>
                              <span className="record-main">
                                <strong>Routine service</strong>
                                <small>
                                  WO-0188 · 24 Mar 2026 · 74,800 km ·
                                  SCH-DEMO-07
                                </small>
                              </span>
                              <Badge tone="success">Work completed</Badge>
                              <ArrowUpRight size={16} />
                            </button>
                            <Row
                              title="Next scheduled service"
                              sub="SCH-DEMO-07 · Schedule dates, not an external booking"
                              value={evidence[1].value}
                              action={() => show("Next service")}
                            />
                            <Notice title="Service completion and release are separate">
                              A work record can be completed while a vehicle
                              restriction remains active.
                            </Notice>
                          </>
                        )}
                      </Panel>
                    )}
                    {view === "compliance" && sub === "mileage" && (
                      <Panel
                        title="Mileage & odometer"
                        sub="Recorded distance with observation source"
                      >
                        {empty ? (
                          <Empty
                            title="No odometer observations"
                            text="Unknown mileage is not zero."
                          />
                        ) : (
                          <>
                            <Row
                              title="Latest selected reading"
                              sub={
                                stale
                                  ? "Observed 2 Sep 2026 · 4:15 pm"
                                  : "Observed 21 Sep 2026 · 8:10 am · Alex Morgan"
                              }
                              value="82,460 km"
                              badge={
                                <Badge tone={stale ? "warning" : "neutral"}>
                                  {stale ? "Stale evidence" : "Manual reading"}
                                </Badge>
                              }
                              action={() => {
                                setRun(
                                  runs.find(
                                    (item) => item.id === originalRun.id,
                                  ) ?? originalRun,
                                );
                                setDialog("run");
                              }}
                            />
                            <Row
                              title="Previous recorded reading"
                              sub="20 Sep · 5:25 pm · Booking return BK-DEMO-40"
                              value="82,418 km"
                              action={() => show("Mileage source")}
                            />
                            <Row
                              title="Last completed service"
                              sub="24 Mar 2026 · WO-0188 · Service record"
                              value="74,800 km"
                              action={() => openWork("WO-0188")}
                            />
                            <Notice title="Source readings remain attributable">
                              A later correction must preserve the earlier
                              reading. Reimbursement mileage and vehicle
                              odometer evidence retain their own source records.
                            </Notice>
                          </>
                        )}
                      </Panel>
                    )}
                    {view === "checks" && (
                      <Panel
                        title={
                          sub === "templates"
                            ? "Checklist templates"
                            : "Checks & inspections"
                        }
                        sub="Version, original answers and follow-up in one place"
                        action={
                          <Button
                            disabled={actionDisabled}
                            onClick={() => setDialog("check")}
                          >
                            <Plus size={16} />
                            Start check
                          </Button>
                        }
                      >
                        {sub === "templates" ? (
                          <>
                            <Row
                              title="Vehicle condition record"
                              sub="DEMO-3 · Fictional example · Demonstrates original answers"
                              value={<Badge tone="info">Demo template</Badge>}
                              action={() =>
                                actionDisabled
                                  ? show("Reporting unavailable")
                                  : setDialog("check")
                              }
                            />
                            <Row
                              title="Return condition record"
                              sub="DEMO-2 · Fictional example · No operational rule asserted"
                              value={<Badge tone="info">Demo template</Badge>}
                              action={() => setDialog("check")}
                            />
                            <Notice title="Operational templates are not configured by this preview">
                              The real approved template and rule version must
                              be selected for the vehicle and check type.
                            </Notice>
                          </>
                        ) : (
                          <>
                            {overdue && (
                              <Notice
                                title="Current check is overdue"
                                tone="critical"
                              >
                                The example requirement has no current submitted
                                record. An older result is not a current pass.
                              </Notice>
                            )}
                            {checkRows.length ? (
                              checkRows
                            ) : (
                              <Empty
                                title="No check history"
                                text="Submitted inspections will keep their original template, answers and evidence."
                              />
                            )}
                            <Notice title="Checks remain separate from release">
                              A failed check can create or link Maintenance. A
                              passed check alone does not remove an active
                              restriction.
                            </Notice>
                          </>
                        )}
                      </Panel>
                    )}
                    {view === "maintenance" && (
                      <Panel
                        title={
                          sub === "history"
                            ? "Maintenance history"
                            : "Open maintenance & issues"
                        }
                        sub="Canonical work, original reports and linked evidence"
                        action={
                          <Button
                            variant="outline"
                            disabled={actionDisabled}
                            onClick={() => {
                              setReportFromCheck(false);
                              setDialog("report");
                            }}
                          >
                            Report a problem
                          </Button>
                        }
                      >
                        {empty && !createdReport ? (
                          <Empty
                            title="No maintenance history"
                            text="Reports and work linked to this vehicle will appear here."
                          />
                        ) : sub === "history" ? (
                          <>
                            <button
                              data-work-link
                              className="record-row"
                              onClick={() => openWork("WO-0188")}
                            >
                              <span className="record-icon">
                                <History />
                              </span>
                              <span className="record-main">
                                <strong>Previous routine service</strong>
                                <small>
                                  WO-0188 · Completed 24 Mar 2026 · Evidence
                                  retained
                                </small>
                              </span>
                              <Badge tone="success">Completed</Badge>
                              <ArrowUpRight size={16} />
                            </button>
                            <Row
                              title="Cancelled provider visit"
                              sub="WO-DEMO-0160 · 10 Feb 2026 · Original cancellation reason retained"
                              value={<Badge>Cancelled</Badge>}
                              action={() => show("Cancelled work")}
                            />
                          </>
                        ) : (
                          openItems
                            .filter((w) => !attention || w.tone !== "info")
                            .map((w) => (
                              <button
                                data-work-link
                                className="record-row"
                                key={w.id}
                                onClick={() => openWork(w.id)}
                              >
                                <span className="record-icon">
                                  <Wrench />
                                </span>
                                <span className="record-main">
                                  <strong>{w.title}</strong>
                                  <small>
                                    {w.id} · {w.note}
                                  </small>
                                </span>
                                <Badge tone={w.tone as any}>{w.status}</Badge>
                                <ArrowUpRight size={16} />
                              </button>
                            ))
                        )}
                        <Notice title="Work status does not clear a restriction">
                          Repair completion, authorised release, custody and
                          Finance approval are separate records.
                        </Notice>
                      </Panel>
                    )}
                    {view === "calendar" && (
                      <div className="content-grid">
                        <Panel
                          title="September 2026 · Upcoming context"
                          sub="Kōwhai van · VH-014 · Pacific/Auckland"
                        >
                          {upcoming}
                        </Panel>
                        <div className="stack">
                          <Notice title="Calendar destination preview">
                            This list demonstrates the vehicle context and
                            source links. Full day/week/month booking
                            interactions are a later mockup.
                          </Notice>
                          <Panel title="Understanding these dates">
                            <Row
                              title="Service appointment"
                              sub="Internal plan; provider confirmation shown separately"
                            />
                            <Row
                              title="Estimated window"
                              sub="Advisory planning dates only"
                            />
                            <Row
                              title="Restriction"
                              sub="Actual use restriction; explicit start and release"
                            />
                            <Row
                              title="Busy"
                              sub="Only time and busy status are disclosed"
                            />
                            <Row
                              title="Compliance reminder"
                              sub="A due date, not a whole-day reservation"
                            />
                            <Button
                              variant="outline"
                              className="mt-4"
                              onClick={() => nav("overview")}
                            >
                              <ArrowLeft size={16} />
                              Return to vehicle readiness
                            </Button>
                          </Panel>
                        </div>
                      </div>
                    )}
                  </div>
                </>
              )}
            </>
          )}
          <footer className="page-footer">
            <span>
              PKG-02B v1 · All people, records, dates and outcomes are
              synthetic.
            </span>
            <span>Design review · Application unchanged</span>
          </footer>
        </main>
      </div>
      {dialog === "check" && (
        <CheckFlow
          nextId={`CHK-DEMO-${String(182 + runs.length).padStart(4, "0")}`}
          onClose={() => setDialog("")}
          fail={fault === "save"}
          searchFail={fault === "search"}
          unconfigured={unknown}
          onDone={(r) => {
            setRun(r);
            setRuns((a) => [r, ...a.filter((x) => x.id !== r.id)]);
          }}
          onReport={() => {
            setReportFromCheck(true);
            setDialog("report");
          }}
        />
      )}
      {dialog === "run" && (
        <RunDetail
          run={run}
          linked={Boolean(linked[run.id])}
          onClose={() => setDialog("")}
          onReport={() => {
            if (actionDisabled) {
              show("Reporting unavailable");
              return;
            }
            if (linked[run.id]) {
              setDialog("");
              openWork(linked[run.id]);
              return;
            }
            setReportFromCheck(true);
            setDialog("report");
          }}
        />
      )}
      {dialog === "report" && (
        <ReportFlow
          run={reportFromCheck ? run : undefined}
          onClose={() => setDialog("")}
          onDone={(id) => {
            if (reportFromCheck)
              setLinked((links) => ({ ...links, [run.id]: id }));
            if (id === "WO-DEMO-0269") setCreatedReport(true);
            setDialog("");
            openWork(id);
          }}
          fail={fault === "save"}
          searchFail={fault === "search"}
          reportOnly={reportOnly}
        />
      )}
      {dialog === "upload" && (
        <UploadFlow
          work={work || "WO-0264"}
          onClose={() => setDialog("")}
          fail={fault === "upload"}
        />
      )}
      {dialog === "search" && (
        <Modal
          title="Find in this vehicle"
          description="Search sections and permitted references"
          onClose={() => setDialog("")}
        >
          <Command>
            <CommandInput
              placeholder="Search service, RUC, checks, WO-0264…"
              aria-label="Search vehicle sections"
            />
            <CommandList>
              <CommandEmpty>No matching section or reference.</CommandEmpty>
              {[
                ...views.map((v) => ({
                  label: v.label,
                  keywords:
                    v.key === "compliance"
                      ? "WoF registration RUC odometer"
                      : v.label,
                  action: () => nav(v.key),
                })),
                {
                  label: "Condition concern · WO-0264",
                  keywords: "WO-0264 Maintenance",
                  action: () => openWork(),
                },
                {
                  label: "Original check · CHK-0182",
                  keywords: "CHK-0182 DEMO-3",
                  action: () => {
                    setRun(
                      runs.find((item) => item.id === originalRun.id) ??
                        originalRun,
                    );
                    setDialog("run");
                  },
                },
              ].map((x) => (
                <CommandItem
                  key={x.label}
                  value={`${x.label} ${x.keywords}`}
                  onSelect={() => {
                    setDialog("");
                    x.action();
                  }}
                >
                  {x.label}
                  <ArrowUpRight className="ml-auto" size={16} />
                </CommandItem>
              ))}
            </CommandList>
          </Command>
        </Modal>
      )}
      {dialog === "event" && (
        <Modal
          title={
            detail === "booking"
              ? "Busy"
              : (defaultEntries.find((e) => e.kind === detail)?.title ??
                "Upcoming event")
          }
          description="Kōwhai van · Pacific/Auckland · Source-owned context"
          onClose={() => setDialog("")}
          footer={
            <>
              <Button variant="outline" onClick={() => setDialog("")}>
                Back to upcoming
              </Button>
              {detail !== "booking" && (
                <Button
                  onClick={() => {
                    setDialog("");
                    detail === "compliance"
                      ? nav("compliance")
                      : openWork(detail === "service" ? "WO-0268" : "WO-0264");
                  }}
                >
                  Open source record
                </Button>
              )}
            </>
          }
        >
          {detail === "booking" ? (
            <>
              <Notice title="Busy · 25 Sep, 10:00 am–12:00 pm">
                Your access allows busy times only. Booking details are not
                included.
              </Notice>
              <p>
                No requester, passenger, destination or purpose is available in
                this preview state.
              </p>
            </>
          ) : (
            <>
              <Row
                title="Source"
                value={defaultEntries.find((e) => e.kind === detail)?.ref}
              />
              <Row
                title="Recorded dates"
                value={defaultEntries.find((e) => e.kind === detail)?.sub}
              />
              <Notice
                title={
                  detail === "restriction"
                    ? "Active restriction"
                    : detail === "service"
                      ? "Internal appointment"
                      : detail === "estimate"
                        ? "Estimated dates only"
                        : "Due reminder"
                }
                tone={detail === "restriction" ? "critical" : "info"}
              >
                {detail === "restriction"
                  ? "No end or authorised release has been recorded."
                  : detail === "service"
                    ? "Provider confirmation has not been recorded. This is not proof of an external booking."
                    : detail === "estimate"
                      ? "24–25 September comes from the report. It does not create a booking or release the vehicle at the end."
                      : "This reminder does not reserve the vehicle for the day."}
              </Notice>
            </>
          )}
        </Modal>
      )}
      {dialog === "detail" && (
        <Modal
          title={detail}
          description="Vehicle profile · Synthetic source detail"
          onClose={() => setDialog("")}
        >
          {evidence.some((e) => e.title === detail) ? (
            <>
              {(() => {
                const e = evidence.find((e) => e.title === detail)!;
                return (
                  <>
                    <Row
                      title="Recorded value"
                      value={e.value}
                      badge={<Badge tone={e.tone as any}>{e.status}</Badge>}
                    />
                    <Row title="Evidence" value={e.source} />
                    <Row title="Observation / effective date" value={e.sub} />
                  </>
                );
              })()}
              <Notice
                title={
                  ["RUC", "CoF"].includes(detail) && !ready
                    ? "Applicability needs assessment"
                    : "Review the original source"
                }
              >
                {detail === "Next service"
                  ? "Schedule SCH-DEMO-07 records 24 Sep 2026 and 85,000 km. Last completed 24 Mar at 74,800 km. Due-soon treatment is an illustrative supplied status; no threshold is inferred."
                  : ["RUC", "CoF"].includes(detail)
                    ? "The demo does not define legal applicability, freshness rules or exemptions. Confirm the vehicle-specific evidence through the approved source."
                    : "The document date, expiry and observation are separate facts. Missing evidence remains unknown."}
              </Notice>
            </>
          ) : detail === "Complete work" ? (
            <Notice title="Continue in Maintenance" tone="warning">
              Completion uses Maintenance's guarded requirements and
              authorisation. It cannot release a restriction or approve an
              invoice. This destination preview does not perform the transition.
            </Notice>
          ) : detail === "Reporting unavailable" ? (
            <Notice title="Additional access required" tone="warning">
              Your current role can review the record but cannot create or link
              a report.
            </Notice>
          ) : detail === "Original evidence" ||
            detail === "Service evidence" ? (
            <>
              <div className="document-preview">
                <FileText size={38} />
                <strong>
                  {detail === "Original evidence"
                    ? "EV-DEMO-0182"
                    : "EV-DEMO-0188"}
                </strong>
                <p>Synthetic evidence reference</p>
                <p>
                  Original author, observation and owning record remain
                  attached.
                </p>
              </div>
              <Notice title="No real document is opened">
                This demonstrates the permitted evidence view and return path
                only.
              </Notice>
            </>
          ) : (
            <Notice title="Contextual destination">
              {detail === "Progress"
                ? "Work progress belongs to the existing Maintenance record. Review its owner and next action there; this preview does not change progress."
                : detail === "Equipment"
                  ? "Passenger lift is recorded on this vehicle. Its current check, service and restriction evidence must be assessed independently."
                  : `${detail} retains its existing destination and access rules. This isolated preview is limited to vehicle readiness and its linked sources.`}
            </Notice>
          )}
        </Modal>
      )}
    </TooltipProvider>
  );
}
function Empty({ title, text }: { title: string; text: string }) {
  return (
    <div className="empty">
      <History size={28} />
      <strong>{title}</strong>
      <p>{text}</p>
    </div>
  );
}
createRoot(document.getElementById("root")!).render(<App />);
