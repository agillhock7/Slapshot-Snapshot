<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function respond_session(PDO $pdo): void
{
    $uid = user_id();
    if (!$uid) {
        json_response(['ok' => true, 'authenticated' => false]);
    }
    $context = get_user_context($pdo, $uid);
    json_response([
        'ok' => true,
        'authenticated' => true,
        'user' => $context['user'],
        'teams' => $context['teams'],
    ]);
}

function handle_register(PDO $pdo): void
{
    require_method('POST');
    $input = get_json_input();

    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $displayName = trim((string) ($input['display_name'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $teamName = trim((string) ($input['team_name'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'Valid email required.'], 422);
    }
    if (strlen($displayName) < 2) {
        json_response(['ok' => false, 'error' => 'Display name must be at least 2 characters.'], 422);
    }
    if (strlen($password) < 8) {
        json_response(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 422);
    }
    if (strlen($teamName) < 2) {
        json_response(['ok' => false, 'error' => 'Team name must be at least 2 characters.'], 422);
    }

    try {
        $pdo->beginTransaction();

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $userStmt = $pdo->prepare('INSERT INTO users (email, display_name, password_hash) VALUES (?, ?, ?)');
        $userStmt->execute([$email, $displayName, $passwordHash]);
        $uid = (int) $pdo->lastInsertId();

        $teamSlug = create_unique_team_slug($pdo, $teamName);
        $joinCode = create_unique_join_code($pdo);
        $teamStmt = $pdo->prepare('INSERT INTO teams (name, slug, join_code, created_by) VALUES (?, ?, ?, ?)');
        $teamStmt->execute([$teamName, $teamSlug, $joinCode, $uid]);
        $teamId = (int) $pdo->lastInsertId();

        $memberStmt = $pdo->prepare(
            'INSERT INTO team_members (team_id, user_id, role, status) VALUES (?, ?, "owner", "active")'
        );
        $memberStmt->execute([$teamId, $uid]);

        $pdo->commit();

        $_SESSION['user_id'] = $uid;
        $_SESSION['display_name'] = $displayName;
        session_regenerate_id(true);

        $context = get_user_context($pdo, $uid);
        json_response([
            'ok' => true,
            'authenticated' => true,
            'user' => $context['user'],
            'teams' => $context['teams'],
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int) $e->getCode() === 23000) {
            json_response(['ok' => false, 'error' => 'Email already exists.'], 409);
        }
        json_response(['ok' => false, 'error' => 'Unable to register account.'], 500);
    }
}

function handle_login(PDO $pdo): void
{
    require_method('POST');
    $input = get_json_input();

    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        json_response(['ok' => false, 'error' => 'Email and password are required.'], 422);
    }

    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        json_response(['ok' => false, 'error' => 'Invalid email or password.'], 401);
    }

    $_SESSION['user_id'] = (int) $user['id'];
    session_regenerate_id(true);
    $context = get_user_context($pdo, (int) $user['id']);
    $_SESSION['display_name'] = (string) ($context['user']['display_name'] ?? '');
    json_response([
        'ok' => true,
        'authenticated' => true,
        'user' => $context['user'],
        'teams' => $context['teams'],
    ]);
}

function handle_logout(): void
{
    require_method('POST');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    json_response(['ok' => true]);
}

function handle_create_team(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();
    $name = trim((string) ($input['name'] ?? ''));
    if (strlen($name) < 2) {
        json_response(['ok' => false, 'error' => 'Team name must be at least 2 characters.'], 422);
    }

    $slug = create_unique_team_slug($pdo, $name);
    $joinCode = create_unique_join_code($pdo);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO teams (name, slug, join_code, created_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $slug, $joinCode, $uid]);
        $teamId = (int) $pdo->lastInsertId();

        $memberStmt = $pdo->prepare(
            'INSERT INTO team_members (team_id, user_id, role, status) VALUES (?, ?, "owner", "active")'
        );
        $memberStmt->execute([$teamId, $uid]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['ok' => false, 'error' => 'Unable to create team.'], 500);
    }

    $context = get_user_context($pdo, $uid);
    json_response(['ok' => true, 'teams' => $context['teams'], 'created_team_id' => $teamId], 201);
}

