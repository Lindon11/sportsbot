#!/usr/bin/env bash
set -uo pipefail

cd "$(dirname "$0")/.."

OUTPUT=""
COMPARE=""
JSON_ONLY=0
STRICT=0
WITH_HEALTH=0
HELPER="/usr/local/bin/sportsbot-fix-permissions"

usage() {
  cat <<'USAGE'
SportsBot environment audit and comparison.

Create a local snapshot:
  ./scripts/sportsbot-environment-audit.sh --write sportsbot-env-local.json

Compare a live server against that snapshot:
  ./scripts/sportsbot-environment-audit.sh --compare sportsbot-env-local.json --strict

Options:
  --write PATH       Write the full JSON audit report to PATH.
  --compare PATH     Compare this server's report against a previous JSON report.
  --json             Print full JSON instead of a short text summary.
  --strict           Treat comparison warnings as failures.
  --with-health      Include php artisan sportsbot:health --json --render in the report.
  -h, --help         Show this help.
USAGE
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --write)
      OUTPUT="${2:-}"
      shift 2
      ;;
    --compare)
      COMPARE="${2:-}"
      shift 2
      ;;
    --json)
      JSON_ONLY=1
      shift
      ;;
    --strict)
      STRICT=1
      shift
      ;;
    --with-health)
      WITH_HEALTH=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [ -n "$OUTPUT" ]; then
  mkdir -p "$(dirname "$OUTPUT")"
fi

TMP_REPORT="$(mktemp)"
SPORTSBOT_AUDIT_WITH_HEALTH="$WITH_HEALTH" SPORTSBOT_AUDIT_HELPER="$HELPER" php > "$TMP_REPORT" <<'PHP'
<?php

$root = getcwd();

function audit_run(string $command, ?string $cwd = null): array
{
    $cwd ??= getcwd();
    $previous = getcwd();
    $changed = @chdir($cwd);
    $output = [];
    $code = 127;

    if ($changed) {
        @exec($command . ' 2>&1', $output, $code);
        @chdir($previous);
    }

    return [
        'command' => $command,
        'exit_code' => $code,
        'ok' => $code === 0,
        'output' => trim(implode("\n", $output)),
    ];
}

function audit_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $json = json_decode((string) file_get_contents($path), true);

    return is_array($json) ? $json : [];
}

function audit_hash(?string $path): ?string
{
    return $path && is_file($path) ? hash_file('sha256', $path) : null;
}

function audit_lock_packages(string $path): array
{
    $json = audit_json($path);
    $packages = [];

    foreach (['packages', 'packages-dev'] as $section) {
        foreach ((array) ($json[$section] ?? []) as $package) {
            if (!is_array($package) || !isset($package['name'])) {
                continue;
            }

            $packages[(string) $package['name']] = [
                'version' => (string) ($package['version'] ?? ''),
                'source_reference' => (string) ($package['source']['reference'] ?? ''),
                'dev' => $section === 'packages-dev',
            ];
        }
    }

    ksort($packages);

    return $packages;
}

function audit_installed_composer_packages(string $path): array
{
    $json = audit_json($path);
    $rows = isset($json['packages']) ? (array) $json['packages'] : (array) $json;
    $packages = [];

    foreach ($rows as $package) {
        if (!is_array($package) || !isset($package['name'])) {
            continue;
        }

        $packages[(string) $package['name']] = [
            'version' => (string) ($package['version'] ?? ''),
            'dev_requirement' => (bool) ($package['dev_requirement'] ?? false),
        ];
    }

    ksort($packages);

    return $packages;
}

function audit_npm_lock_packages(string $path): array
{
    $json = audit_json($path);
    $packages = [];

    foreach ((array) ($json['packages'] ?? []) as $packagePath => $package) {
        if ($packagePath === '' || !is_array($package) || !isset($package['version'])) {
            continue;
        }

        $name = str_starts_with((string) $packagePath, 'node_modules/')
            ? substr((string) $packagePath, strlen('node_modules/'))
            : (string) ($package['name'] ?? $packagePath);

        if ($name === '') {
            continue;
        }

        $packages[$name] = [
            'version' => (string) ($package['version'] ?? ''),
            'dev' => (bool) ($package['dev'] ?? false),
            'optional' => (bool) ($package['optional'] ?? false),
        ];
    }

    ksort($packages);

    return $packages;
}

