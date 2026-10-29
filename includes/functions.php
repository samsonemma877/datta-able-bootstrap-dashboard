<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $page = 'home', array $params = []): string
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($base === '.' || $base === '/') {
        $base = '';
    }

    return $base . '/index.php?' . http_build_query(['page' => $page] + $params);
}

function asset_url(string $path): string
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($base === '.' || $base === '/') {
        $base = '';
    }

    return $base . '/dist/assets/' . ltrim($path, '/');
}

function redirect(string $page, array $params = []): never
{
    header('Location: ' . app_url($page, $params));
    exit;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('Your session expired. Please go back, refresh the page, and try again.');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_role(array $roles): void
{
    $user = current_user();
    if ($user === null) {
        redirect('login');
    }
    if (!in_array($user['role'], $roles, true)) {
        redirect('home');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function status_class(string $status): string
{
    return match ($status) {
        'Open' => 'status-open',
        'Acknowledged' => 'status-assigned',
        'In Progress' => 'status-progress',
        'Awaiting Student' => 'status-waiting',
        'Resolved' => 'status-resolved',
        'Closed' => 'status-closed',
        default => 'status-open',
    };
}

function format_date(string $date): string
{
    return date('M j, Y · g:i a', strtotime($date));
}

function upload_directory(): string
{
    $configured = getenv('FCWM_UPLOAD_DIR');
    if ($configured !== false && $configured !== '') {
        return rtrim($configured, "\\/");
    }

    return dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'fcwm-uploads';
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function audit_log(string $action, string $entityType, ?int $entityId, array $details = []): void
{
    $statement = $GLOBALS['pdo']->prepare(
        'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        current_user()['id'] ?? null,
        $action,
        $entityType,
        $entityId,
        $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null,
    ]);
}

function notify_user(int $userId, string $title, string $message, ?int $ticketId = null): void
{
    $statement = $GLOBALS['pdo']->prepare(
        'INSERT INTO notifications (user_id, ticket_id, title, message) VALUES (?, ?, ?, ?)'
    );
    $statement->execute([$userId, $ticketId, $title, $message]);
}

function notify_ticket_student(int $ticketId, string $title, string $message): void
{
    $statement = $GLOBALS['pdo']->prepare('SELECT student_id FROM tickets WHERE id = ?');
    $statement->execute([$ticketId]);
    $studentId = $statement->fetchColumn();
    if ($studentId !== false) {
        notify_user((int) $studentId, $title, $message, $ticketId);
    }
}

function record_ticket_event(
    int $ticketId,
    ?int $authorId,
    string $message,
    string $visibility = 'public',
    string $eventType = 'reply',
    ?string $oldValue = null,
    ?string $newValue = null
): int {
    $statement = $GLOBALS['pdo']->prepare(
        'INSERT INTO ticket_messages (ticket_id, author_id, message, visibility, event_type, old_value, new_value)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([$ticketId, $authorId, $message, $visibility, $eventType, $oldValue, $newValue]);

    return (int) $GLOBALS['pdo']->lastInsertId();
}

function store_ticket_upload(
    array $file,
    int $ticketId,
    int $uploaderId,
    ?int $messageId = null,
    string $visibility = 'public'
): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('The supporting document could not be uploaded. Please try again.');
    }

    $setting = $GLOBALS['pdo']->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_upload_mb'")->fetchColumn();
    $maximumBytes = max(1, min(30, (int) ($setting ?: 5))) * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > $maximumBytes) {
        throw new RuntimeException('The supporting document exceeds the configured upload-size limit.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    if (!is_string($mime) || !isset($allowed[$mime])) {
        throw new RuntimeException('Only PDF, JPEG, and PNG supporting documents are accepted.');
    }

    $directory = upload_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private upload storage could not be created. Contact the system administrator.');
    }
    $storedName = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $storedName)) {
        throw new RuntimeException('The supporting document could not be saved.');
    }

    try {
        $statement = $GLOBALS['pdo']->prepare(
            'INSERT INTO ticket_files (ticket_id, message_id, uploader_id, original_name, stored_name, mime_type, file_size, visibility)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $ticketId,
            $messageId,
            $uploaderId,
            mb_substr(basename((string) $file['name']), 0, 255),
            $storedName,
            $mime,
            (int) $file['size'],
            $visibility,
        ]);
    } catch (Throwable $exception) {
        if (is_file($directory . DIRECTORY_SEPARATOR . $storedName)) {
            unlink($directory . DIRECTORY_SEPARATOR . $storedName);
        }
        throw $exception;
    }

    return $storedName;
}