function handle_join_team(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();
    $joinCode = strtoupper(trim((string) ($input['join_code'] ?? '')));
    if (strlen($joinCode) < 6) {
        json_response(['ok' => false, 'error' => 'Valid team join code required.'], 422);
    }

    $teamStmt = $pdo->prepare('SELECT id FROM teams WHERE join_code = ? LIMIT 1');
    $teamStmt->execute([$joinCode]);
    $teamId = $teamStmt->fetchColumn();
    if ($teamId === false) {
        json_response(['ok' => false, 'error' => 'Join code not found.'], 404);
    }

    $memberStmt = $pdo->prepare(
        'INSERT INTO team_members (team_id, user_id, role, status)
         VALUES (?, ?, "member", "active")
         ON DUPLICATE KEY UPDATE status = "active"'
    );
    $joinedTeamId = (int) $teamId;
    $memberStmt->execute([$joinedTeamId, $uid]);

    $context = get_user_context($pdo, $uid);
    json_response(['ok' => true, 'teams' => $context['teams'], 'joined_team_id' => $joinedTeamId]);
}

function require_team_admin_role(PDO $pdo, int $uid, int $teamId): string
{
    $role = require_team_membership($pdo, $uid, $teamId);
    if (!in_array($role, ['owner', 'admin'], true)) {
        json_response(['ok' => false, 'error' => 'Team admin permission required.'], 403);
    }
    return $role;
}

function normalize_optional_string(?string $value, int $maxLen): ?string
{
    $v = trim((string) $value);
    if ($v === '') {
        return null;
    }
    if (strlen($v) > $maxLen) {
        json_response(['ok' => false, 'error' => 'Field is too long.'], 422);
    }
    return $v;
}

function remove_dir_recursive(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target)) {
            remove_dir_recursive($target);
        } else {
            @unlink($target);
        }
    }
    @rmdir($path);
}

function list_team_members(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare(
        'SELECT tm.id, tm.user_id, tm.role, tm.status, tm.created_at, u.display_name, u.email
         FROM team_members tm
         INNER JOIN users u ON u.id = tm.user_id
         WHERE tm.team_id = ? AND tm.status = "active"
         ORDER BY FIELD(tm.role, "owner", "admin", "member"), u.display_name'
    );
    $stmt->execute([$teamId]);
    return $stmt->fetchAll();
}

function handle_team_members(PDO $pdo): void
{
    require_method('GET');
    $uid = require_auth();
    $teamId = (int) ($_GET['team_id'] ?? 0);
    if ($teamId <= 0) {
        json_response(['ok' => false, 'error' => 'team_id required.'], 422);
    }
    require_team_membership($pdo, $uid, $teamId);
    $members = list_team_members($pdo, $teamId);
    json_response(['ok' => true, 'members' => $members]);
}

function handle_team_update(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();
    $teamId = (int) ($input['team_id'] ?? 0);
    if ($teamId <= 0) {
        json_response(['ok' => false, 'error' => 'team_id required.'], 422);
    }
    $role = require_team_admin_role($pdo, $uid, $teamId);

    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '' || strlen($name) > 160) {
        json_response(['ok' => false, 'error' => 'Team name must be 1-160 characters.'], 422);
    }
    $ageGroup = normalize_optional_string((string) ($input['age_group'] ?? ''), 60);
    $seasonYear = normalize_optional_string((string) ($input['season_year'] ?? ''), 30);
    $level = normalize_optional_string((string) ($input['level'] ?? ''), 80);
    $homeRink = normalize_optional_string((string) ($input['home_rink'] ?? ''), 160);
    $city = normalize_optional_string((string) ($input['city'] ?? ''), 120);
    $teamNotes = normalize_optional_string((string) ($input['team_notes'] ?? ''), 2000);

    $teamStmt = $pdo->prepare('SELECT id, name FROM teams WHERE id = ? LIMIT 1');
    $teamStmt->execute([$teamId]);
    $team = $teamStmt->fetch();
    if (!$team) {
        json_response(['ok' => false, 'error' => 'Team not found.'], 404);
    }

    $stmt = $pdo->prepare(
        'UPDATE teams
         SET name = ?, age_group = ?, season_year = ?, level = ?, home_rink = ?, city = ?, team_notes = ?
         WHERE id = ?'
    );
    try {
        $stmt->execute([$name, $ageGroup, $seasonYear, $level, $homeRink, $city, $teamNotes, $teamId]);
    } catch (Throwable $e) {
        json_response([
            'ok' => false,
            'error' => 'Team metadata columns missing. Run database/migrations/2026-02-14-team-metadata.sql first.'
        ], 500);
    }

    $context = get_user_context($pdo, $uid);
    json_response(['ok' => true, 'teams' => $context['teams']]);
}

