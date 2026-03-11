#!/usr/bin/env node
const crypto = require('crypto');

function normalizeStudioWebPath(value) {
  const raw = String(value || '').trim();
  if (!raw.startsWith('/studio-output/')) return null;
  if (raw.includes('..')) return null;
  return raw;
}

function buildPublicAssetUrl(webPath, cfg = {}, opts = {}) {
  const normalized = normalizeStudioWebPath(webPath);
  if (!normalized) return null;
  const base = String(cfg.baseUrl || '').trim().replace(/\/$/, '');
  const mode = String(cfg.mode || 'public').trim().toLowerCase() === 'private' ? 'private' : 'public';
  let out = base ? `${base}${normalized}` : normalized;
  const shouldAttachPlaceholderSig = opts.sign === true || mode === 'private';
  if (shouldAttachPlaceholderSig) {
    const exp = Number(opts.expiresAt || 0) || 1_700_000_000_000;
    const secret = String(cfg.signingSecret || '').trim();
    const signer = secret
      ? crypto.createHash('sha256').update(`${normalized}|${exp}|${secret}`).digest('hex').slice(0, 24)
      : 'unsigned';
    out += (out.includes('?') ? '&' : '?') + `sig=${signer}&exp=${Math.floor(exp / 1000)}`;
  }
  return out;
}

function assert(name, condition) {
  if (!condition) throw new Error(`FAIL: ${name}`);
  console.log(`PASS: ${name}`);
}

const p = '/studio-output/generated/test.png';
const noBase = buildPublicAssetUrl(p, { baseUrl: '' });
assert('no base URL keeps relative studio web path', noBase === p);

const withBase = buildPublicAssetUrl(p, { baseUrl: 'https://assets.prismtek.dev/' });
assert('base URL joins correctly', withBase === 'https://assets.prismtek.dev/studio-output/generated/test.png');

const privateUnsigned = buildPublicAssetUrl(p, { baseUrl: 'https://app.prismtek.dev', mode: 'private' });
assert('private mode adds placeholder signature params', privateUnsigned.includes('sig=unsigned&exp='));

const privateSigned = buildPublicAssetUrl(p, { baseUrl: 'https://assets.prismtek.dev', mode: 'private', signingSecret: 'demo-secret' }, { expiresAt: 1_700_000_123_000 });
assert('private mode with signing secret adds deterministic hash signature', /sig=[0-9a-f]{24}&exp=1700000123$/.test(privateSigned));

console.log('Asset URL smoke checks passed.');
