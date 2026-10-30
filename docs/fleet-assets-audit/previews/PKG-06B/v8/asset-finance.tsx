import {
  FinanceRequestFrame,
  FinanceTypeTiles,
  FinanceSourcePicker,
} from "./finance-request-ui";
import React, { useEffect, useState, useRef, type ReactNode } from "react";
import {
  Landmark,
  ReceiptText,
  Link2,
  Search,
  CircleAlert,
  ArrowUpRight,
  Clock3,
  LockKeyhole,
  FileText,
  History,
  CheckCircle2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { StatusBadge, type StatusVariant } from "@/components/ui/status-badge";
import {
  VehicleCollectionToggle,
  VehicleRecordCollection,
} from "@/components/fleet-assets/vehicle-workspace/record-collection";
import { ReviewCard, ReviewRow } from "@/components/wizard/shell";

export const financeStates = [
  ["linked", "Linked records"],
  ["restricted", "Finance restricted"],
  ["ap-restricted", "Invoices restricted"],
  ["unlinked", "Not linked"],
  ["unavailable", "Source unavailable"],
  ["stale", "Older snapshot"],
  ["duplicate", "Conflicting links"],
  ["disposed", "Disposal mismatch"],
] as const;
type RecordItem = {
  id: string;
  title: string;
  type: string;
  date: string;
  amount: number;
  status: string;
  tone: StatusVariant;
  source: string;
  detail: string;
  posted?: boolean;
};
const baseRecords: RecordItem[] = [
  {
    id: "FA-104",
    title: "Transfer hoist",
    type: "Fixed asset",
    date: "31 Aug 2026",
    amount: 4800,
    status: "Capitalised",
    tone: "success",
    source: "Finance · Fixed Assets",
    detail:
      "Acquisition journal recorded in Finance. This is the capitalised cost, not an additional purchase.",
  },
  {
    id: "BILL-271",
    title: "Brake assessment",
    type: "Service invoice",
    date: "25 Sep 2026",
    amount: 380,
    status: "Pending review",
    tone: "warning",
    source: "Finance · Accounts Payable",
    detail:
      "Invoice linked to MW-271. Finance is reviewing the supporting evidence before approval or posting. Payment status is not supplied.",
  },
  {
    id: "BILL-208",
    title: "Annual hoist service",
    type: "Service invoice",
    date: "24 Mar 2026",
    amount: 240,
    status: "Posted",
    tone: "success",
    source: "Finance · Accounts Payable",
    detail:
      "Completed service MW-208. One posted service invoice contributes to the linked service cost. Posting does not confirm payment.",
    posted: true,
  },
  {
    id: "BILL-104",
    title: "Transfer hoist purchase",
    type: "Purchase invoice",
    date: "18 Mar 2025",
    amount: 4800,
    status: "Posted",
    tone: "success",
    source: "Finance · Accounts Payable",
    detail:
      "Harbour Equipment · Receipt RCP-104. The invoice and FA-104 describe the same acquisition; they are not added together.",
    posted: true,
  },
];
const order: RecordItem = {
  id: "PO-104",
  title: "Hoist purchase order",
  type: "Purchase order",
  date: "10 Mar 2025",
  amount: 4800,
  status: "Fulfilled",
  tone: "neutral",
  source: "Finance · Purchasing",
  detail:
    "Kōwhai House · Harbour Equipment. Fulfilled by RCP-104 and BILL-104. Linking this source adds context, not another cost.",
};
type Review = {
  id: string;
  title: string;
  source: string;
  note: string;
  local?: boolean;
  requestedBy?: string;
};
const seededReview: Review = {
  id: "FR-271",
  title: "Supplier invoice review",
  source: "BILL-271",
  note: "Confirm the assessment evidence against MW-271 before approving the service invoice.",
};
const money = (amount: number) =>
  new Intl.NumberFormat("en-NZ", {
    style: "currency",
    currency: "NZD",
    currencyDisplay: "narrowSymbol",
    minimumFractionDigits: 2,
  }).format(amount);
function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="finance-fact">
      <dt>{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}
function Note({
  title,
  children,
  warning = false,
}: {
  title: string;
  children: ReactNode;
  warning?: boolean;
}) {
  return (
    <div className={"finance-note " + (warning ? "finance-note-warning" : "")}>
      <CircleAlert size={17} />
      <div>
        <strong>{title}</strong>
        <p>{children}</p>
      </div>
    </div>
  );
}

export function useAssetFinanceState(sample: string, scenario: string) {
  const [locallyConnected, setLocallyConnected] = useState(false);
  const [linked, setLinked] = useState(false);
  const [reviews, setReviews] = useState<Review[]>([seededReview]);
  useEffect(() => {
    setLocallyConnected(false);
    setLinked(false);
    setReviews([seededReview]);
  }, [sample, scenario]);
  return {
    locallyConnected,
    setLocallyConnected,
    linked,
    setLinked,
    reviews,
    setReviews,
  };
}

export function AssetFinance({
  sample,
  scenario,
  readOnly,
  actor,
  session,
  onWork,
  onDocuments,
  onRetirement,
}: {
  sample: string;
  scenario: string;
  readOnly: boolean;
  actor: string;
  session: ReturnType<typeof useAssetFinanceState>;
  onWork: () => void;
  onDocuments: () => void;
  onRetirement: () => void;
}) {
  const {
    locallyConnected,
    setLocallyConnected,
    linked,
    setLinked,
    reviews,
    setReviews,
  } = session;
  const originalState =
    scenario === "unknown"
      ? "unavailable"
      : scenario === "empty"
        ? "unlinked"
        : sample;
  const state =
    originalState === "unlinked" && locallyConnected ? "linked" : originalState;
  const restricted = state === "restricted";
  const apRestricted = state === "ap-restricted";
  const noSource = state === "unavailable" || state === "unlinked";
  const externalOwner = scenario === "client-owned";
  const blocked = restricted || noSource || externalOwner;
  const conflict = state === "duplicate";
  const valueHidden = blocked || conflict;
  const stale = state === "stale";
  const disposed = state === "disposed";
  const [view, setView] = useState<"list" | "cards">("list");
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState("all");
  const [dialog, setDialog] = useState<string | null>(null);
  const [dialogTrail, setDialogTrail] = useState<string[]>([]);
  const returnDialog = dialogTrail.at(-1) ?? null;
  const [requestType, setRequestType] = useState("Fixed asset update");
  const [requestSource, setRequestSource] = useState("FA-104");
  const [note, setNote] = useState("");
  const [error, setError] = useState("");
  const [stage, setStage] = useState<"edit" | "review">("edit");
  const [saved, setSaved] = useState("");
  const [selectedLink, setSelectedLink] = useState(false);
  const [discard, setDiscard] = useState(false);
  useEffect(() => {
    setDialog(null);
    setDialogTrail([]);
    setSearch("");
    setFilter("all");
    setDiscard(false);
    setRequestSource("FA-104");
    setRequestType("Fixed asset update");
  }, [sample, scenario]);
  const visibleReviews = blocked || apRestricted || stale ? [] : reviews;
  const sourceDate = stale ? "30 Jun 2026" : "31 Aug 2026";
  const records = blocked
    ? []
    : [...baseRecords, ...(linked ? [order] : [])]
        .filter((r) => (!apRestricted && !stale) || r.id === "FA-104")
        .map((r) => (r.id === "FA-104" ? { ...r, date: sourceDate } : r))
        .map((r) =>
          r.id === "FA-104" && disposed
            ? { ...r, status: "Disposed", tone: "warning" as const }
            : r,
        );
  const filtered = records.filter(
    (r) =>
      (filter === "all" ||
        (filter === "pending"
          ? r.status === "Pending review"
          : filter === "fixed"
            ? r.type === "Fixed asset"
            : r.type !== "Fixed asset")) &&
      (r.id + " " + r.title + " " + r.type)
        .toLowerCase()
        .includes(search.toLowerCase()),
  );
  const record = records.find((r) => r.id === dialog);
  const review = visibleReviews.find((r) => r.id === dialog);
  const totalPosted = records
    .filter((r) => r.posted && r.type === "Service invoice")
    .reduce((sum, r) => sum + r.amount, 0);
  const pending = records
    .filter((r) => r.type === "Service invoice" && !r.posted)
    .reduce((sum, r) => sum + r.amount, 0);
  const canRequest =
    !readOnly && !blocked && !apRestricted && !stale && !conflict;
  const triggerRef = useRef<HTMLElement | null>(null);
  useEffect(() => {
    if (!dialog && triggerRef.current) {
      const timer = window.setTimeout(() => {
        if (
          !document.querySelector('[role="dialog"]') &&
          triggerRef.current?.isConnected
        )
          triggerRef.current.focus();
      }, 240);
      return () => window.clearTimeout(timer);
    }
  }, [dialog]);
  const open = (value: string) => {
    triggerRef.current = document.activeElement as HTMLElement;
    setDialog(value);
    setDialogTrail([]);
    setSaved("");
    setError("");
    setDiscard(false);
    setStage("edit");
    setNote("");
    setSelectedLink(false);
    if (value === "request") {
      setRequestType("Fixed asset update");
      setRequestSource("FA-104");
    }
  };
  const close = () => {
    if (
      dialog === "request" &&
      (note.trim() ||
        requestType !== "Fixed asset update" ||
        requestSource !== "FA-104") &&
      !saved
    ) {
      setDiscard(true);
      return;
    }
    setDialog(returnDialog);
    setDialogTrail((v) => v.slice(0, -1));
    setDiscard(false);
  };
  const nested = (next: string) => {
    if (dialog) setDialogTrail((v) => [...v, dialog]);
    setDialog(next);
  };
  const duplicate = visibleReviews.find(
    (r) => r.source === requestSource && r.title === requestType,
  );
  const submit = () => {
    if (!canRequest || duplicate) return;
    if (note.trim().length < 15) {
      setStage("edit");
      window.setTimeout(
        () => document.getElementById("finance-request-note")?.focus(),
        100,
      );
      setError(
        "Describe what Finance needs to review (at least 15 characters).",
      );
      return;
    }
    if (stage === "edit") {
      setError("");
      setStage("review");
      return;
    }
    const id = "FR-LOCAL-" + (reviews.length + 1);
    setReviews((v) => [
      ...v,
      {
        id,
        title: requestType,
        source: requestSource,
        note: note.trim(),
        local: true,
        requestedBy: actor,
      },
    ]);
    setSaved(
      `${id} is now in the sample review queue. No request or notification was sent to Finance.`,
    );
  };
  const actionDisabled =
    readOnly ||
    (blocked && state !== "unlinked") ||
    externalOwner ||
    stale ||
    conflict;
  const metricValue = (value: number) =>
    valueHidden ? "Unavailable" : money(value);
  return (
    <div className="vehicle-studio asset-finance">
      <div className="studio-page record-workspace asset-record-workspace">
        <div className="studio-section-heading">
          <div>
            <span className="studio-eyebrow">Finance connection · AS-104</span>
            <h2 className="text-section-title">Asset value & costs</h2>
            <p>
              Linked records, service costs and decisions waiting with Finance.
            </p>
          </div>
          <div className="finance-actions">
            <Button
              variant="outline"
              disabled={actionDisabled || apRestricted || linked}
              onClick={() => open("link")}
            >
              <Link2 size={16} />
              {linked ? "Purchase order linked" : "Link Finance record"}
            </Button>
            <Button disabled={!canRequest} onClick={() => open("request")}>
              <ReceiptText size={16} />
              Request Finance review
            </Button>
          </div>
        </div>
        {(blocked ||
          conflict ||
          disposed ||
          stale ||
          scenario === "archived" ||
          apRestricted) && (
          <Note
            warning
            title={
              restricted
                ? "Finance access required"
                : externalOwner
                  ? "Client-owned asset · Financial details restricted"
                  : noSource
                    ? state === "unlinked"
                      ? "No Finance record linked"
                      : "Finance source is unavailable"
                    : conflict
                      ? "Two fixed-asset links need reconciliation"
                      : disposed
                        ? "Finance disposal needs an operational review"
                        : scenario === "archived"
                          ? "Asset retired · Fixed asset still active"
                          : apRestricted
                            ? "Accounts Payable access required"
                            : "Showing an older Finance snapshot"
            }
          >
            {restricted || externalOwner
              ? "Financial amounts, suppliers, references and review details are hidden. Asset access does not grant Finance access."
              : noSource
                ? "The operational asset remains valid. Recognition and values are unknown until an authorised Finance source is available."
                : conflict
                  ? "Finance must identify the authoritative fixed asset before a value can be shown. No value is calculated from conflicting records."
                  : disposed
                    ? "The sample Finance record is disposed, but the asset still has operational dependencies. Review custody, open work and retirement evidence."
                    : scenario === "archived"
                      ? "Finance must review depreciation and disposal separately. Archiving the operational record does not dispose of the fixed asset."
                      : apRestricted
                        ? "Fixed-asset values remain visible. Invoice amounts, supplier information, supporting files and review requests are hidden."
                        : "These values were last supplied on 30 Jun 2026. Current balances and later transactions are unavailable."}
          </Note>
        )}
        {!blocked && (
          <>
            <div className="finance-snapshot">
              <span>
                <Clock3 size={13} />
                {stale ? "Older valuation" : "Valuation"} · {sourceDate} · NZD
              </span>
              <span>
                {stale
                  ? "Later records unavailable"
                  : "Linked records · 26 Sep 2026"}{" "}
                · GST treatment not supplied
              </span>
            </div>
            <div className="finance-metrics">
              <section>
                <span>Capitalised cost</span>
                <strong>{metricValue(4800)}</strong>
                <small>Recorded acquisition cost</small>
              </section>
              <section>
                <span>Accumulated depreciation</span>
                <strong>{metricValue(stale ? 1280 : 1440)}</strong>
                <small>Posted by Finance</small>
              </section>
              <section className="finance-value">
                <span>
                  {disposed ? "Last pre-disposal book value" : "Net book value"}
                </span>
                <strong>{metricValue(stale ? 3520 : 3360)}</strong>
                <small>
                  {conflict
                    ? "Authoritative source unresolved"
                    : "Cost less accumulated depreciation"}
                </small>
              </section>
              <section>
                <span>Posted service costs</span>
                <strong>
                  {apRestricted || stale ? "Unavailable" : money(totalPosted)}
                </strong>
                <small>
                  {apRestricted
                    ? "Accounts Payable access needed"
                    : stale
                      ? "Current linked bills unavailable"
                      : `${money(pending)} pending · excluded`}
                </small>
              </section>
            </div>
            <div className="finance-overview-grid">
              <section className="studio-card finance-summary">
                <div className="studio-section-heading">
                  <h3>
                    <Landmark size={17} />
                    Fixed asset & allocation
                  </h3>
                  <StatusBadge
                    variant={conflict || disposed ? "warning" : "success"}
                  >
                    {conflict
                      ? "Reconcile links"
                      : disposed
                        ? "Disposed"
                        : "Capitalised"}
                  </StatusBadge>
                </div>
                <dl className="finance-facts">
                  <Fact label="Finance record">
                    <button
                      className="finance-link"
                      onClick={() => open(conflict ? "reconcile" : "FA-104")}
                    >
                      {conflict
                        ? "FA-104 / FA-104B"
                        : "FA-104 · Transfer hoist"}
                      <ArrowUpRight size={13} />
                    </button>
                  </Fact>
                  <Fact label="Cost centre">
                    KH-OPS · Kōwhai House operations
                  </Fact>
                  <Fact label="Acquired">18 Mar 2025 · Organisation-owned</Fact>
                  <Fact label="Recognition">
                    {conflict
                      ? "Confirmation required"
                      : "Acquisition journal recorded"}
                  </Fact>
                </dl>
                <div className="finance-card-foot">
                  <span>
                    {conflict
                      ? "Finance owns link resolution"
                      : `Valuation date · ${sourceDate}`}
                  </span>
                  <button
                    className="finance-link"
                    onClick={() => open(conflict ? "reconcile" : "valuation")}
                  >
                    {conflict ? "Review conflict" : "Value breakdown"}
                    <ArrowUpRight size={13} />
                  </button>
                </div>
              </section>
              <section className="studio-card finance-attention">
                <div className="studio-section-heading">
                  <h3>
                    <Clock3 size={17} />
                    Needs attention
                  </h3>
                  <StatusBadge variant="warning">
                    {apRestricted
                      ? "Restricted"
                      : `${visibleReviews.length} pending`}
                  </StatusBadge>
                </div>
                <strong>
                  {stale
                    ? "Current review queue unavailable"
                    : apRestricted
                      ? "Review details require Finance access"
                      : disposed || scenario === "archived"
                        ? "Reconcile operational and Finance status"
                        : "Brake assessment invoice"}
                </strong>
                <p>
                  {stale
                    ? "Only the last fixed-asset snapshot is available. Later invoices and review requests cannot be confirmed."
                    : apRestricted
                      ? "Permission is checked independently from asset access."
                      : disposed || scenario === "archived"
                        ? "Resolve custody and open work before completing the retirement review."
                        : `${money(380)} · BILL-271 · MW-271. Awaiting Finance review; not approved or posted.`}
                </p>
                <div className="finance-card-foot">
                  <span>
                    Owner ·{" "}
                    {disposed || scenario === "archived"
                      ? "Assets & Finance"
                      : "Finance Accounts Payable"}
                  </span>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={apRestricted || stale}
                    onClick={
                      disposed || scenario === "archived"
                        ? onRetirement
                        : () => open("FR-271")
                    }
                  >
                    {disposed || scenario === "archived"
                      ? "Review dependencies"
                      : "View review"}
                    <ArrowUpRight size={13} />
                  </Button>
                </div>
              </section>
            </div>
          </>
        )}
        {(!blocked || noSource) && (
          <section className="studio-card finance-records">
            <div className="studio-section-heading">
              <div>
                <h3>Linked Finance records</h3>
                <p>Original sources for acquisition and service spend.</p>
              </div>
              <VehicleCollectionToggle
                label="Finance records"
                view={view}
                onChange={setView}
              />
            </div>
            {!noSource && (
              <div className="finance-toolbar">
                <label>
                  <Search size={15} />
                  <input
                    aria-label="Search Finance records"
                    placeholder="Search reference or record…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                  />
                </label>
                <select
                  aria-label="Filter Finance records"
                  value={filter}
                  onChange={(e) => setFilter(e.target.value)}
                >
                  <option value="all">All records</option>
                  <option value="fixed">Fixed asset</option>
                  <option value="spend">Invoices & orders</option>
                  <option value="pending">Pending review</option>
                </select>
                {(search || filter !== "all") && (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      setSearch("");
                      setFilter("all");
                    }}
                  >
                    Clear filters
                  </Button>
                )}
              </div>
            )}
            <VehicleRecordCollection
              label="Linked Finance records"
              view={view}
              total={records.length}
              columns={[
                { label: "Type" },
                { label: "Status" },
                { label: "Source amount (NZD)" },
              ]}
              records={filtered.map((r) => ({
                id: r.id,
                name: r.title,
                subline: r.id + " · " + r.date,
                icon: r.type === "Fixed asset" ? Landmark : ReceiptText,
                fields: [
                  <span key="type">{r.type}</span>,
                  <StatusBadge key="status" variant={r.tone}>
                    {r.status}
                  </StatusBadge>,
                  <strong key="amount" className="finance-amount">
                    {conflict && r.id === "FA-104"
                      ? "Unresolved"
                      : money(r.amount)}
                  </strong>,
                ],
                onOpen: () => open(r.id),
                footer: { primary: r.type, secondary: r.source },
                actions: [
                  {
                    label: "View Finance record",
                    icon: Landmark,
                    onClick: () => open(r.id),
                  },
                ],
              }))}
              empty={{
                title: noSource
                  ? state === "unavailable"
                    ? "Linked records cannot be loaded"
                    : "No linked Finance records"
                  : "No matching Finance records",
                description: noSource
                  ? "Unknown amounts are not treated as zero. Finance confirms recognition and any links."
                  : "Try another reference or clear the filters.",
              }}
            />
            <p className="studio-footnote">
              Acquisition cost, its invoice and purchase order describe the same
              purchase. They are never added together. Service costs include
              posted service invoices only; payment status stays with Finance.
            </p>
          </section>
        )}
        {!blocked && !apRestricted && !stale && (
          <div className="finance-lower-grid">
            <section className="studio-card">
              <div className="studio-section-heading">
                <h3>Finance review requests</h3>
                <span className="finance-muted">
                  {visibleReviews.length} pending
                </span>
              </div>
              {visibleReviews.map((r) => (
                <button
                  className="finance-review-row"
                  key={r.id}
                  onClick={() => open(r.id)}
                >
                  <span className="finance-row-icon">
                    <ReceiptText size={18} />
                  </span>
                  <span>
                    <strong>{r.title}</strong>
                    <small>
                      {r.id} · {r.source} ·{" "}
                      {r.local ? "Saved in this preview" : "25 Sep 2026"}
                    </small>
                  </span>
                  <StatusBadge variant="warning">
                    {r.local ? "Local sample" : "Submitted"}
                  </StatusBadge>
                  <ArrowUpRight size={16} />
                </button>
              ))}
            </section>
            <section className="studio-card">
              <div className="studio-section-heading">
                <h3>Replacement & retirement</h3>
                <History size={17} />
              </div>
              <p className="finance-copy">
                No replacement budget or target date is recorded. Review the
                brake assessment, custody and service evidence before planning
                the next step.
              </p>
              <dl className="finance-facts">
                <Fact label="Replacement estimate">Not recorded</Fact>
                <Fact label="Disposal decision">
                  {disposed
                    ? "Recorded in Finance · Reconciliation needed"
                    : "No decision recorded"}
                </Fact>
              </dl>
              <button className="finance-link" onClick={onRetirement}>
                View retirement dependencies
                <ArrowUpRight size={13} />
              </button>
            </section>
          </div>
        )}
        <div className="finance-boundary">
          <Landmark size={16} />
          <p>
            Finance owns approvals, payment, depreciation and disposal. Asset
            edits and completed work do not make financial decisions.
          </p>
        </div>
      </div>
      {dialog && (
        <FinanceRequestFrame
          kind={dialog}
          stage={stage}
          onStage={setStage}
          discard={discard}
          pct={Math.round(((2 + (note.trim().length >= 15 ? 1 : 0)) / 3) * 100)}
          title={
            discard
              ? "Discard this review draft?"
              : dialog === "request"
                ? "Request Finance review"
                : dialog === "link"
                  ? "Link a Finance record"
                  : dialog === "valuation"
                    ? "Value breakdown · FA-104"
                    : dialog === "evidence"
                      ? "Supporting evidence"
                      : dialog === "reconcile"
                        ? "Resolve the fixed-asset link"
                        : record
                          ? `${record.id} · ${record.title}`
                          : review
                            ? `${review.id} · Finance review`
                            : "Finance source"
          }
          description={
            discard
              ? "Your draft has not been sent or saved."
              : "Transfer hoist · AS-104 · Kōwhai House"
          }
          icon={Landmark}
          width={dialog === "request" ? 900 : dialog === "link" ? 720 : 480}
          onClose={discard ? () => setDiscard(false) : close}
          closeLabel={
            discard ? "Keep editing" : dialog === "request" ? "Cancel" : "Close"
          }
          backLabel={!discard && returnDialog ? "Back to record" : undefined}
          success={!discard ? saved : undefined}
          actions={
            discard ? (
              <Button
                variant="destructive"
                onClick={() => {
                  setDialog(null);
                  setDiscard(false);
                  setNote("");
                }}
              >
                Discard draft
              </Button>
            ) : dialog === "request" ? (
              <>
                <Button disabled={!canRequest || !!duplicate} onClick={submit}>
                  {stage === "review"
                    ? "Save sample request"
                    : "Review request"}
                </Button>
              </>
            ) : dialog === "link" ? (
              <Button
                disabled={!selectedLink || actionDisabled || apRestricted}
                onClick={() => {
                  if (actionDisabled || apRestricted) return;
                  if (state === "unlinked") {
                    setLocallyConnected(true);
                    setSaved(
                      "FA-104 and its related Finance sources are connected in this preview. Recognition was already recorded by Finance; linking does not capitalise an asset.",
                    );
                    return;
                  }
                  setLinked(true);
                  setSaved(
                    "PO-104 is linked in this preview. No Finance record, approval or amount was changed.",
                  );
                }}
              >
                Link selected record
              </Button>
            ) : undefined
          }
        >
          <div className="finance-modal-content">
            {discard ? (
              <p className="finance-copy">
                Your review type, source and reason changes will be lost. Keep editing to return to the
                request.
              </p>
            ) : (
              <>
                {record && (
                  <>
                    <div className="finance-detail-top">
                      <StatusBadge variant={record.tone}>
                        {record.status}
                      </StatusBadge>
                      <span>{record.source}</span>
                    </div>
                    <dl className="finance-detail-grid">
                      <Fact
                        label={
                          record.id === "FA-104"
                            ? "Capitalised cost"
                            : "Source amount · NZD"
                        }
                      >
                        {conflict ? "Unresolved" : money(record.amount)}
                      </Fact>
                      <Fact label="Source date">{record.date}</Fact>
                      <Fact label="Owner">
                        {record.id === "FA-104"
                          ? "Finance Fixed Assets"
                          : "Finance Accounts Payable"}
                      </Fact>
                      <Fact label="Cost centre">
                        KH-OPS · Kōwhai House operations
                      </Fact>
                      <Fact label="GST treatment">
                        Not supplied by this sample
                      </Fact>
                      <Fact label="Payment status">
                        {record.id === "FA-104"
                          ? "Not applicable"
                          : "Not supplied · Confirm in Finance"}
                      </Fact>
                    </dl>
                    <Note title="Source context">{record.detail}</Note>
                    {record.id === "FA-104" ? (
                      <>
                        <Button
                          variant="outline"
                          disabled={conflict}
                          onClick={() => nested("valuation")}
                        >
                          View value breakdown
                        </Button>
                        <p className="finance-copy">
                          Depreciation method, useful life and residual value
                          are not included in this projection. Finance confirms
                          the accounting policy.
                        </p>
                      </>
                    ) : (
                      <>
                        <div className="finance-detail-links">
                          <Button
                            variant="outline"
                            onClick={() => nested("evidence")}
                          >
                            <FileText size={16} />
                            View supporting evidence
                          </Button>
                          {record.id === "BILL-271" && (
                            <Button
                              variant="outline"
                              onClick={() => nested("FR-271")}
                            >
                              View FR-271
                            </Button>
                          )}
                        </div>
                        {record.id === "BILL-271" && (
                          <Button
                            variant="ghost"
                            onClick={() => {
                              setDialog(null);
                              onWork();
                            }}
                          >
                            Open related work MW-271
                            <ArrowUpRight size={14} />
                          </Button>
                        )}
                      </>
                    )}
                    <div className="finance-source-footer">
                      <LockKeyhole size={14} />
                      Read-only source preview · Approval, posting and payment
                      take place in Finance.
                    </div>
                  </>
                )}
                {dialog === "valuation" && (
                  <>
                    <Note
                      title={
                        stale
                          ? "Older Finance snapshot"
                          : "Finance valuation snapshot"
                      }
                    >
                      As at {sourceDate}. Values below are illustrative records
                      supplied by Finance, not calculations made from
                      operational usage.
                    </Note>
                    <dl className="finance-value-equation">
                      <Fact label="Capitalised cost">{money(4800)}</Fact>
                      <Fact label="Less accumulated depreciation">
                        − {money(stale ? 1280 : 1440)}
                      </Fact>
                      <Fact
                        label={
                          disposed
                            ? "Last pre-disposal book value"
                            : "Net book value"
                        }
                      >
                        {money(stale ? 3520 : 3360)}
                      </Fact>
                    </dl>
                    <dl className="finance-detail-grid">
                      <Fact label="Depreciation method">Not supplied</Fact>
                      <Fact label="Useful life / residual value">
                        Confirm in Finance
                      </Fact>
                      <Fact label="Last posting included">{sourceDate}</Fact>
                      <Fact label="Revaluation / impairment">Not supplied</Fact>
                    </dl>
                    <Note title="No forecast implied">
                      The carrying value is not a resale estimate or a
                      replacement budget. Posting depreciation or disposing of
                      this record requires Finance authority.
                    </Note>
                  </>
                )}
                {review && (
                  <>
                    <div className="finance-detail-top">
                      <StatusBadge variant="warning">
                        {review.local
                          ? "Saved in this preview"
                          : "Submitted · Awaiting Finance"}
                      </StatusBadge>
                      <span>
                        {review.local
                          ? "Local simulation"
                          : "25 Sep 2026 · 3:10 pm NZST"}
                      </span>
                    </div>
                    <dl className="finance-detail-grid">
                      <Fact label="Review type">{review.title}</Fact>
                      <Fact label="Source">{review.source}</Fact>
                      <Fact label="Requested by">
                        {review.local ? review.requestedBy : "Mara Ellis"}
                      </Fact>
                      <Fact label="Decision owner">Finance</Fact>
                    </dl>
                    <section className="finance-comment">
                      <h3>Reason for review</h3>
                      <p>{review.note}</p>
                    </section>
                    <Note title="Awaiting a Finance decision">
                      No approval, posting, payment or disposal has occurred
                      through this request.
                    </Note>
                    <h3 className="text-section-title">Request history</h3>
                    <ol className="finance-history">
                      <li>
                        <span className="finance-history-dot" />
                        <div>
                          <strong>
                            {review.local
                              ? "Sample request saved"
                              : "Review requested"}
                          </strong>
                          <p>
                            {review.local
                              ? review.requestedBy + " · This preview only"
                              : "Mara Ellis · 25 Sep 2026, 3:10 pm NZST"}
                          </p>
                        </div>
                      </li>
                      <li>
                        <span className="finance-history-dot pending" />
                        <div>
                          <strong>Finance decision pending</strong>
                          <p>No decision or decision note recorded.</p>
                        </div>
                      </li>
                    </ol>
                    <div className="finance-detail-links">
                      <Button
                        variant="outline"
                        onClick={() => nested(review.source)}
                      >
                        View source record
                      </Button>
                      {review.source === "BILL-271" && (
                        <Button
                          variant="outline"
                          onClick={() => nested("evidence")}
                        >
                          View supporting evidence
                        </Button>
                      )}
                    </div>
                  </>
                )}
                {dialog === "evidence" && (
                  <>
                    <Note title="Original evidence stays with its source">
                      The service invoice belongs to Finance Accounts Payable.
                      The assessment evidence belongs to MW-271. These links do
                      not create a second invoice or expense.
                    </Note>
                    <div className="finance-evidence">
                      <FileText size={22} />
                      <div>
                        <strong>
                          {returnDialog === "BILL-104"
                            ? "Purchase invoice · BILL-104"
                            : returnDialog === "BILL-208"
                              ? "Service invoice · BILL-208"
                              : returnDialog === "PO-104"
                                ? "Purchase order · PO-104"
                                : "Service invoice · BILL-271"}
                        </strong>
                        <p>Source metadata only · Synthetic document</p>
                      </div>
                      <StatusBadge variant="info">Finance source</StatusBadge>
                    </div>
                    <p className="finance-copy">
                      No original invoice file is attached to this mockup.
                    </p>
                    <Button
                      variant="outline"
                      onClick={() => {
                        setDialog(null);
                        onDocuments();
                      }}
                    >
                      Open asset document library
                      <ArrowUpRight size={14} />
                    </Button>
                  </>
                )}
                {dialog === "reconcile" && (
                  <>
                    <Note
                      warning
                      title="Authoritative financial record is unresolved"
                    >
                      FA-104 and FA-104B both point to AS-104. The asset page
                      cannot choose between them or combine their balances.
                    </Note>
                    <dl className="finance-detail-grid">
                      <Fact label="Operational record">
                        AS-104 · Transfer hoist
                      </Fact>
                      <Fact label="Resolution owner">Finance Fixed Assets</Fact>
                    </dl>
                    <p className="finance-copy">
                      Finance must verify acquisition evidence, correct the
                      relationship, and retain the audit history before a
                      valuation can be presented.
                    </p>
                  </>
                )}
                {dialog === "link" && (
                  <>
                    <Note title="Add a source link">
                      Only Finance records within the permitted site are shown.
                      Finance-owned fixed-asset links cannot be replaced here.
                    </Note>
                    <label className="finance-link-choice">
                      <input
                        type="checkbox"
                        checked={selectedLink}
                        onChange={(e) => setSelectedLink(e.target.checked)}
                      />
                      <span>
                        <strong>
                          {state === "unlinked"
                            ? "FA-104 · Transfer hoist"
                            : "PO-104 · Hoist purchase order"}
                        </strong>
                        <small>
                          Kōwhai House · Harbour Equipment · {money(4800)}
                        </small>
                        <small>
                          {state === "unlinked"
                            ? "Capitalised in Finance · AS-104 matches the source asset. Related acquisition and service records will be visible."
                            : "Fulfilled · Same acquisition as BILL-104 and FA-104"}
                        </small>
                      </span>
                    </label>
                    <p className="finance-copy">
                      Linking adds context only. The order will not increase the
                      cost totals or grant approval.
                    </p>
                  </>
                )}
                {dialog === "request" && (
                  <>
                    {stage === "edit" ? (
                      <>
                        <FinanceTypeTiles
                          value={requestType}
                          onChange={setRequestType}
                        />
                        <FinanceSourcePicker
                          records={records}
                          value={requestSource}
                          onChange={setRequestSource}
                        />
                        <label className="field">
                          What should Finance review?{" "}
                          <span className="text-status-critical">*</span>
                          <textarea
                            id="finance-request-note"
                            aria-invalid={!!error}
                            aria-describedby={
                              error ? "finance-request-error" : undefined
                            }
                            value={note}
                            onChange={(e) => {
                              setNote(e.target.value);
                              setError("");
                            }}
                            placeholder="Explain the correction or decision needed…"
                          />
                        </label>
                        {error && (
                          <p
                            id="finance-request-error"
                            className="form-error"
                            role="alert"
                          >
                            {error}
                          </p>
                        )}
                        {duplicate && (
                          <Note
                            warning
                            title="An open request already covers this source"
                          >
                            {duplicate.id} · {duplicate.title}. Review the
                            existing request instead of creating another.{" "}
                            <button
                              type="button"
                              className="finance-link"
                              onClick={() => nested(duplicate.id)}
                            >
                              View existing request
                            </button>
                          </Note>
                        )}
                        <Note title="Supporting source included">
                          The selected Finance record and its existing evidence
                          are referenced. Adding evidence does not approve
                          expenditure.
                        </Note>
                      </>
                    ) : (
                      <>
                        <ReviewCard
                          icon={Landmark}
                          title="Finance request"
                          onEdit={() => setStage("edit")}
                        >
                          <ReviewRow label="Type" value={requestType} />
                          <ReviewRow
                            label="Source"
                            value={
                              requestSource +
                              " · " +
                              (records.find((r) => r.id === requestSource)
                                ?.title ?? "Finance record")
                            }
                          />
                          <ReviewRow label="Requested by" value={actor} />
                          <ReviewRow label="Decision owner" value="Finance" />
                          <ReviewRow label="Reason" value={note} />
                        </ReviewCard>
                        <Note title="Local preview only">
                          Saving demonstrates the request and queue state. It
                          does not send a request, create a task or notify
                          anyone.
                        </Note>
                      </>
                    )}
                  </>
                )}
              </>
            )}
          </div>
        </FinanceRequestFrame>
      )}
    </div>
  );
}