function handle_team_member_role(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();
    $teamId = (int) ($input['team_id'] ?? 0);
    $memberUserId = (int) ($input['member_user_id'] ?? 0);
    $newRole = (string) ($input['role'] ?? '');
    if ($teamId <= 0 || $memberUserId <= 0) {
        json_response(['ok' => false, 'error' => 'team_id and member_user_id required.'], 422);
    }
    if (!in_array($newRole, ['admin', 'member'], true)) {
        json_response(['ok' => false, 'error' => 'Role must be admin or member.'], 422);
    }

    $actorRole = require_team_admin_role($pdo, $uid, $teamId);
    $targetStmt = $pdo->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ? AND status = "active" LIMIT 1');
    $targetStmt->execute([$teamId, $memberUserId]);
    $targetRole = $targetStmt->fetchColumn();
    if ($targetRole === false) {
        json_response(['ok' => false, 'error' => 'Member not found.'], 404);
    }
    if ($memberUserId === $uid) {
        json_response(['ok' => false, 'error' => 'You cannot edit your own role.'], 403);
    }
    if ($targetRole === 'owner') {
        json_response(['ok' => false, 'error' => 'Owner role cannot be changed.'], 403);
    }
    if ($actorRole !== 'owner' && $targetRole === 'admin') {
        json_response(['ok' => false, 'error' => 'Only owner can edit admins.'], 403);
    }

    $updateStmt = $pdo->prepare(
        'UPDATE team_members SET role = ? WHERE team_id = ? AND user_id = ? AND status = "active"'
    );
    $updateStmt->execute([$newRole, $teamId, $memberUserId]);
    json_response(['ok' => true, 'members' => list_team_members($pdo, $teamId)]);
}

function handle_team_member_remove(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();
    $teamId = (int) ($input['team_id'] ?? 0);
    $memberUserId = (int) ($input['member_user_id'] ?? 0);
    if ($teamId <= 0 || $memberUserId <= 0) {
        json_response(['ok' => false, 'error' => 'team_id and member_user_id required.'], 422);
    }

    $actorRole = require_team_admin_role($pdo, $uid, $teamId);
    $targetStmt = $pdo->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ? AND status = "active" LIMIT 1');
    $targetStmt->execute([$teamId, $memberUserId]);
    $targetRole = $targetStmt->fetchColumn();
    if ($targetRole === false) {
        json_response(['ok' => false, 'error' => 'Member not found.'], 404);
    }
    if ($memberUserId === $uid) {
        json_response(['ok' => false, 'error' => 'You cannot remove yourself.'], 403);
    }
    if ($targetRole === 'owner') {
        json_response(['ok' => false, 'error' => 'Owner cannot be removed.'], 403);
    }
    if ($actorRole !== 'owner' && $targetRole === 'admin') {
        json_response(['ok' => false, 'error' => 'Only owner can remove admins.'], 403);
    }

    $updateStmt = $pdo->prepare(
        'UPDATE team_members SET status = "removed", role = "member" WHERE team_id = ? AND user_id = ? AND status = "active"'
    );
    $updateStmt->execute([$teamId, $memberUserId]);
    json_response(['ok' => true, 'members' => list_team_members($pdo, $teamId)]);
}

function handle_team_delete(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();
    $teamId = (int) ($input['team_id'] ?? 0);
    $confirmName = trim((string) ($input['confirm_team_name'] ?? ''));
    $confirmWord = strtoupper(trim((string) ($input['confirm_word'] ?? '')));
    if ($teamId <= 0) {
        json_response(['ok' => false, 'error' => 'team_id required.'], 422);
    }

    $role = require_team_membership($pdo, $uid, $teamId);
    if ($role !== 'owner') {
        json_response(['ok' => false, 'error' => 'Only team owner can delete a team.'], 403);
    }

    $teamStmt = $pdo->prepare('SELECT id, name FROM teams WHERE id = ? LIMIT 1');
    $teamStmt->execute([$teamId]);
    $team = $teamStmt->fetch();
    if (!$team) {
        json_response(['ok' => false, 'error' => 'Team not found.'], 404);
    }
    if ($confirmWord !== 'DELETE') {
        json_response(['ok' => false, 'error' => 'Type DELETE to confirm removal.'], 422);
    }
    if ($confirmName !== (string) $team['name']) {
        json_response(['ok' => false, 'error' => 'Team name confirmation does not match.'], 422);
    }

    $teamUploadPath = UPLOAD_ROOT . '/team-' . $teamId;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['ok' => false, 'error' => 'Unable to delete team.'], 500);
    }

    $uploadRootReal = realpath(UPLOAD_ROOT) ?: '__none__';
    $teamPathReal = realpath($teamUploadPath);
    if ($teamPathReal && str_starts_with_compat($teamPathReal, $uploadRootReal)) {
        remove_dir_recursive($teamPathReal);
    } elseif (is_dir($teamUploadPath)) {
        // Fallback when realpath fails for edge cases.
        remove_dir_recursive($teamUploadPath);
    }

    $context = get_user_context($pdo, $uid);
    json_response(['ok' => true, 'teams' => $context['teams']]);
}

