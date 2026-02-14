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

function send_multipart_email(string $to, string $subject, string $plainText, string $htmlBody, string $replyTo = ''): bool
{
    $host = preg_replace('/[^A-Za-z0-9\.\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = (string) (parse_url(APP_PUBLIC_URL, PHP_URL_HOST) ?: 'snap.pucc.us');
    }
    $fromAddress = 'noreply@' . $host;
    $reply = trim($replyTo) !== '' ? trim($replyTo) : $fromAddress;
    $boundary = '=_slapshot_' . bin2hex(random_bytes(8));
    $headers = [
        'From: ' . APP_BRAND_NAME . ' <' . $fromAddress . '>',
        'Reply-To: ' . $reply,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    $body = '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= $plainText . "\r\n\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";
    $body .= '--' . $boundary . "--\r\n";

    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

function send_plain_email(string $to, string $subject, string $plainText, string $replyTo = ''): bool
{
    $host = preg_replace('/[^A-Za-z0-9\.\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = (string) (parse_url(APP_PUBLIC_URL, PHP_URL_HOST) ?: 'snap.pucc.us');
    }
    $fromAddress = 'noreply@' . $host;
    $reply = trim($replyTo) !== '' ? trim($replyTo) : $fromAddress;
    $headers = [
        'From: ' . APP_BRAND_NAME . ' <' . $fromAddress . '>',
        'Reply-To: ' . $reply,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    return @mail($to, $subject, $plainText, implode("\r\n", $headers));
}

function request_ip_address(): string
{
    $forwarded = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($forwarded === '') {
        $forwarded = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }
    return substr($forwarded, 0, 45);
}

function absolute_public_url(string $pathOrUrl): string
{
    $raw = trim($pathOrUrl);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $raw)) {
        return $raw;
    }
    if ($raw[0] !== '/') {
        $raw = '/' . $raw;
    }
    return rtrim(APP_PUBLIC_URL, '/') . $raw;
}

function default_brand_logo_url(): string
{
    if (APP_INVITE_LOGO_URL !== '') {
        return absolute_public_url(APP_INVITE_LOGO_URL);
    }
    return absolute_public_url('/brand-mark.svg');
}

function email_shell_html(
    string $eyebrow,
    string $title,
    string $contentHtml,
    string $accentStart = '#15355f',
    string $accentEnd = '#2f6ea9',
    string $logoUrl = ''
): string {
    $brandEsc = htmlspecialchars(APP_BRAND_NAME, ENT_QUOTES, 'UTF-8');
    $eyebrowEsc = htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8');
    $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $logo = trim($logoUrl) !== '' ? trim($logoUrl) : default_brand_logo_url();
    $logoEsc = htmlspecialchars($logo, ENT_QUOTES, 'UTF-8');
    $accentStartEsc = htmlspecialchars($accentStart, ENT_QUOTES, 'UTF-8');
    $accentEndEsc = htmlspecialchars($accentEnd, ENT_QUOTES, 'UTF-8');

    return '<!doctype html><html><body style="margin:0;background:#eef3ff;font-family:Arial,sans-serif;color:#1b2940;">
<div style="max-width:700px;margin:24px auto;background:#fff;border:1px solid #dce5fb;border-radius:16px;overflow:hidden;">
<div style="padding:18px 20px;background:linear-gradient(135deg,' . $accentStartEsc . ',' . $accentEndEsc . ');color:#fff;">
<table role="presentation" style="border-collapse:collapse;"><tr>
<td style="padding-right:12px;vertical-align:middle;"><img src="' . $logoEsc . '" alt="' . $brandEsc . '" style="height:42px;width:42px;border-radius:10px;display:block;"></td>
<td style="vertical-align:middle;">
<p style="margin:0;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;opacity:0.9;">' . $brandEsc . '</p>
<h2 style="margin:2px 0 0;font-size:22px;color:#fff;">' . $titleEsc . '</h2>
<p style="margin:4px 0 0;font-size:12px;letter-spacing:0.06em;text-transform:uppercase;opacity:0.82;">' . $eyebrowEsc . '</p>
</td></tr></table>
</div>
<div style="padding:20px;">' . $contentHtml . '</div>
</div></body></html>';
}

function team_logo_extension_for_mime(string $mimeType): ?string
{
    switch ($mimeType) {
        case 'image/jpeg':
            return 'jpg';
        case 'image/png':
            return 'png';
        case 'image/webp':
            return 'webp';
        case 'image/gif':
            return 'gif';
        default:
            return null;
    }
}

function unlink_upload_relative_path(string $relativePath): void
{
    $path = APP_ROOT . $relativePath;
    $dirReal = realpath(dirname($path)) ?: '';
    $uploadReal = realpath(UPLOAD_ROOT) ?: '__none__';
    if ($dirReal !== '' && str_starts_with_compat($dirReal, $uploadReal) && is_file($path)) {
        @unlink($path);
    }
}

function handle_account_update_profile(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();

    $displayName = trim((string) ($input['display_name'] ?? ''));
    $currentPassword = (string) ($input['current_password'] ?? '');
    $newPassword = (string) ($input['new_password'] ?? '');
    $newPasswordConfirm = (string) ($input['new_password_confirm'] ?? '');

    if (strlen($displayName) < 2 || strlen($displayName) > 120) {
        json_response(['ok' => false, 'error' => 'Display name must be 2-120 characters.'], 422);
    }
    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            json_response(['ok' => false, 'error' => 'New password must be at least 8 characters.'], 422);
        }
        if ($newPassword !== $newPasswordConfirm) {
            json_response(['ok' => false, 'error' => 'New password confirmation does not match.'], 422);
        }
        if ($currentPassword === '') {
            json_response(['ok' => false, 'error' => 'Current password is required to change password.'], 422);
        }
    }

    $userStmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$uid]);
    $account = $userStmt->fetch();
    if (!$account) {
        json_response(['ok' => false, 'error' => 'User not found.'], 404);
    }

    $newHash = null;
    if ($newPassword !== '') {
        if (!password_verify($currentPassword, (string) $account['password_hash'])) {
            json_response(['ok' => false, 'error' => 'Current password is incorrect.'], 403);
        }
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    if ($newHash !== null) {
        $stmt = $pdo->prepare('UPDATE users SET display_name = ?, password_hash = ? WHERE id = ?');
        $stmt->execute([$displayName, $newHash, $uid]);
    } else {
        $stmt = $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?');
        $stmt->execute([$displayName, $uid]);
    }

    $_SESSION['display_name'] = $displayName;
    $context = get_user_context($pdo, $uid);
    json_response(['ok' => true, 'user' => $context['user'], 'teams' => $context['teams']]);
}