function audit_package_json_direct(string $path, array $lockPackages): array
{
    $json = audit_json($path);
    $direct = [];

    foreach (['dependencies', 'devDependencies'] as $section) {
        foreach ((array) ($json[$section] ?? []) as $name => $constraint) {
            $direct[(string) $name] = [
                'constraint' => (string) $constraint,
                'locked_version' => (string) ($lockPackages[$name]['version'] ?? ''),
                'dev' => $section === 'devDependencies',
            ];
        }
    }

    ksort($direct);

    return $direct;
}

function audit_extensions(array $required): array
{
    $all = get_loaded_extensions();
    sort($all);
    $requiredStatus = [];

    foreach ($required as $extension) {
        $requiredStatus[$extension] = [
            'loaded' => extension_loaded($extension),
            'version' => extension_loaded($extension) ? (phpversion($extension) ?: '') : '',
        ];
    }

    return [
        'required' => $requiredStatus,
        'loaded' => $all,
    ];
}

function audit_relevant_env(): array
{
    $keys = [
        'APP_ENV',
        'APP_URL',
        'APP_DEBUG',
        'DB_CONNECTION',
        'CACHE_STORE',
        'CACHE_DRIVER',
        'QUEUE_CONNECTION',
        'SPORTSBOT_CARD_V3_BROWSER_ENABLED',
        'SPORTSBOT_CARD_GD_FALLBACK_ENABLED',
        'SPORTSBOT_CARD_NODE_BINARY',
        'SPORTSBOT_CARD_CHROME_PATH',
        'SPORTSBOT_CARD_BROWSER_TIMEOUT',
        'SPORTSBOT_CARD_BROWSER_RETRIES',
        'PUPPETEER_EXECUTABLE_PATH',
    ];
    $secretKeys = [
        'APP_KEY',
        'DB_PASSWORD',
        'SPORTSBOT_THESPORTSDB_API_KEY',
        'SPORTSBOT_TELEGRAM_BOT_TOKEN',
        'SPORTSBOT_DISCORD_BOT_TOKEN',
        'LARAVEL_CP_LICENSE',
    ];
    $env = [];

    foreach ($keys as $key) {
        $value = getenv($key);
        $env[$key] = $value === false ? null : (string) $value;
    }

    foreach ($secretKeys as $key) {
        $value = getenv($key);
        $env[$key] = [
            'configured' => $value !== false && trim((string) $value) !== '',
            'length' => $value === false ? 0 : strlen((string) $value),
        ];
    }

    return $env;
}

function audit_file_group(string $base, string $glob): array
{
    $files = [];

    foreach (glob($base . '/' . $glob) ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }

        $relative = ltrim(substr($path, strlen($base)), DIRECTORY_SEPARATOR);
        $files[$relative] = [
            'size' => filesize($path),
            'sha256' => audit_hash($path),
        ];
    }

    ksort($files);

    return $files;
}

function audit_system_packages(array $packages): array
{
    $status = [];
    $hasDpkg = audit_run('command -v dpkg-query')['ok'];

    foreach ($packages as $package) {
        if (!$hasDpkg) {
            $status[$package] = ['installed' => null, 'version' => '', 'reason' => 'dpkg-query unavailable'];
            continue;
        }

        $result = audit_run('dpkg-query -W -f=\'${Version}\' ' . escapeshellarg($package));
        $status[$package] = [
            'installed' => $result['ok'],
            'version' => $result['ok'] ? trim($result['output']) : '',
            'reason' => $result['ok'] ? '' : trim($result['output']),
        ];
    }

    return $status;
}

function audit_bool_path(string $path): array
{
    return [
        'exists' => is_dir($path) || is_file($path),
        'is_dir' => is_dir($path),
        'is_file' => is_file($path),
        'readable' => is_readable($path),
        'writable' => is_writable($path),
    ];
}

