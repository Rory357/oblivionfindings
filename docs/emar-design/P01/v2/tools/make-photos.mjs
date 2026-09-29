// Generates the synthetic "staff photo of the supplied pack" placeholders used
// by the P01 mockup (public/photos/*.png). No real product imagery: flat packs
// with pill shapes and a diagonal hatch marking them as placeholders.
// Dependency-free (node:zlib). Run: node docs/emar-design/P01/v2/tools/make-photos.mjs
import { writeFileSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import zlib from 'node:zlib';

const out = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'public', 'photos');
mkdirSync(out, { recursive: true });
const W = 480, H = 360;

function crc32(buf) {
    let c, crc = 0xffffffff;
    for (let n = 0; n < buf.length; n++) {
        c = (crc ^ buf[n]) & 0xff;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        crc = (crc >>> 8) ^ c;
    }
    return (crc ^ 0xffffffff) >>> 0;
}
function chunk(type, data) {
    const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
    const td = Buffer.concat([Buffer.from(type), data]);
    const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td));
    return Buffer.concat([len, td, crc]);
}
function png(px) {
    const raw = Buffer.alloc((W * 3 + 1) * H);
    for (let y = 0; y < H; y++) {
        raw[y * (W * 3 + 1)] = 0;
        px.copy(raw, y * (W * 3 + 1) + 1, y * W * 3, (y + 1) * W * 3);
    }
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(W, 0); ihdr.writeUInt32BE(H, 4);
    ihdr[8] = 8; ihdr[9] = 2; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
    return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
function canvas(bg) {
    const px = Buffer.alloc(W * H * 3);
    for (let i = 0; i < W * H; i++) px.set(bg, i * 3);
    return {
        px,
        set(x, y, c) { if (x >= 0 && y >= 0 && x < W && y < H) px.set(c, (y * W + x) * 3); },
        rect(x0, y0, x1, y1, c, r = 0) {
            for (let y = y0; y < y1; y++) for (let x = x0; x < x1; x++) {
                const dx = Math.max(x0 + r - x, 0, x - (x1 - r - 1)), dy = Math.max(y0 + r - y, 0, y - (y1 - r - 1));
                if (dx * dx + dy * dy <= r * r) this.set(x, y, c);
            }
        },
        ellipse(cx, cy, rx, ry, c) {
            for (let y = cy - ry; y <= cy + ry; y++) for (let x = cx - rx; x <= cx + rx; x++)
                if (((x - cx) / rx) ** 2 + ((y - cy) / ry) ** 2 <= 1) this.set(x, y, c);
        },
        hatch(c) { for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) if ((x + y) % 22 === 0) this.set(x, y, c); },
    };
}
const packs = {
    'metformin.png': { bg: [226, 228, 234], pack: [246, 247, 250], pill: [250, 250, 250], edge: [190, 194, 204], shape: 'oval', rows: 2, cols: 5 },
    'losartan.png': { bg: [224, 230, 236], pack: [245, 248, 252], pill: [214, 230, 246], edge: [170, 190, 212], shape: 'oval', rows: 2, cols: 4 },
    'sertraline.png': { bg: [230, 226, 236], pack: [248, 246, 252], pill: [236, 226, 246], edge: [196, 184, 214], shape: 'oval', rows: 2, cols: 4 },
    'vitamin-d.png': { bg: [236, 232, 222], pack: [252, 249, 240], pill: [245, 214, 130], edge: [200, 178, 120], shape: 'caps', rows: 1, cols: 3 },
    'clonazepam.png': { bg: [222, 230, 226], pack: [244, 250, 247], pill: [252, 252, 252], edge: [176, 198, 188], shape: 'round', rows: 2, cols: 5 },
    'paracetamol.png': { bg: [228, 228, 228], pack: [248, 248, 248], pill: [255, 255, 255], edge: [196, 196, 196], shape: 'oval', rows: 2, cols: 6 },
    'insulin.png': { bg: [222, 228, 238], pack: [242, 246, 252], pill: [120, 150, 200], edge: [150, 170, 205], shape: 'pen', rows: 1, cols: 1 },
};
for (const [file, p] of Object.entries(packs)) {
    const c = canvas(p.bg);
    c.rect(40, 50, W - 40, H - 50, p.edge, 18);
    c.rect(44, 54, W - 44, H - 54, p.pack, 16);
    if (p.shape === 'pen') {
        c.rect(90, 160, 360, 200, p.pill, 18);
        c.rect(360, 168, 410, 192, p.edge, 8);
        c.rect(70, 170, 92, 190, p.edge, 6);
    } else {
        const gx = (W - 88) / p.cols, gy = (H - 108) / p.rows;
        for (let r = 0; r < p.rows; r++) for (let k = 0; k < p.cols; k++) {
            const cx = Math.round(44 + gx * (k + 0.5)), cy = Math.round(54 + gy * (r + 0.5));
            c.ellipse(cx, cy, Math.round(gx * 0.36), Math.round(gy * 0.3), p.edge);
            if (p.shape === 'round') c.ellipse(cx, cy, Math.round(gx * 0.22), Math.round(gx * 0.22), p.pill);
            else if (p.shape === 'caps') { c.rect(cx - 34, cy - 16, cx + 34, cy + 16, p.pill, 16); }
            else c.ellipse(cx, cy, Math.round(gx * 0.28), Math.round(gy * 0.2), p.pill);
        }
    }
    c.hatch([205, 208, 214]);
    writeFileSync(path.join(out, file), png(c.px));
}
process.stdout.write(`Wrote ${Object.keys(packs).length} synthetic photos to ${out}\n`);