function handle_account_email_change_request(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();

    $requestedEmail = strtolower(trim((string) ($input['requested_email'] ?? '')));
    $reason = trim((string) ($input['reason'] ?? ''));
    if (!filter_var($requestedEmail, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'Valid requested email is required.'], 422);
    }
    if (strlen($reason) > 1500) {
        json_response(['ok' => false, 'error' => 'Reason must be 1500 characters or fewer.'], 422);
    }

    $userStmt = $pdo->prepare('SELECT id, email, display_name FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$uid]);
    $account = $userStmt->fetch();
    if (!$account) {
        json_response(['ok' => false, 'error' => 'User not found.'], 404);
    }
    $currentEmail = strtolower((string) $account['email']);
    if ($requestedEmail === $currentEmail) {
        json_response(['ok' => false, 'error' => 'Requested email matches your current login email.'], 422);
    }

    $existsStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $existsStmt->execute([$requestedEmail, $uid]);
    if ($existsStmt->fetchColumn() !== false) {
        json_response(['ok' => false, 'error' => 'That email is already used by another account.'], 409);
    }

    $now = time();
    $window = $_SESSION['email_change_window'] ?? ['start' => $now, 'count' => 0];
    if (!is_array($window) || (int) ($window['start'] ?? 0) < ($now - 86400)) {
        $window = ['start' => $now, 'count' => 0];
    }
    if ((int) ($window['count'] ?? 0) >= 5) {
        json_response(['ok' => false, 'error' => 'Email change request limit reached. Try again tomorrow.'], 429);
    }
    $lastSent = (int) ($_SESSION['email_change_last_sent'] ?? 0);
    if ($lastSent > ($now - 30)) {
        json_response(['ok' => false, 'error' => 'Please wait before sending another request.'], 429);
    }

    $approveToken = bin2hex(random_bytes(24));
    $denyToken = bin2hex(random_bytes(24));
    $approveHash = hash('sha256', $approveToken);
    $denyHash = hash('sha256', $denyToken);
    $expiresAt = gmdate('Y-m-d H:i:s', $now + (7 * 86400));
    $requestIp = request_ip_address();
    $requestUserAgent = substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown')), 0, 350);
    $requestId = 0;
    try {
        $pendingStmt = $pdo->prepare(
            'SELECT id FROM email_change_requests
             WHERE user_id = ? AND status = "pending" AND expires_at >= UTC_TIMESTAMP()
             ORDER BY id DESC LIMIT 1'
        );
        $pendingStmt->execute([$uid]);
        if ($pendingStmt->fetchColumn() !== false) {
            json_response(['ok' => false, 'error' => 'You already have a pending email change request.'], 409);
        }

        $pdo->beginTransaction();
        $insStmt = $pdo->prepare(
            'INSERT INTO email_change_requests
             (user_id, current_email, requested_email, reason, status, approve_token_hash, deny_token_hash, expires_at)
             VALUES (?, ?, ?, ?, "pending", ?, ?, ?)'
        );
        $insStmt->execute([$uid, $currentEmail, $requestedEmail, $reason !== '' ? $reason : null, $approveHash, $denyHash, $expiresAt]);
        $requestId = (int) $pdo->lastInsertId();

        $approveUrl = APP_PUBLIC_URL . '/api/index.php?action=account_email_request_decision&decision=approve&token=' . rawurlencode($approveToken);
        $denyUrl = APP_PUBLIC_URL . '/api/index.php?action=account_email_request_decision&decision=deny&token=' . rawurlencode($denyToken);
        $requestedByName = htmlspecialchars((string) ($account['display_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8');
        $currentEsc = htmlspecialchars($currentEmail, ENT_QUOTES, 'UTF-8');
        $requestedEsc = htmlspecialchars($requestedEmail, ENT_QUOTES, 'UTF-8');
        $ipEsc = htmlspecialchars($requestIp, ENT_QUOTES, 'UTF-8');
        $uaEsc = htmlspecialchars($requestUserAgent, ENT_QUOTES, 'UTF-8');
        $reasonEsc = $reason !== ''
            ? nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'))
            : '<em>No reason provided.</em>';
        $approveEsc = htmlspecialchars($approveUrl, ENT_QUOTES, 'UTF-8');
        $denyEsc = htmlspecialchars($denyUrl, ENT_QUOTES, 'UTF-8');
        $subject = sprintf('Email change approval required (%s #%d)', APP_BRAND_NAME, $requestId);
        $plainText = implode("\r\n", [
            APP_BRAND_NAME . ' email change request requires review.',
            '',
            'Request ID: #' . $requestId,
            'User ID: ' . $uid,
            'Display name: ' . (string) ($account['display_name'] ?? 'Unknown'),
            'Current email: ' . $currentEmail,
            'Requested email: ' . $requestedEmail,
            'Request IP: ' . $requestIp,
            'User Agent: ' . $requestUserAgent,
            'Requested at (UTC): ' . gmdate('Y-m-d H:i:s'),
            'Expires at (UTC): ' . gmdate('Y-m-d H:i:s', strtotime($expiresAt) ?: $now),
            '',
            'Reason:',
            $reason !== '' ? $reason : '(No reason provided)',
            '',
            'Approve: ' . $approveUrl,
            'Deny: ' . $denyUrl,
        ]);
        $bodyContentHtml = '<p style="margin:0 0 14px;">A user requested a login email change and needs a decision.</p>
<table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
<tr><td style="padding:6px 0;color:#4f5e78;">Request ID</td><td style="padding:6px 0;"><strong>#' . $requestId . '</strong></td></tr>
<tr><td style="padding:6px 0;color:#4f5e78;">User ID</td><td style="padding:6px 0;"><strong>' . $uid . '</strong></td></tr>
<tr><td style="padding:6px 0;color:#4f5e78;">Display Name</td><td style="padding:6px 0;"><strong>' . $requestedByName . '</strong></td></tr>
<tr><td style="padding:6px 0;color:#4f5e78;">Current Email</td><td style="padding:6px 0;"><code>' . $currentEsc . '</code></td></tr>
<tr><td style="padding:6px 0;color:#4f5e78;">Requested Email</td><td style="padding:6px 0;"><code>' . $requestedEsc . '</code></td></tr>
<tr><td style="padding:6px 0;color:#4f5e78;">Request IP</td><td style="padding:6px 0;"><code>' . $ipEsc . '</code></td></tr>
<tr><td style="padding:6px 0;color:#4f5e78;">User Agent</td><td style="padding:6px 0;"><span style="word-break:break-word;">' . $uaEsc . '</span></td></tr>
<tr><td style="padding:6px 0;color:#4f5e78;">Expires</td><td style="padding:6px 0;">' . htmlspecialchars(gmdate('Y-m-d H:i:s', strtotime($expiresAt) ?: $now) . ' UTC', ENT_QUOTES, 'UTF-8') . '</td></tr>
</table>
<div style="margin:0 0 16px;padding:10px 12px;background:#f8faff;border:1px solid #e3e9fb;border-radius:10px;">
<p style="margin:0 0 6px;color:#4f5e78;">Reason</p>
<div style="color:#1f2f46;">' . $reasonEsc . '</div>
</div>
<p style="margin:0 0 10px;"><a href="' . $approveEsc . '" style="display:inline-block;background:#1f8a4d;color:#fff;text-decoration:none;padding:10px 14px;border-radius:9px;font-weight:700;">Approve Email Change</a></p>
<p style="margin:0 0 16px;"><a href="' . $denyEsc . '" style="display:inline-block;background:#b22d2d;color:#fff;text-decoration:none;padding:10px 14px;border-radius:9px;font-weight:700;">Deny Request</a></p>
<p style="margin:0;color:#67758f;font-size:12px;">Each link is single-use and expires automatically.</p>';
        $htmlBody = email_shell_html(
            'Support Action',
            'Email Change Review',
            $bodyContentHtml,
            '#15355f',
            '#2f6ea9'
        );

        $sent = send_multipart_email(SUPPORT_EMAIL, $subject, $plainText, $htmlBody, SUPPORT_EMAIL);
        if (!$sent) {
            throw new RuntimeException('Unable to send support approval email.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (($e instanceof PDOException) && (string) $e->getCode() === '42S02') {
            json_response([
                'ok' => false,
                'error' => 'Email request table missing. Run database/migrations/2026-02-14-email-change-requests.sql first.'
            ], 500);
        }
        json_response(['ok' => false, 'error' => 'Unable to submit email change request right now.'], 500);
    }

    $_SESSION['email_change_last_sent'] = $now;
    $window['count'] = (int) ($window['count'] ?? 0) + 1;
    $_SESSION['email_change_window'] = $window;

    json_response([
        'ok' => true,
        'message' => 'Email change request submitted. Support will review it shortly.',
        'request_id' => $requestId
    ]);
}

function handle_account_email_request_decision(PDO $pdo): void
{
    require_method('GET');
    $decision = strtolower(trim((string) ($_GET['decision'] ?? '')));
    $token = trim((string) ($_GET['token'] ?? ''));
    if (!in_array($decision, ['approve', 'deny'], true)) {
        json_response(['ok' => false, 'error' => 'decision must be approve or deny.'], 422);
    }
    if ($token === '' || strlen($token) < 20) {
        json_response(['ok' => false, 'error' => 'Missing or invalid token.'], 422);
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT *
         FROM email_change_requests
         WHERE approve_token_hash = ? OR deny_token_hash = ?
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([$tokenHash, $tokenHash]);
    $request = $stmt->fetch();
    if (!$request) {
        json_response(['ok' => false, 'error' => 'Request token not found.'], 404);
    }

    $matchesApprove = hash_equals((string) $request['approve_token_hash'], $tokenHash);
    $matchesDeny = hash_equals((string) $request['deny_token_hash'], $tokenHash);
    if (($decision === 'approve' && !$matchesApprove) || ($decision === 'deny' && !$matchesDeny)) {
        json_response(['ok' => false, 'error' => 'Token does not match requested decision action.'], 403);
    }

    $requestId = (int) $request['id'];
    $status = (string) $request['status'];
    if ($status !== 'pending') {
        json_response(['ok' => false, 'error' => 'This request has already been processed.'], 409);
    }

    $nowUtc = gmdate('Y-m-d H:i:s');
    $requestIp = request_ip_address();
    $resultMessage = '';
    $notifySubject = '';
    $notifyPlain = '';
    $notifyHtml = '';
    $notifyTo = [];

    $pdo->beginTransaction();
    try {
        $lockStmt = $pdo->prepare('SELECT * FROM email_change_requests WHERE id = ? LIMIT 1 FOR UPDATE');
        $lockStmt->execute([$requestId]);
        $locked = $lockStmt->fetch();
        if (!$locked) {
            throw new RuntimeException('Request not found while locking.');
        }
        if ((string) $locked['status'] !== 'pending') {
            $pdo->commit();
            json_response(['ok' => false, 'error' => 'This request has already been processed.'], 409);
        }

        $expiresAt = (string) $locked['expires_at'];
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
            $expireStmt = $pdo->prepare(
                'UPDATE email_change_requests SET status = "expired", decided_at = ?, decided_by_ip = ? WHERE id = ?'
            );
            $expireStmt->execute([$nowUtc, $requestIp, $requestId]);
            $pdo->commit();
            json_response(['ok' => false, 'error' => 'This request has expired.'], 410);
        }

        $userId = (int) $locked['user_id'];
        $currentEmail = strtolower((string) $locked['current_email']);
        $requestedEmail = strtolower((string) $locked['requested_email']);

        if ($decision === 'approve') {
            $conflictStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $conflictStmt->execute([$requestedEmail, $userId]);
            if ($conflictStmt->fetchColumn() !== false) {
                $denyStmt = $pdo->prepare(
                    'UPDATE email_change_requests SET status = "denied", decided_at = ?, decided_by_ip = ? WHERE id = ?'
                );
                $denyStmt->execute([$nowUtc, $requestIp, $requestId]);
                $pdo->commit();
                json_response(['ok' => false, 'error' => 'Cannot approve: requested email is already in use.'], 409);
            }

            $userUpdateStmt = $pdo->prepare('UPDATE users SET email = ? WHERE id = ?');
            $userUpdateStmt->execute([$requestedEmail, $userId]);

            $approveStmt = $pdo->prepare(
                'UPDATE email_change_requests SET status = "approved", decided_at = ?, decided_by_ip = ? WHERE id = ?'
            );
            $approveStmt->execute([$nowUtc, $requestIp, $requestId]);

            $resultMessage = 'Email change approved and applied.';
            $notifySubject = APP_BRAND_NAME . ': your email change was approved';
            $notifyPlain = implode("\r\n", [
                'Your email change request was approved.',
                '',
                'Old email: ' . $currentEmail,
                'New email: ' . $requestedEmail,
                'Request ID: #' . $requestId,
            ]);
            $notifyBody = '<p style="margin:0 0 12px;">Your email change request was approved.</p>
<p style="margin:0;"><strong>Old email:</strong> ' . htmlspecialchars($currentEmail, ENT_QUOTES, 'UTF-8') . '<br><strong>New email:</strong> ' . htmlspecialchars($requestedEmail, ENT_QUOTES, 'UTF-8') . '<br><strong>Request ID:</strong> #' . $requestId . '</p>';
            $notifyHtml = email_shell_html(
                'Account Update',
                'Email Change Approved',
                $notifyBody,
                '#15355f',
                '#2f6ea9'
            );
            $notifyTo = [$requestedEmail, $currentEmail];
        } else {
            $denyStmt = $pdo->prepare(
                'UPDATE email_change_requests SET status = "denied", decided_at = ?, decided_by_ip = ? WHERE id = ?'
            );
            $denyStmt->execute([$nowUtc, $requestIp, $requestId]);
            $resultMessage = 'Email change request denied.';
            $notifySubject = APP_BRAND_NAME . ': your email change request was denied';
            $notifyPlain = implode("\r\n", [
                'Your email change request was denied.',
                '',
                'Current email remains: ' . $currentEmail,
                'Requested email: ' . $requestedEmail,
                'Request ID: #' . $requestId,
            ]);
            $notifyBody = '<p style="margin:0 0 12px;">Your email change request was denied.</p>
<p style="margin:0;"><strong>Current email remains:</strong> ' . htmlspecialchars($currentEmail, ENT_QUOTES, 'UTF-8') . '<br><strong>Requested email:</strong> ' . htmlspecialchars($requestedEmail, ENT_QUOTES, 'UTF-8') . '<br><strong>Request ID:</strong> #' . $requestId . '</p>';
            $notifyHtml = email_shell_html(
                'Account Update',
                'Email Change Denied',
                $notifyBody,
                '#5b1321',
                '#9d2f4c'
            );
            $notifyTo = [$currentEmail];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['ok' => false, 'error' => 'Unable to process decision right now.'], 500);
    }

    foreach (array_values(array_unique($notifyTo)) as $targetEmail) {
        if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        send_multipart_email($targetEmail, $notifySubject, $notifyPlain, $notifyHtml, SUPPORT_EMAIL);
    }

    json_response(['ok' => true, 'result' => $decision, 'message' => $resultMessage, 'request_id' => $requestId]);
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

function list_team_invites(PDO $pdo, int $teamId): array
{
    // Keep statuses accurate when an invited email has now joined the team.
    $syncStmt = $pdo->prepare(
        'UPDATE team_invites ti
         INNER JOIN users u ON u.email = ti.email
         INNER JOIN team_members tm ON tm.team_id = ti.team_id AND tm.user_id = u.id AND tm.status = "active"
         SET ti.status = "accepted", ti.accepted_at = COALESCE(ti.accepted_at, tm.created_at)
         WHERE ti.team_id = ? AND ti.status <> "accepted"'
    );
    $syncStmt->execute([$teamId]);

    $stmt = $pdo->prepare(
        'SELECT
            ti.id,
            ti.email,
            CASE WHEN tm.id IS NOT NULL THEN "accepted" ELSE ti.status END AS status,
            ti.message_preview,
            ti.send_count,
            ti.created_at,
            ti.last_sent_at,
            COALESCE(ti.accepted_at, tm.created_at) AS accepted_at,
            inviter.display_name AS invited_by_name,
            accepted_user.id AS accepted_user_id,
            accepted_user.display_name AS accepted_user_name
         FROM team_invites ti
         LEFT JOIN users inviter ON inviter.id = ti.invited_by_user_id
         LEFT JOIN users accepted_user ON accepted_user.email = ti.email
         LEFT JOIN team_members tm ON tm.team_id = ti.team_id AND tm.user_id = accepted_user.id AND tm.status = "active"
         WHERE ti.team_id = ?
         ORDER BY
            CASE
                WHEN tm.id IS NOT NULL THEN 2
                WHEN ti.status = "pending" THEN 1
                ELSE 3
            END ASC,
            ti.last_sent_at DESC,
            ti.id DESC'
    );
    $stmt->execute([$teamId]);
    return $stmt->fetchAll();
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
    $role = require_team_membership($pdo, $uid, $teamId);
    $members = list_team_members($pdo, $teamId);
    $invites = [];
    $inviteTrackingEnabled = true;
    if ($role === 'owner') {
        try {
            $invites = list_team_invites($pdo, $teamId);
        } catch (Throwable $e) {
            $inviteTrackingEnabled = false;
        }
    }
    json_response([
        'ok' => true,
        'members' => $members,
        'invites' => $invites,
        'invite_tracking_enabled' => $inviteTrackingEnabled
    ]);
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

function handle_team_logo_upload(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $teamId = (int) ($_POST['team_id'] ?? 0);
    if ($teamId <= 0) {
        json_response(['ok' => false, 'error' => 'team_id required.'], 422);
    }
    require_team_admin_role($pdo, $uid, $teamId);
    if (!isset($_FILES['logo'])) {
        json_response(['ok' => false, 'error' => 'Team logo file is required.'], 422);
    }

    $file = $_FILES['logo'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'error' => 'Logo upload failed.'], 422);
    }
    $tmpName = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpName)) {
        json_response(['ok' => false, 'error' => 'Invalid upload source.'], 422);
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > MAX_TEAM_LOGO_BYTES) {
        json_response(['ok' => false, 'error' => 'Team logo must be under 8MB.'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpName);
    $ext = team_logo_extension_for_mime($mimeType);
    if ($ext === null) {
        json_response(['ok' => false, 'error' => 'Logo must be JPG, PNG, WEBP, or GIF.'], 422);
    }

    $teamDirFs = UPLOAD_ROOT . '/team-' . $teamId;
    $brandingDirFs = $teamDirFs . '/branding';
    if (!is_dir($brandingDirFs) && !mkdir($brandingDirFs, 0755, true) && !is_dir($brandingDirFs)) {
        json_response(['ok' => false, 'error' => 'Unable to create branding directory.'], 500);
    }

    $filename = 'logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    $destFs = $brandingDirFs . '/' . $filename;
    if (!move_uploaded_file($tmpName, $destFs)) {
        json_response(['ok' => false, 'error' => 'Failed to store team logo.'], 500);
    }

    $logoPath = '/uploads/team-' . $teamId . '/branding/' . $filename;
    try {
        $teamStmt = $pdo->prepare('SELECT logo_path FROM teams WHERE id = ? LIMIT 1');
        $teamStmt->execute([$teamId]);
        $team = $teamStmt->fetch();
        if (!$team) {
            @unlink($destFs);
            json_response(['ok' => false, 'error' => 'Team not found.'], 404);
        }
        $previousLogo = trim((string) ($team['logo_path'] ?? ''));

        $updateStmt = $pdo->prepare('UPDATE teams SET logo_path = ? WHERE id = ?');
        $updateStmt->execute([$logoPath, $teamId]);
        if ($previousLogo !== '' && $previousLogo !== $logoPath) {
            unlink_upload_relative_path($previousLogo);
        }
    } catch (Throwable $e) {
        @unlink($destFs);
        if (($e instanceof PDOException) && (string) $e->getCode() === '42S22') {
            json_response([
                'ok' => false,
                'error' => 'Logo column missing. Run database/migrations/2026-02-14-team-logo.sql first.'
            ], 500);
        }
        json_response(['ok' => false, 'error' => 'Unable to save team logo right now.'], 500);
    }

    $context = get_user_context($pdo, $uid);
    json_response(['ok' => true, 'teams' => $context['teams'], 'logo_path' => $logoPath]);
}

function handle_team_logo_delete(PDO $pdo): void
{
    require_method('POST');
    $uid = require_auth();
    $input = get_json_input();
    $teamId = (int) ($input['team_id'] ?? 0);
    if ($teamId <= 0) {
        json_response(['ok' => false, 'error' => 'team_id required.'], 422);
    }
    require_team_admin_role($pdo, $uid, $teamId);

    try {
        $teamStmt = $pdo->prepare('SELECT logo_path FROM teams WHERE id = ? LIMIT 1');
        $teamStmt->execute([$teamId]);
        $team = $teamStmt->fetch();
        if (!$team) {
            json_response(['ok' => false, 'error' => 'Team not found.'], 404);
        }
        $previousLogo = trim((string) ($team['logo_path'] ?? ''));
        if ($previousLogo !== '') {
            $updateStmt = $pdo->prepare('UPDATE teams SET logo_path = NULL WHERE id = ?');
            $updateStmt->execute([$teamId]);
            unlink_upload_relative_path($previousLogo);
        }
    } catch (Throwable $e) {
        if (($e instanceof PDOException) && (string) $e->getCode() === '42S22') {
            json_response([
                'ok' => false,
                'error' => 'Logo column missing. Run database/migrations/2026-02-14-team-logo.sql first.'
            ], 500);
        }
        json_response(['ok' => false, 'error' => 'Unable to remove team logo right now.'], 500);
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
    try {
        $summaryStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN media_type = 'photo' THEN 1 ELSE 0 END) AS photo_count,
                SUM(CASE WHEN media_type = 'video' THEN 1 ELSE 0 END) AS video_count
             FROM media_items
             WHERE team_id = ?"
        );
        $summaryStmt->execute([$teamId]);
        $summary = $summaryStmt->fetch() ?: ['total_count' => 0, 'photo_count' => 0, 'video_count' => 0];

        $stmt = $pdo->prepare(
            'SELECT m.*, u.display_name AS uploader_name
             FROM media_items m
             INNER JOIN users u ON u.id = m.uploader_user_id
             WHERE m.team_id = :team_id
             ORDER BY m.created_at DESC, m.id DESC'
             . sprintf(' LIMIT %d OFFSET %d', $limit, $offset)
        );
        $stmt->bindValue(':team_id', $teamId, PDO::PARAM_INT);
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
    } catch (Throwable $e) {
        error_log('media_list failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'Unable to load media right now.'], 500);
    }
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

    try {
        $teamStmt = $pdo->prepare('SELECT name, join_code, logo_path FROM teams WHERE id = ? LIMIT 1');
        $teamStmt->execute([$teamId]);
        $team = $teamStmt->fetch();
    } catch (Throwable $e) {
        // Backward-compatible fallback when logo_path migration has not been applied yet.
        $teamStmt = $pdo->prepare('SELECT name, join_code, NULL AS logo_path FROM teams WHERE id = ? LIMIT 1');
        $teamStmt->execute([$teamId]);
        $team = $teamStmt->fetch();
    }
    if (!$team) {
        json_response(['ok' => false, 'error' => 'Team not found.'], 404);
    }

    $appUrl = APP_PUBLIC_URL;
    $inviteUrl = $appUrl . '/?join=' . rawurlencode((string) $team['join_code']);
    $senderName = (string) ($_SESSION['display_name'] ?? 'A team member');
    $brandName = APP_BRAND_NAME;
    $teamLogoPath = trim((string) ($team['logo_path'] ?? ''));
    $logoUrl = $teamLogoPath !== '' ? absolute_public_url($teamLogoPath) : default_brand_logo_url();

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
    $codeEsc = htmlspecialchars((string) $team['join_code'], ENT_QUOTES, 'UTF-8');
    $linkEsc = htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8');
    $messageEsc = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $personalNoteHtml = $message !== ''
        ? '<p style="margin:16px 0 6px;color:#334;">Personal note:</p><div style="background:#f7f8fc;border-radius:10px;padding:10px 12px;color:#25324a;">' . $messageEsc . '</div>'
        : '';
    $inviteBodyHtml = '
<h2 style="margin:0 0 12px;color:#0f2f59;">You are invited to join ' . $teamEsc . '</h2>
<p style="margin:0 0 10px;color:#2e3c56;">' . $senderEsc . ' invited you to join on ' . htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') . '.</p>
<p style="margin:0 0 6px;color:#2e3c56;"><strong>Join code:</strong> ' . $codeEsc . '</p>
<p style="margin:0 0 18px;"><a href="' . $linkEsc . '" style="display:inline-block;background:#ff5a2a;color:#fff;text-decoration:none;padding:10px 14px;border-radius:9px;font-weight:700;">Open Invite Link</a></p>
' . $personalNoteHtml . '
<p style="margin:18px 0 0;color:#54607a;">See you at the rink.</p>';
    $html = email_shell_html(
        'Team Invite',
        'Join ' . (string) $team['name'],
        $inviteBodyHtml,
        '#133056',
        '#1f5e94',
        $logoUrl
    );

    $ok = send_multipart_email($email, $subject, $plainText, $html);
    if (!$ok) {
        error_log('invite_email multipart send failed for team_id=' . $teamId . ' email=' . $email);
        $ok = send_plain_email($email, $subject, $plainText);
    }
    if (!$ok) {
        error_log('invite_email plain-text fallback send failed for team_id=' . $teamId . ' email=' . $email);
        json_response(['ok' => false, 'error' => 'Email send failed. Verify your server mail configuration.'], 500);
    }

    $messagePreview = $message !== '' ? substr($message, 0, 255) : null;
    $inviteTrackingEnabled = true;
    try {
        $inviteStmt = $pdo->prepare(
            'INSERT INTO team_invites
             (team_id, invited_by_user_id, email, message_preview, status, send_count, created_at, last_sent_at)
             VALUES (?, ?, ?, ?, "pending", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                invited_by_user_id = VALUES(invited_by_user_id),
                message_preview = VALUES(message_preview),
                send_count = send_count + 1,
                last_sent_at = UTC_TIMESTAMP(),
                status = IF(status = "accepted", "accepted", "pending")'
        );
        $inviteStmt->execute([$teamId, $uid, $email, $messagePreview]);
    } catch (Throwable $e) {
        $inviteTrackingEnabled = false;
    }

    $_SESSION['invite_email_last_sent'] = $now;
    $window['count'] = (int) ($window['count'] ?? 0) + 1;
    $_SESSION['invite_email_window'] = $window;

    json_response(['ok' => true, 'invite_tracking_enabled' => $inviteTrackingEnabled]);
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
    case 'account_update_profile':
        handle_account_update_profile($pdo);
        break;
    case 'account_email_change_request':
        handle_account_email_change_request($pdo);
        break;
    case 'account_email_request_decision':
        handle_account_email_request_decision($pdo);
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
    case 'team_logo_upload':
        handle_team_logo_upload($pdo);
        break;
    case 'team_logo_delete':
        handle_team_logo_delete($pdo);
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
                'account_update_profile',
                'account_email_change_request',
                'account_email_request_decision',
                'team_create',
                'team_join',
                'team_update',
                'team_logo_upload',
                'team_logo_delete',
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