function handle_media_list(PDO $pdo): void
{
    require_method('GET');
    $uid = require_auth();
    $teamId = (int) ($_GET['team_id'] ?? 0);
    $limit = (int) ($_GET['limit'] ?? 60);
    $offset = (int) ($_GET['offset'] ?? 0);
    if ($limit < 1) $limit = 60;
    if ($limit > 150) $limit = 150;
    if ($offset < 0) $offset = 0;
    if ($teamId <= 0) {
        json_response(['ok' => false, 'error' => 'team_id required.'], 422);
    }
    require_team_membership($pdo, $uid, $teamId);

    $summaryStmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN media_type = "photo" THEN 1 ELSE 0 END) AS photo_count,
            SUM(CASE WHEN media_type = "video" THEN 1 ELSE 0 END) AS video_count
         FROM media_items
         WHERE team_id = ?'
    );
    $summaryStmt->execute([$teamId]);
    $summary = $summaryStmt->fetch() ?: ['total_count' => 0, 'photo_count' => 0, 'video_count' => 0];

    $stmt = $pdo->prepare(
        'SELECT m.*, u.display_name AS uploader_name
         FROM media_items m
         INNER JOIN users u ON u.id = m.uploader_user_id
         WHERE m.team_id = :team_id
         ORDER BY m.created_at DESC, m.id DESC'
         . ' LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':team_id', $teamId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = array_map('format_media_row', $stmt->fetchAll());
    $totalCount = (int) ($summary['total_count'] ?? 0);
    $nextOffset = $offset + count($items);
    json_response([
        'ok' => true,
        'items' => $items,
        'total_count' => $totalCount,
        'photo_count' => (int) ($summary['photo_count'] ?? 0),
        'video_count' => (int) ($summary['video_count'] ?? 0),
        'next_offset' => $nextOffset,
        'has_more' => $nextOffset < $totalCount
    ]);
}

function media_upload_type(string $mimeType): ?string
{
    if (strpos($mimeType, 'image/') === 0) {
        return 'photo';
    }
    if (strpos($mimeType, 'video/') === 0) {
        return 'video';
    }
    return null;
}

