const canvas = document.getElementById('game');
const ctx = canvas.getContext('2d');
const scoreEl = document.getElementById('score');
const statusEl = document.getElementById('status');
const startBtn = document.getElementById('startBtn');
const submitBtn = document.getElementById('submitBtn');

const GAME_SLUG = (() => {
  const p = new URLSearchParams(window.location.search);
  return (p.get('game') || 'prism-starter-game').toLowerCase();
})();

let running = false;
let score = 0;
let lastTs = 0;
let tAccum = 0;
let player = { x: 32, y: 140, w: 16, h: 16, vx: 0, vy: 0, speed: 140 };
let enemies = [];
let keys = new Set();

function reset() {
  score = 0;
  tAccum = 0;
  lastTs = 0;
  player.x = 32; player.y = 140;
  enemies = Array.from({ length: 6 }, (_, i) => ({
    x: 160 + i * 60,
    y: 20 + (i % 5) * 55,
    w: 14,
    h: 14,
    vx: -60 - Math.random() * 90,
    vy: (Math.random() - 0.5) * 45
  }));
  render();
  setStatus('Game ready. Press Start.');
}

function setStatus(msg){ statusEl.textContent = msg; }
function setScore(v){ score = Math.max(0, Math.floor(v)); scoreEl.textContent = String(score); }

function rectsOverlap(a,b){
  return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
}

function update(dt){
  const left = keys.has('arrowleft') || keys.has('a');
  const right = keys.has('arrowright') || keys.has('d');
  const up = keys.has('arrowup') || keys.has('w');
  const down = keys.has('arrowdown') || keys.has('s');

  player.vx = (right - left) * player.speed;
  player.vy = (down - up) * player.speed;
  player.x += player.vx * dt;
  player.y += player.vy * dt;

  player.x = Math.max(0, Math.min(canvas.width - player.w, player.x));
  player.y = Math.max(0, Math.min(canvas.height - player.h, player.y));

  for (const e of enemies) {
    e.x += e.vx * dt;
    e.y += e.vy * dt;
    if (e.y < 0 || e.y + e.h > canvas.height) e.vy *= -1;
    if (e.x + e.w < 0) {
      e.x = canvas.width + Math.random() * 80;
      e.y = Math.random() * (canvas.height - e.h);
      e.vx = -70 - Math.random() * 130;
      e.vy = (Math.random() - 0.5) * 60;
    }
    if (rectsOverlap(player, e)) {
      running = false;
      setStatus(`Game over. Final score: ${score}`);
      return;
    }
  }

  tAccum += dt;
  if (tAccum >= 0.2) {
    setScore(score + 1);
    tAccum = 0;
  }
}

function render(){
  ctx.fillStyle = '#050814';
  ctx.fillRect(0,0,canvas.width,canvas.height);

  // stars
  ctx.fillStyle = '#1f2d63';
  for (let i=0;i<30;i++) {
    const x = (i * 37) % canvas.width;
    const y = (i * 53) % canvas.height;
    ctx.fillRect(x,y,2,2);
  }

  ctx.fillStyle = '#66e3ff';
  ctx.fillRect(player.x, player.y, player.w, player.h);

  ctx.fillStyle = '#ff5e78';
  for (const e of enemies) ctx.fillRect(e.x, e.y, e.w, e.h);
}

function loop(ts){
  if (!running) return;
  if (!lastTs) lastTs = ts;
  const dt = Math.min(0.05, (ts - lastTs) / 1000);
  lastTs = ts;
  update(dt);
  render();
  if (running) requestAnimationFrame(loop);
}

async function submitScore(){
  if (score <= 0) return setStatus('No score to submit yet.');
  setStatus('Submitting score...');
  try {
    const fd = new FormData();
    fd.append('game', GAME_SLUG);
    fd.append('score', String(score));
    const playerKey = localStorage.getItem('pixel_player_key') || (`pk_${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`);
    localStorage.setItem('pixel_player_key', playerKey);
    fd.append('playerKey', playerKey);
    fd.append('name', localStorage.getItem('pixel_player_name') || 'Guest');
    const res = await fetch('/wp-json/prismtek/v1/scores', { method:'POST', body: fd, credentials:'include' });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || !json?.ok) throw new Error(json?.error || `HTTP ${res.status}`);
    setStatus('Score submitted ✅');
  } catch (err) {
    setStatus(`Score submit failed: ${err.message}`);
  }
}

window.addEventListener('keydown', (e) => keys.add(e.key.toLowerCase()));
window.addEventListener('keyup', (e) => keys.delete(e.key.toLowerCase()));

startBtn.addEventListener('click', () => {
  reset();
  running = true;
  setStatus('Survive as long as possible.');
  requestAnimationFrame(loop);
});
submitBtn.addEventListener('click', submitScore);

reset();
