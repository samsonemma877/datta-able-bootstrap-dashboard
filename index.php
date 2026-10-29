<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'use_strict_mode' => true,
]);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/layout.php';

$user = current_user();
if ($user !== null) {
    $accountCheck = $pdo->prepare(
        'SELECT u.is_active, u.role, u.department_id, d.name AS department_name
         FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?'
    );
    $accountCheck->execute([$user['id']]);
    $freshAccount = $accountCheck->fetch();
    if (!$freshAccount || !(int) $freshAccount['is_active']) {
        unset($_SESSION['user']);
        session_regenerate_id(true);
        flash('danger', 'This account has been disabled. Contact the portal administrator.');
        redirect('login');
    }
    $_SESSION['user']['role'] = $freshAccount['role'];
    $_SESSION['user']['department_id'] = $freshAccount['department_id'];
    $_SESSION['user']['department_name'] = $freshAccount['department_name'];
    $user = current_user();
}

$page = (string) ($_GET['page'] ?? 'home');
if ($page === 'topics') {
    if ($user === null || $user['role'] !== 'student') {
        http_response_code(403);
        exit;
    }
    $categoryId = filter_var($_GET['category_id'] ?? null, FILTER_VALIDATE_INT);
    $topicQuery = $pdo->prepare(
        'SELECT t.id, t.name FROM complaint_topics t
         JOIN complaint_categories c ON c.id = t.category_id
         WHERE t.category_id = ? AND t.is_active = 1 AND c.is_active = 1 ORDER BY t.name'
    );
    $topicQuery->execute([$categoryId]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($topicQuery->fetchAll(), JSON_THROW_ON_ERROR);
    exit;
}

if ($page === 'download') {
    if ($user === null) {
        redirect('login');
    }
    $fileId = filter_var($_GET['file'] ?? null, FILTER_VALIDATE_INT);
    $fileQuery = $pdo->prepare(
        'SELECT f.*, t.student_id, t.department_id, u.department_id AS uploader_department_id
         FROM ticket_files f JOIN tickets t ON t.id = f.ticket_id
         JOIN users u ON u.id = f.uploader_id WHERE f.id = ?'
    );
    $fileQuery->execute([$fileId]);
    $file = $fileQuery->fetch();
    $authorized = $file && (
        $user['role'] === 'admin'
        || ($user['role'] === 'student' && (int) $file['student_id'] === (int) $user['id'])
        || ($user['role'] === 'department' && (int) $file['department_id'] === (int) $user['department_id'])
    );
    if (
        !$authorized
        || ($file['visibility'] === 'internal' && $user['role'] === 'student')
        || ($file['visibility'] === 'internal' && $user['role'] === 'department' && (int) $file['uploader_department_id'] !== (int) $user['department_id'])
    ) {
        http_response_code(404);
        exit('Document not found.');
    }
    $path = upload_directory() . DIRECTORY_SEPARATOR . basename($file['stored_name']);
    if (!is_file($path)) {
        http_response_code(404);
        exit('Document not found.');
    }
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="attachment"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

if ($page === 'export-csv') {
    require_role(['admin']);
    $statement = $pdo->query(
        'SELECT t.reference_number, t.subject, c.name AS category, tp.name AS topic, t.priority, t.status,
                u.full_name AS student, u.email, u.matric_number, d.name AS department, t.created_at, t.due_at, t.updated_at
         FROM tickets t JOIN users u ON u.id = t.student_id
         LEFT JOIN complaint_categories c ON c.id = t.category_id
         LEFT JOIN complaint_topics tp ON tp.id = t.topic_id
         LEFT JOIN departments d ON d.id = t.department_id ORDER BY t.created_at DESC'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fcwm-complaints-' . date('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to generate the complaint report.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Reference', 'Title', 'Category', 'Topic', 'Priority', 'Status', 'Student', 'Email', 'Matric number', 'Department', 'Submitted', 'Due date', 'Last update']);
    while ($row = $statement->fetch(PDO::FETCH_NUM)) {
        foreach ($row as &$cell) {
            $cell = (string) ($cell ?? '');
            if (preg_match('/^[\s]*[=+\-@]/', $cell)) {
                $cell = "'" . $cell;
            }
        }
        unset($cell);
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

$adminExists = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
require __DIR__ . '/includes/extended_actions.php';
require __DIR__ . '/includes/actions.php';

$publicPages = ['login', 'register', 'setup', 'track'];

if ($page === 'track' && $user === null) {
    $reference = strtoupper(trim((string) ($_GET['ref'] ?? '')));
    $matric = strtoupper(trim((string) ($_GET['matric_number'] ?? '')));
    $ticket = null;
    if ($reference !== '' && $matric !== '') {
        $statement = $pdo->prepare(
            'SELECT t.reference_number, t.category, t.subject, t.status, t.department_response, t.created_at, d.name AS department_name
             FROM tickets t JOIN users u ON u.id = t.student_id
             LEFT JOIN departments d ON d.id = t.department_id
             WHERE t.reference_number = ? AND u.matric_number = ?'
        );
        $statement->execute([$reference, $matric]);
        $ticket = $statement->fetch() ?: null;
    }
    ?>
    <!doctype html><html lang="en"><head>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Track a ticket | FCWM Student Support</title>
      <link rel="stylesheet" href="<?= e(asset_url('css/style.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('css/style-preset.css')) ?>">
      <link rel="stylesheet" href="<?= e(asset_url('fonts/phosphor/regular/style.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('css/portal.css')) ?>">
    </head><body class="portal-auth-page">
    <main class="portal-auth-wrap"><a class="portal-brand portal-auth-brand" href="<?= e(app_url('track')) ?>"><span class="portal-brand-mark">FC</span><span><strong>FCWM</strong><small>Student Support</small></span></a>
      <div class="card portal-auth-card"><div class="card-body">
        <span class="portal-eyebrow">Ticket lookup</span><h1>Track a ticket</h1>
        <p class="text-muted mb-4">Enter the reference number you received after submitting your request.</p>
        <form method="get" action="<?= e(app_url('track')) ?>" class="mb-3"><input type="hidden" name="page" value="track"><div class="mb-3"><label class="form-label" for="ref">Reference number</label><input class="form-control text-uppercase" id="ref" name="ref" value="<?= e($reference) ?>" placeholder="FCWM-2026-000123" required></div><div class="mb-3"><label class="form-label" for="matric_number">Student matric number</label><input class="form-control text-uppercase" id="matric_number" name="matric_number" value="<?= e($matric) ?>" required></div><button class="btn btn-primary w-100" type="submit">Check status</button></form>
        <?php if (($reference !== '' || $matric !== '') && !$ticket): ?><div class="alert alert-warning">We could not verify that reference and matric number. Check the details and try again.</div><?php endif; ?>
        <?php if ($ticket): ?><div class="portal-track-result"><span class="portal-eyebrow"><?= e($ticket['reference_number']) ?></span><h3><?= e($ticket['subject']) ?></h3><p><?= e($ticket['category']) ?> · Submitted <?= e(date('M j, Y', strtotime($ticket['created_at']))) ?></p><div class="mb-3"><span class="portal-status <?= e(status_class($ticket['status'])) ?>"><?= e($ticket['status']) ?></span></div><div class="portal-detail-pair"><span>Assigned department</span><strong><?= e($ticket['department_name'] ?? 'Awaiting assignment') ?></strong></div><?php if ($ticket['department_response']): ?><div class="portal-response mt-3"><span>Latest update</span><p><?= nl2br(e($ticket['department_response'])) ?></p></div><?php endif; ?></div><?php endif; ?>
        <p class="text-center mt-4 mb-0"><a href="<?= e(app_url('login')) ?>">Sign in to your student account</a></p>
      </div></div><p class="portal-auth-footnote">Federal College of Wildlife Management · New Bussa, Niger State</p>
    </main></body></html>
    <?php
    exit;
}

if (!$adminExists && !in_array($page, ['setup', 'login'], true)) {
    redirect('setup');
}
if (!$adminExists && $page === 'login') {
    redirect('setup');
}
if ($user === null && !in_array($page, $publicPages, true)) {
    redirect('login');
}
if ($user !== null && in_array($page, ['login', 'register', 'setup'], true)) {
    redirect('home');
}

$roleHome = match ($user['role'] ?? '') {
    'admin' => 'home',
    'department' => 'home',
    default => 'home',
};

if ($user !== null) {
    $allowedPages = match ($user['role']) {
        'student' => ['home', 'new-ticket', 'ticket-created', 'my-tickets', 'view-ticket', 'track', 'notifications', 'profile', 'topics'],
        'admin' => ['home', 'cases', 'staff', 'departments', 'users', 'categories', 'workflow', 'reports', 'audit', 'settings', 'notifications', 'profile', 'view-ticket'],
        'department' => ['home', 'department-cases', 'reports', 'notifications', 'profile', 'view-ticket'],
        default => [],
    };
    if (!in_array($page, $allowedPages, true)) {
        redirect($roleHome);
    }
}

if ($page === 'setup') {
    $title = 'Set up administrator';
    ?>
    <!doctype html><html lang="en"><head>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= e($title) ?> | FCWM Student Support</title>
      <link rel="stylesheet" href="<?= e(asset_url('css/style.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('css/style-preset.css')) ?>">
      <link rel="stylesheet" href="<?= e(asset_url('fonts/phosphor/regular/style.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('css/portal.css')) ?>">
    </head><body class="portal-auth-page">
    <main class="portal-auth-wrap"><a class="portal-brand portal-auth-brand" href="<?= e(app_url('setup')) ?>"><span class="portal-brand-mark">FC</span><span><strong>FCWM</strong><small>Student Support</small></span></a>
      <div class="card portal-auth-card"><div class="card-body">
        <span class="portal-eyebrow">First-time setup</span><h1>Create the administrator account</h1>
        <p class="text-muted mb-4">This account manages case routing and department access. Choose a strong password and keep it private.</p>
        <?php render_flash(); ?>
        <form method="post" action="<?= e(app_url('setup')) ?>">
          <?= csrf_input() ?><input type="hidden" name="action" value="setup-admin">
          <div class="mb-3"><label class="form-label" for="full_name">Administrator full name</label><input class="form-control" id="full_name" name="full_name" maxlength="150" required autocomplete="name"></div>
          <div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" id="email" name="email" type="email" maxlength="190" required autocomplete="email"></div>
          <div class="mb-4"><label class="form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" minlength="10" required autocomplete="new-password"><div class="form-text">Use at least 10 characters.</div></div>
          <button class="btn btn-primary w-100" type="submit">Create administrator account</button>
        </form>
      </div></div>
      <p class="portal-auth-footnote">Federal College of Wildlife Management · New Bussa, Niger State</p>
    </main></body></html>
    <?php
    exit;
}

if (in_array($page, ['login', 'register'], true)) {
    $title = $page === 'login' ? 'Welcome back' : 'Create your student account';
    $departments = $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();
    ?>
    <!doctype html><html lang="en"><head>
      <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= e($title) ?> | FCWM Student Support</title>
      <link rel="stylesheet" href="<?= e(asset_url('css/style.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('css/style-preset.css')) ?>">
      <link rel="stylesheet" href="<?= e(asset_url('fonts/phosphor/regular/style.css')) ?>"><link rel="stylesheet" href="<?= e(asset_url('css/portal.css')) ?>">
    </head><body class="portal-auth-page">
    <main class="portal-auth-wrap"><a class="portal-brand portal-auth-brand" href="<?= e(app_url('login')) ?>"><span class="portal-brand-mark">FC</span><span><strong>FCWM</strong><small>Student Support</small></span></a>
      <div class="card portal-auth-card"><div class="card-body">
        <span class="portal-eyebrow"><?= $page === 'login' ? 'Student and staff access' : 'Student registration' ?></span>
        <h1><?= $page === 'login' ? 'Welcome back' : 'Create your account' ?></h1>
        <p class="text-muted mb-4"><?= $page === 'login' ? 'Sign in to manage your support requests.' : 'Register with your college details to submit and track a case.' ?></p>
        <?php render_flash(); ?>
        <?php if ($page === 'login'): ?>
          <form method="post" action="<?= e(app_url('login')) ?>">
            <?= csrf_input() ?><input type="hidden" name="action" value="login">
            <div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" id="email" name="email" type="email" required autocomplete="email"></div>
            <div class="mb-4"><label class="form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" required autocomplete="current-password"></div>
            <button class="btn btn-primary w-100" type="submit">Sign in</button>
          </form>
          <p class="text-center mt-4 mb-0">New student? <a href="<?= e(app_url('register')) ?>">Create an account</a></p>
        <?php else: ?>
          <form method="post" action="<?= e(app_url('register')) ?>">
            <?= csrf_input() ?><input type="hidden" name="action" value="register">
            <div class="mb-3"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" maxlength="150" required autocomplete="name"></div>
            <div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" id="email" name="email" type="email" maxlength="190" required autocomplete="email"></div>
            <div class="mb-3"><label class="form-label" for="matric_number">Matric number</label><input class="form-control" id="matric_number" name="matric_number" maxlength="50" required></div>
            <div class="mb-3"><label class="form-label" for="department_id">Department</label><select class="form-select" id="department_id" name="department_id" required><option value="">Select your department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option><?php endforeach; ?></select></div>
            <div class="mb-3"><label class="form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" minlength="8" required autocomplete="new-password"><div class="form-text">At least 8 characters.</div></div>
            <div class="mb-4"><label class="form-label" for="confirm_password">Confirm password</label><input class="form-control" id="confirm_password" name="confirm_password" type="password" minlength="8" required autocomplete="new-password"></div>
            <button class="btn btn-primary w-100" type="submit">Create student account</button>
          </form>
          <p class="text-center mt-4 mb-0">Already registered? <a href="<?= e(app_url('login')) ?>">Sign in</a></p>
        <?php endif; ?>
      </div></div>
      <p class="portal-auth-footnote">Federal College of Wildlife Management · New Bussa, Niger State</p>
    </main></body></html>
    <?php
    exit;
}

$titles = [
    'home' => match ($user['role'] ?? '') {
        'admin' => 'Administration overview',
        'department' => 'Department overview',
        default => 'Student support overview',
    },
    'new-ticket' => 'Create a support ticket',
    'my-tickets' => 'My support tickets',
    'cases' => 'All support cases',
    'department-cases' => 'Assigned department cases',
    'staff' => 'Department accounts',
    'departments' => 'Manage departments',
    'users' => 'Student and officer accounts',
    'categories' => 'Complaint categories and topics',
    'workflow' => 'Statuses and priorities',
    'reports' => 'Complaint reports',
    'audit' => 'Audit log',
    'settings' => 'System settings',
    'notifications' => 'Notifications',
    'profile' => 'My profile',
    'change-password' => 'Change password',
    'view-ticket' => 'Case details',
    'ticket-created' => 'Ticket submitted',
    'track' => 'Track a ticket',
];
$title = $titles[$page] ?? 'Student support';
layout_start($title, $page);
require __DIR__ . '/includes/extended_pages.php';
if (render_extended_page($page, $pdo, $user)) {
    layout_end();
    exit;
}

if ($page === 'home' && $user['role'] === 'student') {
    $stats = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status IN ('Open', 'Assigned', 'In Progress')) AS active, SUM(status = 'Resolved') AS resolved FROM tickets WHERE student_id = ?");
    $stats->execute([$user['id']]);
    $summary = $stats->fetch();
    $recent = $pdo->prepare('SELECT t.*, d.name AS department_name FROM tickets t LEFT JOIN departments d ON d.id = t.department_id WHERE t.student_id = ? ORDER BY t.created_at DESC LIMIT 5');
    $recent->execute([$user['id']]);
    page_heading('Your support space', 'Hello, ' . $user['full_name'], 'Submit a request, review progress, and follow up with the right college team.');
    ?>
    <div class="row g-3 mb-4">
      <?php stat_card('Total tickets', (int) $summary['total'], 'ph-ticket', 'tone-blue'); ?>
      <?php stat_card('Active tickets', (int) $summary['active'], 'ph-arrows-clockwise', 'tone-amber'); ?>
      <?php stat_card('Resolved', (int) $summary['resolved'], 'ph-check-circle', 'tone-green'); ?>
      <div class="col-md-6 col-xl-3"><a class="card portal-action-card" href="<?= e(app_url('new-ticket')) ?>"><div class="card-body"><span class="portal-stat-icon tone-violet"><i class="ph ph-plus"></i></span><div><p>Need help?</p><strong>Create a ticket <i class="ph ph-arrow-up-right"></i></strong></div></div></a></div>
    </div>
    <div class="card portal-panel"><div class="card-header"><div><h3>Recent tickets</h3><p>Your latest requests and their current status</p></div><a class="btn btn-light btn-sm" href="<?= e(app_url('my-tickets')) ?>">View all</a></div><?php ticket_table($recent->fetchAll()); ?></div>
    <?php
} elseif ($page === 'home' && $user['role'] === 'admin') {
    $counts = $pdo->query("SELECT COUNT(*) AS total, SUM(department_id IS NULL) AS waiting, SUM(status = 'In Progress') AS working, SUM(status = 'Resolved') AS resolved FROM tickets")->fetch();
    $recent = $pdo->query('SELECT t.*, u.full_name AS student_name, u.matric_number, d.name AS department_name FROM tickets t JOIN users u ON u.id = t.student_id LEFT JOIN departments d ON d.id = t.department_id ORDER BY t.created_at DESC LIMIT 7')->fetchAll();
    page_heading('Support operations', 'Administration overview', 'Review incoming student concerns and route each case to the team best placed to help.');
    ?>
    <div class="row g-3 mb-4">
      <?php stat_card('All cases', (int) $counts['total'], 'ph-ticket', 'tone-blue'); ?>
      <?php stat_card('Awaiting assignment', (int) $counts['waiting'], 'ph-hourglass', 'tone-amber'); ?>
      <?php stat_card('In progress', (int) $counts['working'], 'ph-arrows-clockwise', 'tone-violet'); ?>
      <?php stat_card('Resolved', (int) $counts['resolved'], 'ph-check-circle', 'tone-green'); ?>
    </div>
    <div class="card portal-panel"><div class="card-header"><div><h3>Recently submitted cases</h3><p>Route unassigned requests to a department</p></div><a class="btn btn-primary btn-sm" href="<?= e(app_url('cases')) ?>">Manage all cases</a></div><?php ticket_table($recent, true, true); ?></div>
    <?php
} elseif ($page === 'home' && $user['role'] === 'department') {
    $stats = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status IN ('Assigned', 'In Progress')) AS active, SUM(status = 'Resolved') AS resolved FROM tickets WHERE department_id = ?");
    $stats->execute([$user['department_id']]);
    $summary = $stats->fetch();
    $recent = $pdo->prepare('SELECT t.*, u.full_name AS student_name, u.matric_number, d.name AS department_name FROM tickets t JOIN users u ON u.id = t.student_id LEFT JOIN departments d ON d.id = t.department_id WHERE t.department_id = ? ORDER BY t.updated_at DESC LIMIT 7');
    $recent->execute([$user['department_id']]);
    page_heading('Your team workspace', 'Department overview', 'Review cases routed to ' . ($user['department_name'] ?? 'your department') . ' and keep students updated.');
    ?>
    <div class="row g-3 mb-4">
      <?php stat_card('Assigned cases', (int) $summary['total'], 'ph-ticket', 'tone-blue'); ?>
      <?php stat_card('Needs attention', (int) $summary['active'], 'ph-arrows-clockwise', 'tone-amber'); ?>
      <?php stat_card('Resolved', (int) $summary['resolved'], 'ph-check-circle', 'tone-green'); ?>
      <div class="col-md-6 col-xl-3"><a class="card portal-action-card" href="<?= e(app_url('department-cases')) ?>"><div class="card-body"><span class="portal-stat-icon tone-violet"><i class="ph ph-list-checks"></i></span><div><p>Case queue</p><strong>Review assigned cases <i class="ph ph-arrow-up-right"></i></strong></div></div></a></div>
    </div>
    <div class="card portal-panel"><div class="card-header"><div><h3>Recently updated</h3><p>Your department's latest case activity</p></div><a class="btn btn-light btn-sm" href="<?= e(app_url('department-cases')) ?>">View queue</a></div><?php ticket_table($recent->fetchAll(), true); ?></div>
    <?php
} elseif ($page === 'new-ticket') {
    require_role(['student']);
    page_heading('Student support', 'Tell us how we can help', 'Choose the topic that best fits your request. You will receive a reference number when it is submitted.');
    ?>
    <div class="card portal-panel portal-form-panel"><div class="card-body">
      <form method="post" action="<?= e(app_url('new-ticket')) ?>">
        <?= csrf_input() ?><input type="hidden" name="action" value="create-ticket">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="category">Topic</label><select class="form-select" id="category" name="category" required><option value="">Select an issue or request topic</option><?php foreach (['Academic', 'Facilities and maintenance', 'Finance and fees', 'ICT and online services', 'Student welfare', 'Request', 'Other'] as $category): ?><option value="<?= e($category) ?>"><?= e($category) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label" for="subject">Subject</label><input class="form-control" id="subject" name="subject" maxlength="180" required placeholder="A short summary of your issue"></div>
          <div class="col-12"><label class="form-label" for="description">Describe your issue or request</label><textarea class="form-control" id="description" name="description" rows="7" minlength="15" maxlength="10000" required placeholder="Include relevant details so the support team can assist you."></textarea><div class="form-text">Please do not include passwords or sensitive personal information.</div></div>
          <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary" type="submit"><i class="ph ph-paper-plane-tilt me-1"></i>Submit ticket</button></div>
        </div>
      </form>
    </div></div>
    <?php
} elseif ($page === 'my-tickets') {
    require_role(['student']);
    $statement = $pdo->prepare('SELECT t.*, d.name AS department_name FROM tickets t LEFT JOIN departments d ON d.id = t.department_id WHERE t.student_id = ? ORDER BY t.created_at DESC');
    $statement->execute([$user['id']]);
    page_heading('Your requests', 'My support tickets', 'Follow the progress of the issues and requests you have submitted.');
    ?><div class="card portal-panel"><?php ticket_table($statement->fetchAll()); ?></div><?php
} elseif ($page === 'cases' || $page === 'department-cases') {
    require_role($page === 'cases' ? ['admin'] : ['department']);
    if ($page === 'cases') {
        $tickets = $pdo->query('SELECT t.*, u.full_name AS student_name, u.matric_number, d.name AS department_name FROM tickets t JOIN users u ON u.id = t.student_id LEFT JOIN departments d ON d.id = t.department_id ORDER BY (t.department_id IS NULL) DESC, t.created_at DESC')->fetchAll();
        page_heading('Support operations', 'All support cases', 'Filter through submitted cases and open any case to route it to a department.');
        ?><div class="card portal-panel"><?php ticket_table($tickets, true, true); ?></div><?php
    } else {
        $statement = $pdo->prepare('SELECT t.*, u.full_name AS student_name, u.matric_number, d.name AS department_name FROM tickets t JOIN users u ON u.id = t.student_id LEFT JOIN departments d ON d.id = t.department_id WHERE t.department_id = ? ORDER BY FIELD(t.status, "Assigned", "In Progress", "Resolved", "Closed"), t.updated_at DESC');
        $statement->execute([$user['department_id']]);
        page_heading('Department queue', 'Assigned department cases', 'Only cases routed to your department are shown here.');
        ?><div class="card portal-panel"><?php ticket_table($statement->fetchAll(), true); ?></div><?php
    }
} elseif ($page === 'staff') {
    require_role(['admin']);
    $departments = $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();
    $staff = $pdo->query("SELECT u.full_name, u.email, d.name AS department_name, u.created_at FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.role = 'department' ORDER BY d.name, u.full_name")->fetchAll();
    page_heading('Access management', 'Department accounts', 'Create secure sign-in accounts for teams that handle routed student cases.');
    ?>
    <div class="row g-4">
      <div class="col-lg-5"><div class="card portal-panel"><div class="card-header"><div><h3>Add department account</h3><p>One account is scoped to one department</p></div></div><div class="card-body">
        <form method="post" action="<?= e(app_url('staff')) ?>">
          <?= csrf_input() ?><input type="hidden" name="action" value="create-department-user">
          <div class="mb-3"><label class="form-label" for="full_name">Staff member's name</label><input class="form-control" id="full_name" name="full_name" maxlength="150" required></div>
          <div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" id="email" name="email" type="email" maxlength="190" required></div>
          <div class="mb-3"><label class="form-label" for="department_id">Department</label><select class="form-select" id="department_id" name="department_id" required><option value="">Select a department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option><?php endforeach; ?></select></div>
          <div class="mb-4"><label class="form-label" for="password">Temporary password</label><input class="form-control" id="password" name="password" type="password" minlength="8" required><div class="form-text">Share it securely; use at least 8 characters.</div></div>
          <button class="btn btn-primary" type="submit">Create account</button>
        </form>
      </div></div></div>
      <div class="col-lg-7"><div class="card portal-panel"><div class="card-header"><div><h3>Active department accounts</h3><p>Accounts with access to the department workspace</p></div></div>
        <div class="table-responsive"><table class="table portal-table align-middle"><thead><tr><th>Staff member</th><th>Department</th></tr></thead><tbody>
        <?php if (!$staff): ?><tr><td colspan="2" class="portal-empty">No department accounts have been created.</td></tr><?php else: foreach ($staff as $member): ?><tr><td><strong><?= e($member['full_name']) ?></strong><small class="d-block text-muted"><?= e($member['email']) ?></small></td><td><?= e($member['department_name'] ?? 'Unassigned') ?></td></tr><?php endforeach; endif; ?>
        </tbody></table></div>
      </div></div>
    </div>
    <?php
} elseif ($page === 'view-ticket') {
    $ticketId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$ticketId) {
        http_response_code(404);
        echo '<div class="alert alert-warning">Ticket not found.</div>';
    } else {
        $query = 'SELECT t.*, u.full_name AS student_name, u.email AS student_email, u.matric_number, s.name AS student_department, d.name AS department_name
                  FROM tickets t JOIN users u ON u.id = t.student_id
                  LEFT JOIN departments s ON s.id = u.department_id LEFT JOIN departments d ON d.id = t.department_id
                  WHERE t.id = ?';
        $statement = $pdo->prepare($query);
        $statement->execute([$ticketId]);
        $ticket = $statement->fetch();
        $canView = $ticket && (
            $user['role'] === 'admin'
            || ($user['role'] === 'student' && (int) $ticket['student_id'] === (int) $user['id'])
            || ($user['role'] === 'department' && (int) $ticket['department_id'] === (int) $user['department_id'])
        );
        if (!$canView) {
            http_response_code(404);
            echo '<div class="alert alert-warning">That ticket could not be found in your support workspace.</div>';
        } else {
            page_heading('Case details', $ticket['subject'], 'Reference ' . $ticket['reference_number']);
            ?>
            <div class="row g-4">
              <div class="col-lg-7"><div class="card portal-panel"><div class="card-header"><div><h3>Issue description</h3><p>Submitted <?= e(format_date($ticket['created_at'])) ?></p></div><span class="portal-status <?= e(status_class($ticket['status'])) ?>"><?= e($ticket['status']) ?></span></div>
                <div class="card-body"><div class="portal-detail-pair"><span>Topic</span><strong><?= e($ticket['category']) ?></strong></div><div class="portal-detail-pair"><span>Student</span><strong><?= e($ticket['student_name']) ?> · <?= e($ticket['matric_number'] ?? '') ?></strong></div><div class="portal-detail-pair"><span>Student department</span><strong><?= e($ticket['student_department'] ?? 'Not listed') ?></strong></div><div class="portal-description"><?= nl2br(e($ticket['description'])) ?></div>
                <?php if ($ticket['department_response']): ?><div class="portal-response"><span>Latest department response</span><p><?= nl2br(e($ticket['department_response'])) ?></p></div><?php endif; ?>
                </div></div></div>
              <div class="col-lg-5">
                <?php if ($user['role'] === 'admin'): $departments = $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(); ?>
                  <div class="card portal-panel mb-4"><div class="card-header"><div><h3>Route this case</h3><p>Assign a team to follow up</p></div></div><div class="card-body">
                    <form method="post" action="<?= e(app_url('view-ticket', ['id' => $ticketId])) ?>"><?= csrf_input() ?><input type="hidden" name="action" value="route-ticket"><input type="hidden" name="ticket_id" value="<?= (int) $ticketId ?>">
                      <label class="form-label" for="department_id">Department</label><select class="form-select mb-3" id="department_id" name="department_id" required><option value="">Select department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= (int) $ticket['department_id'] === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select>
                      <button class="btn btn-primary" type="submit">Save assignment</button>
                    </form>
                  </div></div>
                <?php endif; ?>
                <?php if (in_array($user['role'], ['admin', 'department'], true)): ?>
                  <div class="card portal-panel"><div class="card-header"><div><h3>Update case</h3><p>Record progress for the student</p></div></div><div class="card-body">
                    <form method="post" action="<?= e(app_url('view-ticket', ['id' => $ticketId])) ?>"><?= csrf_input() ?><input type="hidden" name="action" value="update-case"><input type="hidden" name="ticket_id" value="<?= (int) $ticketId ?>">
                      <label class="form-label" for="status">Status</label><select class="form-select mb-3" id="status" name="status" required><?php foreach (['Assigned', 'In Progress', 'Resolved', 'Closed'] as $status): ?><option value="<?= e($status) ?>" <?= $ticket['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select>
                      <label class="form-label" for="department_response">Response or progress note</label><textarea class="form-control mb-3" id="department_response" name="department_response" rows="5" maxlength="10000" placeholder="Write an update visible to the student"><?= e($ticket['department_response'] ?? '') ?></textarea>
                      <button class="btn btn-primary" type="submit">Save update</button>
                    </form>
                  </div></div>
                <?php else: ?>
                  <div class="card portal-panel"><div class="card-body"><h3 class="h5">Assigned department</h3><p class="mb-0"><?= e($ticket['department_name'] ?? 'Awaiting assignment') ?></p></div></div>
                <?php endif; ?>
              </div>
            </div>
            <?php
        }
    }
} elseif ($page === 'ticket-created') {
    require_role(['student']);
    $reference = (string) ($_GET['ref'] ?? '');
    $statement = $pdo->prepare('SELECT id FROM tickets WHERE reference_number = ? AND student_id = ?');
    $statement->execute([$reference, $user['id']]);
    $createdTicket = $statement->fetch();
    if (!$createdTicket) {
        http_response_code(404);
        ?><div class="alert alert-warning">Ticket not found.</div><?php
    } else {
        ?>
        <div class="portal-success-wrap"><div class="portal-success-icon"><i class="ph ph-check"></i></div><span class="portal-eyebrow">Request received</span><h2>Your ticket is on its way</h2><p>The student support team has received your request. Keep this reference number to follow its progress.</p>
          <div class="portal-reference-box"><small>Your reference number</small><strong><?= e($reference) ?></strong></div>
          <div class="d-flex flex-wrap justify-content-center gap-2"><a class="btn btn-primary" href="<?= e(app_url('view-ticket', ['id' => $createdTicket['id']])) ?>">View ticket</a><a class="btn btn-light" href="<?= e(app_url('new-ticket')) ?>">Submit another request</a></div>
        </div>
        <?php
    }
} elseif ($page === 'track') {
    $reference = strtoupper(trim((string) ($_GET['ref'] ?? '')));
    $ticket = null;
    if ($reference !== '') {
        $statement = $pdo->prepare(
            'SELECT t.reference_number, t.category, t.subject, t.status, t.department_response, t.created_at, t.updated_at, d.name AS department_name
             FROM tickets t LEFT JOIN departments d ON d.id = t.department_id
             WHERE t.reference_number = ?' . ($user['role'] === 'student' ? ' AND t.student_id = ?' : '')
        );
        $parameters = $user['role'] === 'student' ? [$reference, $user['id']] : [$reference];
        $statement->execute($parameters);
        $ticket = $statement->fetch() ?: null;
    }
    page_heading('Ticket lookup', 'Track a ticket', 'Enter the reference number you received after submitting a support request.');
    ?>
    <div class="card portal-panel portal-track-panel"><div class="card-body">
      <form method="get" action="<?= e(app_url('track')) ?>" class="row g-2 align-items-end"><input type="hidden" name="page" value="track"><div class="col-md-5"><label class="form-label" for="ref">Reference number</label><input class="form-control text-uppercase" id="ref" name="ref" value="<?= e($reference) ?>" placeholder="FCWM-2026-000123" required></div><?php if ($user['role'] !== 'student'): ?><div class="col-md-5"><label class="form-label" for="matric_number">Student matric number</label><input class="form-control text-uppercase" id="matric_number" name="matric_number" required></div><?php endif; ?><div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Check</button></div></form>
      <?php if ($reference !== '' && !$ticket): ?><div class="alert alert-warning mt-4 mb-0">No ticket was found with that reference number. Check it and try again.</div><?php endif; ?>
      <?php if ($ticket): ?><div class="portal-track-result mt-4"><div class="d-flex justify-content-between gap-3 flex-wrap"><div><span class="portal-eyebrow"><?= e($ticket['reference_number']) ?></span><h3><?= e($ticket['subject']) ?></h3><p><?= e($ticket['category']) ?> · Submitted <?= e(date('M j, Y', strtotime($ticket['created_at']))) ?></p></div><span class="portal-status <?= e(status_class($ticket['status'])) ?>"><?= e($ticket['status']) ?></span></div>
        <div class="portal-detail-pair"><span>Assigned department</span><strong><?= e($ticket['department_name'] ?? 'Awaiting assignment') ?></strong></div>
        <?php if ($ticket['department_response']): ?><div class="portal-response mt-3"><span>Latest update</span><p><?= nl2br(e($ticket['department_response'])) ?></p></div><?php endif; ?>
      </div><?php endif; ?>
    </div></div>
    <?php
}

layout_end();

function render_flash(): void
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if ($message) {
        echo '<div class="alert alert-' . e($message['type']) . '" role="alert">' . e($message['message']) . '</div>';
    }
}
