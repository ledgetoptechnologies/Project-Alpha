import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import crypto from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const suffix = crypto.randomBytes(16).toString('hex');
const name = `pa-archive-http-${suffix}`;
const database = `pa_archive_http_${suffix}`;
const password = crypto.randomBytes(24).toString('hex');
const image = (process.env.ARCHIVE_HTTP_TEST_IMAGE ?? '').trim();
let id = '';
function run(command, args, options = {}) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true,
    shell: false, timeout: 300_000, ...options });
  assert.equal(result.status, 0, [result.stdout, result.stderr].filter(Boolean).join('\n') || `${command} failed`);
  return result.stdout.trim();
}
try {
  const args = ['run', '-d', '--name', name];
  if (!image) args.push('-p', '127.0.0.1::3306');
  args.push('-e', `MYSQL_ROOT_PASSWORD=${password}`, '-e', `MYSQL_DATABASE=${database}`, 'mysql:8.4');
  id = run('docker', args);
  assert.match(id, /^[a-f0-9]{64}$/);
  let ready = false;
  for (let attempt = 0; attempt < 60; attempt++) {
    const probe = spawnSync('docker', ['exec', '-e', `MYSQL_PWD=${password}`, id,
      'mysqladmin', '-h', '127.0.0.1', '-uroot', 'ping', '--silent'],
    { encoding: 'utf8', windowsHide: true, shell: false, timeout: 5_000 });
    if (probe.status === 0) { ready = true; break; }
    await new Promise(resolve => setTimeout(resolve, 500));
  }
  assert(ready, 'disposable MySQL did not become ready');
  const port = image ? '3306' : run('docker', ['port', id, '3306/tcp']).match(/^127\.0\.0\.1:([0-9]+)$/)?.[1];
  assert(port);
  const variables = {
    ARCHIVE_HTTP_DATABASE: database,
    ARCHIVE_HTTP_DSN: `mysql:host=127.0.0.1;port=${port};dbname=${database};charset=utf8mb4`,
    ARCHIVE_HTTP_PASSWORD: password,
    ARCHIVE_HTTP_ALLOW: 'isolated-disposable-only',
  };
  const tests = ['vendor/bin/phpunit', 'tests/Integration/ClientArchivePolicyHttpMySqlTest.php',
    '--do-not-cache-result', '--colors=never', '--fail-on-skipped'];
  if (image) {
    const docker = ['run', '--rm', '--network', `container:${id}`,
      '--mount', `type=bind,source=${root},target=/source,readonly`];
    for (const [key, value] of Object.entries(variables)) docker.push('--env', `${key}=${value}`);
    // Use this checkout's front controller, helper and tests, even with an older
    // image. Reuse vendor only when its lock matches; copying it under the new
    // root also makes Composer's relative PSR-4 paths resolve current src.
    const stage = `set -eu
cmp -s /source/composer.lock /var/www/composer.lock || { echo 'Test image Composer lock differs from checkout' >&2; exit 1; }
test_root=$(mktemp -d /tmp/pa-archive-http.XXXXXX)
cp -a /source/public /source/src /source/database /source/tests /source/phpunit.xml "$test_root/"
cp -a /var/www/vendor "$test_root/vendor"
cd "$test_root"
exec php "$@"`;
    docker.push('--entrypoint', 'sh', image, '-c', stage, 'archive-http', ...tests);
    process.stdout.write(run('docker', docker) + '\n');
  } else process.stdout.write(run('php', tests, { cwd: root, env: { ...process.env, ...variables } }) + '\n');
} finally {
  if (id) {
    assert.equal(run('docker', ['inspect', id, '--format', '{{.Id}} {{.Name}} {{.Config.Image}}']), `${id} /${name} mysql:8.4`);
    run('docker', ['rm', '-f', '-v', id]);
  }
}

