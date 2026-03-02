#!/usr/bin/env node
const http = require('node:http');

const PORT = process.env.PORT || 8787;

const server = http.createServer(async (req, res) => {
  if (req.method === 'POST' && req.url === '/api/mobile/chat') {
    let body = '';
    req.on('data', (chunk) => (body += chunk.toString()));
    req.on('end', () => {
      try {
        const { message } = JSON.parse(body || '{}');
        const reply = `Bridge online. You said: ${message || '(empty)'}`;
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ reply }));
      } catch {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Invalid JSON' }));
      }
    });
    return;
  }

  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ error: 'Not found' }));
});

server.listen(PORT, () => {
  console.log(`prismbot-mobile dev bridge running on http://0.0.0.0:${PORT}`);
});
