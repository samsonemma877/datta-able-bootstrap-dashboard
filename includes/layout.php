<?php
declare(strict_types=1);

function layout_start(string $title, string $activePage): void
{
    $user = current_user();
    $role = $user['role'] ?? 'student';
    $roles = [
        'student' => [
            'name' => 'Student portal',
            'sub' => 'Student services',
            'initials' => 'ST',
            'links' => [
                ['home', 'Overview', 'ph-house'],
                ['new-ticket', 'New complaint', 'ph-plus-circle'],
                ['my-tickets', 'My complaints', 'ph-ticket'],
                ['track', 'Track a reference', 'ph-magnifying-glass'],
                ['notifications', 'Notifications', 'ph-bell'],
                ['profile', 'My profile', 'ph-user-circle'],
            ],
            'footer' => 'Student support portal',
        ],
        'admin' => [
            'name' => 'Admin console',
            'sub' => 'Portal administration',
            'initials' => 'AD',
            'links' => [
                ['home', 'Overview', 'ph-house'],
                ['cases', 'All complaints', 'ph-ticket'],
                ['departments', 'Departments', 'ph-buildings'],
                ['staff', 'Department officers', 'ph-users-three'],
                ['users', 'Student accounts', 'ph-user-list'],
                ['categories', 'Categories and topics', 'ph-list-bullets'],
                ['workflow', 'Statuses and priorities', 'ph-sliders-horizontal'],
                ['reports', 'Reports and analytics', 'ph-chart-bar'],
                ['audit', 'Audit log', 'ph-scroll'],
                ['settings', 'System settings', 'ph-gear'],
                ['notifications', 'Notifications', 'ph-bell'],
                ['profile', 'My profile', 'ph-user-circle'],
            ],
            'footer' => 'Administration workspace',
        ],
        'department' => [
            'name' => 'Department desk',
            'sub' => $user['department_name'] ?? 'Department support',
            'initials' => 'DP',
            'links' => [
                ['home', 'Overview', 'ph-house'],
                ['department-cases', 'Assigned complaints', 'ph-ticket'],
                ['reports', 'Department reports', 'ph-chart-bar'],
                ['notifications', 'Notifications', 'ph-bell'],
                ['profile', 'My profile', 'ph-user-circle'],
            ],
            'footer' => 'Department support workspace',
        ],
    ];
    $config = $roles[$role] ?? $roles['student'];
    $unreadStatement = $GLOBALS['pdo']->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $unreadStatement->execute([$user['id']]);
    $unreadCount = (int) $unreadStatement->fetchColumn();
    $flashMessage = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= e($title) ?> | FCWM Student Support</title>
      <link rel="stylesheet" href="<?= e(asset_url('css/style.css')) ?>">
      <link rel="stylesheet" href="<?= e(asset_url('css/style-preset.css')) ?>">
      <link rel="stylesheet" href="<?= e(asset_url('fonts/phosphor/regular/style.css')) ?>">
      <link rel="stylesheet" href="<?= e(asset_url('fonts/tabler-icons.min.css')) ?>">
      <link rel="stylesheet" href="<?= e(asset_url('css/portal.css')) ?>">
    </head>
    <body data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" data-pc-theme="light">
    <nav class="pc-sidebar portal-sidebar portal-sidebar-<?= e($role) ?>">
      <div class="navbar-wrapper">
        <a class="portal-brand" href="<?= e(app_url('home')) ?>">
          <span class="portal-brand-mark">FC</span>
          <span><strong>FCWM</strong><small>Student Support</small></span>
        </a>
        <div class="portal-nav-caption"><?= e($config['name']) ?></div>
        <ul class="pc-navbar">
          <?php foreach ($config['links'] as [$page, $label, $icon]): ?>
            <li class="pc-item <?= $activePage === $page ? 'active' : '' ?>">
              <a class="pc-link" href="<?= e(app_url($page)) ?>">
                <span class="pc-micon"><i class="ph <?= e($icon) ?>"></i></span>
                <span class="pc-mtext"><?= e($label) ?><?= $page === 'notifications' && $unreadCount > 0 ? ' (' . $unreadCount . ')' : '' ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="portal-sidebar-note">
          <i class="ph ph-seal-check"></i>
          <span>Federal College of Wildlife Management<br>New Bussa, Niger State</span>
        </div>
      </div>
    </nav>
    <header class="pc-header portal-header portal-header-<?= e($role) ?>">
      <div class="portal-header-inner">
        <div>
          <span class="portal-header-eyebrow"><?= e($config['sub']) ?></span>
          <h1><?= e($title) ?></h1>
        </div>
        <div class="portal-account">
          <span class="portal-avatar"><?= e($config['initials']) ?></span>
          <span class="portal-account-copy"><strong><?= e($user['full_name'] ?? '') ?></strong><small><?= e(ucfirst($role)) ?></small></span>
          <form method="post" action="<?= e(app_url('logout')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn btn-light btn-sm portal-logout"><i class="ph ph-sign-out me-1"></i>Sign out</button>
          </form>
        </div>
      </div>
    </header>
    <main class="pc-container portal-main">
      <div class="pc-content">
        <?php if ($flashMessage): ?>
          <div class="alert alert-<?= e($flashMessage['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($flashMessage['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
    <?php
}

