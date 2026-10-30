const http = require("node:http");
const fs = require("node:fs");
const path = require("node:path");
const port = Number(process.argv[2] || 8904);
const manifest = require("./document-manifest.json");
const files = new Set(
  Object.values(manifest).flatMap((file) => [file.filename, ...file.pages]),
);
const appFiles = new Set(["index.html", "app.js", "app.css"]);
http
  .createServer((req, res) => {
    res.setHeader("Cache-Control", "no-store");
    res.setHeader("X-PKG-06B-Source", "8821-v9");
    res.setHeader("X-Content-Type-Options", "nosniff");
    res.setHeader(
      "Content-Security-Policy",
      "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' blob: data:; connect-src 'none'; font-src 'self'; base-uri 'none'; form-action 'none'",
    );
    if (!["GET", "HEAD"].includes(req.method)) {
      res.writeHead(405, { Allow: "GET, HEAD" });
      return res.end();
    }
    let url, name;
    try {
      url = new URL(req.url, "http://127.0.0.1");
      name = decodeURIComponent(url.pathname);
    } catch {
      res.writeHead(400);
      return res.end();
    }
    const isFile = name.startsWith("/files/");
    name = isFile ? name.slice(7) : name === "/" ? "index.html" : name.slice(1);
    if (!(isFile ? files : appFiles).has(name)) {
      res.writeHead(404);
      return res.end("Not a preview resource");
    }
    const fullPath = path.join(__dirname, isFile ? "files" : "", name);
    let data;
    try {
      data = fs.readFileSync(fullPath);
    } catch {
      res.writeHead(404);
      return res.end("File unavailable");
    }
    const mime = {
      ".html": "text/html; charset=utf-8",
      ".js": "text/javascript",
      ".css": "text/css",
      ".pdf": "application/pdf",
      ".png": "image/png",
      ".jpg": "image/jpeg",
    };
    res.setHeader(
      "Content-Type",
      mime[path.extname(name)] || "application/octet-stream",
    );
    if (isFile) {
      res.setHeader(
        "Content-Disposition",
        (url.searchParams.get("download") === "1" ? "attachment" : "inline") +
          '; filename="' +
          name +
          '"',
      );
      res.setHeader("Accept-Ranges", "bytes");
    }
    let start = 0,
      end = data.length - 1,
      status = 200;
    if (isFile && req.headers.range) {
      const match = /^bytes=(\d+)-(\d*)$/.exec(req.headers.range);
      start = match ? Number(match[1]) : -1;
      end = match && match[2] ? Number(match[2]) : data.length - 1;
      if (start < 0 || start > end || start >= data.length) {
        res.writeHead(416, { "Content-Range": "bytes */" + data.length });
        return res.end();
      }
      end = Math.min(end, data.length - 1);
      status = 206;
      res.setHeader(
        "Content-Range",
        "bytes " + start + "-" + end + "/" + data.length,
      );
    }
    res.setHeader("Content-Length", end - start + 1);
    res.writeHead(status);
    res.end(req.method === "HEAD" ? undefined : data.subarray(start, end + 1));
  })
  .listen(port, "127.0.0.1", () =>
    console.log(
      JSON.stringify({
        preview: "PKG-06B v9",
        port,
        root: __dirname,
        pid: process.pid,
      }),
    ),
  );
