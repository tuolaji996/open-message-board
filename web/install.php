<?php
declare(strict_types=1);

session_start();

$rootDir = dirname(__DIR__);
$privateDir = $rootDir . '/private';
$configPath = $privateDir . '/config.php';
$schemaPath = $privateDir . '/schema.sql';
$defaultBaseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'example.com');
$defaultUploadsDir = $rootDir . '/uploads';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function input_value(string $key, string $default = ''): string
{
    return e((string)($_POST[$key] ?? $default));
}

function checked_value(string $key): string
{
    return !empty($_POST[$key]) ? ' checked' : '';
}

function normalize_base_url(string $url): string
{
    return rtrim(trim($url), '/');
}

function valid_dsn_part(string $value): bool
{
    return $value !== '' && !str_contains($value, ';') && !str_contains($value, "\n") && !str_contains($value, "\r");
}

function mysql_dsn(array $data): string
{
    $database = trim((string)$data['db_name']);
    $socket = trim((string)($data['db_socket'] ?? ''));
    if ($socket !== '') {
        return 'mysql:unix_socket=' . $socket . ';dbname=' . $database . ';charset=utf8mb4';
    }

    $host = trim((string)$data['db_host']);
    $port = trim((string)$data['db_port']);
    return 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4';
}

function schema_statements(string $sql): array
{
    $parts = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn(string $statement): bool => $statement !== ''));
}

function install_config_contents(array $data, string $passwordHash, string $uploadsExpression): string
{
    $turnstileEnabled = !empty($data['turnstile_enabled']) && trim((string)$data['turnstile_site_key']) !== '' && trim((string)$data['turnstile_secret_key']) !== '';
    return "<?php\n"
        . "declare(strict_types=1);\n\n"
        . "return [\n"
        . "    'site_name' => " . var_export(trim((string)$data['site_name']), true) . ",\n"
        . "    'base_url' => " . var_export(normalize_base_url((string)$data['base_url']), true) . ",\n"
        . "    'timezone' => " . var_export(trim((string)$data['timezone']), true) . ",\n"
        . "    'asset_version' => 'open-4',\n"
        . "    'db' => [\n"
        . "        'dsn' => " . var_export(mysql_dsn($data), true) . ",\n"
        . "        'user' => " . var_export(trim((string)$data['db_user']), true) . ",\n"
        . "        'pass' => " . var_export((string)$data['db_pass'], true) . ",\n"
        . "    ],\n"
        . "    'admin' => [\n"
        . "        'username' => " . var_export(trim((string)$data['admin_user']), true) . ",\n"
        . "        'password_hash' => " . var_export($passwordHash, true) . ",\n"
        . "    ],\n"
        . "    'mail' => [\n"
        . "        'from' => " . var_export(trim((string)$data['mail_from']), true) . ",\n"
        . "        'from_name' => " . var_export(trim((string)$data['site_name']), true) . ",\n"
        . "    ],\n"
        . "    'turnstile' => [\n"
        . "        'enabled' => " . ($turnstileEnabled ? 'true' : 'false') . ",\n"
        . "        'site_key' => " . var_export(trim((string)$data['turnstile_site_key']), true) . ",\n"
        . "        'secret_key' => " . var_export(trim((string)$data['turnstile_secret_key']), true) . ",\n"
        . "    ],\n"
        . "    'market' => [\n"
        . "        'ticker' => " . var_export(strtoupper(trim((string)$data['market_ticker'])), true) . ",\n"
        . "        'cache_seconds' => 300,\n"
        . "    ],\n"
        . "    'uploads_dir' => " . $uploadsExpression . ",\n"
        . "    'max_images' => 8,\n"
        . "    'max_image_bytes' => 5 * 1024 * 1024,\n"
        . "    'allowed_image_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],\n"
        . "    'reserved_names' => ['admin', 'moderator', 'system'],\n"
        . "    'reserved_name_replacement' => 'ReservedName',\n"
        . "    'default_keywords' => [\n"
        . "        'message board',\n"
        . "        'community message board',\n"
        . "        'user posts',\n"
        . "        'comments',\n"
        . "        'hashtags',\n"
        . "        'image uploads',\n"
        . "        'moderation',\n"
        . "        'Cloudflare Turnstile',\n"
        . "    ],\n"
        . "];\n";
}

