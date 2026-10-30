import React, { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import {
  QrCode,
  Printer,
  Download,
  Settings2,
  History,
  Eye,
  LockKeyhole,
  CircleAlert,
  Loader2,
  FileText,
  CheckCircle2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { StatusBadge } from "@/components/ui/status-badge";
import { WizardShell, WizardStepPane } from "@/components/wizard/shell";
import { ModalFrame } from "./modal-ui";
import {
  qrSvg,
  labelSvg,
  labelsPdf,
  labelPages,
  positions,
  svgPng,
  matrixSize,
  downloadFile,
  demoQrTarget,
  validateLabel,
  type LabelOptions,
} from "./qr-export";
import "./qr.css";
import { type LabelBranding } from "./asset-branding";
export const qrStates = [
  ["active", "Active QR"],
  ["missing", "Missing QR"],
  ["revoked", "Revoked label"],
  ["denied", "QR export restricted"],
  ["failure", "Export failure"],
  ["no-logo", "No branding logo"],
  ["logo-unavailable", "Branding logo unavailable"],
] as const;
type ExportEntry = {
  id: number;
  format: string;
  copies: number;
  pages: number;
  actor: string;
};
export function useAssetQrSession(sample: string) {
  const [entries, setEntries] = useState<ExportEntry[]>([]);
  const [generated, setGenerated] = useState(false);
  useEffect(() => {
    setEntries([]);
    setGenerated(false);
  }, [sample]);
  return { entries, setEntries, generated, setGenerated };
}
export function AssetQr({
  branding,
  name,
  sample,
  readOnly,
  privateOwner,
  actor,
  bulkUrl,
  session,
}: {
  branding: LabelBranding;
  name: string;
  sample: string;
  readOnly: boolean;
  privateOwner: boolean;
  actor: string;
  bulkUrl?: string;
  session: ReturnType<typeof useAssetQrSession>;
}) {
  const { entries, setEntries, generated, setGenerated } = session;
  const [open, setOpen] = useState(false),
    [section, setSection] = useState(0),
    [layout, setLayout] = useState<"a4" | "single">("a4");
  const [copies, setCopies] = useState("1"),
    [start, setStart] = useState("1"),
    [width, setWidth] = useState("70"),
    [height, setHeight] = useState("40"),
    [includeName, setIncludeName] = useState(!privateOwner);
  const [prepared, setPrepared] = useState<{
    url: string;
    name: string;
  } | null>(null);
  useEffect(
    () => () => {
      if (prepared) URL.revokeObjectURL(prepared.url);
    },
    [prepared],
  );
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [status, setStatus] = useState(""),
    [printOptions, setPrintOptions] = useState<LabelOptions | null>(null),
    [discard, setDiscard] = useState(false),
    [dirty, setDirty] = useState(false),
    [retryReady, setRetryReady] = useState(false);
  const trigger = useRef<HTMLButtonElement>(null),
    errorRef = useRef<HTMLDivElement>(null),
    running = useRef(false);
  const inactive = sample === "revoked" || (sample === "missing" && !generated),
    permitted = sample !== "denied",
    usable = permitted && !inactive;
  const options: LabelOptions = {
    layout,
    width: layout === "a4" ? 70 : Number(width),
    height: layout === "a4" ? 40 : Number(height),
    copies: Number(copies),
    start: Number(start),
    name,
    includeName: includeName && !privateOwner,
    branding: {
      ...branding,
      logoUrl:
        sample === "no-logo" || sample === "logo-unavailable"
          ? null
          : branding.logoUrl,
    },
  };
  const invalid = validateLabel(options),
    pages = invalid ? 0 : labelPages(options);
  useEffect(() => {
    setOpen(false);
    setError("");
    setRetryReady(false);
    setStatus("");
  }, [sample]);
  useEffect(() => {
    if (privateOwner) setIncludeName(false);
  }, [privateOwner]);
  useEffect(() => {
    if (error) errorRef.current?.focus();
  }, [error]);
  useEffect(() => {
    if (!printOptions) return;
    const after = () => {
      setPrintOptions(null);
      setStatus(
        "Print dialog opened. Confirm the paper output before treating labels as printed.",
      );
    };
    window.addEventListener("afterprint", after);
    const timer = window.setTimeout(() => {
      document.documentElement.style.setProperty(
        "--qr-page-width",
        `${printOptions.layout === "a4" ? 210 : printOptions.width}mm`,
      );
      document.documentElement.style.setProperty(
        "--qr-page-height",
        `${printOptions.layout === "a4" ? 297 : printOptions.height}mm`,
      );
      window.print();
      setPrintOptions(null);
      setStatus(
        "Print requested. Check your browser preview or print the downloaded PDF. Physical output is not confirmed.",
      );
    }, 100);
    return () => {
      window.clearTimeout(timer);
      window.removeEventListener("afterprint", after);
    };
  }, [printOptions]);
  const close = () => {
    if (busy) return;
    if (dirty) {
      setDiscard(true);
      return;
    }
    setOpen(false);
    window.setTimeout(() => trigger.current?.focus(), 200);
  };
  const change = () => {
    setDirty(true);
    setStatus("");
    setError("");
  };
  const run = async (format: "PDF" | "SVG" | "PNG" | "Print") => {
    if (!usable || running.current) return;
    if (invalid) {
      setError(invalid);
      setSection(1);
      return;
    }
    running.current = true;
    setBusy(true);
    setError("");
    setStatus("");
    try {
      if (sample === "failure" && !retryReady)
        throw new Error(
          "The sample export could not be prepared. Your layout and copies are retained. Retry prepares the same labels.",
        );
      if (format === "PDF") {
        const data = await labelsPdf(options);
        setPrepared(
          downloadFile(
            data as BlobPart,
            "application/pdf",
            `AS-104-${layout}-${copies}-labels-DEMO.pdf`,
          ),
        );
      }
      if (format === "SVG")
        setPrepared(
          downloadFile(qrSvg(), "image/svg+xml", "AS-104-qr-DEMO.svg"),
        );
      if (format === "PNG") {
        const data = await svgPng(qrSvg(), matrixSize * 20, matrixSize * 20);
        downloadFile(data as BlobPart, "image/png", "AS-104-qr-DEMO.png");
      }
      if (format === "Print") setPrintOptions({ ...options });
      else {
        setEntries((v) => [
          {
            id: Date.now(),
            format,
            copies: format === "PDF" ? options.copies : 1,
            pages: format === "PDF" ? pages : 1,
            actor,
          },
          ...v,
        ]);
        setStatus(
          `${format} prepared and download started. Printing has not been confirmed.`,
        );
      }
      setDirty(false);
    } catch (e) {
      setError(
        e instanceof Error
          ? e.message
          : "Export failed. Retry with your settings retained.",
      );
      setRetryReady(true);
    } finally {
      setBusy(false);
      running.current = false;
    }
  };
  return (
    <section className="panel qr-card" aria-label="Asset QR label">
      <div className="panel-header">
        <h2>
          <QrCode size={17} />
          QR label
        </h2>
        <StatusBadge
          variant={usable ? "success" : inactive ? "warning" : "neutral"}
        >
          {sample === "denied"
            ? "Restricted"
            : sample === "revoked"
              ? "Revoked"
              : inactive
                ? "Not generated"
                : "Ready"}
        </StatusBadge>
      </div>
      <div className="panel-body">
        <div className="qr-card-content">
          {usable ? (
            <div
              className="qr-code"
              dangerouslySetInnerHTML={{ __html: qrSvg() }}
            />
          ) : (
            <div className="qr-unavailable">
              <LockKeyhole size={36} />
            </div>
          )}
          <div>
            <strong>AS-104 · Stable asset identity</strong>
            <p>
              {sample === "denied"
                ? "QR export access is required."
                : sample === "revoked"
                  ? "This label is revoked. It cannot be reprinted. An authorised replacement keeps the same asset record."
                  : inactive
                    ? "Existing assets with missing QR tokens need a controlled backfill. A new token must not replace a valid one."
                    : "Print a replacement label or download this asset’s QR. Reprints keep the same identity when its name, room or custodian changes."}
            </p>
            <small>Demonstration only · QR does not open a live asset</small>
            {usable && (
              <Button
                ref={trigger}
                variant="outline"
                onClick={() => {
                  setOpen(true);
                  setSection(0);
                  setError("");
                  setDirty(false);
                }}
              >
                <Printer size={16} />
                Print & export QR
              </Button>
            )}
            {sample === "missing" && !generated && !readOnly && (
              <Button variant="outline" onClick={() => setGenerated(true)}>
                Generate sample QR
              </Button>
            )}
          </div>
        </div>
        <div className="qr-bulk-note">
          <QrCode size={16} />
          <div>
            <strong>Printing many assets?</strong>
            <p>
              Select assets or all matching filters in Assets Register → QR
              labels.
            </p>
            {bulkUrl && (
              <a href={bulkUrl} className="text-primary">
                Open bulk QR labels ↗
              </a>
            )}
          </div>
        </div>
      </div>
      {open && (
        <WizardShell
          open
          onClose={close}
          title="Asset QR label"
          description="AS-104 · Transfer hoist. Preview, export and print a synthetic asset label."
          railIcon={QrCode}
          railTitle="Asset QR label"
          railSub="AS-104 · Transfer hoist"
          steps={[
            {
              key: "preview",
              label: "Label preview",
              blurb: "Code, identity and scan target",
              icon: Eye,
            },
            {
              key: "layout",
              label: "Print layout",
              blurb: "Stock, copies and position",
              icon: Settings2,
            },
            {
              key: "exports",
              label: "Export history",
              blurb: "Files prepared in this preview",
              icon: History,
            },
          ]}
          stepIndex={section}
          onStepClick={(i) => {
            if (!busy) setSection(i);
          }}
          headerLabel={
            ["Label preview", "Print layout", "Export history"][section]
          }
          pct={null}
          maxWidth="min(92vw, 1100px)"
          maxHeight="min(88vh, 800px)"
          onCloseAutoFocus={(e) => {
            e.preventDefault();
            trigger.current?.focus();
          }}
          railExtra={
            <div className="modal-rail-context">
              <LockKeyhole size={15} />
              <p>
                Same identity on every reprint. Scanning requires authorised
                access and does not confirm custody or a stocktake.
              </p>
              <small>
                Sample labels only. No client or custodian details are included.
              </small>
            </div>
          }
          footerStart={
            <Button variant="outline" disabled={busy} onClick={close}>
              Close
            </Button>
          }
          footerEnd={
            <>
              <Button
                variant="outline"
                disabled={busy || !!invalid}
                onClick={() => run("Print")}
              >
                <Printer size={15} />
                Print labels
              </Button>
              <Button disabled={busy} onClick={() => run("PDF")}>
                {busy ? (
                  <Loader2 size={15} className="animate-spin" />
                ) : (
                  <Download size={15} />
                )}
                Download label PDF
              </Button>
            </>
          }
        >
          <WizardStepPane>
            <div className="qr-modal-body">
              {error && (
                <div
                  className="form-error"
                  tabIndex={-1}
                  ref={errorRef}
                  role="alert"
                >
                  {error}
                  {retryReady && (
                    <Button variant="outline" onClick={() => run("PDF")}>
                      Retry PDF export
                    </Button>
                  )}
                </div>
              )}
              {status && (
                <div className="qr-success" role="status">
                  <CheckCircle2 size={16} />
                  <div>
                    {status}
                    {prepared && (
                      <a
                        className="block underline font-medium mt-1"
                        href={prepared.url}
                        download={prepared.name}
                      >
                        Download prepared file · {prepared.name}
                      </a>
                    )}
                  </div>
                </div>
              )}
              {section === 0 && (
                <>
                  <div
                    className="qr-label-preview"
                    dangerouslySetInnerHTML={{
                      __html: labelSvg(
                        invalid
                          ? { ...options, width: 70, height: 40 }
                          : options,
                      ),
                    }}
                  />
                  <div className="qr-preview-caption">
                    {options.width} × {options.height} mm label · preview is not
                    shown at physical size
                  </div>
                  <div className="qr-branding-source" role="note">
                    <strong>Company branding · Settings → Branding</strong>
                    <p>
                      {sample === "logo-unavailable"
                        ? "Company logo unavailable. The company name is used on this label."
                        : sample === "no-logo"
                          ? "No logo configured. The company name is used on this label."
                          : "The company logo is included in label PDFs and printing. This design uses the bundled logo as a sample."}
                    </p>
                  </div>
                  <div className="qr-warning">
                    <CircleAlert size={17} />
                    <div>
                      <strong>Demo QR target</strong>
                      <code>{demoQrTarget}</code>
                      <p>
                        Exported files are real, but this reserved demo address
                        cannot open a live asset. Production labels use the
                        existing authorised asset-token route.
                      </p>
                    </div>
                  </div>
                  <div className="qr-download-options">
                    <Button
                      variant="outline"
                      disabled={busy}
                      onClick={() => run("SVG")}
                    >
                      <Download size={15} />
                      Download QR SVG
                    </Button>
                    <Button
                      variant="outline"
                      disabled={busy}
                      onClick={() => run("PNG")}
                    >
                      <Download size={15} />
                      Download QR PNG
                    </Button>
                  </div>
                  <p className="qr-help">
                    SVG keeps the code sharp at any size. PNG contains a white
                    border around the code. Keep that border clear; do not
                    overlay a logo or text.
                  </p>
                </>
              )}
              {section === 1 && (
                <>
                  <fieldset disabled={busy} className="qr-settings">
                    <legend>
                      Label stock{" "}
                      <span className="text-status-critical">*</span>
                    </legend>
                    <div className="qr-choices">
                      {[
                        [
                          "a4",
                          "A4 sheet",
                          "12 labels · 70 × 40 mm · generic layout",
                        ],
                        [
                          "single",
                          "Label printer",
                          "One label per page · custom dimensions",
                        ],
                      ].map(([value, label, description]) => (
                        <button
                          key={value}
                          type="button"
                          aria-pressed={layout === value}
                          className={
                            "choice " + (layout === value ? "selected" : "")
                          }
                          onClick={() => {
                            setLayout(value as "a4" | "single");
                            change();
                          }}
                        >
                          <Printer size={18} />
                          <strong>{label}</strong>
                          <small>{description}</small>
                        </button>
                      ))}
                    </div>
                    <div className="qr-fields">
                      <label className="field">
                        Copies of AS-104{" "}
                        <span className="text-status-critical">*</span>
                        <input
                          type="number"
                          min="1"
                          max="100"
                          value={copies}
                          onChange={(e) => {
                            setCopies(e.target.value);
                            change();
                          }}
                        />
                        <small>
                          One copy is one sticker. Preview limit: 100 per asset.
                        </small>
                      </label>
                      {layout === "a4" ? (
                        <label className="field">
                          Start at sheet position{" "}
                          <span className="text-status-critical">*</span>
                          <select
                            value={start}
                            onChange={(e) => {
                              setStart(e.target.value);
                              change();
                            }}
                          >
                            {Array.from({ length: 12 }, (_, i) => (
                              <option key={i + 1} value={i + 1}>
                                {i + 1} · row {Math.floor(i / 2) + 1}, column{" "}
                                {(i % 2) + 1}
                              </option>
                            ))}
                          </select>
                          <small>
                            Top left first, then across. Skipped positions
                            remain blank.
                          </small>
                        </label>
                      ) : (
                        <>
                          <label className="field">
                            Label width (mm){" "}
                            <span className="text-status-critical">*</span>
                            <input
                              type="number"
                              min="60"
                              max="150"
                              value={width}
                              onChange={(e) => {
                                setWidth(e.target.value);
                                change();
                              }}
                            />
                          </label>
                          <label className="field">
                            Label height (mm){" "}
                            <span className="text-status-critical">*</span>
                            <input
                              type="number"
                              min="40"
                              max="100"
                              value={height}
                              onChange={(e) => {
                                setHeight(e.target.value);
                                change();
                              }}
                            />
                          </label>
                        </>
                      )}
                    </div>
                    <label className="qr-name-toggle">
                      <input
                        type="checkbox"
                        disabled={privateOwner}
                        checked={includeName && !privateOwner}
                        onChange={(e) => {
                          setIncludeName(e.target.checked);
                          change();
                        }}
                      />
                      Include asset name on the label{" "}
                      {privateOwner
                        ? "· Hidden for this client-owned example"
                        : ""}
                    </label>
                  </fieldset>
                  <div className="qr-print-summary">
                    <strong>
                      {invalid
                        ? "Check the label settings"
                        : `${copies} ${Number(copies) === 1 ? "label" : "labels"} · ${pages} ${pages === 1 ? "page" : "pages"}`}
                    </strong>
                    <p>
                      {layout === "a4"
                        ? "A4 portrait · 2 columns × 6 rows · 33.5 mm side margins · 21 mm top/bottom · 3 mm gaps."
                        : "The PDF page matches the selected label size. Match your printer stock and driver settings."}
                    </p>
                    <p>
                      Print at 100% / actual size, with browser headers and
                      footers off. Test one page before a batch. The A4 layout
                      is generic; confirm it matches your sticker stock.
                    </p>
                  </div>
                  {!invalid && (
                    <div
                      className="qr-sheet-grid"
                      aria-label="First page label positions"
                    >
                      {positions(options)[0].map((filled, i) => (
                        <div className={filled ? "filled" : ""} key={i}>
                          {filled ? (
                            <>
                              <QrCode size={15} />
                              <span>AS-104</span>
                            </>
                          ) : (
                            <span>Skip {i + 1}</span>
                          )}
                        </div>
                      ))}
                    </div>
                  )}
                </>
              )}
              {section === 2 && (
                <>
                  {entries.length ? (
                    <ol className="qr-export-history">
                      {entries.map((entry) => (
                        <li key={entry.id}>
                          <FileText size={20} />
                          <div>
                            <strong>
                              {entry.format} · {entry.copies}{" "}
                              {entry.copies === 1 ? "label" : "labels"}
                            </strong>
                            <p>
                              {entry.pages}{" "}
                              {entry.pages === 1 ? "page" : "pages"} ·{" "}
                              {entry.actor} · This browser session
                            </p>
                          </div>
                          <StatusBadge variant="info">Prepared</StatusBadge>
                        </li>
                      ))}
                    </ol>
                  ) : (
                    <div className="empty-state">
                      <History size={32} />
                      <strong>No files prepared yet</strong>
                      <p>
                        Your successful PDF, SVG and PNG exports appear here for
                        this session.
                      </p>
                    </div>
                  )}
                  <div className="qr-warning">
                    <CircleAlert size={17} />
                    <p>
                      Download started is not proof of a saved file or a printed
                      sticker. Printer delivery and physical scan checks must be
                      confirmed separately.
                    </p>
                  </div>
                </>
              )}
            </div>
          </WizardStepPane>
        </WizardShell>
      )}
      {discard && (
        <ModalFrame
          title="Discard label settings?"
          description="Unsaved layout and copy changes will be reset."
          icon={CircleAlert}
          width={480}
          onClose={() => setDiscard(false)}
          closeLabel="Keep editing"
          actions={
            <Button
              variant="destructive"
              onClick={() => {
                setDiscard(false);
                setDirty(false);
                setOpen(false);
                setLayout("a4");
                setCopies("1");
                setStart("1");
                setWidth("70");
                setHeight("40");
                setIncludeName(!privateOwner);
              }}
            >
              Discard settings
            </Button>
          }
        >
          <p className="qr-help">
            Previously downloaded files are kept. This does not revoke or
            regenerate the asset’s QR identity.
          </p>
        </ModalFrame>
      )}
      {printOptions &&
        createPortal(
          <div id="asset-print-root">
            {positions(printOptions).map((page, p) => (
              <div
                className={
                  "qr-print-page " +
                  (printOptions.layout === "a4"
                    ? "qr-print-a4"
                    : "qr-print-single")
                }
                key={p}
                style={{
                  width: `${printOptions.layout === "a4" ? 210 : printOptions.width}mm`,
                  height: `${printOptions.layout === "a4" ? 297 : printOptions.height}mm`,
                }}
              >
                {page.map((filled, i) => (
                  <div
                    key={i}
                    style={{
                      width: `${printOptions.width}mm`,
                      height: `${printOptions.height}mm`,
                    }}
                    dangerouslySetInnerHTML={{
                      __html: filled ? labelSvg(printOptions) : "",
                    }}
                  />
                ))}
              </div>
            ))}
          </div>,
          document.body,
        )}
    </section>
  );
}
