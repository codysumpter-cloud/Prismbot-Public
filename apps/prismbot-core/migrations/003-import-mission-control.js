const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const mission = path.join(root, 'mission-control', 'data');
const core = path.join(root, 'prismbot-core', 'data');

function load(file, fallback) {
  try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch { return fallback; }
}
function save(file, data) { fs.writeFileSync(file, JSON.stringify(data, null, 2)); }

const coreTasks = load(path.join(core, 'tasks.json'), []);
const coreActivity = load(path.join(core, 'activity.json'), []);
const coreIntegrations = load(path.join(core, 'integrations.json'), {});

const missionTasks = load(path.join(mission, 'tasks.json'), []);
const missionActivity = load(path.join(mission, 'activity.json'), []);
const missionUsers = load(path.join(mission, 'family-users.json'), []);
const missionQueue = load(path.join(mission, 'family-chat-queue.json'), []);

const tasks = [...(Array.isArray(coreTasks) ? coreTasks : []), ...(Array.isArray(missionTasks) ? missionTasks : [])];
const activity = [...(Array.isArray(coreActivity) ? coreActivity : []), ...(Array.isArray(missionActivity) ? missionActivity : [])];

const integrations = {
  ...coreIntegrations,
  missionControl: {
    importedAt: new Date().toISOString(),
    familyUsersCount: Array.isArray(missionUsers) ? missionUsers.length : 0,
    queueCount: Array.isArray(missionQueue) ? missionQueue.length : 0,
  },
};

save(path.join(core, 'tasks.json'), tasks);
save(path.join(core, 'activity.json'), activity);
save(path.join(core, 'integrations.json'), integrations);

console.log('Imported mission-control data into prismbot-core');
console.log('Counts:', {
  tasks: tasks.length,
  activity: activity.length,
  missionUsers: Array.isArray(missionUsers) ? missionUsers.length : 0,
});