$backendLock = audit_npm_lock_packages($root . '/package-lock.json');
$adminLock = audit_npm_lock_packages($root . '/resources/admin/package-lock.json');
$requiredExtensions = ['curl', 'fileinfo', 'gd', 'json', 'mbstring', 'openssl', 'pdo_mysql', 'xml', 'zip'];
$chromePackages = ['fonts-dejavu-core', 'chromium', 'chromium-browser', 'libnss3', 'libnspr4', 'libatk-bridge2.0-0', 'libatk1.0-0', 'libcups2', 'libdrm2', 'libxkbcommon0', 'libxcomposite1', 'libxdamage1', 'libxrandr2', 'libgbm1'];

$nodePuppeteerScript = <<<'NODE'
const fs=require('fs');
let p=null, pkg=null, source='';
try{p=require('puppeteer'); pkg=require('puppeteer/package.json'); source='puppeteer';}
catch(e){try{p=require('puppeteer-core'); pkg=require('puppeteer-core/package.json'); source='puppeteer-core';}catch(e2){}}
const candidates=[process.env.SPORTSBOT_CARD_CHROME_PATH,process.env.PUPPETEER_EXECUTABLE_PATH,'/usr/bin/chromium','/usr/bin/chromium-browser','/usr/bin/google-chrome','/usr/bin/google-chrome-stable'];
let executable=candidates.find(v=>v&&fs.existsSync(v)) || '';
if(!executable&&p&&p.executablePath){try{const guessed=p.executablePath();if(guessed&&fs.existsSync(guessed))executable=guessed}catch(e){}}
console.log(JSON.stringify({source, version: pkg ? pkg.version : '', executable}));
NODE;

$puppeteerResult = audit_run('node -e ' . escapeshellarg($nodePuppeteerScript), $root);
$puppeteer = json_decode($puppeteerResult['output'], true);
$puppeteer = is_array($puppeteer) ? $puppeteer : ['source' => '', 'version' => '', 'executable' => ''];
$chromeVersion = $puppeteer['executable'] !== ''
    ? audit_run(escapeshellarg((string) $puppeteer['executable']) . ' --version')
    : ['ok' => false, 'output' => '', 'exit_code' => null];

$artisanVersion = is_file($root . '/artisan')
    ? audit_run('php artisan --version', $root)
    : ['ok' => false, 'output' => '', 'exit_code' => null];
$migrateStatus = is_file($root . '/artisan')
    ? audit_run('php artisan migrate:status --no-interaction', $root)
    : ['ok' => false, 'output' => '', 'exit_code' => null];
$sportsbotHealth = null;

if ((string) getenv('SPORTSBOT_AUDIT_WITH_HEALTH') === '1' && is_file($root . '/artisan')) {
    $sportsbotHealth = audit_run('php artisan sportsbot:health --json --fix --render', $root);
}

