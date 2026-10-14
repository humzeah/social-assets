<?php
/**
 * Pinterest token helper (command line only).
 *
 * Gets a fresh production access token + refresh token for ONE Pinterest app
 * using the normal OAuth "authorization code" flow, without needing any public
 * web page. Run it over SSH:
 *
 *     php pinterest-token.php
 *
 * It asks for the app ID, app secret and redirect URI (from
 * developers.pinterest.com -> My apps -> your app), prints a link to open in
 * your browser, then asks you to paste the address Pinterest sends you back to.
 * Tokens are printed in the terminal only. Nothing is saved to disk.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const SCOPES = 'boards:read,boards:write,pins:read,pins:write,user_accounts:read';

function ask(string $label, bool $hidden = false): string
{
    echo $label;
    if ($hidden) {
        shell_exec('stty -echo');
    }
    $value = trim((string) fgets(STDIN));
    if ($hidden) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $value;
}

function api(string $method, string $url, array $headers, ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return [0, ['message' => 'Network error: ' . $error]];
    }
    return [$status, json_decode($raw, true) ?? ['message' => $raw]];
}

echo "\n=== Pinterest token helper ===\n";
echo "Find these at developers.pinterest.com -> My apps -> (your app) -> Manage.\n\n";

$appId    = ask('App ID: ');
$secret   = ask('App secret (hidden while typing): ', true);
$redirect = ask('Redirect URI (exactly as listed in the app settings): ');

if ($appId === '' || $secret === '' || $redirect === '') {
    exit("Missing a value. Nothing was done.\n");
}

$authUrl = 'https://www.pinterest.com/oauth/?' . http_build_query([
    'client_id'     => $appId,
    'redirect_uri'  => $redirect,
    'response_type' => 'code',
    'scope'         => SCOPES,
    'state'         => 'cli-' . bin2hex(random_bytes(4)),
]);

echo "\n1. Make sure your browser is logged in to the RIGHT Pinterest account.\n";
echo "2. Open this link and click Allow:\n\n   {$authUrl}\n\n";
echo "3. Pinterest sends you to your redirect address. The page may say\n";
echo "   'Not Found' - that is fine. Copy the FULL address from the address bar.\n\n";

$returned = ask('Paste that full address here: ');
parse_str((string) parse_url($returned, PHP_URL_QUERY), $query);
$code = $query['code'] ?? $returned;

if (!empty($query['error'])) {
    exit("\nPinterest returned an error: {$query['error']}. Nothing was done.\n");
}
if ($code === '' || str_contains($code, ' ')) {
    exit("\nCould not find a code in that address. Nothing was done.\n");
}

[$status, $data] = api('POST', 'https://api.pinterest.com/v5/oauth/token', [
    'Authorization: Basic ' . base64_encode($appId . ':' . $secret),
    'Content-Type: application/x-www-form-urlencoded',
], http_build_query([
    'grant_type'   => 'authorization_code',
    'code'         => $code,
    'redirect_uri' => $redirect,
]));

if ($status !== 200 || empty($data['access_token'])) {
    $msg = $data['message'] ?? json_encode($data);
    exit("\nToken exchange failed (HTTP {$status}): {$msg}\n"
        . "Common causes: the code was already used (they work once, for a few minutes),\n"
        . "or the redirect URI does not exactly match the app settings. Run it again.\n");
}

$access  = $data['access_token'];
$refresh = $data['refresh_token'] ?? '(none returned)';
$days    = isset($data['expires_in']) ? round($data['expires_in'] / 86400) : '?';
$rdays   = isset($data['refresh_token_expires_in']) ? round($data['refresh_token_expires_in'] / 86400) : '?';

[$uStatus, $user] = api('GET', 'https://api.pinterest.com/v5/user_account', ["Authorization: Bearer {$access}"]);
[$bStatus, $boards] = api('GET', 'https://api.pinterest.com/v5/boards?page_size=50', ["Authorization: Bearer {$access}"]);

echo "\n=== Success ===\n";
echo 'Account: ' . ($uStatus === 200 ? ($user['username'] ?? '?') : "could not read (HTTP {$uStatus})") . "\n";
echo "Scopes:  " . ($data['scope'] ?? '?') . "\n";
echo "Access token lasts about {$days} days. Refresh token lasts about {$rdays} days.\n\n";

if ($bStatus === 200 && !empty($boards['items'])) {
    echo "Boards:\n";
    foreach ($boards['items'] as $b) {
        echo "  {$b['id']}  {$b['name']}\n";
    }
    echo "\n";
}

echo "Copy these into config.local.php (use nano, not File Manager):\n\n";
echo "ACCESS TOKEN:\n{$access}\n\n";
echo "REFRESH TOKEN:\n{$refresh}\n\n";
echo "Then clear the screen with:  clear\n";
