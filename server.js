// Static file server for PlaceHub (Windows-safe paths + extensionless HTML routes).
const http = require('http');
const fs = require('fs');
const path = require('path');

const args = process.argv.slice(2);
let port = parseInt(process.env.PORT || '8080', 10);
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === '-p' || a === '--port') { port = parseInt(args[i + 1], 10) || port; i++; }
  else if (a.startsWith('--port=')) { port = parseInt(a.split('=')[1], 10) || port; }
}

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.ico': 'image/x-icon',
  '.webp': 'image/webp',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.txt': 'text/plain; charset=utf-8',
};

const root = path.resolve(__dirname);

function safeResolve(relativePath) {
  const filePath = path.resolve(root, relativePath);
  const rel = path.relative(root, filePath);
  if (rel.startsWith('..') || path.isAbsolute(rel)) return null;
  return filePath;
}

function urlPathname(url) {
  return decodeURIComponent((url || '/').split('?')[0]);
}

function urlToRelative(url) {
  const trimmed = urlPathname(url).replace(/^\/+/, '').replace(/\/+$/, '');
  return trimmed || 'public-stats.html';
}

function candidatesFor(rel) {
  const list = [rel];
  if (!path.extname(rel)) list.push(`${rel}.html`);
  return [...new Set(list)];
}

function findFile(rel, cb) {
  const tryCandidate = (names, idx = 0) => {
    if (idx >= names.length) return cb(null);
    const candidate = names[idx];
    const filePath = safeResolve(candidate);
    if (!filePath) return tryCandidate(names, idx + 1);

    fs.stat(filePath, (err, st) => {
      if (err) return tryCandidate(names, idx + 1);
      if (st.isFile()) return cb(filePath);
      if (st.isDirectory()) {
        const indexPath = safeResolve(path.join(candidate, 'index.html'));
        if (!indexPath) return tryCandidate(names, idx + 1);
        return fs.stat(indexPath, (e2, st2) => {
          if (!e2 && st2.isFile()) return cb(indexPath);
          tryCandidate(names, idx + 1);
        });
      }
      tryCandidate(names, idx + 1);
    });
  };

  tryCandidate(candidatesFor(rel));
}

function send404(res) {
  res.writeHead(404, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end('<h1>404 — Not found</h1><p><a href="/public-stats">Back to home</a></p>');
}

const server = http.createServer((req, res) => {
  try {
    const pathname = urlPathname(req.url);
    if (pathname === '/' || pathname === '/index.html' || pathname === '/index') {
      res.writeHead(302, { Location: '/public-stats' });
      return res.end();
    }

    const rel = urlToRelative(req.url);
    findFile(rel, (filePath) => {
      if (!filePath) return send404(res);
      fs.readFile(filePath, (err, buf) => {
        if (err) return send404(res);
        const ext = path.extname(filePath).toLowerCase();
        res.writeHead(200, {
          'Content-Type': MIME[ext] || 'application/octet-stream',
          'Cache-Control': 'no-cache',
        });
        res.end(buf);
      });
    });
  } catch (e) {
    res.writeHead(500);
    res.end('Server error');
  }
});

server.listen(port, '0.0.0.0', () => {
  console.log(`PlaceHub static server: http://localhost:${port}`);
  console.log(`Serving files from: ${root}`);
});