$report = [
    'schema' => 'sportsbot-environment-audit/v1',
    'generated_at' => date('c'),
    'root' => $root,
    'git' => [
        'branch' => audit_run('git -C ' . escapeshellarg(dirname($root)) . ' rev-parse --abbrev-ref HEAD')['output'],
        'commit' => audit_run('git -C ' . escapeshellarg(dirname($root)) . ' rev-parse HEAD')['output'],
        'status_porcelain' => audit_run('git -C ' . escapeshellarg(dirname($root)) . ' status --porcelain')['output'],
    ],
    'os' => [
        'php_os_family' => PHP_OS_FAMILY,
        'php_uname' => php_uname(),
        'uname' => audit_run('uname -a')['output'],
    ],
    'php' => [
        'version' => PHP_VERSION,
        'version_id' => PHP_VERSION_ID,
        'sapi' => PHP_SAPI,
        'binary' => PHP_BINARY,
        'ini_loaded_file' => php_ini_loaded_file() ?: '',
        'extensions' => audit_extensions($requiredExtensions),
    ],
    'composer' => [
        'version' => audit_run('composer --version')['output'],
        'lock_hash' => audit_hash($root . '/composer.lock'),
        'lock_packages' => audit_lock_packages($root . '/composer.lock'),
        'installed_packages' => audit_installed_composer_packages($root . '/vendor/composer/installed.json'),
        'platform_check' => audit_run('composer check-platform-reqs --no-interaction', $root),
    ],
    'node' => [
        'version' => audit_run('node --version')['output'],
        'npm_version' => audit_run('npm --version')['output'],
        'backend_lock_hash' => audit_hash($root . '/package-lock.json'),
        'backend_direct_packages' => audit_package_json_direct($root . '/package.json', $backendLock),
        'backend_lock_packages' => $backendLock,
        'admin_lock_hash' => audit_hash($root . '/resources/admin/package-lock.json'),
        'admin_direct_packages' => audit_package_json_direct($root . '/resources/admin/package.json', $adminLock),
        'admin_lock_packages' => $adminLock,
        'puppeteer_probe' => $puppeteerResult,
        'puppeteer' => $puppeteer,
        'chrome_version' => $chromeVersion,
    ],
    'system_packages' => audit_system_packages($chromePackages),
    'files' => [
        'hashes' => [
            'composer_json' => audit_hash($root . '/composer.json'),
            'composer_lock' => audit_hash($root . '/composer.lock'),
            'backend_package_json' => audit_hash($root . '/package.json'),
            'backend_package_lock' => audit_hash($root . '/package-lock.json'),
            'admin_package_json' => audit_hash($root . '/resources/admin/package.json'),
            'admin_package_lock' => audit_hash($root . '/resources/admin/package-lock.json'),
            'sportsbot_config' => audit_hash($root . '/app/Plugins/SportsBot/config.php'),
            'sportsbot_renderer_php' => audit_hash($root . '/app/Plugins/SportsBot/Services/SportsBotCardRenderer.php'),
            'sportsbot_v3_renderer' => audit_hash($root . '/resources/sportsbot/v3-card-renderer.cjs'),
            'production_check_script' => audit_hash($root . '/scripts/sportsbot-production-check.sh'),
            'environment_audit_script' => audit_hash($root . '/scripts/sportsbot-environment-audit.sh'),
        ],
        'sportsbot_migrations' => audit_file_group($root . '/app/Plugins/SportsBot/database/migrations', '*.php'),
        'card_templates' => audit_file_group($root . '/resources/cards/templates', '*'),
        'card_themes' => audit_file_group($root . '/resources/cards/themes', '*'),
    ],
    'env' => audit_relevant_env(),
    'paths' => [
        'storage' => audit_bool_path($root . '/storage'),
        'storage_logs' => audit_bool_path($root . '/storage/logs'),
        'sportsbot_cards' => audit_bool_path($root . '/storage/app/sportsbot/cards'),
        'sportsbot_assets' => audit_bool_path($root . '/storage/app/sportsbot/assets'),
        'sportsbot_render_input' => audit_bool_path($root . '/storage/app/sportsbot/render-input'),
        'sportsbot_render_debug' => audit_bool_path($root . '/storage/app/sportsbot/render-debug'),
        'bootstrap_cache' => audit_bool_path($root . '/bootstrap/cache'),
        'sudo_helper' => [
            'path' => (string) getenv('SPORTSBOT_AUDIT_HELPER'),
            'sudo_list' => audit_run('sudo -n -l ' . escapeshellarg((string) getenv('SPORTSBOT_AUDIT_HELPER'))),
        ],
    ],
    'laravel' => [
        'artisan_version' => $artisanVersion,
        'migrate_status' => $migrateStatus,
        'sportsbot_health_render' => $sportsbotHealth,
    ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
PHP
REPORT="$(cat "$TMP_REPORT")"
rm -f "$TMP_REPORT"

if [ -n "$OUTPUT" ]; then
  printf '%s\n' "$REPORT" > "$OUTPUT"
fi

if [ "$JSON_ONLY" -eq 1 ]; then
  printf '%s\n' "$REPORT"
fi

if [ -n "$COMPARE" ]; then
  if [ ! -f "$COMPARE" ]; then
    echo "Compare file not found: $COMPARE" >&2
    exit 2
  fi

  TMP_CURRENT="$(mktemp)"
  printf '%s\n' "$REPORT" > "$TMP_CURRENT"

  SPORTSBOT_AUDIT_CURRENT="$TMP_CURRENT" SPORTSBOT_AUDIT_BASELINE="$COMPARE" SPORTSBOT_AUDIT_STRICT="$STRICT" php <<'PHP'
<?php

$current = json_decode((string) file_get_contents((string) getenv('SPORTSBOT_AUDIT_CURRENT')), true) ?: [];
$baseline = json_decode((string) file_get_contents((string) getenv('SPORTSBOT_AUDIT_BASELINE')), true) ?: [];
$strict = ((string) getenv('SPORTSBOT_AUDIT_STRICT')) === '1';
$failures = [];
$warnings = [];
$passes = [];

function dot_get(array $data, string $path): mixed
{
    $value = $data;
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }

    return $value;
}

function same_or_fail(array $baseline, array $current, string $path, string $label, array &$passes, array &$failures): void
{
    $expected = dot_get($baseline, $path);
    $actual = dot_get($current, $path);

    if ($expected === $actual && $actual !== null && $actual !== '') {
        $passes[] = $label;
        return;
    }

    $failures[] = $label . ' mismatch. expected=' . json_encode($expected) . ' actual=' . json_encode($actual);
}

function same_or_warn(array $baseline, array $current, string $path, string $label, array &$passes, array &$warnings): void
{
    $expected = dot_get($baseline, $path);
    $actual = dot_get($current, $path);

    if ($expected === $actual && $actual !== null && $actual !== '') {
        $passes[] = $label;
        return;
    }

    $warnings[] = $label . ' differs. expected=' . json_encode($expected) . ' actual=' . json_encode($actual);
}

foreach ([
    'files.hashes.composer_lock' => 'composer.lock',
    'files.hashes.backend_package_lock' => 'backend package-lock.json',
    'files.hashes.admin_package_lock' => 'admin package-lock.json',
    'files.hashes.sportsbot_v3_renderer' => 'SportsBot v3 renderer file',
    'files.hashes.sportsbot_config' => 'SportsBot config file',
    'files.sportsbot_migrations' => 'SportsBot migration files',
    'files.card_templates' => 'card template files',
    'files.card_themes' => 'card theme files',
] as $path => $label) {
    same_or_fail($baseline, $current, $path, $label, $passes, $failures);
}

foreach ([
    'composer.lock_packages' => 'Composer locked dependency graph',
    'node.backend_lock_packages' => 'backend NPM locked dependency graph',
    'node.admin_lock_packages' => 'admin NPM locked dependency graph',
] as $path => $label) {
    same_or_fail($baseline, $current, $path, $label, $passes, $failures);
}

foreach ((array) dot_get($baseline, 'php.extensions.required') as $extension => $meta) {
    $loaded = (bool) dot_get($current, 'php.extensions.required.' . $extension . '.loaded');
    if ($loaded) {
        $passes[] = 'PHP extension loaded: ' . $extension;
    } else {
        $failures[] = 'PHP extension missing on current server: ' . $extension;
    }
}

foreach ((array) dot_get($current, 'system_packages') as $package => $meta) {
    if (($meta['installed'] ?? true) === false) {
        $failures[] = 'System package missing on current server: ' . $package;
    }
}

foreach ([
    'php.version' => 'PHP version',
    'node.version' => 'Node version',
    'node.npm_version' => 'NPM version',
    'node.puppeteer.source' => 'Puppeteer package source',
    'node.puppeteer.version' => 'Puppeteer version',
    'node.chrome_version.output' => 'Chrome/Chromium version',
    'os.php_os_family' => 'OS family',
] as $path => $label) {
    same_or_warn($baseline, $current, $path, $label, $passes, $warnings);
}

foreach ([
    'composer.platform_check' => 'Composer platform check',
    'laravel.migrate_status' => 'Laravel migration status',
] as $path => $label) {
    $value = dot_get($current, $path);
    if (is_array($value) && ($value['ok'] ?? false)) {
        $passes[] = $label;
    } else {
        $failures[] = $label . ' failed on current server: ' . json_encode($value);
    }
}

$health = dot_get($current, 'laravel.sportsbot_health_render');
if ($health !== null) {
    if (is_array($health) && ($health['ok'] ?? false)) {
        $passes[] = 'SportsBot Browser v3 render health';
    } else {
        $failures[] = 'SportsBot Browser v3 render health failed: ' . json_encode($health);
    }
}

foreach ([
    'paths.storage_logs.writable' => 'storage/logs writable',
    'paths.sportsbot_cards.writable' => 'sportsbot cards writable',
    'paths.sportsbot_assets.writable' => 'sportsbot assets writable',
    'paths.sportsbot_render_input.writable' => 'sportsbot render-input writable',
    'paths.bootstrap_cache.writable' => 'bootstrap/cache writable',
] as $path => $label) {
    if (dot_get($current, $path) === true) {
        $passes[] = $label;
    } else {
        $failures[] = $label . ' failed on current server';
    }
}

echo "== Environment comparison ==\n";
foreach ($passes as $pass) {
    echo "PASS: {$pass}\n";
}
foreach ($warnings as $warning) {
    echo "WARN: {$warning}\n";
}
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}

