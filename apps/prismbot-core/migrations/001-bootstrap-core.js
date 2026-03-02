const fs = require('fs');
const path = require('path');

const base = path.resolve(__dirname, '..', 'data');
const files = {
  users: {},
  sessions: {},
  profiles: {},
  history: {},
  tasks: [],
  activity: [],
  integrations: {},
};

fs.mkdirSync(base, { recursive: true });
for (const [name, initial] of Object.entries(files)) {
  const p = path.join(base, `${name}.json`);
  if (!fs.existsSync(p)) fs.writeFileSync(p, JSON.stringify(initial, null, 2));
}
console.log('Bootstrap complete:', base);