if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$warnings = [];
$success = false;
$installed = is_file($configPath);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    if (!hash_equals((string)$_SESSION['install_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'The installer session expired. Refresh the page and try again.';
    }

    $required = [
        'site_name' => 'Site name',
        'base_url' => 'Base URL',
        'timezone' => 'Timezone',
        'db_name' => 'Database name',
        'db_user' => 'Database user',
        'admin_user' => 'Admin username',
        'admin_pass' => 'Admin password',
        'admin_pass_confirm' => 'Admin password confirmation',
        'mail_from' => 'Mail sender address',
        'market_ticker' => 'Market ticker',
    ];
    foreach ($required as $key => $label) {
        if (trim((string)($_POST[$key] ?? '')) === '') {
            $errors[] = $label . ' is required.';
        }
    }

    if (trim((string)($_POST['db_socket'] ?? '')) === '') {
        foreach (['db_host' => 'Database host', 'db_port' => 'Database port'] as $key => $label) {
            if (trim((string)($_POST[$key] ?? '')) === '') {
                $errors[] = $label . ' is required unless a Unix socket is provided.';
            }
        }
    }

    $baseUrl = normalize_base_url((string)($_POST['base_url'] ?? ''));
    if ($baseUrl !== '' && !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Base URL must be a valid URL.';
    }

    $timezone = trim((string)($_POST['timezone'] ?? ''));
    if ($timezone !== '' && !in_array($timezone, timezone_identifiers_list(), true)) {
        $errors[] = 'Timezone is not recognized by PHP.';
    }

    foreach (['db_host', 'db_port', 'db_name', 'db_socket'] as $key) {
        $value = trim((string)($_POST[$key] ?? ''));
        if ($value !== '' && !valid_dsn_part($value)) {
            $errors[] = 'Database connection fields cannot contain semicolons or line breaks.';
            break;
        }
    }

    if ((string)($_POST['admin_pass'] ?? '') !== (string)($_POST['admin_pass_confirm'] ?? '')) {
        $errors[] = 'Admin passwords do not match.';
    }
    if (strlen((string)($_POST['admin_pass'] ?? '')) < 10) {
        $errors[] = 'Admin password must be at least 10 characters.';
    }

    if (!filter_var((string)($_POST['mail_from'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Mail sender must be a valid email address.';
    }

    if (!extension_loaded('pdo_mysql')) {
        $errors[] = 'The PHP pdo_mysql extension is not enabled.';
    }
    if (!is_file($schemaPath)) {
        $errors[] = 'private/schema.sql was not found.';
    }
    if (!is_dir($privateDir) || !is_writable($privateDir)) {
        $errors[] = 'The private directory must be writable while the installer creates config.php.';
    }

    $uploadsInput = trim((string)($_POST['uploads_dir'] ?? ''));
    $uploadsUseDefault = $uploadsInput === '';
    $uploadsPath = $uploadsUseDefault ? $defaultUploadsDir : $uploadsInput;
    if (!$uploadsUseDefault && !preg_match('/^(?:[A-Za-z]:)?[\/\\\\]/', $uploadsPath)) {
        $uploadsPath = $rootDir . '/' . $uploadsPath;
    }
    $uploadsPath = rtrim(str_replace('\\', '/', $uploadsPath), '/');
    $uploadsExpression = $uploadsUseDefault ? "__DIR__ . '/../uploads'" : var_export($uploadsPath, true);

    if (!$errors) {
        try {
            $pdo = new PDO(mysql_dsn($_POST), trim((string)$_POST['db_user']), (string)$_POST['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

            $schemaSql = file_get_contents($schemaPath);
            if ($schemaSql === false) {
                throw new RuntimeException('Unable to read private/schema.sql.');
            }
            foreach (schema_statements($schemaSql) as $statement) {
                $pdo->exec($statement);
            }

            if (!is_dir($uploadsPath) && !mkdir($uploadsPath, 0750, true)) {
                throw new RuntimeException('Unable to create upload directory: ' . $uploadsPath);
            }
            if (!is_writable($uploadsPath)) {
                throw new RuntimeException('Upload directory is not writable by PHP: ' . $uploadsPath);
            }

            $passwordHash = password_hash((string)$_POST['admin_pass'], PASSWORD_DEFAULT);
            $config = install_config_contents($_POST, $passwordHash, $uploadsExpression);
            if (file_put_contents($configPath, $config, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write private/config.php.');
            }

            @chmod($configPath, 0640);
            $_SESSION['install_csrf'] = bin2hex(random_bytes(24));
            $success = true;
            $installed = true;
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Open Message Board Installer</title>
    <style>
        :root { color-scheme: dark light; --bg: #0b0f17; --panel: #121927; --line: #263349; --text: #f4f7fb; --muted: #9ca9bb; --accent: #5b6cff; --danger: #ff6b7a; --ok: #31d08b; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        main { width: min(960px, calc(100% - 32px)); margin: 0 auto; padding: 48px 0; }
        header { margin-bottom: 22px; }
        h1 { margin: 0 0 8px; font-size: clamp(28px, 5vw, 44px); line-height: 1.05; }
        h2 { margin: 0 0 12px; font-size: 18px; }
        p { color: var(--muted); margin: 0 0 14px; }
        a { color: var(--text); }
        .card { border: 1px solid var(--line); border-radius: 10px; background: var(--panel); padding: 20px; box-shadow: 0 18px 50px rgba(0, 0, 0, 0.24); }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .full { grid-column: 1 / -1; }
        label { display: grid; gap: 6px; color: var(--muted); font-weight: 700; }
        input { width: 100%; border: 1px solid var(--line); border-radius: 8px; background: rgba(255, 255, 255, 0.04); color: var(--text); padding: 11px 12px; outline: none; }
        input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(91, 108, 255, 0.18); }
        .check { display: flex; align-items: center; gap: 10px; }
        .check input { width: auto; }
        .actions { display: flex; align-items: center; gap: 12px; margin-top: 18px; }
        button { border: 0; border-radius: 8px; background: var(--accent); color: white; cursor: pointer; font-weight: 850; min-height: 44px; padding: 0 18px; }
        .note, .error, .success { border: 1px solid var(--line); border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; }
        .error { border-color: rgba(255, 107, 122, 0.5); color: #ffd8dd; background: rgba(255, 107, 122, 0.1); }
        .success { border-color: rgba(49, 208, 139, 0.5); color: #d8ffed; background: rgba(49, 208, 139, 0.1); }
        .note { color: var(--muted); background: rgba(255, 255, 255, 0.03); }
        code { color: var(--text); overflow-wrap: anywhere; }
        ul { margin: 8px 0 0; padding-left: 20px; }
        @media (max-width: 720px) { .grid { grid-template-columns: 1fr; } main { width: min(100% - 20px, 960px); padding: 24px 0; } }
    </style>
</head>
<body>
<main>
    <header>
        <h1>Open Message Board Installer</h1>
        <p>Connect a MySQL database, import the schema, create the private configuration file, and set the first admin account.</p>
    </header>

    <?php if ($success): ?>
        <section class="card">
            <div class="success">
                <strong>Installation complete.</strong>
                The database schema was imported and <code>private/config.php</code> was created.
            </div>
            <p>For security, delete or rename <code>web/install.php</code> now. The installer will refuse to run while <code>private/config.php</code> exists, but removing it is still the safer production setup.</p>
            <div class="actions">
                <a href="/">Open site</a>
                <a href="/admin.php">Open admin</a>
            </div>
        </section>
    <?php elseif ($installed): ?>
        <section class="card">
            <div class="note">
                <strong>This site already looks installed.</strong>
                <code>private/config.php</code> exists, so the installer is locked.
            </div>
            <p>Delete or rename <code>web/install.php</code> before going live. To reinstall intentionally, remove <code>private/config.php</code> manually first.</p>
            <div class="actions">
                <a href="/">Open site</a>
                <a href="/admin.php">Open admin</a>
            </div>
        </section>
    <?php else: ?>
        <?php if ($errors): ?>
            <div class="error">
                <strong>Installation could not continue:</strong>
                <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
        <form method="post" class="card">
            <input type="hidden" name="csrf_token" value="<?= e((string)$_SESSION['install_csrf']) ?>">
            <div class="note">
                Create an empty MySQL database and database user first. This installer imports tables into that database; it does not create MySQL users.
            </div>
            <h2>Site</h2>
            <div class="grid">
                <label>Site name
                    <input name="site_name" required value="<?= input_value('site_name', 'Open Message Board') ?>">
                </label>
                <label>Base URL
                    <input name="base_url" required value="<?= input_value('base_url', $defaultBaseUrl) ?>">
                </label>
                <label>Timezone
                    <input name="timezone" required value="<?= input_value('timezone', 'UTC') ?>">
                </label>
                <label>Mail sender
                    <input name="mail_from" type="email" required value="<?= input_value('mail_from', 'noreply@example.com') ?>">
                </label>
            </div>

            <h2>MySQL</h2>
            <div class="grid">
                <label>Host
                    <input name="db_host" value="<?= input_value('db_host', '127.0.0.1') ?>">
                </label>
                <label>Port
                    <input name="db_port" value="<?= input_value('db_port', '3306') ?>">
                </label>
                <label>Database name
                    <input name="db_name" required value="<?= input_value('db_name', 'message_board') ?>">
                </label>
                <label>Database user
                    <input name="db_user" required value="<?= input_value('db_user', 'message_board_user') ?>">
                </label>
                <label>Database password
                    <input name="db_pass" type="password" value="">
                </label>
                <label>Unix socket, optional
                    <input name="db_socket" value="<?= input_value('db_socket') ?>" placeholder="/tmp/mysql.sock">
                </label>
            </div>

            <h2>Admin</h2>
            <div class="grid">
                <label>Admin username
                    <input name="admin_user" required value="<?= input_value('admin_user', 'admin') ?>">
                </label>
                <label>Market ticker
                    <input name="market_ticker" required value="<?= input_value('market_ticker', 'NVDA') ?>">
                </label>
                <label>Admin password
                    <input name="admin_pass" type="password" required autocomplete="new-password">
                </label>
                <label>Confirm admin password
                    <input name="admin_pass_confirm" type="password" required autocomplete="new-password">
                </label>
            </div>

            <h2>Uploads</h2>
            <div class="grid">
                <label class="full">Upload directory, optional
                    <input name="uploads_dir" value="<?= input_value('uploads_dir') ?>" placeholder="<?= e($defaultUploadsDir) ?>">
                </label>
            </div>

            <h2>Optional Turnstile</h2>
            <div class="grid">
                <label class="check full">
                    <input type="checkbox" name="turnstile_enabled" value="1"<?= checked_value('turnstile_enabled') ?>>
                    Enable Cloudflare Turnstile when both keys are provided
                </label>
                <label>Site key
                    <input name="turnstile_site_key" value="<?= input_value('turnstile_site_key') ?>">
                </label>
                <label>Secret key
                    <input name="turnstile_secret_key" type="password" value="">
                </label>
            </div>

            <div class="actions">
                <button type="submit">Install</button>
                <span class="note">After installation, remove <code>web/install.php</code>.</span>
            </div>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
