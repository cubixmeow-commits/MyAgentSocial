<?php
/**
 * Agent Relay — simplest version.
 *
 * One file. Two representatives (A and B) talk through a shared session
 * in SQLite. Each representative authenticates with its own key, so the
 * server can serve each side the right payload:
 *   - your representative sees YOUR full profile + the transcript
 *   - the transcript is shared; raw profiles never cross to the other side
 *
 * Endpoints:
 *   POST /relay.php?action=create          -> make a session, returns id
 *   GET  /relay.php?action=next&session=ID -> transcript + (your own profile)
 *   POST /relay.php?action=say&session=ID  -> post this representative's message
 *
 * Auth: header  X-Relay-Key: <key>
 *   The key maps to a seat (a or b) via config.php ($KEYS).
 */

declare(strict_types=1);
header('Content-Type: application/json');

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------
function fail(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}
function body(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ?: '{}', true);
    return is_array($j) ? $j : [];
}

// ---------------------------------------------------------------------------
// CONFIG — load keys from config.php (never commit secrets)
// ---------------------------------------------------------------------------
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    fail(500, 'Relay configuration is missing.');
}
$config = require $configPath;
if (!is_array($config) || !isset($config['keys']) || !is_array($config['keys'])) {
    fail(500, 'Relay configuration is invalid.');
}
$KEYS = $config['keys'];

$DB_PATH      = __DIR__ . '/relay.sqlite';   // move outside web root in production
$PROFILE_DIR  = __DIR__ . '/profiles';       // move outside web root in production

// Soft ceiling so sessions do not grow forever. Publish via git — no config.php edit needed.
const MAX_CONVERSATION_MESSAGES = 1000;

// ---------------------------------------------------------------------------
// auth — who is calling?
// ---------------------------------------------------------------------------
$key = $_SERVER['HTTP_X_RELAY_KEY'] ?? '';
if (!isset($KEYS[$key])) {
    fail(401, 'Missing or invalid X-Relay-Key.');
}
$me = $KEYS[$key];  // ['seat'=>..,'name'=>..,'profile'=>..]

// ---------------------------------------------------------------------------
// db
// ---------------------------------------------------------------------------
$db = new PDO('sqlite:' . $DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode = WAL;');
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec('PRAGMA busy_timeout = 5000;');
$db->exec("
    CREATE TABLE IF NOT EXISTS sessions (
        id         TEXT PRIMARY KEY,
        created_at TEXT NOT NULL,
        closed     INTEGER NOT NULL DEFAULT 0
    );
");
$db->exec("
    CREATE TABLE IF NOT EXISTS messages (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT NOT NULL,
        seat       TEXT NOT NULL,
        name       TEXT NOT NULL,
        content    TEXT NOT NULL,
        created_at TEXT NOT NULL
    );
");

$action = $_GET['action'] ?? '';

// ---------------------------------------------------------------------------
// CREATE
// ---------------------------------------------------------------------------
if ($action === 'create') {
    $id = substr(strtoupper(bin2hex(random_bytes(4))), 0, 6);
    $stmt = $db->prepare('INSERT INTO sessions (id, created_at) VALUES (?, ?)');
    $stmt->execute([$id, gmdate('c')]);
    echo json_encode([
        'session'   => $id,
        'share_url' => 'https://iainreid.dev/myagent/relay.php?session=' . $id,
        'note'      => 'Both representatives now call ?action=next&session=' . $id,
    ]);
    exit;
}

// everything below needs a session
$sessionId = $_GET['session'] ?? '';
if ($sessionId === '') fail(400, 'Missing ?session=ID');
$sess = $db->prepare('SELECT * FROM sessions WHERE id = ?');
$sess->execute([$sessionId]);
$session = $sess->fetch(PDO::FETCH_ASSOC);
if (!$session) fail(404, 'No such session.');

// ---------------------------------------------------------------------------
// NEXT — read the shared transcript + your own profile (never the other's)
// ---------------------------------------------------------------------------
if ($action === 'next') {
    $rows = $db->prepare('SELECT seat, name, content, created_at FROM messages WHERE session_id = ? ORDER BY id ASC');
    $rows->execute([$sessionId]);
    $messages = $rows->fetchAll(PDO::FETCH_ASSOC);

    // your own profile, served only to you
    $profilePath = $PROFILE_DIR . '/' . basename($me['profile']);
    $myProfile = is_file($profilePath) ? file_get_contents($profilePath) : '(no profile on file)';

    $lastSeat = $messages ? end($messages)['seat'] : null;
    $yourTurn = ($lastSeat !== $me['seat']);   // simple alternation

    echo json_encode([
        'session'      => $sessionId,
        'you_are'      => $me['name'] . ' (seat ' . $me['seat'] . ')',
        'your_profile' => $myProfile,          // full grounding, your side only
        'transcript'   => $messages,           // shared, both sides see this
        'your_turn'    => $yourTurn && !$session['closed'],
        'closed'       => (bool)$session['closed'],
        'guidance'     => 'Reply as this person\'s representative. Use your_profile as evidence, do not invent facts, do not reveal private profile details unless relevant. Keep it conversational.',
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// SAY — post your representative's message to the shared transcript
// ---------------------------------------------------------------------------
if ($action === 'say') {
    if ($session['closed']) fail(409, 'Session is closed.');
    $content = trim((string)(body()['message'] ?? ''));
    if ($content === '') fail(400, 'Provide {"message": "..."}');

    // enforce alternation so one side can't flood
    $last = $db->prepare('SELECT seat FROM messages WHERE session_id = ? ORDER BY id DESC LIMIT 1');
    $last->execute([$sessionId]);
    $lastSeat = $last->fetchColumn();
    if ($lastSeat === $me['seat']) {
        fail(409, 'Not your turn — wait for the other representative to reply.');
    }

    $ins = $db->prepare('INSERT INTO messages (session_id, seat, name, content, created_at) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$sessionId, $me['seat'], $me['name'], $content, gmdate('c')]);

    // Close only when the configurable message ceiling is reached.
    // Sessions otherwise stay open so both seats keep alternating turns.
    $count = (int)$db->query('SELECT COUNT(*) FROM messages WHERE session_id = ' . $db->quote($sessionId))->fetchColumn();
    $closed = $count >= MAX_CONVERSATION_MESSAGES;
    if ($closed) {
        $db->prepare('UPDATE sessions SET closed = 1 WHERE id = ?')->execute([$sessionId]);
    }

    echo json_encode(['ok' => true, 'posted_as' => $me['name'], 'message_count' => $count, 'closed' => $closed]);
    exit;
}

fail(400, 'Unknown action. Use create | next | say.');
