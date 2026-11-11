// Discover tests instead of maintaining a second fixed file list in CI.
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const mode = process.argv[2];
if (!['dom', 'browser'].includes(mode)) throw new Error('Choose dom or browser');
const files = fs.readdirSync(path.join(root, 'tests')).filter(name =>
    name.endsWith('.test.cjs') && (name.includes('_browser.') === (mode === 'browser'))
).sort().map(name => `tests/${name}`);
if (!files.length) throw new Error('No tests selected');
const result = spawnSync(process.execPath, ['--test', '--test-concurrency=1', ...files], {cwd: root, stdio: 'inherit'});
if (result.error) throw result.error;
process.exit(result.status ?? 1);