function layout_end(): void
{
    $role = current_user()['role'] ?? 'student';
    $footers = [
        'student' => 'Need help? Submit a ticket and keep your reference number safe.',
        'admin' => 'Manage student support requests and department assignments.',
        'department' => 'Update your assigned cases to keep students informed.',
    ];
    ?>
      </div>
    </main>
    <footer class="portal-footer portal-footer-<?= e($role) ?>">
      <span>&copy; <?= date('Y') ?> Federal College of Wildlife Management, New Bussa</span>
      <span><?= e($footers[$role] ?? $footers['student']) ?></span>
    </footer>
    <script src="<?= e(asset_url('js/plugins/bootstrap.min.js')) ?>"></script>
    <script src="<?= e(asset_url('js/script.js')) ?>"></script>
    <script src="<?= e(asset_url('js/theme.js')) ?>"></script>
    </body>
    </html>
    <?php
}

function page_heading(string $eyebrow, string $heading, string $description = ''): void
{
    ?>
    <div class="portal-page-heading">
      <div><span class="portal-eyebrow"><?= e($eyebrow) ?></span><h2><?= e($heading) ?></h2>
        <?php if ($description !== ''): ?><p><?= e($description) ?></p><?php endif; ?>
      </div>
    </div>
    <?php
}

function stat_card(string $label, int $count, string $icon, string $tone): void
{
    ?>
    <div class="col-md-6 col-xl-3">
      <div class="card portal-stat-card">
        <div class="card-body">
          <span class="portal-stat-icon <?= e($tone) ?>"><i class="ph <?= e($icon) ?>"></i></span>
          <div><p><?= e($label) ?></p><h3><?= $count ?></h3></div>
        </div>
      </div>
    </div>
    <?php
}

function ticket_table(array $tickets, bool $showStudent = false, bool $showDepartment = false): void
{
    ?>
    <div class="table-responsive">
      <table class="table portal-table align-middle">
        <thead><tr>
          <th>Reference</th>
          <?php if ($showStudent): ?><th>Student</th><?php endif; ?>
          <th>Issue</th>
          <?php if ($showDepartment): ?><th>Department</th><?php endif; ?>
          <th>Submitted</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (!$tickets): ?>
          <tr><td colspan="<?= 5 + (int) $showStudent + (int) $showDepartment ?>" class="portal-empty">No tickets to display yet.</td></tr>
        <?php else: foreach ($tickets as $ticket): ?>
          <tr>
            <td><span class="portal-ref"><?= e($ticket['reference_number']) ?></span></td>
            <?php if ($showStudent): ?><td><strong><?= e($ticket['student_name']) ?></strong><small class="d-block text-muted"><?= e($ticket['matric_number'] ?? '') ?></small></td><?php endif; ?>
            <td><strong><?= e($ticket['subject']) ?></strong><small class="d-block text-muted"><?= e($ticket['category']) ?></small></td>
            <?php if ($showDepartment): ?><td><?= e($ticket['department_name'] ?? 'Unassigned') ?></td><?php endif; ?>
            <td><?= e(date('M j, Y', strtotime($ticket['created_at']))) ?></td>
            <td><span class="portal-status <?= e(status_class($ticket['status'])) ?>"><?= e($ticket['status']) ?></span></td>
            <td><a class="btn btn-light btn-sm" href="<?= e(app_url('view-ticket', ['id' => $ticket['id']])) ?>">View</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}
