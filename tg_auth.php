<?php
declare(strict_types=1);

/**
 * tg_auth.php — Telegram Mini App authentication & authorization.
 *
 * Fixes the "identity comes from ?tg_id=" hole. Identity is derived ONLY from
 * a cryptographically validated Telegram initData payload, never from a param.
 *
 * Usage in every endpoint, at the very top:
 *
 *     require_once __DIR__ . '/tg_auth.php';
 *     $me = tg_require_auth();                    // authenticated user row
 *     tg_require_role($me, ['admin','monitor']);  // optional role gate
 *
 * Then use $me['id'] / $me['role'] / $me['group_id'] as the source of truth.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

/**
 * Telegram Web embeds Mini Apps in a cross-site iframe, so in production the
 * session cookie needs SameSite=None; Secure or the browser drops it. HttpOnly
 * keeps it out of reach of any XSS.
 *
 * Secure cookies are never sent over plain HTTP, which would make a local
 * http://localhost dev stack untestable. Rather than adding a bypass flag (a
 * future vulnerability), derive it from APP_HOST — which is already configured
 * per environment. Production stays strict automatically; only an explicitly
 * http:// APP_HOST relaxes, and that can't happen on the real host.
 */
function tg_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = str_starts_with(APP_HOST, 'https://');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => $secure ? 'None' : 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------------------
// 1. initData validation — the core fix
// ---------------------------------------------------------------------------

/**
 * Parse a query string WITHOUT PHP's parse_str(), which mangles keys
 * (dots and spaces become underscores) and auto-creates arrays for a[b].
 * The data-check-string must be built from the exact decoded key/value pairs.
 *
 * @return array<string,string>
 */
function tg_parse_qs(string $qs): array
{
    $out = [];
    foreach (explode('&', $qs) as $pair) {
        if ($pair === '') {
            continue;
        }
        $eq = strpos($pair, '=');
        if ($eq === false) {
            continue;
        }
        // rawurldecode, NOT urldecode: urldecode turns a literal '+' into a
        // space, which would corrupt the data-check-string and permanently
        // fail login for any user whose initData carries a raw '+'.
        $k = rawurldecode(substr($pair, 0, $eq));
        $v = rawurldecode(substr($pair, $eq + 1));
        $out[$k] = $v;
    }
    return $out;
}

/**
 * Verify that initData was signed by Telegram with our bot token.
 *
 * secret_key    = HMAC_SHA256(key: "WebAppData", msg: bot_token)
 * expected_hash = HMAC_SHA256(key: secret_key,   msg: data_check_string)
 *
 * data_check_string = all pairs EXCEPT `hash`, formatted "k=v", sorted
 * alphabetically by key, joined with "\n".
 *
 * Telegram's `hash` empirically covers the newer Ed25519 `signature` field
 * (verified against a real iOS payload, 2026-07), but docs and older payloads
 * disagree — so BOTH constructions (with and without `signature`) are
 * accepted. Each is an HMAC under the bot token; the union is just as strong.
 *
 * @return array{id:int,...}|null Validated Telegram user, or null if untrusted.
 */
function tg_validate_init_data(string $initData, string $botToken, int $maxAgeSeconds = 86400): ?array
{
    if ($initData === '' || $botToken === '') {
        return null;
    }

    $fields = tg_parse_qs($initData);

    $hash = (string)($fields['hash'] ?? '');
    if ($hash === '' || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
        return null;
    }
    unset($fields['hash']);

    if ($fields === []) {
        return null;
    }

    ksort($fields, SORT_STRING);
    $withSig    = [];
    $withoutSig = [];
    foreach ($fields as $k => $v) {
        $pair = $k . '=' . $v;
        $withSig[] = $pair;
        if ($k !== 'signature') {
            $withoutSig[] = $pair;
        }
    }

    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $ok = false;
    foreach ([$withSig, $withoutSig] as $pairs) {
        if (hash_equals(hash_hmac('sha256', implode("\n", $pairs), $secretKey), strtolower($hash))) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        return null;
    }

    // Reject replay of stale payloads.
    $authDate = (int)($fields['auth_date'] ?? 0);
    if ($authDate <= 0 || (time() - $authDate) > $maxAgeSeconds || $authDate > time() + 300) {
        return null;
    }

    // JSON_BIGINT_AS_STRING: Telegram IDs exceed 2^31. On 32-bit PHP
    // (armhf Raspberry Pi OS) json_decode would otherwise return them as
    // floats — "9.87654321E+9" fails ctype_digit and login breaks for
    // every user with a modern ID. As a string the ID survives intact.
    $user = json_decode((string)($fields['user'] ?? ''), true, 512, JSON_BIGINT_AS_STRING);
    if (!is_array($user) || !isset($user['id']) || !ctype_digit((string)$user['id'])) {
        return null;
    }

    return $user;
}

// ---------------------------------------------------------------------------
// 2. Bootstrap: exchange validated initData for a session
// ---------------------------------------------------------------------------

/**
 * Handle the client's POST {"init_data": "<Telegram.WebApp.initData>"}.
 * Call this at the top of greeting.php. Exits on completion.
 */