echo "\nSummary: " . count($passes) . " pass, " . count($warnings) . " warn, " . count($failures) . " fail\n";

if (count($failures) > 0 || ($strict && count($warnings) > 0)) {
    exit(1);
}
PHP
  COMPARE_EXIT=$?
  rm -f "$TMP_CURRENT"
  exit "$COMPARE_EXIT"
fi

if [ "$JSON_ONLY" -eq 0 ]; then
  TMP_SUMMARY="$(mktemp)"
  printf '%s\n' "$REPORT" > "$TMP_SUMMARY"
  SPORTSBOT_AUDIT_REPORT="$TMP_SUMMARY" php <<'PHP'
<?php
$report = json_decode((string) file_get_contents((string) getenv('SPORTSBOT_AUDIT_REPORT')), true) ?: [];
echo "SportsBot environment audit\n";
echo "Generated: " . ($report['generated_at'] ?? '-') . "\n";
echo "Root: " . ($report['root'] ?? '-') . "\n";
echo "Git: " . ($report['git']['branch'] ?? '-') . " @ " . substr((string) ($report['git']['commit'] ?? ''), 0, 12) . "\n";
echo "PHP: " . ($report['php']['version'] ?? '-') . "\n";
echo "Composer lock: " . substr((string) ($report['composer']['lock_hash'] ?? ''), 0, 12) . "\n";
echo "Node: " . ($report['node']['version'] ?? '-') . " / npm " . ($report['node']['npm_version'] ?? '-') . "\n";
echo "Backend package-lock: " . substr((string) ($report['node']['backend_lock_hash'] ?? ''), 0, 12) . "\n";
echo "Admin package-lock: " . substr((string) ($report['node']['admin_lock_hash'] ?? ''), 0, 12) . "\n";
echo "Puppeteer: " . ($report['node']['puppeteer']['source'] ?? '-') . " " . ($report['node']['puppeteer']['version'] ?? '-') . "\n";
echo "Chrome: " . trim((string) ($report['node']['chrome_version']['output'] ?? '-')) . "\n";
echo "V3 renderer hash: " . substr((string) ($report['files']['hashes']['sportsbot_v3_renderer'] ?? ''), 0, 12) . "\n";
echo "SportsBot config hash: " . substr((string) ($report['files']['hashes']['sportsbot_config'] ?? ''), 0, 12) . "\n";
echo "Composer platform: " . (($report['composer']['platform_check']['ok'] ?? false) ? 'ok' : 'failed') . "\n";
echo "Migrations: " . (($report['laravel']['migrate_status']['ok'] ?? false) ? 'readable' : 'failed') . "\n";
if (($report['laravel']['sportsbot_health_render'] ?? null) !== null) {
    echo "SportsBot render health: " . (($report['laravel']['sportsbot_health_render']['ok'] ?? false) ? 'ok' : 'failed') . "\n";
}
if (($report['git']['status_porcelain'] ?? '') !== '') {
    echo "WARN: git worktree has local changes\n";
}
PHP
  rm -f "$TMP_SUMMARY"
fi

if [ -n "$OUTPUT" ]; then
  echo "Wrote audit report: $OUTPUT"
fi
