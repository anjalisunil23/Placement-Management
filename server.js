// Minimal static file server. Tolerates extra CLI args injected by the dev harness.
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
  '.html':'text/html; charset=utf-8', '.css':'text/css; charset=utf-8',
  '.js':'application/javascript; charset=utf-8', '.json':'application/json; charset=utf-8',
  '.svg':'image/svg+xml', '.png':'image/png', '.jpg':'image/jpeg', '.jpeg':'image/jpeg',
  '.gif':'image/gif', '.ico':'image/x-icon', '.webp':'image/webp', '.woff':'font/woff',
  '.woff2':'font/woff2', '.txt':'text/plain; charset=utf-8'
};

const root = __dirname;

const server = http.createServer((req, res) => {
  try {
    const url = decodeURIComponent((req.url || '/').split('?')[0]);
    let filePath = path.join(root, url === '/' ? '/index.html' : url);
    if (!filePath.startsWith(root)) { res.writeHead(403); return res.end('Forbidden'); }
    fs.stat(filePath, (err, st) => {
      if (err) { res.writeHead(404, { 'Content-Type':'text/html' }); return res.end('<h1>404</h1>'); }
      if (st.isDirectory()) filePath = path.join(filePath, 'index.html');
      fs.readFile(filePath, (e, buf) => {
        if (e) { res.writeHead(404); return res.end('Not found'); }
        res.writeHead(200, { 'Content-Type': MIME[path.extname(filePath).toLowerCase()] || 'application/octet-stream', 'Cache-Control': 'no-cache' });
        res.end(buf);
      });
    });
  } catch (e) {
    res.writeHead(500); res.end('Server error');
  }
});

server.listen(port, '0.0.0.0', () => console.log(`PlaceHub static server: http://localhost:${port}`));
