const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const kid = path.join(root, 'kid-chat-mvp');
const core = path.join(root, 'prismbot-core', 'data');

function load(file, fallback) {
  try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch { return fallback; }
}
function save(file, data) { fs.writeFileSync(file, JSON.stringify(data, null, 2)); }

const users = load(path.join(kid, 'users.json'), {});
const profiles = load(path.join(kid, 'profiles.json'), {});
const history = load(path.join(kid, 'history.json'), {});
const tasks = load(path.join(kid, 'tasks.json'), []);
const activity = load(path.join(kid, 'activity.json'), []);
const builder = load(path.join(kid, 'builder.json'), {});

const coreProfiles = Object.fromEntries(Object.entries(profiles).map(([uid, p]) => [uid, { ...p, builder: builder[uid] || null }]));

save(path.join(core, 'users.json'), users);
save(path.join(core, 'profiles.json'), coreProfiles);
save(path.join(core, 'history.json'), history);
save(path.join(core, 'tasks.json'), Array.isArray(tasks) ? tasks : []);
save(path.join(core, 'activity.json'), Array.isArray(activity) ? activity : []);

console.log('Imported kid-chat data into prismbot-core');
console.log('Counts:', {
  users: Object.keys(users || {}).length,
  profiles: Object.keys(coreProfiles || {}).length,
  historyUsers: Object.keys(history || {}).length,
});
