import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import crypto from "node:crypto";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const suffix = crypto.randomBytes(16).toString("hex");
const container = `pa-financial-http-test-${suffix}`;
const database = `api_v2_financial_test_${suffix}`, password = crypto.randomBytes(24).toString("hex");
const image = (process.env.API_V2_FINANCIAL_TEST_IMAGE ?? "").trim();
let containerId = "";
function run(command, args, options = {}) { const result = spawnSync(command, args, { encoding: "utf8",
  windowsHide: true, shell: false, timeout: 300_000, ...options });
  assert.equal(result.status, 0, result.stderr || result.stdout || `${command} failed`); return result.stdout.trim(); }
try {
  if (image) run("docker", ["image", "inspect", image]);
  const mysql = ["run", "-d", "--name", container];
  if (!image) mysql.push("-p", "127.0.0.1::3306");
  mysql.push("-e", `MYSQL_ROOT_PASSWORD=${password}`, "-e", `MYSQL_DATABASE=${database}`, "mysql:8.4");
  containerId = run("docker", mysql); assert.match(containerId, /^[a-f0-9]{64}$/);
  let ready = false;
  for (let attempt = 0; attempt < 60; attempt += 1) { const result = spawnSync("docker",
    ["exec", "-e", `MYSQL_PWD=${password}`, container, "mysqladmin", "-h", "127.0.0.1", "-uroot", "ping", "--silent"],
    { encoding: "utf8", windowsHide: true, shell: false, timeout: 5_000 });
    if (result.status === 0) { ready = true; break; } await new Promise(resolve => setTimeout(resolve, 500)); }
  assert(ready, "new disposable MySQL not ready");
  const host = "127.0.0.1";
  const port = image ? "3306" : run("docker", ["port", containerId, "3306/tcp"]).match(/^127\.0\.0\.1:([0-9]+)$/)?.[1];
  assert(port, "could not resolve disposable MySQL port");
  const variables = { API_V2_FINANCIAL_MYSQL_DSN: `mysql:host=${host};port=${port};dbname=${database};charset=utf8mb4`,
    API_V2_FINANCIAL_MYSQL_USER: "root", API_V2_FINANCIAL_MYSQL_PASSWORD: password,
    API_V2_FINANCIAL_MYSQL_DATABASE: database, API_V2_FINANCIAL_MYSQL_ALLOW_DESTRUCTIVE: "isolated-disposable-only" };
  const tests = ["tests/Integration/ApiV2FinancialHttpMySqlTest.php",
    "tests/Integration/ApiV2FinancialDocumentMigrationMySqlTest.php",
    "tests/Integration/ApiV2FinancialReleaseMigrationMySqlTest.php"];
  let result;
  if (image) { const args = ["run", "--rm", "--network", `container:${containerId}`, "--workdir", "/var/www"];
    for (const [name, value] of Object.entries(variables)) args.push("--env", `${name}=${value}`);
    args.push(image, "php", "vendor/bin/phpunit", ...tests, "--do-not-cache-result", "--colors=never", "--fail-on-skipped");
    result = spawnSync("docker", args, { cwd: root, encoding: "utf8", windowsHide: true, shell: false, timeout: 300_000 });
  } else result = spawnSync("php", [...(process.platform === "win32" ? ["-d", "extension=sodium"] : []),
    "vendor/bin/phpunit", ...tests, "--do-not-cache-result", "--colors=never", "--fail-on-skipped"],
  { cwd: root, env: { ...process.env, ...variables }, encoding: "utf8", windowsHide: true, shell: false, timeout: 300_000 });
  process.stdout.write(result.stdout ?? ""); process.stderr.write(result.stderr ?? "");
  assert.equal(result.status, 0, `financial MySQL suite failed with status ${result.status}`);
} finally {
  if (containerId) { assert.equal(run("docker", ["inspect", containerId, "--format", "{{.Id}} {{.Name}} {{.Config.Image}}"]),
    `${containerId} /${container} mysql:8.4`); run("docker", ["rm", "-f", "-v", containerId]); }
}