function handle_media_upload(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();

    $teamId = (int) ($_POST['team_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $gameDateRaw = trim((string) ($_POST['game_date'] ?? ''));
    $gameDate = normalize_game_date($gameDateRaw);
    if ($gameDateRaw !== '' && $gameDate === null) {
        json_response(['ok' => false, 'error' => 'game_date must be YYYY-MM-DD.'], 422);
    }
    if ($teamId <= 0) {
        json_response(['ok' => false, 'error' => 'team_id required.'], 422);
    }
    require_team_membership($pdo, $uid, $teamId);

    if (!isset($_FILES['file'])) {
        json_response(['ok' => false, 'error' => 'Upload file is required.'], 422);
    }

    $file = $_FILES['file'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'error' => 'Upload failed.'], 422);
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpName)) {
        json_response(['ok' => false, 'error' => 'Invalid upload source.'], 422);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
        json_response(['ok' => false, 'error' => 'File too large.'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpName);
    $mediaType = media_upload_type($mimeType);
    if ($mediaType === null) {
        json_response(['ok' => false, 'error' => 'Only image and video uploads are supported.'], 422);
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = $mediaType === 'photo' ? 'jpg' : 'mp4';
    }

    $teamDirFs = UPLOAD_ROOT . '/team-' . $teamId;
    if (!is_dir($teamDirFs) && !mkdir($teamDirFs, 0755, true) && !is_dir($teamDirFs)) {
        json_response(['ok' => false, 'error' => 'Unable to create upload directory.'], 500);
    }

    $filename = date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    $destFs = $teamDirFs . '/' . $filename;
    if (!move_uploaded_file($tmpName, $destFs)) {
        json_response(['ok' => false, 'error' => 'Failed to store upload.'], 500);
    }

    $filePath = '/uploads/team-' . $teamId . '/' . $filename;
    $thumbnailPath = null;
    if ($mediaType === 'photo') {
        $thumbDirFs = $teamDirFs . '/thumbs';
        if (!is_dir($thumbDirFs)) {
            @mkdir($thumbDirFs, 0755, true);
        }
        $thumbFilename = pathinfo($filename, PATHINFO_FILENAME) . '.webp';
        $thumbFs = $thumbDirFs . '/' . $thumbFilename;
        if (create_image_thumbnail($destFs, $thumbFs, $mimeType, 960)) {
            $thumbnailPath = '/uploads/team-' . $teamId . '/thumbs/' . $thumbFilename;
        }
    }
    if ($title === '') {
        $title = pathinfo((string) ($file['name'] ?? 'Team upload'), PATHINFO_FILENAME);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO media_items
         (team_id, uploader_user_id, media_type, storage_type, title, description, game_date, file_path, thumbnail_url, mime_type, file_size)
         VALUES (?, ?, ?, "upload", ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$teamId, $uid, $mediaType, $title, $description, $gameDate, $filePath, $thumbnailPath, $mimeType, $size]);

    json_response(['ok' => true], 201);
}

function handle_media_external(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();

    $teamId = (int) ($input['team_id'] ?? 0);
    $title = trim((string) ($input['title'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $gameDateRaw = trim((string) ($input['game_date'] ?? ''));
    $gameDate = normalize_game_date($gameDateRaw);
    if ($gameDateRaw !== '' && $gameDate === null) {
        json_response(['ok' => false, 'error' => 'game_date must be YYYY-MM-DD.'], 422);
    }
    $url = trim((string) ($input['url'] ?? ''));

    if ($teamId <= 0) {
        json_response(['ok' => false, 'error' => 'team_id required.'], 422);
    }
    require_team_membership($pdo, $uid, $teamId);
    if ($title === '' || $url === '') {
        json_response(['ok' => false, 'error' => 'Title and video URL are required.'], 422);
    }

    $yt = youtube_embed_url($url);
    $embedUrl = $yt['embed_url'] ?? $url;
    $thumbUrl = $yt['thumbnail_url'] ?? null;

    $stmt = $pdo->prepare(
        'INSERT INTO media_items
         (team_id, uploader_user_id, media_type, storage_type, title, description, game_date, external_url, thumbnail_url)
         VALUES (?, ?, "video", "external", ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$teamId, $uid, $title, $description, $gameDate, $embedUrl, $thumbUrl]);

    json_response(['ok' => true], 201);
}

function handle_media_delete(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();
    $mediaId = (int) ($input['media_id'] ?? 0);
    if ($mediaId <= 0) {
        json_response(['ok' => false, 'error' => 'media_id required.'], 422);
    }

    $stmt = $pdo->prepare(
        'SELECT m.id, m.team_id, m.uploader_user_id, m.storage_type, m.file_path
         FROM media_items m
         WHERE m.id = ? LIMIT 1'
    );
    $stmt->execute([$mediaId]);
    $item = $stmt->fetch();
    if (!$item) {
        json_response(['ok' => false, 'error' => 'Media item not found.'], 404);
    }

    $role = require_team_membership($pdo, $uid, (int) $item['team_id']);
    $isOwnerLike = in_array($role, ['owner', 'admin'], true);
    if ((int) $item['uploader_user_id'] !== $uid && !$isOwnerLike) {
        json_response(['ok' => false, 'error' => 'Delete not allowed.'], 403);
    }

    $delStmt = $pdo->prepare('DELETE FROM media_items WHERE id = ?');
    $delStmt->execute([$mediaId]);

    if ($item['storage_type'] === 'upload' && !empty($item['file_path'])) {
        $path = APP_ROOT . (string) $item['file_path'];
        if (str_starts_with_compat((string) (realpath(dirname($path)) ?: ''), (string) (realpath(UPLOAD_ROOT) ?: '__none__')) && file_exists($path)) {
            @unlink($path);
        }
    }

    json_response(['ok' => true]);
}

function handle_media_delete_batch(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();
    $mediaIds = $input['media_ids'] ?? [];
    if (!is_array($mediaIds) || count($mediaIds) === 0) {
        json_response(['ok' => false, 'error' => 'media_ids array required.'], 422);
    }

    $deleted = 0;
    foreach ($mediaIds as $rawId) {
        $mediaId = (int) $rawId;
        if ($mediaId <= 0) {
            continue;
        }
        $stmt = $pdo->prepare(
            'SELECT m.id, m.team_id, m.uploader_user_id, m.storage_type, m.file_path
             FROM media_items m
             WHERE m.id = ? LIMIT 1'
        );
        $stmt->execute([$mediaId]);
        $item = $stmt->fetch();
        if (!$item) {
            continue;
        }

        $role = require_team_membership($pdo, $uid, (int) $item['team_id']);
        $isOwnerLike = in_array($role, ['owner', 'admin'], true);
        if ((int) $item['uploader_user_id'] !== $uid && !$isOwnerLike) {
            continue;
        }

        $delStmt = $pdo->prepare('DELETE FROM media_items WHERE id = ?');
        $delStmt->execute([$mediaId]);
        $deleted++;

        if ($item['storage_type'] === 'upload' && !empty($item['file_path'])) {
            $path = APP_ROOT . (string) $item['file_path'];
            if (str_starts_with_compat((string) (realpath(dirname($path)) ?: ''), (string) (realpath(UPLOAD_ROOT) ?: '__none__')) && file_exists($path)) {
                @unlink($path);
            }
        }
    }

    json_response(['ok' => true, 'deleted' => $deleted]);
}

function handle_invite_email(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();

    $teamId = (int) ($input['team_id'] ?? 0);
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $message = trim((string) ($input['message'] ?? ''));

    if ($teamId <= 0) {
        json_response(['ok' => false, 'error' => 'team_id required.'], 422);
    }
    require_team_membership($pdo, $uid, $teamId);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'Valid recipient email required.'], 422);
    }
    if (strlen($message) > 500) {
        json_response(['ok' => false, 'error' => 'Custom message must be 500 characters or fewer.'], 422);
    }

    $now = time();
    $window = $_SESSION['invite_email_window'] ?? ['start' => $now, 'count' => 0];
    if (!is_array($window) || ($window['start'] ?? 0) < ($now - 3600)) {
        $window = ['start' => $now, 'count' => 0];
    }
    if (($window['count'] ?? 0) >= 20) {
        json_response(['ok' => false, 'error' => 'Invite email rate limit reached. Try again later.'], 429);
    }
    $lastSent = (int) ($_SESSION['invite_email_last_sent'] ?? 0);
    if ($lastSent > ($now - 10)) {
        json_response(['ok' => false, 'error' => 'Please wait a few seconds before sending another invite.'], 429);
    }

    $teamStmt = $pdo->prepare('SELECT name, join_code FROM teams WHERE id = ? LIMIT 1');
    $teamStmt->execute([$teamId]);
    $team = $teamStmt->fetch();
    if (!$team) {
        json_response(['ok' => false, 'error' => 'Team not found.'], 404);
    }

    $appUrl = APP_PUBLIC_URL;
    $inviteUrl = $appUrl . '/?join=' . rawurlencode((string) $team['join_code']);
    $senderName = (string) ($_SESSION['display_name'] ?? 'A team member');
    $brandName = APP_BRAND_NAME;
    $logoUrl = APP_INVITE_LOGO_URL;

    $subject = sprintf('Invitation to join %s on %s', (string) $team['name'], $brandName);
    $plainLines = [
        sprintf('%s invited you to join "%s" on %s.', $senderName, (string) $team['name'], $brandName),
        '',
        sprintf('Join code: %s', (string) $team['join_code']),
        sprintf('Direct link: %s', $inviteUrl),
    ];
    if ($message !== '') {
        $plainLines[] = '';
        $plainLines[] = 'Personal note:';
        $plainLines[] = $message;
    }
    $plainLines[] = '';
    $plainLines[] = 'See you at the rink.';
    $plainText = implode("\r\n", $plainLines);

    $senderEsc = htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8');
    $teamEsc = htmlspecialchars((string) $team['name'], ENT_QUOTES, 'UTF-8');
    $brandEsc = htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8');
    $codeEsc = htmlspecialchars((string) $team['join_code'], ENT_QUOTES, 'UTF-8');
    $linkEsc = htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8');
    $messageEsc = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $logoHtml = '';
    if ($logoUrl !== '') {
        $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        $logoHtml = '<img src="' . $logoEsc . '" alt="' . $brandEsc . '" style="max-height:48px;display:block;margin-bottom:12px;">';
    }
    $personalNoteHtml = $message !== ''
        ? '<p style="margin:16px 0 6px;color:#334;">Personal note:</p><div style="background:#f7f8fc;border-radius:10px;padding:10px 12px;color:#25324a;">' . $messageEsc . '</div>'
        : '';
    $html = '<!doctype html><html><body style="margin:0;background:#f2f5ff;font-family:Arial,sans-serif;color:#17253d;">
<div style="max-width:640px;margin:28px auto;background:#fff;border-radius:14px;padding:24px;border:1px solid #dde5ff;">
' . $logoHtml . '
<h2 style="margin:0 0 12px;color:#0f2f59;">You are invited to join ' . $teamEsc . '</h2>
<p style="margin:0 0 10px;color:#2e3c56;">' . $senderEsc . ' invited you to join on ' . $brandEsc . '.</p>
<p style="margin:0 0 6px;color:#2e3c56;"><strong>Join code:</strong> ' . $codeEsc . '</p>
<p style="margin:0 0 18px;"><a href="' . $linkEsc . '" style="display:inline-block;background:#ff5a2a;color:#fff;text-decoration:none;padding:10px 14px;border-radius:9px;font-weight:700;">Open Invite Link</a></p>
' . $personalNoteHtml . '
<p style="margin:18px 0 0;color:#54607a;">See you at the rink.</p>
</div></body></html>';

    $host = preg_replace('/[^A-Za-z0-9\.\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'snap.pucc.us'));
    $fromAddress = 'noreply@' . ($host !== '' ? $host : 'snap.pucc.us');
    $boundary = '=_slapshot_' . bin2hex(random_bytes(8));
    $headers = [
        'From: ' . $brandName . ' <' . $fromAddress . '>',
        'Reply-To: ' . $fromAddress,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body = '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= $plainText . "\r\n\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $html . "\r\n\r\n";
    $body .= '--' . $boundary . "--\r\n";

    $ok = @mail($email, $subject, $body, implode("\r\n", $headers));
    if (!$ok) {
        json_response(['ok' => false, 'error' => 'Email send failed. Verify your server mail configuration.'], 500);
    }

    $_SESSION['invite_email_last_sent'] = $now;
    $window['count'] = (int) ($window['count'] ?? 0) + 1;
    $_SESSION['invite_email_window'] = $window;

    json_response(['ok' => true]);
}

$pdo = db();
$action = (string) ($_GET['action'] ?? '');

switch ($action) {
    case 'session':
        respond_session($pdo);
        break;
    case 'auth_register':
        handle_register($pdo);
        break;
    case 'auth_login':
        handle_login($pdo);
        break;
    case 'auth_logout':
        handle_logout();
        break;
    case 'team_create':
        handle_create_team($pdo);
        break;
    case 'team_join':
        handle_join_team($pdo);
        break;
    case 'team_update':
        handle_team_update($pdo);
        break;
    case 'team_delete':
        handle_team_delete($pdo);
        break;
    case 'team_members':
        handle_team_members($pdo);
        break;
    case 'team_member_role':
        handle_team_member_role($pdo);
        break;
    case 'team_member_remove':
        handle_team_member_remove($pdo);
        break;
    case 'media_list':
        handle_media_list($pdo);
        break;
    case 'media_upload':
        handle_media_upload($pdo);
        break;
    case 'media_external':
        handle_media_external($pdo);
        break;
    case 'media_delete':
        handle_media_delete($pdo);
        break;
    case 'media_delete_batch':
        handle_media_delete_batch($pdo);
        break;
    case 'invite_email':
        handle_invite_email($pdo);
        break;
    default:
        json_response([
            'ok' => true,
            'service' => 'Slapshot Snapshot API',
            'actions' => [
                'session',
                'auth_register',
                'auth_login',
                'auth_logout',
                'team_create',
                'team_join',
                'team_update',
                'team_delete',
                'team_members',
                'team_member_role',
                'team_member_remove',
                'media_list',
                'media_upload',
                'media_external',
                'media_delete',
                'media_delete_batch',
                'invite_email'
            ]
        ]);
}
