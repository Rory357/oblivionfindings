import { PDFDocument } from "pdf-lib";
import { type LabelBranding } from "./asset-branding";
import { demoQrMatrix, demoQrTarget } from "./qr-matrix";
export { demoQrTarget };
export type LabelOptions = {
  layout: "a4" | "single";
  width: number;
  height: number;
  copies: number;
  start: number;
  name: string;
  includeName: boolean;
  branding: LabelBranding;
};
const esc = (s: string) =>
  s.replace(
    /[&<>"']/g,
    (c) =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&apos;",
      })[c]!,
  );
const cells = demoQrMatrix
  .flatMap((row, y) =>
    row.flatMap((cell, x) => (cell ? [`M${x + 4} ${y + 4}h1v1h-1z`] : [])),
  )
  .join("");
export const matrixSize = demoQrMatrix.length + 8;
export const qrSvg = () =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${matrixSize} ${matrixSize}" width="${matrixSize * 20}" height="${matrixSize * 20}" role="img" aria-label="Demo QR code for AS-104"><title>DEMO AS-104 - not a live asset</title><rect width="100%" height="100%" fill="white"/><path d="${cells}" fill="black" shape-rendering="crispEdges"/></svg>`;
export function labelSvg(options: LabelOptions) {
  const w = options.width,
    h = options.height,
    q = Math.min(32, h - 8, w * 0.48),
    left = q + 6;
  const labelName = options.includeName ? options.name : "";
  const branding = options.branding;
  const brandWidth = w - left - 2;
  const brandName = branding.name.trim() || "Company";
  const header = branding.logoUrl
    ? `<image href="${esc(branding.logoUrl)}" x="${left}" y="2" width="${brandWidth}" height="6" preserveAspectRatio="xMinYMid meet"/>`
    : `<text x="${left}" y="7" font-size="2.5">${esc(brandName.length > 18 ? brandName.slice(0, 17) + "…" : brandName)}</text>`;
  // Text remains separate from the QR quiet zone; preview and export share this SVG.
  const words = labelName.split(/\s+/);
  const lines: string[] = [];
  let current = "";
  const maxChars = Math.max(8, Math.floor((w - left - 3) / 1.55));
  for (const word of words) {
    if ((current + " " + word).trim().length > maxChars && current) {
      lines.push(current);
      current = word;
    } else current = (current + " " + word).trim();
  }
  if (current) lines.push(current);
  const display = lines
    .slice(0, 3)
    .map(
      (line, i) =>
        `<text x="${left}" y="${18 + i * 4}" font-size="3.1">${esc(line.slice(0, maxChars))}${line.length > maxChars ? "…" : ""}${i === 2 && lines.length > 3 ? "…" : ""}</text>`,
    )
    .join("");
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${w}mm" height="${h}mm" viewBox="0 0 ${w} ${h}"><rect width="${w}" height="${h}" fill="white"/><svg x="2" y="${(h - q) / 2}" width="${q}" height="${q}" viewBox="0 0 ${matrixSize} ${matrixSize}"><rect width="100%" height="100%" fill="white"/><path d="${cells}" fill="black" shape-rendering="crispEdges"/></svg><g font-family="Arial, sans-serif" fill="black">${header}<text x="${left}" y="12.5" font-size="4.2" font-weight="bold">AS-104</text>${display}<text x="${left}" y="${h - 6}" font-size="2.3">Scan to open asset</text><text x="${left}" y="${h - 2}" font-size="2.1" font-weight="bold">${w < 70 ? "DEMO · NOT LIVE" : "DEMO · NOT A LIVE LABEL"}</text></g></svg>`;
}
export async function svgPng(
  svg: string,
  width: number,
  height: number,
): Promise<Uint8Array> {
  const url = URL.createObjectURL(new Blob([svg], { type: "image/svg+xml" }));
  try {
    const img = new Image();
    await new Promise<void>((resolve, reject) => {
      img.onload = () => resolve();
      img.onerror = () =>
        reject(new Error("Label image could not be prepared."));
      img.src = url;
    });
    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext("2d");
    if (!ctx) throw new Error("Image export is unavailable.");
    ctx.fillStyle = "#fff";
    ctx.fillRect(0, 0, width, height);
    ctx.drawImage(img, 0, 0, width, height);
    const blob = await new Promise<Blob>((resolve, reject) =>
      canvas.toBlob(
        (b) => (b ? resolve(b) : reject(new Error("PNG export failed."))),
        "image/png",
      ),
    );
    return new Uint8Array(await blob.arrayBuffer());
  } finally {
    URL.revokeObjectURL(url);
  }
}
export function validateLabel(o: LabelOptions) {
  if (!Number.isInteger(o.copies) || o.copies < 1 || o.copies > 100)
    return "Enter between 1 and 100 copies for this asset.";
  if (
    o.layout === "a4" &&
    (!Number.isInteger(o.start) || o.start < 1 || o.start > 12)
  )
    return "Choose a starting position from 1 to 12.";
  if (
    !Number.isFinite(o.width) ||
    o.width < 60 ||
    o.width > 150 ||
    !Number.isFinite(o.height) ||
    o.height < 40 ||
    o.height > 100
  )
    return "Use a label from 60–150 mm wide and 40–100 mm high in this preview.";
  return "";
}
export function labelPages(o: LabelOptions) {
  return o.layout === "single"
    ? o.copies
    : Math.ceil((o.start - 1 + o.copies) / 12);
}
export function positions(o: LabelOptions) {
  return Array.from({ length: labelPages(o) }, (_, page) =>
    Array.from({ length: o.layout === "a4" ? 12 : 1 }, (_, cell) => {
      const index = o.layout === "a4" ? page * 12 + cell - (o.start - 1) : page;
      return index >= 0 && index < o.copies;
    }),
  );
}
export async function labelsPdf(options: LabelOptions) {
  const error = validateLabel(options);
  if (error) throw new Error(error);
  const png = await svgPng(
    labelSvg(options),
    Math.round((options.width / 25.4) * 600),
    Math.round((options.height / 25.4) * 600),
  );
  return labelsPdfFromPng(options, png);
}
export async function labelsPdfFromPng(options: LabelOptions, png: Uint8Array) {
  const error = validateLabel(options);
  if (error) throw new Error(error);
  const pdf = await PDFDocument.create();
  pdf.setTitle("AS-104 demo asset QR labels");
  pdf.setSubject(
    "Synthetic printing preview; labels do not open a live asset.",
  );
  const label = await pdf.embedPng(png),
    mm = 72 / 25.4;
  for (const cells of positions(options)) {
    const w = options.layout === "a4" ? 210 : options.width,
      h = options.layout === "a4" ? 297 : options.height;
    const page = pdf.addPage([w * mm, h * mm]);
    cells.forEach((filled, i) => {
      if (!filled) return;
      const x = options.layout === "a4" ? 33.5 + (i % 2) * 73 : 0;
      const y = options.layout === "a4" ? 21 + Math.floor(i / 2) * 43 : 0;
      page.drawImage(label, {
        x: x * mm,
        y: (h - y - options.height) * mm,
        width: options.width * mm,
        height: options.height * mm,
      });
    });
  }
  return pdf.save();
}
export function downloadFile(data: BlobPart, mime: string, name: string) {
  const url = URL.createObjectURL(new Blob([data], { type: mime }));
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  document.body.append(a);
  a.click();
  a.remove();
  return { url, name };
}
