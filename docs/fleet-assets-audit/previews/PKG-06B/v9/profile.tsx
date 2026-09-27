import {
  DocumentFilePreview,
  DocumentDownload,
  documentFile,
  downloadDocument,
} from "./document-files";
import { sampleBranding } from "./asset-branding";
import { AssetQr, qrStates, useAssetQrSession } from "./asset-qr";
import {
  AssetFinance,
  financeStates,
  useAssetFinanceState,
} from "./asset-finance";
import "./finance.css";
import { FieldErrors, ModalFrame } from "./modal-ui";
import { DatePicker as SharedDatePicker } from "@/components/fleet-assets/maintenance/date-picker";
import { formatDateOnly } from "@/lib/datetime";
import "../../../../../resources/css/maintenance-date-time.css";
import { WorkRecords, KitRecords } from "./asset-records";
import React, { useState, useEffect, useRef, type ReactNode } from "react";
import { createRoot } from "react-dom/client";
import {
  Package,
  LayoutGrid,
  ArrowLeft,
  ArrowRight,
  ArrowUpRight,
  Building2,
  MapPin,
  Wrench,
  FileText,
  History,
  ShieldCheck,
  CircleAlert,
  Check,
  X,
  Search,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Upload,
  Plus,
  LockKeyhole,
  ScanLine,
  Radio,
  UserRound,
  Truck,
  CalendarDays,
  Clock3,
  Bell,
  MessageSquare,
  PanelLeftClose,
  PanelLeftOpen,
  CircleHelp,
  MoreHorizontal,
  Link2,
  RotateCcw,
  CheckCircle2,
  Pencil,
  Archive,
  Layers3,
  CircleDot,
  ClipboardCheck,
  SlidersHorizontal,
  ExternalLink,
  Image,
  Wallet,
  Menu,
  Download,
  Camera,
  Loader2,
  FolderOpen,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { StatusBadge } from "@/components/ui/status-badge";
import { TooltipProvider } from "@/components/ui/tooltip";
import {
  PageHeader,
  PageHeaderRail,
  PageHeaderMeterBlock,
  PageHeaderStatusChip,
  PageHeaderSearchTrigger,
  PageHeaderMeterBig,
  PageHeaderMeterCaption,
  PageHeaderGlassButton,
  PageHeaderPrimaryButton,
} from "@/components/page/page-header";
import { TierTwoTabs } from "@/components/page/grouped-profile-nav";
import {
  WizardShell,
  WizardStepPane,
  WizardSuccessPane,
  ReviewCard,
  ReviewRow,
} from "@/components/wizard/shell";
import {
  Dialog,
  DialogContent,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import {
  Popover,
  PopoverTrigger,
  PopoverContent,
} from "@/components/ui/popover";
import {
  Command,
  CommandInput,
  CommandList,
  CommandEmpty,
  CommandItem,
  CommandGroup,
} from "@/components/ui/command";
import { FileDropzone, StagedFileCard } from "@/components/ui/file-dropzone";
import {
  VehicleCollectionToggle as CollectionToggle,
  VehicleRecordCollection as RecordCollection,
} from "@/components/fleet-assets/vehicle-workspace/record-collection";
import { Input } from "@/components/ui/input";
import { FLEET_WORKSPACES } from "@/lib/fleet-navigation";

const NOW = "26 Sep 2026, 10:42 am NZST";
const groups = [
  {
    key: "overview",
    label: "Overview",
    icon: Package,
    tabs: [
      { key: "summary", label: "Summary", icon: ShieldCheck },
      { key: "identity", label: "Asset details", icon: Package },
      { key: "library", label: "Documents", icon: FileText },
      { key: "finance", label: "Finance", icon: Wallet },
    ],
  },
  {
    key: "custody",
    label: "Custody",
    icon: UserRound,
    tabs: [
      { key: "current", label: "Current custody", icon: UserRound },
      { key: "movements", label: "Movement history", icon: History },
    ],
  },
  {
    key: "checks",
    label: "Checks & service",
    icon: ClipboardCheck,
    tabs: [
      { key: "checks", label: "Original checks", icon: ClipboardCheck },
      { key: "service", label: "Service & calibration", icon: CalendarDays },
    ],
  },
  {
    key: "maintenance",
    label: "Maintenance",
    icon: Wrench,
    tabs: [{ key: "work", label: "Issues & work", icon: Wrench }],
  },
  {
    key: "location",
    label: "Location",
    icon: MapPin,
    tabs: [{ key: "location", label: "Location & observations", icon: MapPin }],
  },
  {
    key: "components",
    label: "Components & kit",
    icon: Layers3,
    tabs: [
      { key: "kit", label: "Kit contents", icon: Package },
      { key: "componentHistory", label: "Replacement history", icon: History },
    ],
  },
  {
    key: "lifecycle",
    label: "History",
    icon: History,
    tabs: [
      { key: "timeline", label: "Asset history", icon: History },
      { key: "retirement", label: "Retirement review", icon: Archive },
    ],
  },
];
function route(g: string, s?: string) {
  if (g === "documents") return { g: "overview", s: "library" };
  if (g === "lifecycle" && s === "finance")
    return { g: "overview", s: "finance" };
  if (g === "overview" && s === "location")
    return { g: "location", s: "location" };
  if (g === "maintenance" && ["checks", "service"].includes(s || ""))
    return { g: "checks", s: s! };
  const item = groups.find((x) => x.key === g) || groups[0];
  return {
    g: item.key,
    s: item.tabs.some((x) => x.key === s) ? s! : item.tabs[0].key,
  };
}

const documentSeed = [
  {
    id: "DOC-104-1",
    name: "Operating manual",
    file: "transfer-hoist-manual.pdf",
    type: "Manual",
    version: 3,
    date: "12 Sep 2026",
    by: "Mara Ellis",
    state: "Available",
    source: "Asset document set · DS-104-1",
    detail:
      "Manufacturer manual · Original file retained with each version. This sample contains no operating instructions.",
  },
  {
    id: "DOC-104-2",
    name: "Purchase & warranty",
    file: "warranty-104.pdf",
    type: "Warranty",
    version: 1,
    date: "18 Mar 2025",
    by: "Nia Patel",
    state: "Available",
    source: "Asset document set · DS-104-2",
    detail:
      "Supplier: Harbour Equipment · Synthetic warranty ends 18 Mar 2027. Finance invoice is a separate source.",
  },
  {
    id: "DOC-104-3",
    name: "Brake assessment photos",
    file: "brake-assessment.jpg",
    type: "Photo",
    version: 1,
    date: "24 Sep 2026",
    by: "Mara Ellis",
    state: "Available",
    source: "Original check · CHK-882",
    detail:
      "Source-owned evidence. Replacing a library document cannot alter this submitted photograph.",
  },
  {
    id: "DOC-104-4",
    name: "Service report",
    file: "service-report-271.pdf",
    type: "Service",
    version: 1,
    date: "25 Sep 2026",
    by: "Harbour Equipment",
    state: "Unavailable",
    source: "Maintenance · MW-271",
    detail:
      "The source file cannot currently be retrieved. The record and its history remain available; retry in the source workspace.",
  },
  {
    id: "DOC-104-5",
    name: "Calibration certificate",
    file: "calibration-certificate.pdf",
    type: "Calibration",
    version: 1,
    date: "26 Sep 2026",
    by: "Nia Patel",
    state: "Quarantined",
    source: "Maintenance evidence · MW-271",
    detail:
      "Access is blocked while the source owner resolves the file check. No clean scan is asserted by this mockup.",
  },
  {
    id: "DOC-104-6",
    name: "Prior manual",
    file: "transfer-hoist-manual-v2.pdf",
    type: "Manual",
    version: 2,
    date: "4 Feb 2026",
    by: "Mara Ellis",
    state: "Archived",
    source: "Asset document set · DS-104-1",
    detail:
      "Replaced by version 3 on 12 Sep 2026. Archive reason: manufacturer document revision. Original retained.",
  },
];
const progressOptions = [
  "Open",
  "In progress",
  "Awaiting internal feedback",
  "Awaiting approval",
  "Awaiting vendor",
  "Awaiting parts",
  "Awaiting scheduling",
  "On hold",
  "Completed/Closed",
  "Cancelled",
];
const sourcePaths: Record<string, string> = {
  register: "/fleet-assets/assets?site=kowhai",
  site: "/sites/kowhai/assets",
  stocktake: "/fleet-assets/assets (stocktake context owned by PKG-06A)",
  vehicle: "/fleet-assets/vehicles/14",
  finance: "/finance/assets (canonical fixed-asset context)",
  device: "/security-devices/devices (canonical device context)",
  maintenance: "/fleet-assets/maintenance/work-orders/271",
};
const Badge = ({
  children,
  tone = "neutral",
}: {
  children: ReactNode;
  tone?: any;
}) => <StatusBadge variant={tone}>{children}</StatusBadge>;
const IconTile = ({ icon: Icon = Package }: { icon?: any }) => (
  <span className="icon-tile">
    <Icon size={19} />
  </span>
);
function Card({
  title,
  icon: Icon,
  action,
  children,
  className = "",
}: {
  title?: string;
  icon?: any;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section className={"panel " + className}>
      {title && (
        <div className="panel-heading">
          <h2>
            {Icon && <Icon size={17} />} {title}
          </h2>
          {action}
        </div>
      )}
      <div className="panel-body">{children}</div>
    </section>
  );
}
function Notice({
  title,
  children,
  tone = "warning",
  action,
}: {
  title: string;
  children?: ReactNode;
  tone?: string;
  action?: ReactNode;
}) {
  return (
    <div className={"notice " + tone}>
      <CircleAlert size={19} />
      <div>
        <strong>{title}</strong>
        {children && <p>{children}</p>}
      </div>
      {action}
    </div>
  );
}
function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="fact">
      <dt>{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}
function TextLink({
  children,
  onClick,
}: {
  children: ReactNode;
  onClick: () => void;
}) {
  return (
    <button className="text-link" onClick={onClick}>
      {children}
      <ArrowUpRight size={14} />
    </button>
  );
}
function Field({
  label,
  children,
  hint,
  required = false,
}: {
  label: string;
  children: ReactNode;
  hint?: string;
  required?: boolean;
}) {
  const id = React.useId();
  const fieldError = React.useContext(FieldErrors)[label];
  return (
    <div className="field" data-field-name={label}>
      <label htmlFor={id}>
        {label}
        {required && <span className="field-required">Required</span>}
      </label>
      {React.isValidElement(children)
        ? React.cloneElement(children as React.ReactElement<any>, {
            id,
            "aria-required": required || undefined,
            "aria-invalid": !!fieldError,
            "aria-describedby":
              [hint ? id + "-hint" : "", fieldError ? id + "-error" : ""]
                .filter(Boolean)
                .join(" ") || undefined,
          })
        : children}
      {hint && <small id={id + "-hint"}>{hint}</small>}
      {fieldError && (
        <small className="field-error" id={id + "-error"}>
          {fieldError}
        </small>
      )}
    </div>
  );
}
function Picker({
  label,
  options,
  value,
  onChange,
}: {
  label: string;
  options: string[];
  value: string;
  onChange: (s: string) => void;
}) {
  const pickerId = React.useId();
  const pickerError = React.useContext(FieldErrors)[label];
  const [open, setOpen] = useState(false);
  const [state, setState] = useState("results");
  return (
    <div className="field" data-field-name={label}>
      <label htmlFor={pickerId}>{label}</label>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            variant="outline"
            id={pickerId}
            role="combobox"
            aria-invalid={!!pickerError}
            aria-describedby={pickerError ? pickerId + "-error" : undefined}
            aria-label={label}
            aria-expanded={open}
            className="picker-trigger"
          >
            {value || "Choose " + label.toLowerCase()}
            <ChevronDown size={15} />
          </Button>
        </PopoverTrigger>
        <PopoverContent
          className="asset-picker p-0"
          style={{ width: 420, maxWidth: "calc(100vw - 48px)" }}
          align="start"
          collisionPadding={20}
          onEscapeKeyDown={(e) => e.stopPropagation()}
        >
          <Command>
            <CommandInput
              placeholder={"Search " + label.toLowerCase()}
              aria-label={"Search " + label.toLowerCase()}
            />
            <div className="picker-state">
              <label>
                Preview response{" "}
                <select
                  aria-label="Picker response"
                  value={state}
                  onChange={(e) => setState(e.target.value)}
                >
                  <option value="results">Results</option>
                  <option value="loading">Loading</option>
                  <option value="failed">Failed</option>
                  <option value="denied">Access changed</option>
                </select>
              </label>
            </div>
            <CommandList>
              {state === "results" ? (
                <>
                  <CommandEmpty>
                    No matching permitted records. Try another name or
                    reference.
                  </CommandEmpty>
                  <CommandGroup heading="Permitted records">
                    {options.map((x) => (
                      <CommandItem
                        key={x}
                        value={x}
                        onSelect={() => {
                          onChange(x);
                          setOpen(false);
                        }}
                      >
                        <Check
                          size={14}
                          className={value === x ? "opacity-100" : "opacity-0"}
                        />
                        {x}
                      </CommandItem>
                    ))}
                  </CommandGroup>
                </>
              ) : (
                <div className="p-4 text-sm">
                  {state === "loading"
                    ? "Loading permitted records…"
                    : state === "denied"
                      ? "This selection is no longer available for your approved sites. Your earlier entry is retained for review."
                      : "Could not load records. Your selection is retained."}
                  {state === "failed" && (
                    <Button
                      variant="outline"
                      onClick={() => setState("results")}
                    >
                      Retry search
                    </Button>
                  )}
                </div>
              )}
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
      {pickerError && (
        <small className="field-error" id={pickerId + "-error"}>
          {pickerError}
        </small>
      )}
    </div>
  );
}
function DatePicker({
  value,
  onChange,
  label = "Expected return date",
}: {
  value: string;
  onChange: (value: string) => void;
  label?: string;
}) {
  const id = React.useId();
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      <SharedDatePicker
        id={id}
        label={label}
        value={value}
        onChange={onChange}
      />
      <small>
        Pacific/Auckland · Planned date; receipt is recorded separately.
      </small>
    </div>
  );
}
function App() {
  const start = new URLSearchParams(location.hash.replace("#", ""));
  const initial = route(
    start.get("view") || "overview",
    start.get("section") || "summary",
  );
  const [group, setGroup] = useState(initial.g),
    [sub, setSub] = useState(initial.s);
  const [scenario, setScenario] = useState(start.get("scenario") || "normal"),
    [collapsed, setCollapsed] = useState(false),
    [search, setSearch] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [modalTrail, setModalTrail] = useState<any[]>([]);
  const [modal, setModal] = useState<any>(null),
    [draftClose, setDraftClose] = useState(false),
    [toast, setToast] = useState(""),
    [failure, setFailure] = useState(false),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false),
    [success, setSuccess] = useState("");
  const [step, setStep] = useState(0),
    [mode, setMode] = useState("Transfer"),
    [destination, setDestination] = useState("Kōwhai House · Equipment room"),
    [person, setPerson] = useState("Nia Patel · Kōwhai House"),
    [date, setDate] = useState("2026-09-30"),
    [reason, setReason] = useState(""),
    [dirty, setDirty] = useState(false);
  const [custody, setCustody] = useState(
      scenario === "available" ? "Acknowledged" : "Awaiting receipt",
    ),
    [confirmedBy, setConfirmedBy] = useState("Mara Ellis"),
    [assignment, setAssignment] = useState(
      scenario === "empty" ? "Unassigned" : "Mara Ellis",
    ),
    [kitChecked, setKitChecked] = useState(
      scenario === "available" ? [true, true, true] : [false, false, false],
    ),
    [receiptOutcome, setReceiptOutcome] = useState("Accept with discrepancy");
  const [files, setFiles] = useState<File[]>([]),
    [uploadState, setUploadState] = useState("Staged"),
    [docs, setDocs] = useState(scenario === "empty" ? [] : documentSeed),
    [docTitle, setDocTitle] = useState(""),
    [docType, setDocType] = useState("Manual"),
    [note, setNote] = useState(""),
    [notesOpen, setNotesOpen] = useState(false),
    [notes, setNotes] = useState([
      "Mara Ellis · 25 Sep, 3:10 pm — The service report is still being retrieved.",
    ]),
    [progress, setProgress] = useState("Awaiting vendor"),
    [nextAction, setNextAction] = useState(
      "Retrieve the original service report and confirm the brake assessment.",
    ),
    [workComplete, setWorkComplete] = useState(false),
    [issueLinked, setIssueLinked] = useState(true),
    [name, setName] = useState("Transfer hoist"),
    [serial, setSerial] = useState("TH-DEMO-104"),
    [condition, setCondition] = useState("Needs assessment"),
    [archived, setArchived] = useState(false),
    [events, setEvents] = useState<string[]>([]);
  const [editDraft, setEditDraft] = useState({
      name: "Transfer hoist",
      serial: "TH-DEMO-104",
      condition: "Needs assessment",
      ownership: "Organisation-owned · Shared equipment",
      category: "Transfer equipment",
    }),
    [ownership, setOwnership] = useState(
      "Organisation-owned · Shared equipment",
    ),
    [category, setCategory] = useState("Transfer equipment"),
    [kitDraft, setKitDraft] = useState([true, true, false]),
    [currentLocation, setCurrentLocation] = useState(
      "Kōwhai House · Equipment room",
    ),
    [movement, setMovement] = useState({
      reference: "TR-104-08",
      origin: "Rimu House · Equipment store",
      destination: "Kōwhai House · Equipment room",
      recipient: "Nia Patel",
      kind: "Return from assessment",
      expected: "Not a loan",
    }),
    [receiptSaved, setReceiptSaved] = useState(""),
    [draftProgress, setDraftProgress] = useState("Awaiting vendor"),
    [draftAction, setDraftAction] = useState(
      "Retrieve the original service report and confirm the brake assessment.",
    );
  const [actor, setActor] = useState("Nia Patel");
  const [docLayout, setDocLayout] = useState<"list" | "cards">("list"),
    [docScope, setDocScope] = useState(
      start.get("section") === "photos"
        ? "photos"
        : start.get("section") === "versions"
          ? "all"
          : "current",
    ),
    [docSource, setDocSource] = useState("all"),
    [docAvailability, setDocAvailability] = useState("all"),
    [findOpen, setFindOpen] = useState(false),
    [photoUrl, setPhotoUrl] = useState("");
  const [reportMode, setReportMode] = useState("new"),
    [reportTitle, setReportTitle] = useState(""),
    [newReports, setNewReports] = useState<
      Array<{
        reference: string;
        title: string;
        description: string;
        by: string;
        files: string[];
      }>
    >([]);
  const lastTrigger = useRef<HTMLElement | null>(null);
  const [exceptionType, setExceptionType] = useState("Missing kit item");
  const [dark, setDark] = useState(false);
  const readOnly = scenario === "read-only" || scenario === "archived",
    denied = scenario === "denied",
    empty = scenario === "empty",
    unknown = scenario === "unknown",
    hold = !["available", "empty", "archived", "vehicle"].includes(scenario),
    selectedGroup = groups.find((x) => x.key === group) || groups[0];
  function go(g: string, s?: string) {
    if (g === "documents")
      setDocScope(
        s === "photos" ? "photos" : s === "versions" ? "all" : "current",
      );
    const next = route(g, s);
    setGroup(next.g);
    setSub(next.s);
    setSearch("");
    setFindOpen(false);
    location.hash = new URLSearchParams({
      view: next.g,
      section: next.s,
      scenario,
    }).toString();
  }
  function open(type: string, data?: any) {
    if (busy) return;
    if (
      (readOnly || denied) &&
      [
        "custody",
        "edit",
        "upload",
        "replace",
        "receipt",
        "report",
        "retire",
        "archiveDoc",
        "releaseAssignment",
        "exception",
        "departure",
      ].includes(type)
    ) {
      say(
        "Read-only access. This action requires the corresponding authority.",
      );
      return;
    }
    if (findOpen || document.querySelector('[role="menu"]')) {
      setFindOpen(false);
      window.setTimeout(() => openNow(type, data), 220);
      return;
    }
    openNow(type, data);
  }
  function openNow(type: string, data?: any) {
    if (!modal) {
      lastTrigger.current = document.activeElement as HTMLElement;
      setModalTrail([]);
      setMode("Transfer");
      setDestination(currentLocation);
      setPerson(
        movement.recipient + " · " + movement.destination.split(" · ")[0],
      );
      setDate("2026-09-30");
    } else {
      setModalTrail((trail) => [
        ...trail,
        {
          modal,
          step,
          mode,
          destination,
          person,
          date,
          reason,
          dirty,
          files,
          docTitle,
          docType,
          receiptOutcome,
          editDraft,
          kitDraft,
          draftProgress,
          draftAction,
          note,
          notesOpen,
          error,
          fieldErrors,
          failure,
        },
      ]);
    }
    setFieldErrors({});
    setNote("");
    setNotesOpen(false);
    setEditDraft({ name, serial, condition, ownership, category });
    setKitDraft(type === "receipt" ? [...kitChecked] : [true, true, false]);
    setDraftProgress(progress);
    setDraftAction(nextAction);
    setModal({ type, ...data });
    setExceptionType(
      type === "departure" ? "Borrower departure" : "Missing kit item",
    );
    setReportMode("new");
    setReportTitle("");
    setStep(0);
    setError("");
    setSuccess("");
    setBusy(false);
    setFailure(false);
    setDirty(false);
    setReason("");
    setFiles([]);
    setUploadState("Staged");
    setDocTitle(data?.photo ? "Asset profile photo" : data?.doc?.name || "");
    setDocType(data?.photo ? "Photo" : data?.doc?.type || "Manual");
  }
  function close() {
    if (busy) {
      say("The simulated save is in progress. Wait for its result.");
      return;
    }
    if (dirty && !success) {
      setDraftClose(true);
      return;
    }
    leaveModal();
  }
  function leaveModal() {
    setDraftClose(false);
    if (modalTrail.length) {
      const frame = modalTrail[modalTrail.length - 1];
      setModalTrail((trail) => trail.slice(0, -1));
      setModal(
        frame.modal.doc
          ? {
              ...frame.modal,
              doc:
                docs.find((d) => d.id === frame.modal.doc.id) ||
                frame.modal.doc,
            }
          : frame.modal,
      );
      setStep(frame.step);
      setMode(frame.mode);
      setDestination(frame.destination);
      setPerson(frame.person);
      setDate(frame.date);
      setReason(frame.reason);
      setDirty(frame.dirty);
      setFiles(frame.files);
      setDocTitle(frame.docTitle);
      setDocType(frame.docType);
      setReceiptOutcome(frame.receiptOutcome);
      setEditDraft(frame.editDraft);
      setKitDraft(frame.kitDraft);
      setDraftProgress(frame.draftProgress);
      setDraftAction(frame.draftAction);
      setNote(frame.note);
      setNotesOpen(frame.notesOpen);
      setError(frame.error);
      setFieldErrors(frame.fieldErrors);
      setFailure(frame.failure);
      setSuccess("");
      setBusy(false);
      return;
    }
    setModal(null);
    setDirty(false);
    setNote("");
    setFiles([]);
    setFieldErrors({});
    setTimeout(() => {
      if (document.querySelector('[role="dialog"], [role="menu"]')) return;
      const target = lastTrigger.current;
      if (target?.isConnected) target.focus();
      else
        document
          .querySelector<HTMLButtonElement>(".asset-find-trigger")
          ?.focus();
    }, 240);
  }
  useEffect(() => {
    if (!error || !modal) return;
    const timer = window.setTimeout(
      () =>
        document.querySelector<HTMLElement>(".modal-error-summary")?.focus(),
      280,
    );
    return () => window.clearTimeout(timer);
  }, [error, step, modal?.type]);
  function say(s: string) {
    setToast(s);
    setTimeout(() => setToast(""), 5500);
  }
  function mutate(effect: () => void, message: string) {
    if (busy || success) return;
    if (failure) {
      setError(
        "Simulated save failed. Nothing was changed. Your entries are retained; turn off “Fail this save” and retry.",
      );
      return;
    }
    setBusy(true);
    setTimeout(() => {
      effect();
      setBusy(false);
      setDirty(false);
      setSuccess(message);
      setEvents((e) => [message + " · " + actor + " · " + NOW, ...e]);
    }, 450);
  }
  function context(kind: string) {
    open("context", { kind });
  }
  function changeScenario(s: string, syncUrl = true) {
    setScenario(s);
    setCustody(s === "available" ? "Acknowledged" : "Awaiting receipt");
    setAssignment(s === "empty" ? "Unassigned" : "Mara Ellis");
    setConfirmedBy("Mara Ellis");
    setModal(null);
    setModalTrail([]);
    setFieldErrors({});
    setWorkComplete(false);
    setIssueLinked(true);
    setDocs(s === "empty" ? [] : documentSeed);
    setEvents([]);
    setNewReports([]);
    setReceiptSaved("");
    setCurrentLocation("Kōwhai House · Equipment room");
    setOwnership(
      s === "client-owned"
        ? "Client-owned · Owner restricted"
        : "Organisation-owned · Shared equipment",
    );
    setMovement({
      reference: "TR-104-08",
      origin: "Rimu House · Equipment store",
      destination: "Kōwhai House · Equipment room",
      recipient: "Nia Patel",
      kind: "Return from assessment",
      expected: "Not a loan",
    });
    setKitChecked(
      s === "available" ? [true, true, true] : [false, false, false],
    );
    setArchived(false);
    setCondition(s === "available" ? "Recorded as good" : "Needs assessment");
    setSearch("");
    setDocScope("current");
    setDocSource("all");
    setDocAvailability("all");
    if (photoUrl) URL.revokeObjectURL(photoUrl);
    setPhotoUrl("");
    if (syncUrl)
      location.hash = new URLSearchParams({
        view: group,
        section: sub,
        scenario: s,
      }).toString();
  }
  useEffect(() => {
    document.title = denied
      ? "Asset unavailable · PKG-06B v9"
      : name + " · Asset Profile · PKG-06B v9";
  }, [denied, name]);
  useEffect(() => {
    const listener = () => {
      const p = new URLSearchParams(location.hash.slice(1));
      const next = route(
        p.get("view") || "overview",
        p.get("section") || undefined,
      );
      setGroup(next.g);
      setSub(next.s);
      if (p.get("view") === "documents")
        setDocScope(
          p.get("section") === "photos"
            ? "photos"
            : p.get("section") === "versions"
              ? "all"
              : "current",
        );
      const nextScenario = p.get("scenario") || "normal";
      if (nextScenario !== scenario) changeScenario(nextScenario, false);
    };
    window.addEventListener("hashchange", listener);
    return () => window.removeEventListener("hashchange", listener);
  }, [scenario]);
  useEffect(() => {
    document.documentElement.classList.toggle("dark", dark);
  }, [dark]);
  useEffect(() => {
    const key = (e: KeyboardEvent) => {
      if (
        (e.target as HTMLElement)?.closest(
          "input,textarea,select,[contenteditable]",
        )
      )
        return;
      if (e.key === "/" && !modal && !denied) {
        e.preventDefault();
        setFindOpen(true);
      }
    };
    window.addEventListener("keydown", key);
    return () => window.removeEventListener("keydown", key);
  }, [modal, denied]);

  const source = (title: string, body: string) =>
    open("source", { title, body });
  const openWork = () => open("work");
  const docVisible = docs.filter(
    (d) =>
      (docScope === "all" ||
        (docScope === "photos"
          ? d.type === "Photo" && d.state !== "Archived"
          : d.state !== "Archived")) &&
      (docSource === "all" ||
        (docSource === "asset"
          ? d.source.startsWith("Asset document")
          : docSource === "check"
            ? d.source.startsWith("Original check")
            : d.source.startsWith("Maintenance"))) &&
      (docAvailability === "all" || d.state === docAvailability) &&
      [d.name, d.type, d.file, d.source, d.by, d.id]
        .join(" ")
        .toLowerCase()
        .includes(search.toLowerCase()),
  );
  const contextNames: Record<string, string> = {
    register: "Assets register",
    site: "Kōwhai House · Assets",
    stocktake: "Stocktake reconciliation",
    vehicle: "Existing Vehicle Profile",
    finance: "Finance fixed-asset record",
    device: "Security & Devices",
    maintenance: "Maintenance work record",
  };
  function identity() {
    return (
      <div className="facts-grid">
        <Fact label="Asset tag">
          <span className="mono">AS-104</span>
        </Fact>
        <Fact label="Serial number">{serial}</Fact>
        <Fact label="Category">{category}</Fact>
        <Fact label="Ownership">
          {scenario === "client-owned"
            ? "Client-owned · Owner details restricted"
            : ownership}
        </Fact>
        <Fact label="Supplier">
          <TextLink
            onClick={() =>
              source(
                "Harbour Equipment",
                "Synthetic supplier record. Warranty reference W-104. Supplier identity belongs to the existing permitted catalogue.",
              )
            }
          >
            Harbour Equipment
          </TextLink>
        </Fact>
        <Fact label="Condition">{unknown ? "Not recorded" : condition}</Fact>
        <Fact label="Lifecycle">
          {scenario === "archived" || archived
            ? "Retired · History retained"
            : "Active operational record"}
        </Fact>
        <Fact label="Criticality">Needs classification by asset owner</Fact>
      </div>
    );
  }
  function summary() {
    return (
      <>
        <div className="section-heading">
          <div>
            <span className="section-eyebrow">ASSET RECORD · AS-104</span>
            <h2>Summary & next actions</h2>
            <p>
              What needs attention, who is responsible, and where to follow up.
            </p>
          </div>
          <Button variant="outline" onClick={() => go("overview", "identity")}>
            <Package size={16} />
            Asset details
          </Button>
        </div>
        <div className="profile-grid summary-v2">
          <Card title="Needs attention" icon={CircleAlert}>
            <div className="action-row">
              <span className="alert-symbol">
                <Wrench size={20} />
              </span>
              <div>
                <strong>
                  {hold
                    ? "Resolve the brake assessment"
                    : "Review the recorded Maintenance concern"}
                </strong>
                <p>MW-271 · {progress} · Mara Ellis</p>
                <small>{nextAction}</small>
              </div>
              <Button variant="outline" onClick={openWork}>
                Open work
                <ArrowUpRight size={15} />
              </Button>
            </div>
            <div className="action-row">
              <IconTile icon={UserRound} />
              <div>
                <strong>
                  {custody === "Acknowledged"
                    ? "Receipt acknowledged"
                    : custody === "Disputed"
                      ? "Resolve the disputed receipt"
                      : "Confirm the actual receipt"}
                </strong>
                <p>
                  {movement.recipient} · {movement.reference} ·{" "}
                  {kitChecked.filter(Boolean).length} of 3 kit items confirmed
                </p>
                <small>
                  {custody === "Acknowledged"
                    ? "Recorded by " +
                      confirmedBy +
                      ". Any separate hold remains."
                    : confirmedBy +
                      " retains responsibility until receipt is resolved."}
                </small>
              </div>
              <Button
                variant="outline"
                disabled={readOnly}
                onClick={() => open("receipt")}
              >
                Review receipt
                <ArrowRight size={15} />
              </Button>
            </div>
            <div className="action-row">
              <IconTile icon={FileText} />
              <div>
                <strong>Retrieve the service report</strong>
                <p>File unavailable · Original source MW-271</p>
                <small>
                  The file record remains visible while access is resolved.
                </small>
              </div>
              <TextLink
                onClick={() =>
                  open("document", {
                    doc: docs.find((d) => d.id === "DOC-104-4")!,
                  })
                }
              >
                View record
              </TextLink>
            </div>
          </Card>
          <Card
            title="Location & custody"
            icon={MapPin}
            action={
              <Badge tone={custody === "Acknowledged" ? "success" : "warning"}>
                {custody}
              </Badge>
            }
          >
            <div className="location-title">
              {currentLocation.split(" · ")[0]}
              <span>{currentLocation.split(" · ")[1]}</span>
            </div>
            <p className="muted small">
              Assigned destination · Updated 25 Sep 2026
            </p>
            <div className="custodian">
              <span className="avatar">
                {confirmedBy
                  .split(" ")
                  .map((x) => x[0])
                  .join("")}
              </span>
              <div>
                <small>Accountable custodian</small>
                <strong>{confirmedBy}</strong>
                <small>
                  {custody === "Acknowledged"
                    ? "Receipt acknowledged"
                    : "Responsibility retained until receipt"}
                </small>
              </div>
            </div>
            <div className="inset">
              <strong>Latest observation · 26 Sep, 9:10 am</strong>
              <p>
                Manual tag lookup at Kōwhai House by Nia Patel. This observation
                does not confirm custody.
              </p>
            </div>
            <div className="flex justify-between">
              <TextLink onClick={() => go("custody")}>View custody</TextLink>
              <TextLink onClick={() => go("location")}>
                Location sources
              </TextLink>
            </div>
          </Card>
        </div>
        <div className="two-col">
          <Card
            title="Original check"
            icon={ClipboardCheck}
            action={<Badge tone="warning">Needs assessment</Badge>}
          >
            <strong>Brake concern · CHK-882</strong>
            <p className="muted small mt-2">
              Mara Ellis · 24 Sep 2026, 8:40 am · Template v2
            </p>
            <p className="small my-3">
              Original answer and evidence remain attached to the submitted
              check.
            </p>
            <TextLink onClick={() => open("check", { check: "CHK-882" })}>
              View original submission
            </TextLink>
          </Card>
          <Card
            title="Asset documents"
            icon={FileText}
            action={
              <TextLink onClick={() => go("overview", "library")}>
                View all documents
              </TextLink>
            }
          >
            <div className="attachment-shelf">
              {docs
                .filter(
                  (d) =>
                    d.source.startsWith("Asset document") &&
                    d.state !== "Archived",
                )
                .slice(0, 2)
                .map((d) => (
                  <button
                    key={d.id}
                    onClick={() => open("document", { doc: d })}
                  >
                    <IconTile icon={FileText} />
                    <span>
                      <strong>{d.name}</strong>
                      <small>
                        Version {d.version} · {d.state}
                      </small>
                    </span>
                    <ArrowUpRight size={15} />
                  </button>
                ))}
            </div>
          </Card>
        </div>
        <Card
          title="Recent history"
          icon={History}
          action={
            <TextLink onClick={() => go("lifecycle", "timeline")}>
              Full asset history
            </TextLink>
          }
        >
          {historyRows(3)}
        </Card>
      </>
    );
  }
  const [qrSample, setQrSample] = useState("active");
  const qrSession = useAssetQrSession(qrSample);
  function detailsView() {
    return (
      <div className="details-layout">
        <Card className="identity-card">
          <div className="asset-photo">
            {photoUrl ? (
              <img src={photoUrl} alt="Local asset profile photo" />
            ) : (
              <Package size={66} />
            )}
            <Button
              variant="outline"
              disabled={readOnly}
              onClick={() => open("upload", { photo: true })}
            >
              <Camera size={16} />
              {photoUrl ? "Change photo" : "Upload profile photo"}
            </Button>
          </div>
          <span className="section-eyebrow">AS-104 · {serial}</span>
          <h2>{name}</h2>
          <p className="muted small">
            {category} · {currentLocation.split(" · ")[0]}
          </p>
          <div className="chips">
            <Badge>
              {scenario === "archived" ? "Retired" : "Active record"}
            </Badge>
            <Badge tone="info">No tracker required</Badge>
          </div>
        </Card>
        <Card
          title="Asset details"
          icon={Package}
          action={
            <Button
              variant="outline"
              disabled={readOnly}
              onClick={() => open("edit")}
            >
              <Pencil size={15} />
              Edit details
            </Button>
          }
        >
          {identity()}
          <div className="details-footer">
            <ShieldCheck size={16} />
            <p>
              Custody, Maintenance holds and Finance decisions keep their own
              history.
            </p>
          </div>
          {scenario === "client-owned" && (
            <Notice title="Owner identity is restricted" tone="info">
              Asset access does not grant access to care details or personal
              location.
            </Notice>
          )}
        </Card>
        <AssetQr
          branding={sampleBranding}
          bulkUrl="http://127.0.0.1:4373/PKG-06A/v9/?view=qr-labels"
          name={name}
          session={qrSession}
          sample={qrSample}
          readOnly={readOnly}
          privateOwner={scenario === "client-owned"}
          actor={actor}
        />
        <Card
          className="details-documents"
          title="Asset documents"
          icon={FileText}
          action={
            <Button
              variant="outline"
              disabled={readOnly}
              onClick={() => open("upload")}
            >
              <Upload size={15} />
              Upload
            </Button>
          }
        >
          <div className="attachment-shelf">
            {docs
              .filter(
                (d) =>
                  d.source.startsWith("Asset document") &&
                  d.state !== "Archived",
              )
              .map((d) => (
                <button key={d.id} onClick={() => open("document", { doc: d })}>
                  <IconTile icon={FileText} />
                  <span>
                    <strong>{d.name}</strong>
                    <small>
                      {d.file} · v{d.version}
                    </small>
                  </span>
                  <ArrowUpRight size={15} />
                </button>
              ))}
          </div>
          <TextLink onClick={() => go("overview", "library")}>
            Open document library
          </TextLink>
        </Card>
      </div>
    );
  }

  function historyRows(limit = 20, custodyOnly = false) {
    const history = [
      ...events,
      "Tag observed at Kōwhai House · Nia Patel · 26 Sep, 9:10 am · SC-204",
      "Dispatched from Rimu House · Mara Ellis · 25 Sep, 2:35 pm · TR-104-08",
      "Service report requested · Mara Ellis · 25 Sep, 11:20 am · MW-271",
      "Brake concern submitted · Mara Ellis · 24 Sep, 8:40 am · CHK-882",
      "Manual version 3 added; version 2 retained · 12 Sep 2026 · DS-104-1",
      "Battery replaced; prior component retained in history · 3 Aug 2026 · AS-104-B1",
      "Asset received & tagged · 18 Mar 2025 · RCP-104",
    ];
    return (
      <ol className="timeline">
        {history
          .filter(
            (e) =>
              !custodyOnly ||
              /TR-104|custody|receipt|assignment|dispatched/i.test(e),
          )
          .slice(0, limit)
          .map((e, i) => (
            <li key={i}>
              <span className="timeline-dot" />
              <div>
                <strong>{e.split(" · ")[0]}</strong>
                <p>{e.split(" · ").slice(1).join(" · ")}</p>
              </div>
              <TextLink
                onClick={() =>
                  source(
                    "Original event",
                    e +
                      "\n\nThis synthetic event retains its source identity, actor and recorded time. Changes append history; they do not rewrite the original record.",
                  )
                }
              >
                Source
              </TextLink>
            </li>
          ))}
      </ol>
    );
  }
  function locationView() {
    return (
      <>
        <Notice title="These sources answer different questions" tone="info">
          Assigned location is the intended placement. Receipt establishes
          custody. Observations say where something was reported, with their own
          source and time.
        </Notice>
        <div className="two-col">
          <Card title="Assigned location" icon={Building2}>
            <div className="location-title">
              {currentLocation.split(" · ")[0]}
              <span>{currentLocation.split(" · ")[1]}</span>
            </div>
            <p>Placement source: Asset · 25 Sep, 2:35 pm NZST</p>
            <p className="muted small">
              Legacy room reference is not automatically treated as the
              canonical room. Any mismatch needs Site resolution.
            </p>
            <TextLink onClick={() => context("site")}>
              Open canonical Site / room
            </TextLink>
          </Card>
          <Card title="Last manual verification" icon={UserRound}>
            <div className="location-title">
              Rimu House<span>Equipment store</span>
            </div>
            <p>Mara Ellis · 25 Sep, 2:20 pm NZST</p>
            <Badge tone="warning">Before current dispatch</Badge>
          </Card>
          <Card title="Last tracker-reported position" icon={Radio}>
            <div className="empty-inline">
              <Radio size={30} />
              <div>
                <h3>No tracker paired</h3>
                <p>Location and custody remain usable without a device.</p>
              </div>
            </div>
            <TextLink onClick={() => context("device")}>
              Optional device links
            </TextLink>
            <p className="muted small">
              Capability, health and observation times come from Security &
              Devices. Pairing does not activate monitoring.
            </p>
          </Card>
          <Card title="Last tag-reader observation" icon={ScanLine}>
            <div className="location-title">
              Kōwhai House<span>Equipment room</span>
            </div>
            <p>Manual tag lookup · Nia Patel · SC-204</p>
            <p className="muted small">
              Observed 26 Sep, 9:10 am · Received 9:12 am NZST
            </p>
            <Badge tone="warning">Not receipt evidence</Badge>
            <div className="mt-3">
              <TextLink onClick={() => context("stocktake")}>
                Open discrepancy ST-022
              </TextLink>
            </div>
          </Card>
        </div>
      </>
    );
  }
  function custodyView() {
    return (
      <>
        <div className="section-heading">
          <div>
            <h2>
              {sub === "movements" ? "Movement history" : "Accountable custody"}
            </h2>
            <p>
              Placement, responsibility and physical receipt are recorded
              separately.
            </p>
          </div>
          <Button disabled={readOnly} onClick={() => open("custody")}>
            Manage custody
            <ArrowRight size={16} />
          </Button>
        </div>
        {sub === "movements" ? (
          <Card>{historyRows(20, true)}</Card>
        ) : (
          <>
            <div className="custody-track">
              <div>
                <Badge>Origin</Badge>
                <h3>{movement.origin.split(" · ")[0]}</h3>
                <p>{movement.origin.split(" · ")[1]}</p>
                <small>Mara Ellis · 25 Sep, 2:35 pm</small>
              </div>
              <ArrowRight className="text-primary" />
              <div>
                <Badge tone="warning">{custody}</Badge>
                <h3>{movement.destination.split(" · ")[0]}</h3>
                <p>{movement.destination.split(" · ")[1]}</p>
                <small>Intended recipient: {movement.recipient}</small>
              </div>
              <Button onClick={() => open("receipt")} disabled={readOnly}>
                Review receipt
              </Button>
            </div>
            <div className="two-col">
              <Card title="Responsibility" icon={UserRound}>
                <dl>
                  <Fact label="Assigned person">{assignment}</Fact>
                  <Fact label="Confirmed custodian">{confirmedBy}</Fact>
                  <Fact label="Movement purpose">
                    {movement.kind} · Not release for use
                  </Fact>
                  <Fact label="Reference">
                    {movement.reference} · Origin and destination retained
                  </Fact>
                  <Fact label="Expected return">
                    {movement.expected} · Scheduled end is not receipt
                  </Fact>
                </dl>
                <Notice title="Scheduled end is not a return" tone="info">
                  Custody continues until an actual receipt or owned exception
                  is recorded.
                </Notice>
              </Card>
              <Card title="Exceptions & next action" icon={CircleAlert}>
                <div className="action-row">
                  <div>
                    <strong>
                      {kitChecked[2]
                        ? "Charger receipt confirmed"
                        : "Charger receipt unconfirmed"}
                    </strong>
                    <p>
                      {kitChecked[2]
                        ? "Arrival recorded in this preview."
                        : movement.recipient + " to check the kit on receipt."}
                    </p>
                  </div>
                  <TextLink onClick={() => go("components")}>Kit</TextLink>
                </div>
                <div className="action-row">
                  <div>
                    <strong>Wrong location, loss or damage</strong>
                    <p>
                      Record the discrepancy and preserve the outgoing
                      custodian.
                    </p>
                  </div>
                  <TextLink onClick={() => open("exception")}>
                    Record exception
                  </TextLink>
                </div>
                <div className="action-row">
                  <div>
                    <strong>Borrower no longer available</strong>
                    <p>
                      Escalate the outstanding loan to the responsible Site
                      owner.
                    </p>
                  </div>
                  <TextLink onClick={() => open("departure")}>Review</TextLink>
                </div>
              </Card>
            </div>
          </>
        )}
      </>
    );
  }
  function maintenanceView() {
    if (sub === "checks")
      return (
        <>
          <Notice
            title="Checklist rules are illustrative and unconfigured"
            tone="info"
          >
            The responsible owner must approve applicable templates, intervals
            and release conditions. These sample answers are for design review
            only.
          </Notice>
          <Card title="Original submitted checks" icon={ClipboardCheck}>
            {[
              [
                "Brake concern",
                "CHK-882",
                "24 Sep 2026 · 8:40 am",
                "Needs assessment",
              ],
              [
                "Equipment review",
                "CHK-771",
                "18 Mar 2025 · 11:05 am",
                "Recorded",
              ],
            ].map(([title, id, time, status]) => (
              <div className="action-row" key={id}>
                <IconTile icon={ClipboardCheck} />
                <div>
                  <strong>{title}</strong>
                  <p>
                    {id} · {time} NZST
                  </p>
                  <small>
                    Equipment observation · Template v2 · Mara Ellis
                  </small>
                </div>
                <Badge tone={id === "CHK-882" ? "warning" : "neutral"}>
                  {status}
                </Badge>
                <Button
                  variant="outline"
                  onClick={() => open("check", { check: id })}
                >
                  Open original
                </Button>
              </div>
            ))}
          </Card>
        </>
      );
    if (sub === "service")
      return (
        <>
          <div className="two-col">
            <Card
              title="Service & calibration requirements"
              icon={CalendarDays}
            >
              <Notice title="Applicability not configured">
                No next-due date is calculated from a missing interval. Ask the
                responsible asset owner to confirm requirements.
              </Notice>
              <dl>
                <Fact label="Service schedule">Not configured</Fact>
                <Fact label="Calibration requirement">
                  Needs assessment · No certificate assumed valid
                </Fact>
                <Fact label="Last service evidence">
                  25 Sep 2026 · MW-271 · File unavailable
                </Fact>
                <Fact label="Next action">
                  Retrieve original evidence and confirm applicable rules
                </Fact>
              </dl>
            </Card>
            <Card title="Source-linked history" icon={History}>
              {[
                ["Service assessment", "25 Sep 2026 · MW-271"],
                ["Battery replacement", "3 Aug 2026 · MW-198"],
                ["Receipt review", "18 Mar 2025 · CHK-771"],
              ].map(([a, b]) => (
                <div className="action-row" key={a}>
                  <div>
                    <strong>{a}</strong>
                    <p>{b}</p>
                  </div>
                  <TextLink
                    onClick={() =>
                      source(
                        a,
                        b +
                          "\nOriginal Maintenance evidence and affected component remain linked. Work completion does not release a separate restriction.",
                      )
                    }
                  >
                    Original
                  </TextLink>
                </div>
              ))}
            </Card>
          </div>
        </>
      );
    return (
      <WorkRecords
        extraRecords={newReports}
        onViewReport={(r) =>
          source(
            r.title + " · " + r.reference,
            "Reported by " +
              r.by +
              " · " +
              NOW +
              "\nProgress: Open\nNext action: Review and assign the reported issue.\n\n" +
              r.description +
              "\nEvidence staged locally: " +
              (r.files.join(", ") || "None") +
              "\nNo notifications or operational records were created.",
          )
        }
        progress={progress}
        nextAction={nextAction}
        readOnly={readOnly}
        onOpen={openWork}
        onCheck={() => open("check", { check: "CHK-882" })}
        onReport={() => open("report")}
        onHistory={() =>
          source(
            "Battery replacement · MW-198",
            "Completed 3 Aug 2026 by Mara Ellis. Original evidence and prior component AS-104-B1 are retained.",
          )
        }
      />
    );
  }
  function documentSource(d: (typeof documentSeed)[number]) {
    if (d.source.startsWith("Original check"))
      open("check", { check: "CHK-882" });
    else if (d.source.startsWith("Maintenance")) openWork();
    else
      source(d.source, d.detail + "\nAdded by " + d.by + " on " + d.date + ".");
  }
  function documentActions(d: (typeof documentSeed)[number]) {
    return [
      {
        label: documentFile(d) ? "View document" : "Review file status",
        icon: FileText,
        onClick: () => open("document", { doc: d }),
      },
      ...(documentFile(d)
        ? [
            {
              label: "Download file",
              icon: Download,
              onClick: () => downloadDocument(d),
            },
          ]
        : []),
      {
        label: "Open original source",
        icon: ArrowUpRight,
        onClick: () => documentSource(d),
      },
      {
        label: "Version history",
        icon: History,
        onClick: () => open("documentHistory", { doc: d }),
      },
      ...(!readOnly &&
      d.state !== "Archived" &&
      d.source.startsWith("Asset document")
        ? [
            {
              label: "Replace version",
              icon: Upload,
              onClick: () => open("replace", { doc: d }),
            },
            {
              label: "Archive version",
              icon: Archive,
              onClick: () => open("archiveDoc", { doc: d }),
            },
          ]
        : []),
    ];
  }
  function documentsView() {
    return (
      <div className="vehicle-studio document-workspace studio-page record-workspace">
        <div className="studio-section-heading">
          <div>
            <span className="studio-eyebrow">ASSET RECORD · AS-104</span>
            <h2 className="text-section-title">Documents & evidence</h2>
            <p>Find the original, replace a version, or follow its source.</p>
          </div>
          <CollectionToggle
            label="Documents"
            view={docLayout}
            onChange={setDocLayout}
          />
          <Button disabled={readOnly} onClick={() => open("upload")}>
            <Upload size={16} />
            Upload document
          </Button>
        </div>
        <div className="document-summary">
          <div>
            <FileText size={19} />
            <span>
              <strong>
                {
                  docs.filter(
                    (d) =>
                      d.source.startsWith("Asset document") &&
                      d.state !== "Archived",
                  ).length
                }{" "}
                asset documents
              </strong>
              <small>Manuals, warranty and asset-owned files</small>
            </span>
            <button
              className="text-link"
              onClick={() => {
                setDocSource("asset");
                setDocAvailability("all");
                setDocScope("current");
                setSearch("");
              }}
            >
              View
            </button>
          </div>
          <div>
            <Link2 size={19} />
            <span>
              <strong>
                {
                  docs.filter(
                    (d) =>
                      !d.source.startsWith("Asset document") &&
                      d.state !== "Archived",
                  ).length
                }{" "}
                linked evidence records
              </strong>
              <small>Original checks and Maintenance</small>
            </span>
            <button
              className="text-link"
              onClick={() => {
                setDocSource("all");
                setDocAvailability("Unavailable");
                setDocScope("current");
                setSearch("");
              }}
            >
              Review unavailable
            </button>
          </div>
        </div>
        <div className="asset-document-filters">
          <div className="document-search">
            <Search size={16} />
            <Input
              aria-label="Search asset documents"
              placeholder="Search file, type, reference or source…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <label>
            <span className="sr-only">Document versions</span>
            <select
              aria-label="Document versions"
              value={docScope}
              onChange={(e) => setDocScope(e.target.value)}
            >
              <option value="current">Current versions</option>
              <option value="all">All history</option>
              <option value="photos">Photos only</option>
            </select>
          </label>
          <label>
            <span className="sr-only">Document source</span>
            <select
              aria-label="Document source"
              value={docSource}
              onChange={(e) => setDocSource(e.target.value)}
            >
              <option value="all">All sources</option>
              <option value="asset">Asset documents</option>
              <option value="check">Original checks</option>
              <option value="maintenance">Maintenance</option>
            </select>
          </label>
          <label>
            <span className="sr-only">File availability</span>
            <select
              aria-label="File availability"
              value={docAvailability}
              onChange={(e) => setDocAvailability(e.target.value)}
            >
              <option value="all">Any availability</option>
              {[
                "Available",
                "Unavailable",
                "Quarantined",
                "Pending verification",
                "Archived",
              ].map((x) => (
                <option key={x}>{x}</option>
              ))}
            </select>
          </label>
          {(search ||
            docScope !== "current" ||
            docSource !== "all" ||
            docAvailability !== "all") && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setSearch("");
                setDocScope("current");
                setDocSource("all");
                setDocAvailability("all");
              }}
            >
              Reset filters
            </Button>
          )}
        </div>
        <RecordCollection
          label="Document library"
          view={docLayout}
          total={docs.length}
          identityWidth="1.4fr"
          columns={[
            { label: "Version / added", width: "1fr" },
            { label: "Original source", width: "1.2fr" },
            { label: "Availability / action", width: "1fr" },
          ]}
          empty={{
            title: "No matching documents",
            description: "Try another name, source or version filter.",
            action: (
              <Button
                variant="outline"
                onClick={() => {
                  setSearch("");
                  setDocScope("current");
                  setDocSource("all");
                  setDocAvailability("all");
                }}
              >
                Clear filters
              </Button>
            ),
          }}
          records={docVisible.map((d) => ({
            id: d.id,
            name: d.name,
            subline: d.file,
            icon: d.type === "Photo" ? Image : FileText,
            tone: ["Unavailable", "Quarantined"].includes(d.state)
              ? "warning"
              : undefined,
            fields: [
              <React.Fragment key="version">
                <Badge tone={d.state === "Archived" ? "neutral" : "info"}>
                  {d.state === "Archived" ? "Archived" : "Current"} · v
                  {d.version}
                </Badge>
                <small>
                  {d.date} · {d.by}
                </small>
              </React.Fragment>,
              <React.Fragment key="source">
                <button className="text-link" onClick={() => documentSource(d)}>
                  {d.source}
                  <ArrowUpRight size={13} />
                </button>
                <small>
                  {d.source.startsWith("Asset document")
                    ? "Managed with this asset"
                    : "Kept with the original record"}
                </small>
              </React.Fragment>,
              <React.Fragment key="availability">
                <Badge
                  tone={
                    d.state === "Available"
                      ? "success"
                      : d.state === "Archived"
                        ? "neutral"
                        : "warning"
                  }
                >
                  {d.state}
                </Badge>
                <button
                  className="text-link"
                  onClick={() => open("document", { doc: d })}
                >
                  {documentFile(d) ? "View document" : "Review file status"}
                  <ArrowUpRight size={13} />
                </button>
                <DocumentDownload doc={d} compact />
              </React.Fragment>,
            ],
            onOpen: () => open("document", { doc: d }),
            actions: documentActions(d),
            footer: {
              primary: d.by,
              secondary: d.type + " · Version " + d.version,
            },
          }))}
        />
        <div className="document-footnotes">
          <span>
            <LockKeyhole size={15} />
            Private to permitted roles, approved sites and the original record.
          </span>
          <span>
            <History size={15} />
            Replacements retain earlier versions and submitted evidence.
          </span>
        </div>
      </div>
    );
  }

  function componentsView() {
    if (sub !== "componentHistory")
      return (
        <KitRecords
          reference={movement.reference}
          confirmed={kitChecked}
          readOnly={readOnly}
          onReceipt={() => open("receipt")}
          onSource={source}
          onHistory={() => go("components", "componentHistory")}
        />
      );
    return (
      <>
        <div className="section-heading">
          <div>
            <h2>
              {sub === "componentHistory"
                ? "Component replacement history"
                : "Components & removable kit"}
            </h2>
            <p>
              Each meaningful component keeps its own service history and
              custody evidence.
            </p>
          </div>
        </div>
        {sub === "componentHistory" ? (
          <Card title="Battery replacement" icon={History}>
            <div className="replacement">
              <div>
                <Badge>Prior component</Badge>
                <h3>Battery pack · AS-104-B1</h3>
                <p>Removed 3 Aug 2026</p>
                <TextLink
                  onClick={() =>
                    source(
                      "Battery AS-104-B1 · Historical component",
                      "Original service history: MW-144 (12 Feb 2026); MW-198 (3 Aug 2026). Removed from parent, retained in history.",
                    )
                  }
                >
                  View preserved history
                </TextLink>
              </div>
              <ArrowRight />
              <div>
                <Badge tone="info">Current component</Badge>
                <h3>Battery pack · AS-104-B2</h3>
                <p>Installed 3 Aug 2026</p>
                <TextLink
                  onClick={() =>
                    source(
                      "Battery AS-104-B2",
                      "Independent component record linked to parent AS-104. Maintenance source MW-198. Finance allocation requires the canonical fixed-asset record.",
                    )
                  }
                >
                  View component
                </TextLink>
              </div>
            </div>
          </Card>
        ) : (
          <>
            <Notice title="Charger receipt is unconfirmed">
              Moving the hoist does not prove all removable items arrived.
              Confirm each item at receipt or leave an owned discrepancy.
            </Notice>
            <Card>
              <div className="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th>Relationship</th>
                      <th>Custody / history</th>
                      <th>Finance treatment</th>
                      <th>
                        <span className="sr-only">Actions</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {[
                      [
                        "Hoist frame",
                        "AS-104",
                        "Parent asset",
                        "Included in dispatch",
                      ],
                      [
                        "Battery pack",
                        "AS-104-B2",
                        "Independently maintained component",
                        "Included in dispatch",
                      ],
                      [
                        "Charger",
                        "AS-104-K1",
                        "Removable kit item",
                        "Not confirmed",
                      ],
                    ].map(([a, b, c, d]) => (
                      <tr
                        key={b}
                        onContextMenu={(e) => {
                          e.preventDefault();
                          source(
                            a + " · " + b,
                            c +
                              "\n" +
                              d +
                              "\nNo movement of the parent attests this item.",
                          );
                        }}
                      >
                        <td>
                          <strong>{a}</strong>
                          <small>{b}</small>
                        </td>
                        <td>{c}</td>
                        <td>
                          <Badge
                            tone={d === "Not confirmed" ? "warning" : "neutral"}
                          >
                            {d}
                          </Badge>
                        </td>
                        <td>
                          Review source allocation
                          <br />
                          <small>No added purchase total</small>
                        </td>
                        <td>
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label={"Open " + a}
                            onClick={() =>
                              source(
                                a + " · " + b,
                                c +
                                  "\n" +
                                  d +
                                  "\nIndependent history and source-linked financial treatment.",
                              )
                            }
                          >
                            <MoreHorizontal />
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
            <Button
              variant="outline"
              disabled={readOnly}
              onClick={() => open("receipt")}
            >
              Review individual receipt
            </Button>
          </>
        )}
      </>
    );
  }
  const [financeSample, setFinanceSample] = useState("linked");
  const financeSession = useAssetFinanceState(financeSample, scenario);
  function financeView() {
    return (
      <AssetFinance
        sample={financeSample}
        session={financeSession}
        scenario={scenario}
        readOnly={readOnly}
        actor={actor}
        onWork={openWork}
        onDocuments={() => go("overview", "library")}
        onRetirement={() => go("lifecycle", "retirement")}
      />
    );
  }
  function retirementView() {
    return (
      <>
        <div className="section-heading">
          <div>
            <h2>Retirement & disposal review</h2>
            <p>
              Resolve dependencies and record separate decisions before changing
              the lifecycle.
            </p>
          </div>
          <Button disabled={readOnly} onClick={() => open("retire")}>
            <Archive size={16} />
            Start review
          </Button>
        </div>
        <Card title="Open dependencies" icon={CircleAlert}>
          <div className="dependency">
            <Badge tone="warning">Unresolved</Badge>
            <strong>Receipt / assignment</strong>
            <p>
              {movement.reference} is {custody.toLowerCase()}; {assignment}{" "}
              remains assigned.
            </p>
            <TextLink onClick={() => go("custody")}>Resolve custody</TextLink>
          </div>
          <div className="dependency">
            <Badge tone="warning">Unresolved</Badge>
            <strong>Maintenance & evidence</strong>
            <p>MW-271; service report unavailable; active restriction.</p>
            <TextLink onClick={openWork}>Open work</TextLink>
          </div>
          <div className="dependency">
            <Badge>Not applicable</Badge>
            <strong>Bookings</strong>
            <p>No reservation capability configured for this static asset.</p>
          </div>
          <div className="dependency">
            <Badge tone="warning">Decision needed</Badge>
            <strong>Finance disposal</strong>
            <p>
              FA-104 remains recognised. An authorised Finance decision is
              separate.
            </p>
            <TextLink onClick={() => context("finance")}>Open Finance</TextLink>
          </div>
        </Card>
        <Notice title="Historical records are retained" tone="info">
          Loss, write-off and disposal are distinct outcomes with reasons and
          authority. There is no hard-delete shortcut.
        </Notice>
      </>
    );
  }
  function body() {
    if (denied)
      return (
        <Card>
          <div className="empty-state">
            <LockKeyhole size={36} />
            <h2>This record is not available</h2>
            <p>
              You may not have access, or the record may no longer be available.
            </p>
            <Button variant="outline" onClick={() => context("register")}>
              Return to permitted Assets
            </Button>
          </div>
        </Card>
      );
    if (scenario === "vehicle")
      return (
        <Card title="Open the existing Vehicle Profile" icon={Truck}>
          <p>
            Vehicles use the same canonical Asset identity. Vehicle operations,
            calendar, evidence and Finance links stay in the existing vehicle
            workspace.
          </p>
          <Button className="mt-4" onClick={() => context("vehicle")}>
            View Vehicle Profile
            <ArrowUpRight size={16} />
          </Button>
        </Card>
      );
    if (
      empty &&
      !(group === "overview" && ["library", "identity"].includes(sub))
    )
      return (
        <Card>
          <div className="empty-state">
            <Package size={36} />
            <h2>
              No{" "}
              {group === "documents"
                ? "documents"
                : group === "maintenance"
                  ? "Maintenance history"
                  : "recorded activity"}{" "}
              yet
            </h2>
            <p>
              No source records have been supplied for this synthetic empty
              state. Missing evidence is not a pass.
            </p>
            <Button
              variant="outline"
              onClick={() => open(group === "documents" ? "upload" : "custody")}
            >
              {group === "documents" ? "Add document" : "Manage custody"}
            </Button>
          </div>
        </Card>
      );
    if (group === "overview")
      return sub === "summary"
        ? summary()
        : sub === "identity"
          ? detailsView()
          : sub === "library"
            ? documentsView()
            : financeView();
    if (group === "location") return locationView();
    if (group === "checks" || group === "maintenance") return maintenanceView();
    if (group === "custody") return custodyView();
    if (group === "components") return componentsView();
    return sub === "retirement" ? (
      retirementView()
    ) : (
      <Card title="Asset history" icon={History}>
        {historyRows()}
      </Card>
    );
  }

  const footerFailure = (
    <label className="preview-failure">
      <input
        type="checkbox"
        checked={failure}
        onChange={(e) => setFailure(e.target.checked)}
      />
      Fail this save (demo)
    </label>
  );
  function modalContent() {
    if (!modal) return null;
    const formTypes = [
      "custody",
      "edit",
      "upload",
      "replace",
      "receipt",
      "report",
      "retire",
    ];
    if (formTypes.includes(modal.type)) {
      const type = modal.type,
        isUpload = type === "upload" || type === "replace";
      const steps =
        type === "custody"
          ? ["Movement", "Recipient & kit", "Review"]
          : type === "edit"
            ? ["Identity", "Ownership & condition", "Review"]
            : isUpload
              ? ["Files & details", "Review"]
              : type === "receipt"
                ? ["Receipt & kit", "Review"]
                : type === "report"
                  ? ["Problem & evidence", "Review"]
                  : ["Dependencies", "Review"];
      const title =
        type === "custody"
          ? "Manage custody"
          : type === "edit"
            ? "Edit asset details"
            : type === "replace"
              ? "Replace document version"
              : type === "upload"
                ? modal.photo
                  ? "Upload asset profile photo"
                  : "Add private document"
                : type === "receipt"
                  ? "Review actual receipt"
                  : type === "report"
                    ? "Report a problem"
                    : "Retirement review";
      const review = step === steps.length - 1;
      const stepMeta: Record<string, { blurb: string; icon: any }> = {
        Movement: { blurb: "Type, destination and return", icon: Truck },
        "Recipient & kit": {
          blurb: "Person and dispatched items",
          icon: UserRound,
        },
        Identity: { blurb: "Name, serial and category", icon: Package },
        "Ownership & condition": {
          blurb: "Owner and recorded condition",
          icon: ShieldCheck,
        },
        "Files & details": {
          blurb: "File, title and classification",
          icon: Upload,
        },
        "Receipt & kit": {
          blurb: "Arrival, items and discrepancies",
          icon: ClipboardCheck,
        },
        "Problem & evidence": {
          blurb: "Observation and supporting files",
          icon: CircleAlert,
        },
        Dependencies: {
          blurb: "Outstanding decisions and owners",
          icon: Archive,
        },
        Review: {
          blurb: "Confirm the details and outcome",
          icon: CheckCircle2,
        },
      };
      function validate(all = false) {
        const problems: Array<{
          field: string;
          message: string;
          step: number;
        }> = [];
        const add = (field: string, message: string, at = 0) =>
          problems.push({ field, message, step: at });
        if (isUpload) {
          if (!files.length)
            add("Document file", "Choose one file before continuing.");
          if (!docTitle.trim())
            add("Document title", "Enter a title people can recognise.");
          if (type === "replace" && !reason.trim())
            add(
              "Reference / version reason",
              "Explain why this version replaces the original.",
            );
          if (modal.photo && files.some((f) => !f.type.startsWith("image/")))
            add("Document file", "Choose an image for the asset photo.");
        }
        if (type === "edit") {
          if (!editDraft.name.trim())
            add("Asset name", "Enter the asset name.");
          if (!editDraft.serial.trim())
            add("Serial number", "Enter the serial required by this sample.");
        }
        if (type === "report") {
          if (reportMode === "new" && !reportTitle.trim())
            add("Problem summary", "Give this new issue a short summary.");
          if (!reason.trim())
            add("What happened?", "Describe what you observed.");
        }
        if (type === "custody") {
          if (mode === "Loan" && hold)
            add(
              "Movement",
              "Loan for use is blocked while the asset hold is active.",
            );
          if (!person.includes(destination.split(" · ")[0]))
            add(
              "Responsible recipient",
              "Choose a recipient permitted at the destination site.",
              1,
            );
        }
        if (type === "receipt") {
          if (
            receiptOutcome === "Confirm complete receipt" &&
            kitDraft.some((x) => !x)
          )
            add(
              "Kit items",
              "Confirm every item, or choose a discrepancy or dispute.",
            );
          if (receiptOutcome !== "Confirm complete receipt" && !reason.trim())
            add(
              "Receipt notes / discrepancy",
              "Describe the discrepancy or dispute.",
            );
          if (actor !== movement.recipient)
            add(
              "Receiving person",
              "This receipt must be recorded by " +
                movement.recipient +
                " in this sample.",
            );
        }
        const relevant = problems.filter((p) => all || p.step === step);
        setFieldErrors(
          Object.fromEntries(relevant.map((p) => [p.field, p.message])),
        );
        if (relevant.length) {
          setStep(relevant[0].step);
          setError(
            "Before continuing: " + relevant.map((p) => p.message).join(" "),
          );
          return false;
        }
        setError("");
        return true;
      }
      function advance() {
        if (!busy && validate())
          setStep((s) => Math.min(s + 1, steps.length - 1));
      }
      function submit() {
        if (busy || success || !validate(true)) return;
        if (type === "retire") {
          setError(
            "Retirement is blocked: resolve receipt/assignment, Maintenance evidence and the separate Finance decision. No lifecycle or financial change has been made.",
          );
          return;
        }
        if (type === "custody") {
          if (!person.includes(destination.split(" · ")[0])) {
            setError(
              "The selected recipient is outside the destination site for this sample authority. Choose a permitted recipient; no custody change was made.",
            );
            return;
          }
          if (scenario === "stale") {
            setError(
              "Assignment changed after you opened this form. Latest owner: Mara Ellis, revision 9. Your draft is retained. Reload current custody before submitting.",
            );
            return;
          }
          if (
            ["Awaiting receipt", "Incomplete receipt", "Disputed"].includes(
              custody,
            )
          ) {
            setError(
              movement.reference +
                " has an unresolved receipt. Resolve that movement before starting another.",
            );
            return;
          }
          if (mode === "Assign" && assignment !== "Unassigned") {
            setError(
              "Release the current assignment before assigning this asset again. Original assignment history must be retained.",
            );
            return;
          }
          mutate(
            () => {
              if (mode === "Assign") setAssignment(person.split(" · ")[0]);
              else {
                setCustody("Awaiting receipt");
                setKitChecked([false, false, false]);
                setReceiptSaved("");
                setMovement({
                  reference: "TR-104-09",
                  origin: currentLocation,
                  destination,
                  recipient: person.split(" · ")[0],
                  kind: mode,
                  expected:
                    mode === "Loan" ? formatDateOnly(date) : "Not a loan",
                });
                setCurrentLocation(destination);
              }
            },
            mode === "Assign"
              ? "Assignment recorded in this preview."
              : mode +
                  " dispatched from " +
                  currentLocation +
                  " to " +
                  destination +
                  " for " +
                  person +
                  ". Expected return: " +
                  (mode === "Loan" ? formatDateOnly(date) : "not a loan") +
                  ". Receipt remains pending.",
          );
          return;
        }
        if (type === "receipt") {
          if (actor !== movement.recipient) {
            setError(
              "Receipt must be acknowledged by the intended recipient in this sample. Switch the preview actor to " +
                movement.recipient +
                " to review their receipt. No custody change was made.",
            );
            return;
          }
          if (
            receiptOutcome === "Confirm complete receipt" &&
            kitDraft.some((x) => !x)
          ) {
            setError(
              "Confirm each kit item or record an incomplete / disputed receipt. The missing charger cannot be silently accepted.",
            );
            return;
          }
          if (receiptOutcome !== "Confirm complete receipt" && !reason.trim()) {
            setError(
              "Describe the discrepancy or dispute so the responsible person can resolve it.",
            );
            return;
          }
          const completeReceipt =
            receiptOutcome === "Confirm complete receipt" &&
            kitDraft.every(Boolean);
          const receiptKey = JSON.stringify([
            movement.reference,
            receiptOutcome,
            kitDraft,
            reason,
          ]);
          if (receiptSaved === receiptKey) {
            setSuccess(
              "Existing receipt returned. No duplicate custody event.",
            );
            return;
          }
          mutate(
            () => {
              setKitChecked([...kitDraft]);
              setReceiptSaved(receiptKey);
              if (receiptOutcome === "Dispute receipt") {
                setCustody("Disputed");
              } else if (completeReceipt) {
                setCustody("Acknowledged");
                setConfirmedBy(actor);
              } else {
                setCustody("Incomplete receipt");
              }
            },
            receiptOutcome === "Dispute receipt"
              ? "Receipt disputed. " +
                  confirmedBy +
                  " retains responsibility pending resolution."
              : completeReceipt
                ? "Receipt acknowledged by " +
                  actor +
                  " in this preview. Any separate hold remains."
                : "Receipt discrepancy recorded. " +
                  confirmedBy +
                  " retains responsibility pending resolution.",
          );
          return;
        }
        if (isUpload) {
          if (busy || success) return;
          if (failure) {
            setUploadState("Failed");
            setError(
              "Simulated upload failed. The selected files and metadata are retained. Retry only this unresolved upload.",
            );
            return;
          }
          setBusy(true);
          setUploadState("Simulated progress · 35%");
          setTimeout(() => setUploadState("Simulated progress · 80%"), 300);
          setTimeout(() => {
            if (modal.photo) {
              if (photoUrl) URL.revokeObjectURL(photoUrl);
              setPhotoUrl(URL.createObjectURL(files[0]));
            }
            setUploadState("Simulation complete · Not stored or scanned");
            setBusy(false);
            setDirty(false);
            setSuccess(
              modal.photo
                ? "Profile photo updated in this preview only. No image was uploaded or scanned."
                : "Document version staged in this preview only. No file was uploaded or scanned.",
            );
            setDocs((ds) => [
              ...ds.map((d) =>
                type === "replace" && d.id === modal.doc.id
                  ? {
                      ...d,
                      state: "Archived",
                      detail:
                        d.detail +
                        " Replaced by version " +
                        (d.version + 1) +
                        "; original retained. Reason: " +
                        (reason || "New document version"),
                    }
                  : d,
              ),
              {
                id: "DEMO-" + ds.length,
                name: docTitle,
                file: files[0].name,
                type:
                  type === "replace"
                    ? modal.doc.type
                    : modal.photo
                      ? "Photo"
                      : docType,
                version: type === "replace" ? modal.doc.version + 1 : 1,
                date: "26 Sep 2026",
                by: actor,
                state: "Pending verification",
                source:
                  type === "replace"
                    ? modal.doc.source
                    : "Asset document set · DS-DEMO-" + ds.length,
                detail:
                  "Local preview metadata. File bytes are not uploaded, scanned or persisted." +
                  (type === "replace"
                    ? " Replaces " +
                      modal.doc.file +
                      " v" +
                      modal.doc.version +
                      ". Reason: " +
                      reason
                    : "") +
                  " Recorded by " +
                  actor +
                  " · " +
                  NOW,
              },
            ]);
          }, 700);
          return;
        }
        if (type === "report") {
          if (reportMode === "new" && !reportTitle.trim()) {
            setError(
              "Enter a short problem summary before recording this new issue.",
            );
            return;
          }
          const reference =
            reportMode === "existing"
              ? "MW-271"
              : "MW-DEMO-" + (272 + newReports.length);
          mutate(
            () => {
              if (reportMode === "existing") {
                setIssueLinked(true);
                setNotes((ns) => [...ns, actor + " · " + NOW + " — " + reason]);
              } else {
                setNewReports((rs) => [
                  ...rs,
                  {
                    reference,
                    title: reportTitle,
                    description: reason,
                    by: actor,
                    files: files.map((f) => f.name),
                  },
                ]);
              }
            },
            reportMode === "existing"
              ? "Follow-up linked to MW-271 in this preview. Original check retained."
              : reference +
                  " created in this preview. Receipt, safety release and Finance remain separate.",
          );
          return;
        }
        mutate(() => {
          setName(editDraft.name);
          setSerial(editDraft.serial);
          setCondition(editDraft.condition);
          setOwnership(editDraft.ownership);
          setCategory(editDraft.category);
        }, "Asset details saved in this preview. Custody, holds and Finance were not changed.");
      }
      const stage = (fs: File[]) => {
        const good: File[] = [];
        let rejected = "";
        fs.forEach((f) => {
          if (
            (modal.photo && !f.type.startsWith("image/")) ||
            f.size > 20 * 1024 * 1024 ||
            !/\.(pdf|doc|docx|xls|xlsx|csv|jpg|jpeg|png|gif|txt|rtf)$/i.test(
              f.name,
            )
          )
            rejected =
              "That file type or size is not supported. Existing valid selections are retained.";
          else good.push(f);
        });
        if (files.length + good.length > 1) {
          setError(
            "One file per document version. Remove the current staged file before choosing another.",
          );
          return;
        }
        if (good.length) {
          setFiles((f) => [...f, ...good]);
          setDirty(true);
        }
        setError(rejected);
      };
      return (
        <WizardShell
          key={modal.type}
          open
          maxWidth="min(92vw, 1100px)"
          maxHeight="min(88vh, 790px)"
          onClose={close}
          title={title}
          description="Synthetic design preview. No operational data is changed."
          railIcon={
            isUpload
              ? Upload
              : type === "edit"
                ? Package
                : type === "report"
                  ? CircleAlert
                  : type === "retire"
                    ? Archive
                    : type === "receipt"
                      ? ClipboardCheck
                      : Truck
          }
          railTitle={title}
          railSub={"AS-104 · " + name}
          steps={steps.map((label) => ({
            key: label,
            label,
            ...stepMeta[label],
            disabled: busy,
          }))}
          stepIndex={step}
          onStepClick={(i) => {
            if (!busy) {
              setStep(i);
              setError("");
              setFieldErrors({});
            }
          }}
          pct={null}
          railExtra={
            <div className="modal-rail-context">
              <LockKeyhole size={14} />
              <p>
                Private asset record
                <br />
                <strong>AS-104 · {name}</strong>
              </p>
              <small>
                Selections stay in this tab until you close or refresh.
              </small>
              <details className="modal-preview-tools">
                <summary>Preview testing</summary>
                {footerFailure}
              </details>
            </div>
          }
          footerStart={
            <>
              <Button variant="outline" disabled={busy} onClick={close}>
                {modalTrail.length ? "Back to record" : "Cancel"}
              </Button>
              {step > 0 && (
                <Button
                  variant="ghost"
                  disabled={busy}
                  onClick={() => {
                    setStep((s) => s - 1);
                    setError("");
                    setFieldErrors({});
                  }}
                >
                  Back
                </Button>
              )}
            </>
          }
          footerEnd={
            <>
              <Button
                disabled={busy || readOnly}
                onClick={review ? submit : advance}
              >
                {busy && <Loader2 size={15} className="animate-spin" />}
                {busy
                  ? "Saving preview…"
                  : review
                    ? type === "retire"
                      ? "Check retirement decision"
                      : isUpload
                        ? uploadState === "Failed"
                          ? "Retry simulated upload"
                          : "Simulate upload"
                        : type === "receipt"
                          ? "Record receipt"
                          : type === "report"
                            ? reportMode === "new"
                              ? "Create issue in preview"
                              : "Link follow-up to MW-271"
                            : type === "edit"
                              ? "Save asset details"
                              : "Record " + mode.toLowerCase()
                    : step === steps.length - 2
                      ? "Review details"
                      : "Continue"}
                <ArrowRight size={15} />
              </Button>
            </>
          }
          success={
            success ? (
              <WizardSuccessPane
                title={isUpload ? "Preview complete" : "Outcome recorded"}
                blurb={success}
                actions={
                  <Button
                    onClick={() => {
                      leaveModal();
                      say(success);
                    }}
                  >
                    {modalTrail.length ? "Back to record" : "Return to asset"}
                  </Button>
                }
              />
            ) : undefined
          }
        >
          <WizardStepPane>
            <fieldset className="form-stack modal-form" disabled={busy}>
              <div className="locked-context">
                <Package size={20} />
                <div>
                  <strong>{name}</strong>
                  <small>AS-104 · {currentLocation}</small>
                </div>
                <LockKeyhole size={16} />
              </div>
              {error && (
                <div
                  className="form-error modal-error-summary"
                  role="alert"
                  tabIndex={-1}
                >
                  {error}
                  {scenario === "stale" && (
                    <Button
                      variant="outline"
                      onClick={() => {
                        setScenario("available");
                        setCustody("Acknowledged");
                        setError(
                          "Latest custody loaded. Review your retained draft before saving.",
                        );
                      }}
                    >
                      Reload current custody
                    </Button>
                  )}
                </div>
              )}
              {review ? (
                <>
                  <h2 className="text-section-title">
                    {type === "custody"
                      ? "Review " + mode.toLowerCase()
                      : type === "edit"
                        ? "Review asset changes"
                        : type === "report"
                          ? "Review problem report"
                          : type === "receipt"
                            ? "Review receipt"
                            : type === "retire"
                              ? "Review retirement dependencies"
                              : modal.photo
                                ? "Review profile photo"
                                : type === "replace"
                                  ? "Review new version"
                                  : "Review document"}
                  </h2>
                  {isUpload && busy && (
                    <div role="status" className="inset">
                      <strong>{uploadState}</strong>
                      <p>Demonstration only · No file bytes are sent.</p>
                      <progress
                        aria-label="Simulated upload progress"
                        value={uploadState.includes("80") ? 80 : 35}
                        max={100}
                        className="w-full mt-2"
                      />
                    </div>
                  )}
                  <ReviewCard
                    icon={Package}
                    title="Asset & source"
                    onEdit={() => setStep(0)}
                  >
                    <ReviewRow
                      label="Asset"
                      value={
                        (type === "edit" ? editDraft.name : name) + " · AS-104"
                      }
                    />
                    <ReviewRow label="Site" value={currentLocation} />
                    <ReviewRow
                      label="Actor / time"
                      value={actor + " · " + NOW}
                    />
                    {type === "custody" && (
                      <>
                        <ReviewRow label="Movement" value={mode} />
                        <ReviewRow label="Destination" value={destination} />
                        <ReviewRow label="Recipient" value={person} />
                        <ReviewRow
                          label="Kit at dispatch"
                          value={kitDraft
                            .map(
                              (yes, i) =>
                                (yes ? "Included: " : "Not confirmed: ") +
                                ["Hoist frame", "Battery pack", "Charger"][i],
                            )
                            .join(" · ")}
                        />
                        <ReviewRow
                          label="Expected return"
                          value={
                            mode === "Loan"
                              ? formatDateOnly(date)
                              : "Not a loan"
                          }
                        />
                      </>
                    )}
                    {type === "receipt" && (
                      <>
                        <ReviewRow label="Outcome" value={receiptOutcome} />
                        <ReviewRow
                          label="Kit confirmed"
                          value={`${kitDraft.filter(Boolean).length} of 3`}
                        />
                      </>
                    )}
                    {isUpload && (
                      <>
                        <ReviewRow label="Title" value={docTitle} />
                        <ReviewRow
                          label="Files staged"
                          value={files.map((f) => f.name).join(", ")}
                        />
                        <ReviewRow label="Classification" value={docType} />
                        {type === "replace" && (
                          <ReviewRow
                            label="Replaces"
                            value={
                              modal.doc.file +
                              " · v" +
                              modal.doc.version +
                              " → v" +
                              (modal.doc.version + 1)
                            }
                          />
                        )}
                        {type === "replace" && (
                          <ReviewRow
                            label="Original source"
                            value={modal.doc.source}
                          />
                        )}
                        <ReviewRow
                          label="Storage"
                          value="Simulation only · No upload or scan"
                        />
                      </>
                    )}
                    {type === "edit" && (
                      <>
                        <ReviewRow label="Serial" value={editDraft.serial} />
                        <ReviewRow
                          label="Category"
                          value={editDraft.category}
                        />
                        <ReviewRow
                          label="Ownership"
                          value={editDraft.ownership}
                        />
                        <ReviewRow
                          label="Condition"
                          value={editDraft.condition}
                        />
                      </>
                    )}
                    {type === "report" && (
                      <>
                        <ReviewRow
                          label="Recording"
                          value={
                            reportMode === "new"
                              ? "New issue · " + reportTitle
                              : "Follow-up to brake assessment"
                          }
                        />
                        <ReviewRow label="Problem" value={reason} />
                        <ReviewRow
                          label="Evidence"
                          value={
                            files.length
                              ? files.map((f) => f.name).join(", ")
                              : "No files attached"
                          }
                        />
                        <ReviewRow
                          label="Destination"
                          value={
                            reportMode === "new"
                              ? "New Maintenance work record"
                              : "Brake assessment · MW-271"
                          }
                        />
                      </>
                    )}
                    {reason && type !== "report" && (
                      <ReviewRow label="Reason / note" value={reason} />
                    )}
                  </ReviewCard>
                  <Notice
                    title={
                      type === "retire"
                        ? "Unresolved dependencies block retirement"
                        : "Keep the decisions separate"
                    }
                    tone={type === "retire" ? "warning" : "info"}
                  >
                    {isUpload
                      ? "The preview demonstrates file states. It does not persist or scan your files. Original versions and source evidence are retained."
                      : type === "retire"
                        ? "Outstanding assignment, pending receipt, work/evidence and Finance disposal require separate resolution."
                        : "A custody or work update does not release the hold or approve a financial transaction."}
                  </Notice>
                </>
              ) : type === "custody" ? (
                step === 0 ? (
                  <>
                    <h2 className="text-section-title">
                      What needs to change?
                    </h2>
                    <div className="choice-grid">
                      {["Assign", "Transfer", "Loan", "Return"].map((x) => (
                        <button
                          key={x}
                          className={"choice " + (mode === x ? "selected" : "")}
                          onClick={() => {
                            setMode(x);
                            setDirty(true);
                          }}
                          aria-pressed={mode === x}
                        >
                          {x === "Assign" ? (
                            <UserRound size={19} />
                          ) : x === "Return" ? (
                            <RotateCcw size={19} />
                          ) : x === "Loan" ? (
                            <Clock3 size={19} />
                          ) : (
                            <Truck size={19} />
                          )}
                          <strong>{x}</strong>
                          <small>
                            {x === "Assign"
                              ? "Accountable person"
                              : x === "Transfer"
                                ? "Site or room movement"
                                : x === "Loan"
                                  ? "Temporary issue"
                                  : "Actual return handoff"}
                          </small>
                        </button>
                      ))}
                    </div>
                    {[
                      "Awaiting receipt",
                      "Incomplete receipt",
                      "Disputed",
                    ].includes(custody) && (
                      <Notice title="An earlier movement is still open">
                        Resolve {movement.reference} before starting another
                        movement. You can inspect this form without changing
                        custody.
                      </Notice>
                    )}
                    <Picker
                      label="Destination"
                      options={[
                        "Kōwhai House · Equipment room",
                        "Rimu House · Equipment store",
                      ]}
                      value={destination}
                      onChange={(s) => {
                        setDestination(s);
                        setDirty(true);
                      }}
                    />
                    <p className="muted small">
                      Only approved sites and their canonical rooms are
                      selectable.
                    </p>
                    {mode === "Loan" && (
                      <DatePicker
                        value={date}
                        onChange={(d) => {
                          setDate(d);
                          setDirty(true);
                        }}
                      />
                    )}
                    <Field label="Purpose / reason">
                      <textarea
                        value={reason}
                        onChange={(e) => {
                          setReason(e.target.value);
                          setDirty(true);
                        }}
                        placeholder="Explain this movement"
                      />
                    </Field>
                  </>
                ) : (
                  <>
                    <Picker
                      label="Responsible recipient"
                      options={[
                        "Nia Patel · Kōwhai House",
                        "Mara Ellis · Rimu House",
                      ]}
                      value={person}
                      onChange={(s) => {
                        setPerson(s);
                        setDirty(true);
                      }}
                    />
                    <Notice
                      title="Responsibility remains with the current custodian"
                      tone="info"
                    >
                      A selected recipient is not an acknowledgement. Record
                      actual receipt separately.
                    </Notice>
                    <h3>Kit to include</h3>
                    {kitInputs()}
                    <Button
                      variant="outline"
                      onClick={() => open("releaseAssignment")}
                    >
                      Release existing assignment (demo)
                    </Button>
                  </>
                )
              ) : type === "receipt" ? (
                <>
                  <h2 className="text-section-title">What actually arrived?</h2>
                  <div className="inset">
                    <strong>
                      {movement.reference} · {movement.origin} →{" "}
                      {movement.destination}
                    </strong>
                    <p>Dispatched by Mara Ellis · 25 Sep, 2:35 pm NZST</p>
                    <p>
                      Receiving actor: {actor} · {NOW}
                    </p>
                  </div>
                  {kitInputs()}
                  <Field label="Receipt outcome">
                    <select
                      value={receiptOutcome}
                      onChange={(e) => {
                        setReceiptOutcome(e.target.value);
                        setDirty(true);
                      }}
                    >
                      {[
                        "Confirm complete receipt",
                        "Accept with discrepancy",
                        "Dispute receipt",
                      ].map((x) => (
                        <option key={x}>{x}</option>
                      ))}
                    </select>
                  </Field>
                  <Field
                    label="Receipt notes / discrepancy"
                    required={receiptOutcome !== "Confirm complete receipt"}
                  >
                    <textarea
                      value={reason}
                      onChange={(e) => {
                        setReason(e.target.value);
                        setDirty(true);
                      }}
                      placeholder="Describe missing items, damage or a wrong destination"
                    />
                  </Field>
                  <Notice title="Unconfirmed items stay open" tone="info">
                    Only a complete acknowledgement changes confirmed custody in
                    this example. Disputed or incomplete outcomes retain
                    accountable resolution.
                  </Notice>
                </>
              ) : isUpload ? (
                <>
                  <h2 className="text-section-title">
                    {type === "replace"
                      ? "New version, original retained"
                      : modal.photo
                        ? "Choose the asset photo"
                        : "Add a private file"}
                  </h2>
                  {type === "replace" && (
                    <div className="replacement-context">
                      <FileText size={20} />
                      <div>
                        <strong>
                          {modal.doc.name} · v{modal.doc.version} → v
                          {modal.doc.version + 1}
                        </strong>
                        <small>{modal.doc.file}</small>
                        <small>{modal.doc.source} · Original retained</small>
                      </div>
                    </div>
                  )}
                  <div data-field-name="Document file">
                    <FileDropzone
                      id="document-upload"
                      disabled={busy}
                      aria-invalid={!!fieldErrors["Document file"]}
                      aria-describedby={
                        fieldErrors["Document file"]
                          ? "document-file-error"
                          : undefined
                      }
                      onFiles={stage}
                      multiple={false}
                      accept={
                        modal.photo
                          ? ".jpg,.jpeg,.png,.gif"
                          : ".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.gif,.txt,.rtf"
                      }
                      title={
                        modal.photo
                          ? "Choose an asset profile photo"
                          : "Drop documents or photos here"
                      }
                      hint={
                        modal.photo
                          ? "One image · Up to 20 MB · Local preview only"
                          : "One file per version · Up to 20 MB · PDF, Office, CSV, images or text · Preview only"
                      }
                    />
                    {fieldErrors["Document file"] && (
                      <p className="field-error">
                        {fieldErrors["Document file"]}
                      </p>
                    )}
                  </div>
                  {files.map((f, i) => (
                    <StagedFileCard
                      key={i}
                      file={f}
                      onRemove={() =>
                        setFiles((fs) => fs.filter((_, j) => i !== j))
                      }
                    >
                      <span className="small">{uploadState}</span>
                    </StagedFileCard>
                  ))}
                  <Field label="Document title" required>
                    <input
                      value={docTitle}
                      onChange={(e) => {
                        setDocTitle(e.target.value);
                        setDirty(true);
                      }}
                    />
                  </Field>
                  <Picker
                    label="Document classification"
                    options={
                      modal.photo
                        ? ["Photo"]
                        : type === "replace"
                          ? [modal.doc.type]
                          : [
                              "Manual",
                              "Warranty",
                              "Service",
                              "Calibration",
                              "Photo",
                              "Other evidence",
                            ]
                    }
                    value={docType}
                    onChange={(v) => {
                      setDocType(v);
                      setDirty(true);
                    }}
                  />
                  <Field
                    label="Reference / version reason"
                    required={type === "replace"}
                    hint={
                      type === "replace"
                        ? "Earlier versions remain in the original document history."
                        : "Optional source reference or note."
                    }
                  >
                    <input
                      value={reason}
                      onChange={(e) => {
                        setReason(e.target.value);
                        setDirty(true);
                      }}
                    />
                  </Field>
                  <p className="muted small">
                    This browser tab holds your selections temporarily.
                    Refreshing loses them. File bytes are never sent to a
                    server.
                  </p>
                </>
              ) : type === "edit" ? (
                step === 0 ? (
                  <>
                    <h2 className="text-section-title">Asset identity</h2>
                    <Field label="Asset name" required>
                      <input
                        value={editDraft.name}
                        onChange={(e) => {
                          setEditDraft((d) => ({ ...d, name: e.target.value }));
                          setDirty(true);
                        }}
                      />
                    </Field>
                    <Field label="Serial number" required>
                      <input
                        value={editDraft.serial}
                        onChange={(e) => {
                          setEditDraft((d) => ({
                            ...d,
                            serial: e.target.value,
                          }));
                          setDirty(true);
                        }}
                      />
                    </Field>
                    <Field label="Asset tag">
                      <input value="AS-104" readOnly />
                    </Field>
                    <Picker
                      label="Category"
                      options={[
                        "Transfer equipment",
                        "Mobility equipment",
                        "Household equipment",
                      ]}
                      value={editDraft.category}
                      onChange={(v) => {
                        setEditDraft((d) => ({ ...d, category: v }));
                        setDirty(true);
                      }}
                    />
                  </>
                ) : (
                  <>
                    <Picker
                      label="Ownership"
                      options={[
                        "Organisation-owned · Shared equipment",
                        "Client-owned · Owner restricted",
                        "Leased equipment",
                      ]}
                      value={editDraft.ownership}
                      onChange={(v) => {
                        setEditDraft((d) => ({ ...d, ownership: v }));
                        setDirty(true);
                      }}
                    />
                    <Field label="Recorded condition">
                      <select
                        value={editDraft.condition}
                        onChange={(e) => {
                          setEditDraft((d) => ({
                            ...d,
                            condition: e.target.value,
                          }));
                          setDirty(true);
                        }}
                      >
                        <option>Needs assessment</option>
                        <option>Recorded as good</option>
                        <option>Damaged</option>
                        <option>Not recorded</option>
                      </select>
                    </Field>
                    <Notice
                      title="Use custody actions for movement"
                      tone="info"
                    >
                      Changing descriptive fields cannot acknowledge a handoff,
                      release a hold or change Finance recognition.
                    </Notice>
                  </>
                )
              ) : type === "report" ? (
                <>
                  <h2 className="text-section-title">Describe the problem</h2>
                  <div
                    className="choice-grid"
                    role="group"
                    aria-label="Record this as"
                  >
                    {[
                      {
                        value: "new",
                        label: "New issue",
                        detail: "Create a separate Maintenance record",
                        icon: CircleAlert,
                      },
                      {
                        value: "existing",
                        label: "Follow-up to MW-271",
                        detail: "Add evidence to the brake assessment",
                        icon: MessageSquare,
                      },
                    ].map(({ value, label, detail, icon: Icon }) => (
                      <button
                        key={value}
                        className={
                          "choice " + (reportMode === value ? "selected" : "")
                        }
                        aria-pressed={reportMode === value}
                        onClick={() => {
                          setReportMode(value);
                          setDirty(true);
                        }}
                      >
                        <Icon size={19} />
                        <strong>{label}</strong>
                        <small>{detail}</small>
                      </button>
                    ))}
                  </div>
                  {reportMode === "new" && (
                    <Field label="Problem summary" required>
                      <input
                        value={reportTitle}
                        onChange={(e) => {
                          setReportTitle(e.target.value);
                          setDirty(true);
                        }}
                        placeholder="Briefly name the observed problem"
                      />
                    </Field>
                  )}
                  <Field label="What happened?" required>
                    <textarea
                      value={reason}
                      onChange={(e) => {
                        setReason(e.target.value);
                        setDirty(true);
                      }}
                      placeholder="Describe what you observed. Do not infer a diagnosis."
                    />
                  </Field>
                  <Notice title="Existing work to consider" tone="info">
                    Brake assessment · MW-271 · Mara Ellis. Choose follow-up
                    only when your observation belongs to this same issue.
                  </Notice>
                  <FileDropzone
                    onFiles={stage}
                    title="Add supporting evidence"
                    hint="20 MB per file · Staged locally in this preview"
                  />
                  {files.map((file, index) => (
                    <StagedFileCard
                      key={index}
                      file={file}
                      onRemove={() =>
                        setFiles((fs) => fs.filter((_, i) => i !== index))
                      }
                    >
                      <span className="small">
                        Staged locally · Not uploaded
                      </span>
                    </StagedFileCard>
                  ))}
                  <p className="muted small">
                    Original submissions keep their wording and evidence. Review
                    whether this observation is a new issue or a follow-up.
                  </p>
                </>
              ) : (
                <>
                  <h2 className="text-section-title">
                    Review dependencies first
                  </h2>
                  <Notice title="Retirement cannot be completed">
                    Receipt / assignment, Maintenance evidence and Finance
                    disposal are unresolved.
                  </Notice>
                  <Field label="Proposed outcome">
                    <select>
                      <option>Retirement</option>
                      <option>Loss</option>
                      <option>Write-off</option>
                      <option>Disposal</option>
                    </select>
                  </Field>
                  <Field label="Reason">
                    <textarea
                      value={reason}
                      onChange={(e) => {
                        setReason(e.target.value);
                        setDirty(true);
                      }}
                    />
                  </Field>
                  <div className="inset">
                    <strong>Authority remains unconfigured</strong>
                    <p>
                      Operating and financial approval must be explicit. This
                      review does not archive, delete or write off a record.
                    </p>
                  </div>
                </>
              )}
            </fieldset>
          </WizardStepPane>
        </WizardShell>
      );
    }
    const detailTitle =
      modal.type === "work"
        ? "Brake assessment · MW-271"
        : modal.type === "check"
          ? "Original check · " + modal.check
          : modal.type === "documentHistory"
            ? "Version history · " + modal.doc.name
            : modal.type === "document"
              ? modal.doc.name
              : modal.type === "context"
                ? contextNames[modal.kind]
                : modal.type === "exception"
                  ? "Record custody exception"
                  : modal.type === "departure"
                    ? "Resolve an outstanding loan"
                    : modal.type === "archiveDoc"
                      ? "Archive document version"
                      : modal.type === "releaseAssignment"
                        ? "Release current assignment"
                        : modal.type === "completion"
                          ? modal.intent === "Cancelled"
                            ? "Cancellation review"
                            : "Completion review"
                          : modal.title || "Source record";
    const detailIcon =
      modal.type === "work" || modal.type === "completion"
        ? Wrench
        : ["document", "documentHistory"].includes(modal.type)
          ? FileText
          : modal.type === "check"
            ? ClipboardCheck
            : modal.type === "archiveDoc"
              ? Archive
              : modal.type === "exception" || modal.type === "departure"
                ? CircleAlert
                : modal.type === "releaseAssignment"
                  ? UserRound
                  : Link2;
    const documentHistoryAction =
      modal.type === "document" ? (
        <>
          <Button
            variant="outline"
            onClick={() => open("documentHistory", { doc: modal.doc })}
          >
            Version history
          </Button>
          <DocumentDownload doc={modal.doc} />
        </>
      ) : null;
    const detailActions =
      modal.type === "work" ? (
        <Button
          variant="default"
          disabled={readOnly}
          onClick={() => {
            if (failure) {
              setError(
                "Progress save failed. Your draft is retained; the prior authoritative state remains " +
                  progress +
                  ".",
              );
              return;
            }
            setProgress(draftProgress);
            setNextAction(draftAction);
            setDirty(!!note.trim());
            setError("");
            say("Work details saved in preview. Asset hold unchanged.");
          }}
        >
          Save work details
        </Button>
      ) : modal.type === "releaseAssignment" ? (
        <Button
          disabled={busy || readOnly}
          onClick={() =>
            mutate(
              () => setAssignment("Unassigned"),
              "Assignment released. Earlier responsibility remains in history; physical receipt is recorded separately.",
            )
          }
        >
          {busy ? "Recording…" : "Release assignment"}
        </Button>
      ) : modal.type === "archiveDoc" ? (
        <Button
          disabled={!reason.trim()}
          onClick={() => {
            if (readOnly) {
              setError("You have read-only access. No version was archived.");
              return;
            }
            if (failure) {
              setError(
                "Archive failed in this simulation. The current version and your reason are retained; retry when ready.",
              );
              return;
            }
            setDocs((ds) =>
              ds.map((d) =>
                d.id === modal.doc.id
                  ? {
                      ...d,
                      state: "Archived",
                      detail:
                        d.detail +
                        " Archived by " +
                        actor +
                        " · " +
                        NOW +
                        ". Archive reason: " +
                        reason,
                    }
                  : d,
              ),
            );
            setDirty(false);
            setEvents((es) => [
              "Document archived · " +
                actor +
                " · " +
                NOW +
                " · " +
                modal.doc.id +
                " · " +
                reason,
              ...es,
            ]);
            setSuccess(
              "Version archived in this preview. The original remains in document history.",
            );
            say("Version archived in preview. Original evidence retained.");
          }}
        >
          Archive version
        </Button>
      ) : modal.type === "exception" || modal.type === "departure" ? (
        <Button
          disabled={busy || readOnly || !reason.trim()}
          onClick={() =>
            mutate(
              () => setCustody("Disputed"),
              "Custody exception · " +
                exceptionType +
                " · " +
                reason +
                ". Current custodian retained pending resolution.",
            )
          }
        >
          {busy ? "Recording…" : "Record exception"}
        </Button>
      ) : (
        documentHistoryAction
      );
    return (
      <ModalFrame
        key={modal.type}
        title={detailTitle}
        description={
          denied
            ? "Permitted workspace context · No asset details disclosed"
            : "AS-104 · " + name + " · Private record"
        }
        icon={detailIcon}
        width={
          modal.type === "work"
            ? 1100
            : modal.type === "document"
              ? 900
              : ["archiveDoc", "releaseAssignment", "completion"].includes(
                    modal.type,
                  )
                ? 480
                : 720
        }
        onClose={close}
        closeLabel={
          ["archiveDoc", "exception", "departure"].includes(modal.type)
            ? "Cancel"
            : undefined
        }
        backLabel={
          modalTrail.length
            ? "Back to " +
              (modalTrail[modalTrail.length - 1].modal.type === "work"
                ? "work"
                : modalTrail[modalTrail.length - 1].modal.type === "custody"
                  ? "custody"
                  : "record")
            : undefined
        }
        busy={busy}
        success={success}
        actions={detailActions}
        tools={
          [
            "work",
            "check",
            "archiveDoc",
            "exception",
            "departure",
            "releaseAssignment",
          ].includes(modal.type) ? (
            <details className="modal-preview-tools">
              <summary>Preview testing</summary>
              {footerFailure}
            </details>
          ) : null
        }
      >
        <div className="dialog-scroll">
          {error && (
            <p
              className="form-error modal-error-summary"
              role="alert"
              tabIndex={-1}
            >
              {error}
            </p>
          )}

          {modal.type === "work" ? (
            <>
              <Notice title="Asset hold remains active">
                Work completion, retest, independent release and Finance review
                remain separate.
              </Notice>
              <div className="work-grid">
                <div className="stack">
                  <Card title="Reported concern" icon={CircleAlert}>
                    <p>
                      The original check records a brake concern. Assessment and
                      source evidence are outstanding.
                    </p>
                    <TextLink
                      onClick={() => open("check", { check: "CHK-882" })}
                    >
                      Original check CHK-882 · v2
                    </TextLink>
                    <div className="mt-3">
                      <Badge tone="warning">
                        {workComplete ? "Completed/Closed" : progress}
                      </Badge>
                    </div>
                  </Card>
                  <Card
                    title="Notes & updates"
                    icon={MessageSquare}
                    action={
                      <button
                        className="text-link"
                        onClick={() => setNotesOpen(true)}
                      >
                        {note ? "Continue note" : "Add note"}
                      </button>
                    }
                  >
                    <button
                      className="notes-toggle"
                      aria-expanded={notesOpen}
                      onClick={() => setNotesOpen((v) => !v)}
                    >
                      <ChevronDown size={15} />
                      {notes.length} update{notes.length === 1 ? "" : "s"} ·{" "}
                      {notesOpen ? "Collapse" : "Expand"}
                    </button>
                    {notesOpen ? (
                      <>
                        <input
                          className="input-full my-3"
                          aria-label="Search notes"
                          placeholder="Search notes"
                          onChange={(e) => {
                            (e.target.parentElement as HTMLElement)
                              .querySelectorAll("[data-note]")
                              .forEach(
                                (x) =>
                                  ((x as HTMLElement).hidden = !x.textContent
                                    ?.toLowerCase()
                                    .includes(e.target.value.toLowerCase())),
                              );
                          }}
                        />
                        {notes.map((n, i) => (
                          <p data-note key={i} className="small py-2">
                            {n}
                          </p>
                        ))}
                        <Field label="Add note">
                          <textarea
                            disabled={readOnly}
                            value={note}
                            onChange={(e) => {
                              setNote(e.target.value);
                              setDirty(true);
                            }}
                          />
                        </Field>
                        <Button
                          variant="outline"
                          disabled={readOnly || !note.trim()}
                          onClick={() => {
                            if (failure) {
                              setError(
                                "Note not saved. Your draft is retained.",
                              );
                              return;
                            }
                            setNotes((n) => [
                              ...n,
                              actor + " · " + NOW + " — " + note,
                            ]);
                            setNote("");
                            setDirty(
                              draftProgress !== progress ||
                                draftAction !== nextAction,
                            );
                            say(
                              "Note saved in preview; original check unchanged.",
                            );
                          }}
                        >
                          Save note
                        </Button>
                      </>
                    ) : (
                      <p className="muted small">
                        {notes[notes.length - 1]}
                        {note && " · Unsent draft retained"}
                      </p>
                    )}
                  </Card>
                </div>
                <Card title="Work details" icon={Wrench}>
                  <Field label="Next action">
                    <textarea
                      disabled={readOnly}
                      value={draftAction}
                      onChange={(e) => {
                        setDraftAction(e.target.value);
                        setDirty(true);
                      }}
                    />
                  </Field>
                  <Field label="Progress">
                    <select
                      disabled={readOnly}
                      value={workComplete ? "Completed/Closed" : draftProgress}
                      onChange={(e) => {
                        if (
                          ["Completed/Closed", "Cancelled"].includes(
                            e.target.value,
                          )
                        ) {
                          open("completion", { intent: e.target.value });
                          return;
                        }
                        setDraftProgress(e.target.value);
                        setDirty(true);
                      }}
                    >
                      {progressOptions.map((x) => (
                        <option key={x}>{x}</option>
                      ))}
                    </select>
                  </Field>

                  <Fact label="Responsible owner">Mara Ellis</Fact>
                  <Fact label="Target">
                    Not set · No response policy assumed
                  </Fact>
                  <Button
                    className="w-full mt-4"
                    disabled={readOnly || workComplete}
                    onClick={() => open("completion")}
                  >
                    Complete work
                  </Button>
                  <p className="muted small mt-3">
                    Progress “On hold” describes waiting work; it does not
                    create or remove an asset restriction.
                  </p>
                </Card>
              </div>
            </>
          ) : modal.type === "releaseAssignment" ? (
            <>
              <Notice title="Release the current assignment?" tone="info">
                {assignment}'s assignment ends at {NOW}. Its history is
                retained. Releasing responsibility is not physical receipt.
              </Notice>
            </>
          ) : modal.type === "completion" ? (
            <>
              <Notice
                title={
                  modal.intent === "Cancelled"
                    ? "Cancellation needs an authorised decision"
                    : "Completion requirements are not satisfied"
                }
              >
                {modal.intent === "Cancelled"
                  ? "This work still has unresolved source evidence and an independent asset hold. Review cancellation authority and the reason in Maintenance."
                  : "The original service report is unavailable. Required retest and authorised release are separate and remain unresolved."}
              </Notice>
              <p>
                {modal.intent === "Cancelled"
                  ? "Cancellation does not remove the asset hold or erase the original report."
                  : "Retrieve the evidence in Maintenance before completing the work. Choosing Completed/Closed in Progress uses this same guarded path."}
              </p>
            </>
          ) : modal.type === "check" ? (
            <>
              <Badge tone="warning">Original submission · Read-only</Badge>
              <dl className="facts-grid">
                <Fact label="Submitted by">Mara Ellis</Fact>
                <Fact label="Submitted at">
                  {modal.check === "CHK-882"
                    ? "24 Sep 2026, 8:40 am NZST"
                    : "18 Mar 2025, 11:05 am NZST"}
                </Fact>
                <Fact label="Template">
                  Equipment observation · v2 · Illustrative
                </Fact>
                <Fact label="Source">{modal.check} · Asset AS-104</Fact>
              </dl>
              <div className="original-answer">
                <small>Original question snapshot</small>
                <h3>Describe any concern observed</h3>
                <p>
                  {modal.check === "CHK-882"
                    ? "“Brake needs assessment before further use.”"
                    : "“Equipment received with its manual. Applicable checks need configuration.”"}
                </p>
                <small>
                  Answer as submitted · Not an approved operating checklist
                </small>
              </div>
              {modal.check === "CHK-882" ? (
                <TextLink
                  onClick={() => open("document", { doc: documentSeed[2] })}
                >
                  Original attached photo
                </TextLink>
              ) : (
                <p className="muted small">
                  No file was attached to this original submission.
                </p>
              )}
              {modal.check === "CHK-882" && (
                <>
                  <Notice
                    title={
                      issueLinked
                        ? "Linked Maintenance work exists"
                        : "Maintenance link not saved"
                    }
                    tone={issueLinked ? "info" : "warning"}
                  >
                    {issueLinked
                      ? "Brake assessment · MW-271 · Owner Mara Ellis. Retrying returns this same reference."
                      : "Original check is preserved. Retry link creation without resubmitting the check."}
                  </Notice>
                  <div className="flex gap-3">
                    <Button
                      onClick={() => {
                        if (readOnly) {
                          setError(
                            "Read-only access: creating or linking Maintenance requires report authority.",
                          );
                          return;
                        }
                        if (failure) {
                          setIssueLinked(false);
                          setError(
                            "Link request failed. Original submission retained; safe retry available.",
                          );
                        } else {
                          setIssueLinked(true);
                          setError("");
                          say(
                            "Existing work MW-271 returned. No duplicate created.",
                          );
                        }
                      }}
                    >
                      {issueLinked
                        ? "Find linked work"
                        : "Retry Maintenance link"}
                    </Button>
                    <Button variant="outline" onClick={openWork}>
                      Open MW-271
                    </Button>
                  </div>
                </>
              )}
            </>
          ) : modal.type === "documentHistory" ? (
            <div className="document-version-list">
              {docs
                .filter((d) => d.source === modal.doc.source)
                .sort((a, b) => b.version - a.version)
                .map((d) => (
                  <div key={d.id}>
                    <strong>
                      {d.name} · Version {d.version}
                    </strong>
                    <p>
                      {d.state} · {d.date} · {d.by}
                    </p>
                    <div className="flex items-center gap-4">
                      <Button
                        variant="outline"
                        onClick={() => open("document", { doc: d })}
                      >
                        {documentFile(d)
                          ? "View version " + d.version
                          : "Review file status"}
                      </Button>
                      <DocumentDownload doc={d} />
                    </div>
                  </div>
                ))}
            </div>
          ) : modal.type === "document" ? (
            <>
              <DocumentFilePreview
                key={modal.doc.id + ":" + modal.doc.version}
                doc={modal.doc}
              />
              <dl className="facts-grid document-record-facts">
                <Fact label="Original source">{modal.doc.source}</Fact>
                <Fact label="Added">
                  {modal.doc.by} · {modal.doc.date}
                </Fact>
                <Fact label="File identity">
                  {modal.doc.id} · Version {modal.doc.version}
                </Fact>
              </dl>
              <p>{modal.doc.detail}</p>
              {modal.doc.state === "Unavailable" ? (
                <Button
                  variant="outline"
                  onClick={() =>
                    say(
                      "Simulated retry: source file still unavailable. Metadata and history are retained.",
                    )
                  }
                >
                  Retry retrieval
                </Button>
              ) : modal.doc.state === "Quarantined" ? (
                <Notice title="File access is blocked">
                  Ask the source owner to resolve the file check. No preview or
                  download is offered.
                </Notice>
              ) : (
                <div className="flex gap-3 flex-wrap">
                  {modal.doc.source.startsWith("Asset document") &&
                    modal.doc.state !== "Archived" && (
                      <>
                        <Button
                          variant="outline"
                          disabled={readOnly}
                          onClick={() => open("replace", { doc: modal.doc })}
                        >
                          Replace version
                        </Button>
                        <Button
                          variant="outline"
                          disabled={readOnly}
                          onClick={() => open("archiveDoc", { doc: modal.doc })}
                        >
                          Archive version
                        </Button>
                      </>
                    )}
                </div>
              )}
              <p className="muted small">
                {documentFile(modal.doc)
                  ? "This is a downloadable design sample. Real documents remain subject to the original source’s access and file checks."
                  : "This synthetic record demonstrates a blocked or unavailable file. No download is offered."}
              </p>
            </>
          ) : modal.type === "archiveDoc" ? (
            <>
              <p>
                Archive {modal.doc.name} version {modal.doc.version}. The
                original file and linked evidence remain in history.
              </p>
              <Field
                label="Archive reason"
                required
                hint="The file stays in history. Explain why it should leave the current library."
              >
                <textarea
                  value={reason}
                  onChange={(e) => {
                    setReason(e.target.value);
                    setDirty(true);
                  }}
                />
              </Field>
            </>
          ) : modal.type === "context" ? (
            <>
              <Notice title="Linked workspace preview boundary" tone="info">
                This candidate owns the individual Asset Profile. The source
                workspace keeps its canonical records and permissions.
              </Notice>
              <p>
                {denied
                  ? "Return to the permitted Assets register. No identity, source or location details are disclosed for the unavailable record."
                  : modal.kind === "vehicle"
                    ? "Reuse the existing Vehicle Profile; no replacement vehicle design is included."
                    : modal.kind === "register"
                      ? "Return context: Inventory · Kōwhai House · Shared equipment · AS-104 selected. Registration and import remain in PKG-06A."
                      : modal.kind === "stocktake"
                        ? "ST-022 · Observed at Kōwhai House during pending transfer TR-104-08. Snapshot may be stale. The register owns discrepancy investigation and sign-off; observation never rewrites custody."
                        : modal.kind === "device"
                          ? "No tracker paired. The optional canonical Device link owns pairing, capability, health and provenance. No device registry, live commands or personal-location data are created here."
                          : modal.kind === "finance"
                            ? "FA-104 · Linked operational asset AS-104. Finance owns recognition, depreciation and disposal. Source permissions remain independent."
                            : "Kōwhai House · Equipment room. Site and room identities remain canonical. Return to this asset with your current section preserved."}
              </p>
              <div className="inset">
                <small>Canonical destination / bounded handoff</small>
                <p className="mono">
                  {denied ? "/fleet-assets/assets" : sourcePaths[modal.kind]}
                </p>
              </div>
            </>
          ) : modal.type === "exception" || modal.type === "departure" ? (
            <>
              <Notice
                title={
                  modal.type === "departure"
                    ? "Outstanding custody requires an owner"
                    : "Receipt cannot be assumed"
                }
              >
                {modal.type === "departure"
                  ? "Borrower departure does not close a loan. Ask the responsible Site owner to locate the asset, nominate recovery and record actual return."
                  : "Wrong destination, missing kit, loss or damage needs an attributable exception. The outgoing custodian stays visible."}
              </Notice>
              <Field label="Exception type">
                <select
                  value={exceptionType}
                  onChange={(e) => {
                    setExceptionType(e.target.value);
                    setDirty(true);
                  }}
                >
                  <option>Missing kit item</option>
                  <option>Wrong location</option>
                  <option>Borrower departure</option>
                  <option>Loss</option>
                  <option>Damage</option>
                </select>
              </Field>
              <Field
                label="What needs resolving?"
                required
                hint="Describe the next action for the responsible person."
              >
                <textarea
                  value={reason}
                  onChange={(e) => {
                    setReason(e.target.value);
                    setDirty(true);
                  }}
                />
              </Field>
            </>
          ) : (
            <>
              <p className="source-text">{modal.body}</p>
            </>
          )}
        </div>
      </ModalFrame>
    );
  }
  function kitInputs() {
    return (
      <div className="kit-inputs">
        {[
          "Hoist frame · AS-104",
          "Battery pack · AS-104-B2",
          "Charger · AS-104-K1",
        ].map((x, i) => (
          <label key={x}>
            <input
              type="checkbox"
              checked={kitDraft[i]}
              onChange={(e) => {
                setKitDraft((c) =>
                  c.map((v, j) => (j === i ? e.target.checked : v)),
                );
                setDirty(true);
              }}
            />
            <span>
              <strong>{x}</strong>
              <small>
                {i === 2 ? "Not confirmed at dispatch" : "Recorded at dispatch"}
              </small>
            </span>
            {kitDraft[i] ? (
              <CheckCircle2 size={17} />
            ) : (
              <Badge tone="warning">Unconfirmed</Badge>
            )}
          </label>
        ))}
      </div>
    );
  }
  return (
    <TooltipProvider>
      <div className={"app " + (collapsed ? "collapsed" : "")}>
        <header className="global-header">
          <button className="wordmark" onClick={() => context("register")}>
            <span className="brand-ring" />
            blivion <em>Care</em>
          </button>
          <div className="global-date">
            <strong>Saturday</strong> 26 Sep 2026
          </div>
          <button
            className="global-search"
            onClick={() =>
              open("source", {
                title: "Find in Asset Profile",
                body: "Use Find in this asset to jump to sections, files and source records. Document filters stay beside the document library.",
              })
            }
          >
            <Search size={16} />
            Search or jump to…<kbd>Ctrl K</kbd>
          </button>
          <div className="global-actions">
            <Button
              size="sm"
              onClick={() =>
                source(
                  "Report incident",
                  "Incident reporting remains with the canonical Incidents workflow. This Asset Profile preview does not create incidents.",
                )
              }
            >
              Report incident
            </Button>
            <button
              aria-label="Clock in"
              onClick={() =>
                source(
                  "Clock in",
                  "The existing My Day / HR clock workflow remains unchanged.",
                )
              }
            >
              <Clock3 size={17} />
            </button>
            <button
              aria-label="Messages"
              onClick={() =>
                source(
                  "Messages",
                  "No messages or notifications are sent by this design preview.",
                )
              }
            >
              <MessageSquare size={17} />
            </button>
            <button
              aria-label="Notifications"
              onClick={() =>
                source(
                  "Notifications",
                  "No notifications are sent by this design preview.",
                )
              }
            >
              <Bell size={17} />
            </button>
            <span className="user-avatar">
              {actor === "Nia Patel" ? "NP" : "ME"}
            </span>
          </div>
        </header>
        <aside className="sidebar">
          <div className="personal-nav">
            {["My Day", "Overview", "Today", "My Calendar", "All Tasks"].map(
              (x, i) => {
                const Icon = [
                  CircleDot,
                  LayoutGrid,
                  Clock3,
                  CalendarDays,
                  ClipboardCheck,
                ][i];
                return (
                  <button
                    key={x}
                    onClick={() =>
                      source(
                        x,
                        "This global destination is outside the bounded Asset Profile mockup.",
                      )
                    }
                  >
                    <Icon size={17} />
                    <span>{x}</span>
                  </button>
                );
              },
            )}
          </div>
          <div className="sidebar-modules">
            {[
              "Sites & Locations",
              "Operations",
              "People & HR",
              "Compliance",
              "Incidents",
              "Governance",
            ].map((x) => (
              <button
                key={x}
                onClick={() =>
                  context(x.startsWith("Sites") ? "site" : "register")
                }
              >
                <Building2 size={17} />
                <span>{x}</span>
                <ChevronRight size={14} />
              </button>
            ))}
            <button
              className="module-active"
              onClick={() => setCollapsed((c) => !c)}
            >
              <Truck size={18} />
              <span>Fleet & Assets</span>
              <ChevronDown size={14} />
            </button>
            <nav aria-label="Fleet & Assets">
              {FLEET_WORKSPACES.map((w) => (
                <button
                  key={w.key}
                  className={w.key === "assets" ? "active" : ""}
                  onClick={() =>
                    w.key === "assets"
                      ? context("register")
                      : source(
                          w.label + " workspace",
                          "The published seven-entry Fleet & Assets navigation is retained. This profile mockup does not redesign " +
                            w.label +
                            ".",
                        )
                  }
                >
                  <span>{w.label}</span>
                </button>
              ))}
            </nav>
            {["Finance", "Security & Devices", "Settings"].map((x) => (
              <button
                key={x}
                onClick={() => context(x === "Finance" ? "finance" : "device")}
              >
                <Wallet size={17} />
                <span>{x}</span>
                <ChevronRight size={14} />
              </button>
            ))}
          </div>
          <button
            className="collapse-button"
            aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"}
            onClick={() => setCollapsed((c) => !c)}
          >
            {collapsed ? (
              <PanelLeftOpen size={18} />
            ) : (
              <PanelLeftClose size={18} />
            )}
          </button>
          <div className="sidebar-foot">
            <ShieldCheck size={17} />
            <span>One organisation · Approved sites</span>
          </div>
        </aside>
        <main>
          <div className="breadcrumb">
            <button onClick={() => context("register")}>Home</button>
            <ChevronRight size={12} />
            <button onClick={() => context("register")}>Fleet & Assets</button>
            <ChevronRight size={12} />
            <button onClick={() => context("register")}>Assets</button>
            {!denied && (
              <>
                <ChevronRight size={12} />
                <span>{name}</span>
                <span className="muted">AS-104</span>
              </>
            )}
          </div>
          <div className="page-stack">
            {!denied && (
              <>
                <PageHeader
                  variant="profile"
                  wrapTitle
                  mark={
                    <div className="asset-header-mark">
                      <button
                        className="asset-header-back"
                        aria-label="Back to Assets"
                        onClick={() => context("register")}
                      >
                        <ArrowLeft size={17} />
                      </button>
                      <button
                        className="eh-mark-ring asset-photo-trigger"
                        disabled={readOnly}
                        aria-label="Change asset profile photo"
                        onClick={() => open("upload", { photo: true })}
                      >
                        {photoUrl ? (
                          <img src={photoUrl} alt="" />
                        ) : (
                          <Package size={25} />
                        )}
                        <Camera className="photo-corner" size={13} />
                      </button>
                    </div>
                  }
                  title={scenario === "vehicle" ? "Vehicle Asset" : name}
                  titleChip={
                    <PageHeaderStatusChip
                      variant={hold ? "warning" : "neutral"}
                      icon={hold ? CircleAlert : Package}
                    >
                      {scenario === "archived"
                        ? "Retired"
                        : unknown
                          ? "Needs assessment"
                          : hold
                            ? "On hold"
                            : "Active record"}
                    </PageHeaderStatusChip>
                  }
                  subline={
                    "AS-104 · " +
                    currentLocation.replace(" · ", " / ") +
                    " · " +
                    (scenario === "client-owned"
                      ? "Client-owned equipment"
                      : "Shared equipment")
                  }
                  actions={
                    <>
                      <PageHeaderSearchTrigger
                        className="asset-find-trigger"
                        placeholder="Find in this asset…"
                        onOpen={() => setFindOpen(true)}
                      />
                      <PageHeaderGlassButton
                        icon={UserRound}
                        disabled={readOnly}
                        onClick={() => open("custody")}
                      >
                        Manage custody
                      </PageHeaderGlassButton>
                      <PageHeaderPrimaryButton
                        icon={CircleAlert}
                        disabled={readOnly}
                        onClick={() => open("report")}
                      >
                        Report a problem
                      </PageHeaderPrimaryButton>
                    </>
                  }
                  meters={
                    <div className="asset-meter-grid">
                      {[
                        {
                          label: "Maintenance",
                          value: empty
                            ? "No history"
                            : hold
                              ? "Hold active"
                              : "View work",
                          text: empty
                            ? "No recorded work"
                            : "MW-271 · " + progress,
                          g: "maintenance",
                          s: "work",
                          tone: hold ? "critical" : "brand",
                        },
                        {
                          label: "Custody",
                          value: empty
                            ? "Unassigned"
                            : custody === "Acknowledged"
                              ? "Received"
                              : custody === "Disputed"
                                ? "Disputed"
                                : "Receipt pending",
                          text: empty
                            ? "No responsible person"
                            : confirmedBy +
                              " · " +
                              kitChecked.filter(Boolean).length +
                              "/3 items",
                          g: "custody",
                          s: "current",
                        },
                        {
                          label: "Last check",
                          value: empty ? "No record" : "Needs assessment",
                          text: empty
                            ? "No submitted checks"
                            : "CHK-882 · 24 Sep 2026",
                          g: "checks",
                          s: "checks",
                        },
                        {
                          label: "Documents",
                          value: !docs.length
                            ? "No files"
                            : docs.filter((d) => d.state !== "Archived")
                                .length + " current",
                          text: !docs.length
                            ? "No uploaded evidence"
                            : "Manuals, warranty & source evidence",
                          g: "overview",
                          s: "library",
                        },
                      ].map((m) => (
                        <PageHeaderMeterBlock
                          key={m.label}
                          label={m.label}
                          tone={m.tone as any}
                          ariaLabel={"View " + m.label.toLowerCase()}
                          onClick={() => go(m.g, m.s)}
                        >
                          <PageHeaderMeterBig>{m.value}</PageHeaderMeterBig>
                          <PageHeaderMeterCaption>
                            {m.text}
                          </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                      ))}
                    </div>
                  }
                  filters={
                    <span className="hero-context">
                      <Clock3 size={12} />
                      Illustrative record · As at {NOW}
                    </span>
                  }
                  rail={
                    <PageHeaderRail
                      items={groups.map(({ key, label, icon }) => ({
                        key,
                        label,
                        icon,
                      }))}
                      value={group}
                      onSelect={(g) => go(g)}
                      ariaLabel="Asset Profile views"
                      onFind={() => setFindOpen(true)}
                    />
                  }
                />
                <TierTwoTabs
                  tabs={selectedGroup.tabs}
                  activeTab={sub}
                  onTab={(s) => go(group, s)}
                  testIdPrefix="asset-profile"
                  ariaLabel="Asset Profile sections"
                  panelId="profile-panel"
                  renderLink={(tab, className, inner, props) => (
                    <button
                      key={tab.key}
                      {...props}
                      className={className}
                      onClick={() => go(group, tab.key)}
                    >
                      {inner}
                    </button>
                  )}
                />
              </>
            )}
            {readOnly && (
              <Notice
                title={
                  scenario === "archived"
                    ? "Archived history · Read-only"
                    : "Read-only access"
                }
                tone="info"
              >
                {scenario === "archived"
                  ? "Original records and Finance decisions remain discoverable. No edit or deletion is offered."
                  : "You can view permitted sources. Assignment, uploads and lifecycle changes require the corresponding authority."}
              </Notice>
            )}
            {unknown && (
              <Notice title="Some source information is not available">
                Condition, financial recognition and applicable check rules need
                confirmation. Unknown values are never shown as passed.
              </Notice>
            )}
            {hold && !denied && !empty && (
              <div className="compact-hold">
                <Notice
                  title="Use on hold · Brake assessment pending"
                  action={
                    <Button variant="outline" size="sm" onClick={openWork}>
                      Open MW-271
                      <ArrowUpRight size={14} />
                    </Button>
                  }
                >
                  Mara Ellis owns the next action. Moving or receiving this
                  asset does not release the hold.
                </Notice>
              </div>
            )}
            <div id="profile-panel" className="stack" role="tabpanel">
              {body()}
            </div>
            <div className="page-footer">
              <button onClick={() => context("register")}>
                <ArrowLeft size={14} />
                Return to Assets register
              </button>
              {!denied && (
                <button onClick={() => context("site")}>
                  Kōwhai House assets
                  <ArrowUpRight size={14} />
                </button>
              )}
              <span>All names, references and records are synthetic.</span>
            </div>
          </div>
        </main>
        <div className="review-bar">
          <span className="preview-label">
            <CircleDot size={12} />
            DESIGN PREVIEW
          </span>
          <strong>PKG-06B · v9</strong>
          <span>Local simulation only</span>
          <button onClick={() => setDark((v) => !v)} aria-pressed={dark}>
            {dark ? "Light theme" : "Dark theme"}
          </button>
          <label>
            Scenario{" "}
            <select
              aria-label="Preview scenario"
              value={scenario}
              onChange={(e) => changeScenario(e.target.value)}
            >
              {[
                ["normal", "Pending receipt"],
                ["available", "Acknowledged / editable"],
                ["read-only", "Read-only"],
                ["denied", "Direct-object denial"],
                ["empty", "Empty records"],
                ["unknown", "Unknown source"],
                ["stale", "Concurrent assignment"],
                ["archived", "Archived history"],
                ["client-owned", "Client-owned privacy"],
                ["vehicle", "Vehicle handoff"],
              ].map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </label>
          {group === "overview" && sub === "finance" && !denied && (
            <label className="finance-sample-control">
              Finance sample
              <select
                aria-label="Finance sample state"
                value={financeSample}
                onChange={(e) => setFinanceSample(e.target.value)}
              >
                {financeStates.map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
          )}
          {group === "overview" && sub === "identity" && (
            <label className="qr-review-control">
              QR sample
              <select
                aria-label="QR sample state"
                value={qrSample}
                onChange={(e) => setQrSample(e.target.value)}
              >
                {qrStates.map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
          )}
          <label className="actor-control">
            Actor{" "}
            <select
              aria-label="Preview actor"
              value={actor}
              onChange={(e) => setActor(e.target.value)}
            >
              <option>Nia Patel</option>
              <option>Mara Ellis</option>
            </select>
          </label>
          <button onClick={() => location.reload()}>
            <RotateCcw size={13} />
            Reset preview
          </button>
        </div>
        {findOpen && !denied && (
          <Dialog open onOpenChange={setFindOpen}>
            <DialogContent className="asset-find-dialog">
              <DialogTitle>Find in this asset</DialogTitle>
              <DialogDescription>
                Sections, documents and original sources for AS-104.
              </DialogDescription>
              <Command>
                <CommandInput
                  aria-label="Find in this asset"
                  placeholder="Search sections, files or references…"
                />
                <CommandList>
                  <CommandEmpty>
                    No matches. Try a section, file name or reference.
                  </CommandEmpty>
                  <CommandGroup heading="Asset sections">
                    {groups.flatMap((g) =>
                      g.tabs.map((s) => (
                        <CommandItem
                          key={g.key + s.key}
                          value={g.label + " " + s.label}
                          onSelect={() => go(g.key, s.key)}
                        >
                          <s.icon size={16} />
                          <span>{s.label}</span>
                          <small className="ml-auto muted">{g.label}</small>
                        </CommandItem>
                      )),
                    )}
                  </CommandGroup>
                  <CommandGroup heading="Documents">
                    {docs.map((d) => (
                      <CommandItem
                        key={d.id}
                        value={[d.name, d.file, d.source].join(" ")}
                        onSelect={() => {
                          setFindOpen(false);
                          open("document", { doc: d });
                        }}
                      >
                        <FileText size={16} />
                        {d.name}
                        <small className="ml-auto muted">{d.state}</small>
                      </CommandItem>
                    ))}
                  </CommandGroup>
                  {!empty && (
                    <CommandGroup heading="Original sources">
                      <CommandItem
                        value="MW-271 brake assessment maintenance"
                        onSelect={() => {
                          setFindOpen(false);
                          openWork();
                        }}
                      >
                        <Wrench size={16} />
                        MW-271 · Brake assessment
                      </CommandItem>
                      <CommandItem
                        value="CHK-882 original brake check"
                        onSelect={() => {
                          setFindOpen(false);
                          open("check", { check: "CHK-882" });
                        }}
                      >
                        <ClipboardCheck size={16} />
                        CHK-882 · Original check
                      </CommandItem>
                    </CommandGroup>
                  )}
                </CommandList>
              </Command>
            </DialogContent>
          </Dialog>
        )}
        <FieldErrors.Provider value={fieldErrors}>
          {modalContent()}
        </FieldErrors.Provider>
        {draftClose && (
          <Dialog open onOpenChange={setDraftClose}>
            <DialogContent
              className="asset-discard-dialog"
              style={{ width: "min(92vw,480px)", maxWidth: "min(92vw,480px)" }}
            >
              <DialogTitle>Discard unsaved changes?</DialogTitle>
              <DialogDescription>
                Continue editing to keep your entries and selected files.
                Discard removes this unsaved draft; saved records stay
                unchanged.
              </DialogDescription>
              <div className="flex gap-3 justify-end">
                <Button autoFocus onClick={() => setDraftClose(false)}>
                  Continue editing
                </Button>
                <Button
                  variant="outline"
                  onClick={() => {
                    leaveModal();
                    say("Draft discarded in this browser tab.");
                  }}
                >
                  Discard draft
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        )}
        {toast && (
          <div role="status" className="toast">
            <CheckCircle2 size={18} />
            {toast}
            <button
              aria-label="Dismiss notification"
              onClick={() => setToast("")}
            >
              <X size={15} />
            </button>
          </div>
        )}
      </div>
    </TooltipProvider>
  );
}
createRoot(document.getElementById("root")!).render(<App />);