function tg_handle_bootstrap(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        return;
    }

    header('Content-Type: application/json; charset=UTF-8');
    tg_require_same_origin();

    $body = json_decode(file_get_contents('php://input') ?: '', true);
    $user = tg_validate_init_data((string)($body['init_data'] ?? ''), BOT_TOKEN);

    if ($user === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid Telegram signature']);
        exit;
    }

    // The Telegram ID is now trusted. It must still be a registered user.
    $row = getUserByTgId((string)$user['id']);
    if (!$row) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Not registered']);
        exit;
    }

    tg_session_start();
    session_regenerate_id(true); // prevent session fixation
    $_SESSION['tg_id']   = (string)$user['id']; // string: 32-bit-safe
    $_SESSION['auth_at'] = time();

    echo json_encode(['success' => true]);
    exit;
}

// ---------------------------------------------------------------------------
// 3. Identity accessors
// ---------------------------------------------------------------------------

/**
 * The authenticated user, or null. Session first; falls back to an
 * "Authorization: tma <initData>" header for fetch() calls in contexts where
 * third-party cookies are blocked.
 */
function tg_current_user(): ?array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    tg_session_start();

    // Sessions expire: auth_at was stored but never checked. Expiry is
    // painless — the greeting bootstrap silently re-validates initData.
    $maxAge = (defined('SESSION_MAX_AGE_DAYS') ? (int)SESSION_MAX_AGE_DAYS : 7) * 86400;
    if (!empty($_SESSION['tg_id']) && (time() - (int)($_SESSION['auth_at'] ?? 0)) > $maxAge) {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    if (!empty($_SESSION['tg_id'])) {
        return $cached = getUserByTgId((string)$_SESSION['tg_id']);
    }

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($auth, 'tma ') === 0) {
        $user = tg_validate_init_data(substr($auth, 4), BOT_TOKEN);
        if ($user !== null) {
            return $cached = getUserByTgId((string)$user['id']);
        }
    }

    return null;
}

/** Authenticated user or stop. JSON requests get 401; page loads bounce to bootstrap. */
function tg_require_auth(): array
{
    $me = tg_current_user();
    if ($me) {
        return $me;
    }

    if (tg_wants_json()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    } else {
        header('Location: greeting.php');
    }
    exit;
}

function tg_wants_json(): bool
{
    return str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

// ---------------------------------------------------------------------------
// 4. Authorization guards
// ---------------------------------------------------------------------------

/** @param string[] $roles */
function tg_require_role(array $me, array $roles): void
{
    if (!in_array((string)($me['role'] ?? ''), $roles, true)) {
        tg_deny('Access denied');
    }
}

/** Admins reach any group; everyone else only their own. */
function tg_can_access_group(array $me, int $groupId): bool
{
    if (($me['role'] ?? '') === 'admin') {
        return true;
    }
    return $groupId > 0 && $groupId === (int)$me['group_id'];
}

/**
 * Resolve a requested ?group_id= against what $me may actually touch.
 * Fails loud on tampering rather than silently showing the wrong group.
 */
function tg_resolve_group_id(array $me, $requested): int
{
    $requested = (int)$requested;
    if ($requested <= 0) {
        return (int)$me['group_id'];
    }
    if (!tg_can_access_group($me, $requested)) {
        tg_deny('You do not have access to that group');
    }
    return $requested;
}

/** May $me read $target's personal attendance? Self, own-group monitor, or admin. */
function tg_can_view_user(array $me, array $target): bool
{
    if ((int)$me['id'] === (int)$target['id']) {
        return true;
    }
    if (($me['role'] ?? '') === 'admin') {
        return true;
    }
    if (($me['role'] ?? '') === 'monitor' && (int)$me['group_id'] === (int)$target['group_id']) {
        return true;
    }
    return false;
}

/**
 * Admin-only "view as" via ?tg_id=. For everyone else the param is ignored,
 * so it can never grant identity — only an admin can narrow to someone else.
 */
function tg_resolve_target(array $me, $requestedTgId): array
{
    $requestedTgId = preg_replace('/\D/', '', (string)$requestedTgId);
    if ($requestedTgId === '' || ($me['role'] ?? '') !== 'admin') {
        return $me;
    }
    return getUserByTgId($requestedTgId) ?: $me;
}

function tg_deny(string $msg = 'Access denied'): void
{
    http_response_code(403);
    if (tg_wants_json()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => $msg]);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $msg;
    }
    exit;
}

// ---------------------------------------------------------------------------
// 5. CSRF / origin
// ---------------------------------------------------------------------------

/**
 * With SameSite=None the session cookie rides cross-site requests, so state
 * changes need an origin check. Combined with requiring application/json
 * (which HTML forms cannot send, and which forces a CORS preflight) this
 * closes CSRF without threading tokens through every page.
 */
function tg_require_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return; // same-origin fetch/navigation may omit Origin
    }
    if (rtrim($origin, '/') !== rtrim(APP_HOST, '/')) {
        tg_deny('Cross-origin request rejected');
    }
}

// ---------------------------------------------------------------------------
// 6. CSV formula-injection guard
// ---------------------------------------------------------------------------

/** Neutralize spreadsheet formulas in any user-controlled cell. */
function csv_safe($value): string
{
    $s = (string)$value;
    if ($s === '') {
        return $s;
    }
    if (strpos("=+-@\t\r|", $s[0]) !== false) {
        return "'" . $s;
    }
    return $s;
}
