<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('slapshot_session');
session_start();

header('Content-Type: application/json');

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
}

function get_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function user_id(): ?int
{
    $id = $_SESSION['user_id'] ?? null;
    return is_int($id) || ctype_digit((string) $id) ? (int) $id : null;
}

function require_auth(): int
{
    $uid = user_id();
    if (!$uid) {
        json_response(['ok' => false, 'error' => 'Authentication required.'], 401);
    }
    return $uid;
}

function random_code(int $length = 8): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }
    return $code;
}

function str_contains_compat(string $haystack, string $needle): bool
{
    return $needle !== '' && strpos($haystack, $needle) !== false;
}

function str_starts_with_compat(string $haystack, string $needle): bool
{
    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: 'team';
}

function create_unique_team_slug(PDO $pdo, string $name): string
{
    $base = slugify($name);
    $slug = $base;
    $counter = 2;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE slug = ?');
    while (true) {
        $stmt->execute([$slug]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '-' . $counter;
        $counter++;
    }
}

function create_unique_join_code(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE join_code = ?');
    while (true) {
        $code = random_code(8);
        $stmt->execute([$code]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $code;
        }
    }
}

function get_user_context(PDO $pdo, int $uid): array
{
    $userStmt = $pdo->prepare('SELECT id, email, display_name, created_at FROM users WHERE id = ?');
    $userStmt->execute([$uid]);
    $user = $userStmt->fetch();
    if (!$user) {
        json_response(['ok' => false, 'error' => 'User not found.'], 404);
    }

    try {
        $teamsStmt = $pdo->prepare(
            'SELECT t.id, t.name, t.age_group, t.season_year, t.level, t.home_rink, t.city, t.team_notes,
                    t.slug, t.join_code, tm.role,
                    (SELECT COUNT(*) FROM team_members tm2 WHERE tm2.team_id = t.id AND tm2.status = "active") AS member_count
             FROM team_members tm
             INNER JOIN teams t ON t.id = tm.team_id
             WHERE tm.user_id = ? AND tm.status = "active"
             ORDER BY t.name'
        );
        $teamsStmt->execute([$uid]);
        $teams = $teamsStmt->fetchAll();
    } catch (Throwable $e) {
        // Backward-compatible fallback when team metadata columns are not migrated yet.
        $teamsStmt = $pdo->prepare(
            'SELECT t.id, t.name,
                    NULL AS age_group, NULL AS season_year, NULL AS level, NULL AS home_rink, NULL AS city, NULL AS team_notes,
                    t.slug, t.join_code, tm.role,
                    (SELECT COUNT(*) FROM team_members tm2 WHERE tm2.team_id = t.id AND tm2.status = "active") AS member_count
             FROM team_members tm
             INNER JOIN teams t ON t.id = tm.team_id
             WHERE tm.user_id = ? AND tm.status = "active"
             ORDER BY t.name'
        );
        $teamsStmt->execute([$uid]);
        $teams = $teamsStmt->fetchAll();
    }

    return [
        'user' => $user,
        'teams' => $teams,
    ];
}

function get_membership_role(PDO $pdo, int $uid, int $teamId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT role FROM team_members WHERE user_id = ? AND team_id = ? AND status = "active" LIMIT 1'
    );
    $stmt->execute([$uid, $teamId]);
    $role = $stmt->fetchColumn();
    return $role !== false ? (string) $role : null;
}

function require_team_membership(PDO $pdo, int $uid, int $teamId): string
{
    $role = get_membership_role($pdo, $uid, $teamId);
    if ($role === null) {
        json_response(['ok' => false, 'error' => 'Team access denied.'], 403);
    }
    return $role;
}

function format_media_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'team_id' => (int) $row['team_id'],
        'uploader_user_id' => (int) $row['uploader_user_id'],
        'uploader_name' => $row['uploader_name'],
        'media_type' => $row['media_type'],
        'storage_type' => $row['storage_type'],
        'title' => $row['title'],
        'description' => $row['description'],
        'game_date' => $row['game_date'],
        'file_path' => $row['file_path'],
        'external_url' => $row['external_url'],
        'thumbnail_url' => $row['thumbnail_url'],
        'mime_type' => $row['mime_type'],
        'file_size' => $row['file_size'] !== null ? (int) $row['file_size'] : null,
        'created_at' => $row['created_at'],
    ];
}

function normalize_game_date(?string $raw): ?string
{
    $value = trim((string) $raw);
    if ($value === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    $warningCount = is_array($errors) ? (int) ($errors['warning_count'] ?? 0) : 0;
    $errorCount = is_array($errors) ? (int) ($errors['error_count'] ?? 0) : 0;
    if (!$dt || $warningCount > 0 || $errorCount > 0) {
        return null;
    }

    return $dt->format('Y-m-d');
}

function youtube_embed_url(string $url): ?array
{
    $parsed = parse_url(trim($url));
    if (!$parsed || !isset($parsed['host'])) {
        return null;
    }

    $host = strtolower($parsed['host']);
    $videoId = null;
    if (str_contains_compat($host, 'youtube.com')) {
        if (($parsed['path'] ?? '') === '/watch') {
            parse_str($parsed['query'] ?? '', $query);
            $videoId = $query['v'] ?? null;
        } elseif (str_starts_with_compat((string) ($parsed['path'] ?? ''), '/embed/')) {
            $videoId = basename((string) $parsed['path']);
        } elseif (str_starts_with_compat((string) ($parsed['path'] ?? ''), '/shorts/')) {
            $videoId = basename((string) $parsed['path']);
        }
    } elseif ($host === 'youtu.be') {
        $videoId = ltrim((string) ($parsed['path'] ?? ''), '/');
    }

    if (!$videoId || !preg_match('/^[A-Za-z0-9_-]{6,}$/', $videoId)) {
        return null;
    }

    return [
        'embed_url' => 'https://www.youtube.com/embed/' . $videoId,
        'thumbnail_url' => 'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg',
    ];
}
