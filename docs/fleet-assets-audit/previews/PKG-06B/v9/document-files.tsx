import React, { useState } from "react";
import {
  ChevronLeft,
  ChevronRight,
  Download,
  FileText,
  RotateCcw,
  ZoomIn,
  ZoomOut,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import manifest from "./document-manifest.json";
import "./document-files.css";

type DocumentRef = { id: string; name: string; version: number; state: string };
type FileEntry = {
  filename: string;
  mime: string;
  bytes: number;
  pages: string[];
  text: string[];
};
const files: Record<string, FileEntry> = manifest;
export function documentFile(doc: DocumentRef): FileEntry | undefined {
  return ["Available", "Archived"].includes(doc.state)
    ? files[`${doc.id}:${doc.version}`]
    : undefined;
}
const fileUrl = (name: string, download = false) =>
  `/files/${encodeURIComponent(name)}${download ? "?download=1" : ""}`;
export function downloadDocument(doc: DocumentRef) {
  const file = documentFile(doc);
  if (!file) return;
  const link = document.createElement("a");
  link.href = fileUrl(file.filename, true);
  link.download = file.filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
}
export function DocumentDownload({
  doc,
  compact = false,
}: {
  doc: DocumentRef;
  compact?: boolean;
}) {
  const file = documentFile(doc);
  if (!file) return null;
  return (
    <a
      className={
        compact ? "text-link document-download" : "document-download-button"
      }
      href={fileUrl(file.filename, true)}
      download={file.filename}
      aria-label={`Download ${doc.name} version ${doc.version}`}
    >
      <Download size={15} />
      Download{!compact && <span className="sr-only"> {file.filename}</span>}
    </a>
  );
}

export function DocumentFilePreview({ doc }: { doc: DocumentRef }) {
  const [page, setPage] = useState(0),
    [zoom, setZoom] = useState(100),
    [failed, setFailed] = useState(false),
    [retry, setRetry] = useState(0);
  const file = documentFile(doc);
  if (!file)
    return (
      <div className="document-file-unavailable">
        <FileText size={30} />
        <strong>
          {doc.state === "Pending verification"
            ? "File awaiting verification"
            : doc.state === "Quarantined"
              ? "File access blocked"
              : "File unavailable"}
        </strong>
        <p>
          {doc.state === "Pending verification"
            ? "This staged record has not been uploaded or verified. Its content is not offered as a verified attachment."
            : doc.state === "Quarantined"
              ? "The source owner must resolve the file check before this attachment can be viewed or downloaded."
              : "The file could not be retrieved. Its record and version history are still available."}
        </p>
      </div>
    );
  const image = file.mime.startsWith("image/");
  return (
    <section
      className="document-file-reader"
      aria-label={`File viewer for ${doc.name}`}
    >
      <div className="document-file-heading">
        <div>
          <strong>{file.filename}</strong>
          <small>
            {image ? "JPEG image" : "PDF document"} ·{" "}
            {Math.ceil(file.bytes / 1024)} KB ·{" "}
            {image
              ? "1400 × 900 px"
              : `${file.pages.length} ${file.pages.length === 1 ? "page" : "pages"}`}
          </small>
        </div>
        <span className="document-sample-tag">
          Sample file{doc.state === "Archived" ? " · Archived" : ""}
        </span>
      </div>
      {doc.state === "Archived" && (
        <p className="document-version-warning">
          Archived version {doc.version}. Retained for history; check the
          current library before use.
        </p>
      )}
      <div
        className="document-reader-toolbar"
        aria-label="File viewer controls"
      >
        {!image && (
          <div>
            <Button
              variant="ghost"
              size="icon"
              aria-label="Previous document page"
              disabled={page === 0}
              onClick={() => {
                setPage(page - 1);
                setFailed(false);
              }}
            >
              <ChevronLeft size={17} />
            </Button>
            <span role="status">
              Page {page + 1} of {file.pages.length}
            </span>
            <Button
              variant="ghost"
              size="icon"
              aria-label="Next document page"
              disabled={page === file.pages.length - 1}
              onClick={() => {
                setPage(page + 1);
                setFailed(false);
              }}
            >
              <ChevronRight size={17} />
            </Button>
          </div>
        )}
        <div>
          <Button
            variant="ghost"
            size="icon"
            aria-label="Zoom out"
            disabled={zoom === 75}
            onClick={() => setZoom(zoom - 25)}
          >
            <ZoomOut size={17} />
          </Button>
          <span>{zoom}%</span>
          <Button
            variant="ghost"
            size="icon"
            aria-label="Zoom in"
            disabled={zoom === 200}
            onClick={() => setZoom(zoom + 25)}
          >
            <ZoomIn size={17} />
          </Button>
          <Button variant="ghost" size="sm" onClick={() => setZoom(100)}>
            Fit width
          </Button>
        </div>
        <a
          href={fileUrl(file.filename, true)}
          download={file.filename}
          className="text-link"
          aria-label={`Download original ${image ? "image" : "PDF"}`}
        >
          Download {image ? "image" : "PDF"}
          <Download size={14} />
        </a>
      </div>
      <div
        className="document-file-canvas"
        tabIndex={0}
        aria-label="Document page, scroll to read"
      >
        {failed ? (
          <div className="document-render-error" role="alert">
            <strong>The page preview could not load.</strong>
            <p>Retry the preview or download the original sample file.</p>
            <Button
              variant="outline"
              onClick={() => {
                setRetry(retry + 1);
                setFailed(false);
              }}
            >
              <RotateCcw size={15} />
              Retry preview
            </Button>
          </div>
        ) : (
          <img
            key={`${page}-${retry}`}
            src={fileUrl(file.pages[page]) + (retry ? `?retry=${retry}` : "")}
            style={{ width: `${zoom}%` }}
            alt={`${doc.name}, version ${doc.version}${image ? ", synthetic image fixture" : `, page ${page + 1}`}`}
            onError={() => setFailed(true)}
          />
        )}
      </div>
      <details className="document-page-text">
        <summary>Read {image ? "image description" : "page text"}</summary>
        <pre>{file.text[page]}</pre>
      </details>
      <p className="document-reader-caption">
        {image
          ? "Synthetic image fixture, not an inspection photograph."
          : "Preview rendered from the downloadable PDF."}{" "}
        All content is labelled as a design sample.
      </p>
    </section>
  );
}
