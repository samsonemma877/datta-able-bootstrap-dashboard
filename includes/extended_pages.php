<?php
declare(strict_types=1);

function render_extended_page(string $page, PDO $pdo, array $user): bool
{
    $role = $user['role'];
    $params = [];
    if ($role === 'student') {
        $params[] = $user['id'];
        $scope = 't.student_id = ?';
    } elseif ($role === 'department') {
        $params[] = $user['department_id'];
        $scope = 't.department_id = ?';
    } else {
        $scope = '1 = 1';
    }

    if ($page === 'home') {
        $stats = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(t.status IN ('Open', 'Acknowledged', 'In Progress', 'Awaiting Student')) AS active,
                    SUM(t.status = 'Resolved') AS resolved,
                    SUM(t.due_at < NOW() AND t.status NOT IN ('Resolved', 'Closed')) AS overdue
             FROM tickets t WHERE {$scope}"
        );
        $stats->execute($params);
        $summary = $stats->fetch() ?: ['total' => 0, 'active' => 0, 'resolved' => 0, 'overdue' => 0];
        $recentStatement = $pdo->prepare(
            "SELECT t.*, u.full_name AS student_name, u.matric_number, d.name AS department_name
             FROM tickets t JOIN users u ON u.id = t.student_id
             LEFT JOIN departments d ON d.id = t.department_id
             WHERE {$scope} ORDER BY t.created_at DESC LIMIT 8"
        );
        $recentStatement->execute($params);
        $recent = $recentStatement->fetchAll();
        $chartLabels = [];
        $chartValues = [];

        if ($role === 'admin') {
            $breakdown = $pdo->query('SELECT status, COUNT(*) AS total FROM tickets GROUP BY status ORDER BY status')->fetchAll();
            $chartLabels = array_column($breakdown, 'status');
            $chartValues = array_map('intval', array_column($breakdown, 'total'));
            page_heading('Support operations', 'Administration overview', 'Monitor complaints across the college, overdue cases, and team activity.');
            ?>
            <div class="row g-3 mb-4">
              <?php stat_card('All complaints', (int) $summary['total'], 'ph-ticket', 'tone-blue'); ?>
              <?php stat_card('Active cases', (int) $summary['active'], 'ph-arrows-clockwise', 'tone-amber'); ?>
              <?php stat_card('Resolved', (int) $summary['resolved'], 'ph-check-circle', 'tone-green'); ?>
              <?php stat_card('Overdue', (int) $summary['overdue'], 'ph-warning-circle', 'tone-red'); ?>
            </div>
            <div class="row g-4 mb-4"><div class="col-lg-7"><div class="card portal-panel"><div class="card-header"><div><h3>Complaint status overview</h3><p>Live distribution of cases by lifecycle status</p></div></div><div class="card-body"><canvas id="complaintChart" height="170"></canvas></div></div></div>
              <div class="col-lg-5"><div class="card portal-panel"><div class="card-header"><div><h3>Unassigned complaints</h3><p>Cases awaiting a department</p></div><a class="btn btn-primary btn-sm" href="<?= e(app_url('cases')) ?>">Review queue</a></div>
              <?php $unassigned = array_filter($recent, static fn(array $ticket): bool => $ticket['department_id'] === null); if (!$unassigned): ?><div class="portal-empty">No unassigned complaints in recent activity.</div><?php else: ticket_table(array_values($unassigned), true, true); endif; ?></div></div></div>
            <script src="<?= e(asset_url('js/plugins/chart.umd.min.js')) ?>"></script>
            <script>new Chart(document.getElementById('complaintChart'),{type:'bar',data:{labels:<?= e(json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>,datasets:[{label:'Complaints',data:<?= e(json_encode($chartValues)) ?>,backgroundColor:'#287047',borderRadius:7,maxBarThickness:44}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});</script>
            <?php
        } elseif ($role === 'department') {
            page_heading('Your team workspace', 'Department overview', 'Review and resolve complaints assigned to ' . ($user['department_name'] ?? 'your department') . '.');
            ?>
            <div class="row g-3 mb-4">
              <?php stat_card('Assigned cases', (int) $summary['total'], 'ph-ticket', 'tone-blue'); ?>
              <?php stat_card('Active cases', (int) $summary['active'], 'ph-arrows-clockwise', 'tone-amber'); ?>
              <?php stat_card('Resolved', (int) $summary['resolved'], 'ph-check-circle', 'tone-green'); ?>
              <?php stat_card('Overdue', (int) $summary['overdue'], 'ph-warning-circle', 'tone-red'); ?>
            </div>
            <div class="card portal-panel"><div class="card-header"><div><h3>Latest department complaints</h3><p>Cases routed only to your department</p></div><a class="btn btn-primary btn-sm" href="<?= e(app_url('department-cases')) ?>">Open case queue</a></div><?php ticket_table($recent, true); ?></div>
            <?php
        } else {
            $notifications = $pdo->prepare('SELECT id, title, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 3');
            $notifications->execute([$user['id']]);
            page_heading('Your support space', 'Hello, ' . $user['full_name'], 'Submit a complaint, follow progress, and get updates from college support teams.');
            ?>
            <div class="row g-3 mb-4">
              <?php stat_card('My complaints', (int) $summary['total'], 'ph-ticket', 'tone-blue'); ?>
              <?php stat_card('Active', (int) $summary['active'], 'ph-arrows-clockwise', 'tone-amber'); ?>
              <?php stat_card('Resolved', (int) $summary['resolved'], 'ph-check-circle', 'tone-green'); ?>
              <div class="col-md-6 col-xl-3"><a class="card portal-action-card" href="<?= e(app_url('new-ticket')) ?>"><div class="card-body"><span class="portal-stat-icon tone-green"><i class="ph ph-plus"></i></span><div><p>Need support?</p><strong>Submit a complaint <i class="ph ph-arrow-up-right"></i></strong></div></div></a></div>
            </div>
            <div class="card portal-panel mb-4"><div class="card-header"><div><h3>My recent complaints</h3><p>Review your latest case updates and statuses</p></div><a class="btn btn-light btn-sm" href="<?= e(app_url('my-tickets')) ?>">View all complaints</a></div><?php ticket_table($recent); ?></div>
            <?php if ($notifications->rowCount() > 0): ?><div class="card portal-panel"><div class="card-header"><div><h3>Recent notifications</h3><p>Updates about your complaints</p></div><a href="<?= e(app_url('notifications')) ?>">View all</a></div><div class="card-body"><?php foreach ($notifications->fetchAll() as $notification): ?><div class="portal-notification"><strong><?= e($notification['title']) ?></strong><p><?= e($notification['message']) ?></p><small><?= e(format_date($notification['created_at'])) ?></small></div><?php endforeach; ?></div></div><?php endif; ?>
            <?php
        }
        return true;
    }

    if ($page === 'new-ticket' && $role === 'student') {
        $categories = $pdo->query('SELECT id, name FROM complaint_categories WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
        $topics = $pdo->query('SELECT id, category_id, name FROM complaint_topics WHERE is_active = 1 ORDER BY name')->fetchAll();
        $priorities = $pdo->query('SELECT name FROM complaint_priorities WHERE is_active = 1 ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN);
        $uploadLimit = (int) $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_upload_mb'")->fetchColumn();
        page_heading('Student support', 'Submit a complaint', 'Tell us what happened and which outcome you are requesting. Required fields are marked.');
        ?>
        <div class="row g-4"><div class="col-lg-8"><div class="card portal-panel"><div class="card-body p-4">
          <form method="post" action="<?= e(app_url('new-ticket')) ?>" enctype="multipart/form-data">
            <?= csrf_input() ?><input type="hidden" name="action" value="create-ticket">
            <div class="row g-3">
              <div class="col-md-6"><label class="form-label" for="category_id">Complaint category <span class="text-danger">*</span></label><select class="form-select" id="category_id" name="category_id" required><option value="">Select category</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
              <div class="col-md-6"><label class="form-label" for="topic_id">Specific topic <span class="text-danger">*</span></label><select class="form-select" id="topic_id" name="topic_id" required disabled><option value="">Select a category first</option><?php foreach ($topics as $topic): ?><option value="<?= (int) $topic['id'] ?>" data-category="<?= (int) $topic['category_id'] ?>"><?= e($topic['name']) ?></option><?php endforeach; ?></select></div>
              <div class="col-md-8"><label class="form-label" for="subject">Complaint title <span class="text-danger">*</span></label><input class="form-control" id="subject" name="subject" maxlength="180" required placeholder="Summarize your complaint"></div>
              <div class="col-md-4"><label class="form-label" for="priority">Priority <span class="text-danger">*</span></label><select class="form-select" id="priority" name="priority" required><?php foreach ($priorities as $priority): ?><option value="<?= e($priority) ?>" <?= $priority === 'Normal' ? 'selected' : '' ?>><?= e($priority) ?></option><?php endforeach; ?></select><div class="form-text">The college may adjust priority after review.</div></div>
              <div class="col-12"><label class="form-label" for="description">Describe the problem <span class="text-danger">*</span></label><textarea class="form-control" id="description" name="description" rows="6" minlength="20" maxlength="10000" required placeholder="Include relevant dates and details. Do not include passwords."></textarea></div>
              <div class="col-12"><label class="form-label" for="desired_resolution">Desired resolution or request</label><textarea class="form-control" id="desired_resolution" name="desired_resolution" rows="3" maxlength="5000" placeholder="How would you like the college to address this concern?"></textarea></div>
              <div class="col-12"><label class="form-label" for="attachment">Supporting document (optional)</label><input class="form-control" id="attachment" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"><div class="form-text">One PDF, JPEG, or PNG file, up to <?= max(1, min(30, $uploadLimit ?: 5)) ?> MB.</div></div>
              <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="confirmed_accurate" name="confirmed_accurate" value="1" required><label class="form-check-label" for="confirmed_accurate">I confirm that the information provided is accurate to the best of my knowledge.</label></div></div>
              <div class="col-12 d-flex justify-content-between flex-wrap gap-2"><a class="btn btn-light" href="<?= e(app_url('home')) ?>">Cancel</a><button class="btn btn-primary" type="submit"><i class="ph ph-paper-plane-tilt me-1"></i>Submit complaint</button></div>
            </div>
          </form>
        </div></div></div>
        <div class="col-lg-4"><div class="card portal-panel"><div class="card-body"><h3 class="h5">Before you submit</h3><ul class="portal-help-list"><li>Include enough detail for staff to investigate.</li><li>Choose the closest category and specific topic.</li><li>Never share passwords or private login codes.</li><li>Keep the reference number shown after submission.</li></ul></div></div></div></div>
        <script>
        const categorySelect=document.getElementById('category_id'),topicSelect=document.getElementById('topic_id');
        categorySelect.addEventListener('change',async()=>{topicSelect.replaceChildren(new Option('Loading topics…',''));topicSelect.disabled=true;if(!categorySelect.value){topicSelect.replaceChildren(new Option('Select a category first',''));return;}try{const response=await fetch('<?= e(app_url('topics')) ?>&category_id='+encodeURIComponent(categorySelect.value),{headers:{Accept:'application/json'}});if(!response.ok)throw new Error('Unable to load topics');const topics=await response.json();topicSelect.replaceChildren(new Option(topics.length?'Select a topic':'No topics available',''));for(const topic of topics)topicSelect.add(new Option(topic.name,topic.id));topicSelect.disabled=topics.length===0;}catch(error){topicSelect.replaceChildren(new Option('Topics unavailable — please retry',''));topicSelect.disabled=true;}});
        </script>
        <?php
        return true;
    }

    if ($page === 'view-ticket') {
        $ticketId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        $ticketQuery = $pdo->prepare(
            'SELECT t.*, u.full_name AS student_name, u.email AS student_email, u.matric_number,
                    sd.name AS student_department, d.name AS department_name,
                    c.name AS category_name, tp.name AS topic_name
             FROM tickets t JOIN users u ON u.id = t.student_id
             LEFT JOIN departments sd ON sd.id = u.department_id
             LEFT JOIN departments d ON d.id = t.department_id
             LEFT JOIN complaint_categories c ON c.id = t.category_id
             LEFT JOIN complaint_topics tp ON tp.id = t.topic_id
             WHERE t.id = ?'
        );
        $ticketQuery->execute([$ticketId]);
        $ticket = $ticketQuery->fetch();
        $canView = $ticket && (
            $role === 'admin'
            || ($role === 'student' && (int) $ticket['student_id'] === (int) $user['id'])
            || ($role === 'department' && (int) $ticket['department_id'] === (int) $user['department_id'])
        );
        if (!$canView) {
            http_response_code(404);
            echo '<div class="alert alert-warning">That complaint could not be found in your support workspace.</div>';
            return true;
        }
        page_heading('Complaint details', $ticket['subject'], 'Reference ' . $ticket['reference_number']);
        $messagesQuery = $pdo->prepare(
            'SELECT m.*, u.full_name AS author_name, u.role AS author_role
             FROM ticket_messages m LEFT JOIN users u ON u.id = m.author_id
             WHERE m.ticket_id = ? '
                . ($role === 'student'
                    ? "AND m.visibility = 'public'"
                    : ($role === 'department' ? "AND (m.visibility = 'public' OR u.department_id = ?)" : ''))
                . '
             ORDER BY m.created_at'
        );
        $messagesQuery->execute($role === 'department' ? [$ticketId, $user['department_id']] : [$ticketId]);
        $messages = $messagesQuery->fetchAll();
        $filesQuery = $pdo->prepare(
            'SELECT f.*, u.full_name AS uploader_name
             FROM ticket_files f JOIN users u ON u.id = f.uploader_id
             WHERE f.ticket_id = ? '
                . ($role === 'student'
                    ? "AND f.visibility = 'public'"
                    : ($role === 'department' ? "AND (f.visibility = 'public' OR u.department_id = ?)" : ''))
                . '
             ORDER BY f.created_at'
        );
        $filesQuery->execute($role === 'department' ? [$ticketId, $user['department_id']] : [$ticketId]);
        $files = $filesQuery->fetchAll();
        ?>
        <div class="row g-4"><div class="col-lg-7">
          <div class="card portal-panel mb-4"><div class="card-header"><div><h3>Complaint summary</h3><p>Submitted <?= e(format_date($ticket['created_at'])) ?></p></div><span class="portal-status <?= e(status_class($ticket['status'])) ?>"><?= e($ticket['status']) ?></span></div>
            <div class="card-body"><div class="portal-detail-pair"><span>Category and topic</span><strong><?= e($ticket['category_name'] ?? $ticket['category']) ?> · <?= e($ticket['topic_name'] ?? 'General concern') ?></strong></div><div class="portal-detail-pair"><span>Priority</span><strong><?= e($ticket['priority']) ?></strong></div><div class="portal-detail-pair"><span>Assigned department</span><strong><?= e($ticket['department_name'] ?? 'Awaiting assignment') ?></strong></div><?php if ($role !== 'student'): ?><div class="portal-detail-pair"><span>Student</span><strong><?= e($ticket['student_name']) ?> · <?= e($ticket['matric_number'] ?? '') ?></strong></div><?php endif; ?><div class="portal-description"><?= nl2br(e($ticket['description'])) ?></div><?php if ($ticket['desired_resolution']): ?><div class="portal-response"><span>Requested resolution</span><p><?= nl2br(e($ticket['desired_resolution'])) ?></p></div><?php endif; ?></div>
          </div>
          <div class="card portal-panel"><div class="card-header"><div><h3>Complaint history</h3><p>Replies, status changes, and case notes</p></div></div><div class="card-body">
            <?php if (!$messages): ?><p class="portal-empty">No case activity has been recorded yet.</p><?php else: foreach ($messages as $message): ?>
              <article class="portal-timeline-item <?= $message['visibility'] === 'internal' ? 'portal-internal-note' : '' ?>"><div class="portal-timeline-top"><strong><?= e($message['author_name'] ?? 'System') ?></strong><span><?= e(format_date($message['created_at'])) ?></span></div><small><?= e(ucfirst(str_replace('_', ' ', $message['event_type']))) ?><?= $message['visibility'] === 'internal' ? ' · Internal note' : '' ?></small><p><?= nl2br(e($message['message'])) ?></p><?php if ($message['old_value'] !== null && $message['new_value'] !== null): ?><small><?= e($message['old_value']) ?> &rarr; <?= e($message['new_value']) ?></small><?php endif; ?></article>
            <?php endforeach; endif; ?>
            <?php if ($files): ?><h4 class="h6 mt-4">Supporting documents</h4><?php foreach ($files as $file): ?><a class="portal-file-link" href="<?= e(app_url('download', ['file' => $file['id']])) ?>"><i class="ph ph-paperclip"></i><?= e($file['original_name']) ?> <small>(<?= e(number_format($file['file_size'] / 1024, 0)) ?> KB)</small></a><?php endforeach; ?><?php endif; ?>
          </div></div>
        </div><div class="col-lg-5">
          <?php if ($role === 'admin'): $departments = $pdo->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll(); $priorities = $pdo->query('SELECT name FROM complaint_priorities WHERE is_active = 1 ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN); ?>
            <div class="card portal-panel mb-4"><div class="card-header"><div><h3>Route complaint</h3><p>Assign or reassign a department and response priority</p></div></div><div class="card-body"><form method="post" action="<?= e(app_url('view-ticket', ['id' => $ticketId])) ?>"><?= csrf_input() ?><input type="hidden" name="action" value="route-ticket"><input type="hidden" name="ticket_id" value="<?= (int) $ticketId ?>"><label class="form-label" for="department_id">Department</label><select class="form-select mb-3" id="department_id" name="department_id" required><option value="">Select department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= (int) $ticket['department_id'] === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select><label class="form-label" for="priority">Priority</label><select class="form-select mb-3" id="priority" name="priority"><?php foreach ($priorities as $priority): ?><option value="<?= e($priority) ?>" <?= $ticket['priority'] === $priority ? 'selected' : '' ?>><?= e($priority) ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">Save assignment</button></form></div></div>
          <?php endif; ?>
          <?php if (in_array($role, ['admin', 'department'], true)): $statuses = $pdo->query('SELECT name FROM complaint_statuses WHERE is_active = 1 ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN); ?>
            <div class="card portal-panel mb-4"><div class="card-header"><div><h3>Update status</h3><p>Each status change is added to case history</p></div></div><div class="card-body"><form method="post" action="<?= e(app_url('view-ticket', ['id' => $ticketId])) ?>"><?= csrf_input() ?><input type="hidden" name="action" value="update-case"><input type="hidden" name="ticket_id" value="<?= (int) $ticketId ?>"><label class="form-label" for="status">Status</label><select class="form-select mb-3" id="status" name="status"><?php foreach ($statuses as $status): if ($role === 'department' && !in_array($status, ['Acknowledged', 'In Progress', 'Awaiting Student', 'Resolved', 'Closed'], true)) continue; ?><option value="<?= e($status) ?>" <?= $ticket['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select><label class="form-label" for="department_response">Optional response to student</label><textarea class="form-control mb-3" id="department_response" name="department_response" rows="3" maxlength="10000" placeholder="Explain the progress or outcome"></textarea><button class="btn btn-primary" type="submit">Save status</button></form></div></div>
          <?php endif; ?>
          <div class="card portal-panel"><div class="card-header"><div><h3>Conversation</h3><p><?= $role === 'student' ? 'Your response is shared with the assigned team.' : 'Send an update or add an internal note.' ?></p></div></div><div class="card-body"><form method="post" action="<?= e(app_url('view-ticket', ['id' => $ticketId])) ?>" enctype="multipart/form-data"><?= csrf_input() ?><input type="hidden" name="action" value="ticket-reply"><input type="hidden" name="ticket_id" value="<?= (int) $ticketId ?>"><label class="form-label" for="message">Message</label><textarea class="form-control mb-3" id="message" name="message" rows="4" maxlength="10000" required></textarea><?php if ($role !== 'student'): ?><label class="form-label" for="visibility">Visibility</label><select class="form-select mb-3" id="visibility" name="visibility"><option value="public">Visible to student</option><option value="internal">Internal department note</option></select><?php endif; ?><label class="form-label" for="reply_attachment">Optional attachment</label><input class="form-control mb-3" id="reply_attachment" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"><button class="btn btn-primary" type="submit">Add to history</button></form></div></div>
        </div></div>
        <?php
        return true;
    }

    if ($page === 'my-tickets' && $role === 'student') {
        $statement = $pdo->prepare('SELECT t.*, d.name AS department_name, COALESCE(c.name, t.category) AS category FROM tickets t LEFT JOIN departments d ON d.id = t.department_id LEFT JOIN complaint_categories c ON c.id = t.category_id WHERE t.student_id = ? ORDER BY t.created_at DESC');
        $statement->execute([$user['id']]);
        page_heading('Your requests', 'My complaints', 'View every complaint you have submitted and open its conversation history.');
        ?><div class="card portal-panel"><?php ticket_table($statement->fetchAll()); ?></div><?php
        return true;
    }

    if ($page === 'department-cases' && $role === 'department') {
        $status = trim((string) ($_GET['status'] ?? ''));
        $statement = $pdo->prepare(
            'SELECT t.*, u.full_name AS student_name, u.matric_number, d.name AS department_name
             FROM tickets t JOIN users u ON u.id = t.student_id LEFT JOIN departments d ON d.id = t.department_id
             WHERE t.department_id = ? AND (? = \'\' OR t.status = ?)
             ORDER BY t.due_at IS NULL, t.due_at, t.created_at DESC'
        );
        $statement->execute([$user['department_id'], $status, $status]);
        page_heading('Department queue', 'Assigned complaints', 'Cases assigned to your department, ordered by response deadline.');
        ?>
        <div class="card portal-panel"><div class="card-body border-bottom"><form method="get" class="d-flex gap-2 align-items-center"><input type="hidden" name="page" value="department-cases"><label class="form-label mb-0" for="status_filter">Status</label><select class="form-select portal-filter" id="status_filter" name="status"><option value="">All statuses</option><?php foreach ($pdo->query('SELECT name FROM complaint_statuses WHERE is_active = 1 ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN) as $statusOption): ?><option <?= $status === $statusOption ? 'selected' : '' ?>><?= e($statusOption) ?></option><?php endforeach; ?></select><button class="btn btn-light" type="submit">Filter</button><a class="btn btn-light" href="<?= e(app_url('reports')) ?>">Reports</a></form></div><?php ticket_table($statement->fetchAll(), true); ?></div>
        <?php
        return true;
    }

    if ($page === 'cases' && $role === 'admin') {
        $search = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $departmentId = filter_var($_GET['department_id'] ?? null, FILTER_VALIDATE_INT);
        $unassignedOnly = ($_GET['unassigned'] ?? '') === '1';
        $query = 'SELECT t.*, u.full_name AS student_name, u.matric_number, d.name AS department_name
                  FROM tickets t JOIN users u ON u.id = t.student_id LEFT JOIN departments d ON d.id = t.department_id
                  WHERE (? = \'\' OR t.status = ?)
                    AND (? IS NULL OR t.department_id = ?)
                    AND (? = 0 OR t.department_id IS NULL)
                    AND (? = \'\' OR t.reference_number LIKE ? OR t.subject LIKE ? OR u.full_name LIKE ? OR u.matric_number LIKE ?)
                  ORDER BY (t.department_id IS NULL) DESC, t.due_at IS NULL, t.due_at, t.created_at DESC';
        $statement = $pdo->prepare($query);
        $like = '%' . $search . '%';
        $statement->execute([$status, $status, $departmentId, $departmentId, $unassignedOnly ? 1 : 0, $search, $like, $like, $like, $like]);
        page_heading('Support operations', 'All complaints', 'Search cases, filter by department or lifecycle status, and open a complaint to route it.');
        ?>
        <div class="card portal-panel"><div class="card-body border-bottom"><form method="get" class="row g-2 align-items-end"><input type="hidden" name="page" value="cases"><div class="col-md-4"><label class="form-label" for="q">Search cases</label><input class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="Reference, title, name or matric"></div><div class="col-md-3"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option><?php foreach ($pdo->query('SELECT name FROM complaint_statuses WHERE is_active = 1 ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN) as $statusOption): ?><option value="<?= e($statusOption) ?>" <?= $status === $statusOption ? 'selected' : '' ?>><?= e($statusOption) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label" for="department_id">Department</label><select class="form-select" id="department_id" name="department_id"><option value="">All departments</option><?php foreach ($pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll() as $department): ?><option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2 d-flex gap-2"><button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-light" href="<?= e(app_url('cases')) ?>">Clear</a></div><div class="col-12 d-flex justify-content-between align-items-center mt-3"><label class="form-check"><input class="form-check-input" name="unassigned" value="1" type="checkbox" <?= $unassignedOnly ? 'checked' : '' ?>><span class="form-check-label">Show only unassigned complaints</span></label><a class="btn btn-light btn-sm" href="<?= e(app_url('export-csv')) ?>"><i class="ph ph-download-simple me-1"></i>Export CSV</a></div></form></div><?php ticket_table($statement->fetchAll(), true, true); ?></div>
        <?php
        return true;
    }

    if ($page === 'staff' && $role === 'admin') {
        $departments = $pdo->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll();
        $staff = $pdo->query("SELECT u.id, u.full_name, u.email, u.is_active, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.role = 'department' ORDER BY d.name, u.full_name")->fetchAll();
        page_heading('Access management', 'Department officers', 'Create and manage authorized officers. Each officer is scoped to one department.');
        ?>
        <div class="row g-4"><div class="col-lg-5"><div class="card portal-panel"><div class="card-header"><div><h3>Add department officer</h3><p>Officers can see only their department’s complaints</p></div></div><div class="card-body"><form method="post" action="<?= e(app_url('staff')) ?>"><?= csrf_input() ?><input type="hidden" name="action" value="create-department-user"><div class="mb-3"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" maxlength="150" required></div><div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" id="email" name="email" type="email" maxlength="190" required></div><div class="mb-3"><label class="form-label" for="department_id">Department</label><select class="form-select" name="department_id" id="department_id" required><option value="">Select department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label" for="password">Temporary password</label><input class="form-control" id="password" name="password" type="password" minlength="10" required><div class="form-text">Use at least 10 characters.</div></div><button class="btn btn-primary" type="submit">Create officer account</button></form></div></div></div>
        <div class="col-lg-7"><div class="card portal-panel"><div class="card-header"><div><h3>Department officers</h3><p>Update officer assignments and access</p></div></div><div class="table-responsive"><table class="table portal-table"><thead><tr><th>Officer</th><th>Department</th><th>Access</th></tr></thead><tbody><?php foreach ($staff as $member): ?><tr><td colspan="2"><form method="post" class="d-flex flex-wrap gap-2 align-items-center"><?= csrf_input() ?><input type="hidden" name="action" value="update-officer"><input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>"><input class="form-control portal-inline-input" name="full_name" value="<?= e($member['full_name']) ?>" maxlength="150" required><input class="form-control portal-inline-input" name="email" type="email" value="<?= e($member['email']) ?>" maxlength="190" required><select class="form-select portal-filter" name="department_id" required><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= $member['department_name'] === $department['name'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select><button class="btn btn-light btn-sm" type="submit">Save officer</button></form></td><td><form method="post" action="<?= e(app_url('users')) ?>"><?= csrf_input() ?><input type="hidden" name="action" value="toggle-user"><input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>"><button class="btn btn-sm <?= $member['is_active'] ? 'btn-light' : 'btn-outline-success' ?>" type="submit"><?= $member['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php endforeach; if (!$staff): ?><tr><td colspan="3" class="portal-empty">No department officers have been added.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
        <?php
        return true;
    }

    if ($page === 'departments' && $role === 'admin') {
        $departments = $pdo->query('SELECT d.*, COUNT(DISTINCT u.id) AS officers, COUNT(DISTINCT t.id) AS cases FROM departments d LEFT JOIN users u ON u.department_id = d.id AND u.role = \'department\' LEFT JOIN tickets t ON t.department_id = d.id GROUP BY d.id ORDER BY d.name')->fetchAll();
        page_heading('System management', 'Manage departments', 'Maintain the departments students can select and administrators can route cases to.');
        ?>
        <div class="card portal-panel mb-4"><div class="card-header"><div><h3>Add department</h3></div></div><div class="card-body"><form class="d-flex flex-wrap gap-2" method="post"><?= csrf_input() ?><input type="hidden" name="action" value="save-department"><input class="form-control portal-inline-input" name="name" maxlength="120" placeholder="New department name" required><button class="btn btn-primary" type="submit">Add department</button></form></div></div>
        <div class="card portal-panel"><div class="table-responsive"><table class="table portal-table align-middle"><thead><tr><th>Department</th><th>Officers</th><th>Cases</th><th>Registration/routing</th><th></th></tr></thead><tbody><?php foreach ($departments as $department): ?><tr><td><form method="post" class="d-flex gap-2"><?= csrf_input() ?><input type="hidden" name="action" value="save-department"><input type="hidden" name="department_id" value="<?= (int) $department['id'] ?>"><input class="form-control portal-inline-input" name="name" maxlength="120" value="<?= e($department['name']) ?>" required><button class="btn btn-light btn-sm" type="submit">Save</button></form></td><td><?= (int) $department['officers'] ?></td><td><?= (int) $department['cases'] ?></td><td><span class="portal-status <?= $department['is_active'] ? 'status-resolved' : 'status-closed' ?>"><?= $department['is_active'] ? 'Active' : 'Inactive' ?></span></td><td><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="toggle-department"><input type="hidden" name="department_id" value="<?= (int) $department['id'] ?>"><button class="btn btn-light btn-sm" type="submit"><?= $department['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div></div>
        <?php
        return true;
    }

    if ($page === 'categories' && $role === 'admin') {
        $categories = $pdo->query('SELECT c.*, GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR \'\n\') AS topics FROM complaint_categories c LEFT JOIN complaint_topics t ON t.category_id = c.id AND t.is_active = 1 GROUP BY c.id ORDER BY c.sort_order, c.name')->fetchAll();
        page_heading('System management', 'Complaint categories and topics', 'Edit the categories and topic choices students see when submitting a complaint.');
        ?>
        <div class="row g-4"><div class="col-lg-5"><div class="card portal-panel"><div class="card-header"><div><h3>Add category</h3><p>Add one topic per line</p></div></div><div class="card-body"><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="save-category"><div class="mb-3"><label class="form-label" for="new_category">Category name</label><input class="form-control" id="new_category" name="name" maxlength="120" required></div><div class="mb-3"><label class="form-label" for="new_topics">Topics</label><textarea class="form-control" id="new_topics" name="topics" rows="5" required></textarea></div><button class="btn btn-primary" type="submit">Add category</button></form></div></div></div><div class="col-lg-7"><?php foreach ($categories as $category): ?><div class="card portal-panel mb-3"><div class="card-body"><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="save-category"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><label class="form-label">Category</label><input class="form-control mb-2" name="name" maxlength="120" value="<?= e($category['name']) ?>" required><label class="form-label">Topics (one per line)</label><textarea class="form-control mb-2" name="topics" rows="4"><?= e($category['topics'] ?? '') ?></textarea><div class="d-flex justify-content-between"><button class="btn btn-primary btn-sm" type="submit">Save changes</button><span class="portal-status <?= $category['is_active'] ? 'status-resolved' : 'status-closed' ?>"><?= $category['is_active'] ? 'Active' : 'Inactive' ?></span></div></form><form method="post" class="mt-2"><?= csrf_input() ?><input type="hidden" name="action" value="toggle-category"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><button class="btn btn-light btn-sm" type="submit"><?= $category['is_active'] ? 'Deactivate category' : 'Activate category' ?></button></form></div></div><?php endforeach; ?></div></div>
        <?php
        return true;
    }

    if ($page === 'users' && $role === 'admin') {
        $search = trim((string) ($_GET['q'] ?? ''));
        $like = '%' . $search . '%';
        $statement = $pdo->prepare(
            'SELECT u.id, u.full_name, u.email, u.matric_number, u.role, u.is_active, d.name AS department_name
             FROM users u LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.role <> \'admin\' AND (? = \'\' OR u.full_name LIKE ? OR u.email LIKE ? OR u.matric_number LIKE ?)
             ORDER BY u.role, u.full_name LIMIT 250'
        );
        $statement->execute([$search, $like, $like, $like]);
        page_heading('System management', 'Student and officer accounts', 'Search accounts, review their department, and suspend access when needed.');
        ?>
        <div class="card portal-panel"><div class="card-body border-bottom"><form method="get" class="d-flex gap-2"><input type="hidden" name="page" value="users"><input class="form-control portal-inline-input" name="q" value="<?= e($search) ?>" placeholder="Name, email or matric number"><button class="btn btn-primary" type="submit">Search</button></form></div><div class="table-responsive"><table class="table portal-table"><thead><tr><th>Student account</th><th>Department</th><th>Access</th><th></th></tr></thead><tbody><?php $userRows = $statement->fetchAll(); $activeDepartments = $pdo->query('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name')->fetchAll(); foreach ($userRows as $account): ?><tr><td colspan="2"><form method="post" class="d-flex flex-wrap gap-2 align-items-center"><?= csrf_input() ?><input type="hidden" name="action" value="update-student"><input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>"><input class="form-control portal-inline-input" name="full_name" value="<?= e($account['full_name']) ?>" maxlength="150" required><input class="form-control portal-inline-input" name="email" type="email" value="<?= e($account['email']) ?>" maxlength="190" required><select class="form-select portal-filter" name="department_id" required><?php foreach ($activeDepartments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= $account['department_name'] === $department['name'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select><button class="btn btn-light btn-sm" type="submit">Save details</button><small class="d-block text-muted"><?= e($account['matric_number'] ?? '') ?></small></form></td><td><span class="portal-status <?= $account['is_active'] ? 'status-resolved' : 'status-closed' ?>"><?= $account['is_active'] ? 'Active' : 'Disabled' ?></span></td><td><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="toggle-user"><input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>"><button class="btn btn-light btn-sm" type="submit"><?= $account['is_active'] ? 'Disable' : 'Enable' ?></button></form></td></tr><?php endforeach; if (!$userRows): ?><tr><td colspan="4" class="portal-empty">No student or department accounts match.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php
        return true;
    }

    if ($page === 'reports' && in_array($role, ['admin', 'department'], true)) {
        $reportParams = $role === 'department' ? [$user['department_id']] : [];
        $where = $role === 'department' ? 'WHERE t.department_id = ?' : '';
        $statusReport = $pdo->prepare("SELECT t.status, COUNT(*) AS total FROM tickets t {$where} GROUP BY t.status ORDER BY t.status");
        $statusReport->execute($reportParams);
        $byStatus = $statusReport->fetchAll();
        $departmentReport = $pdo->prepare(
            "SELECT d.name, COUNT(t.id) AS total,
                    SUM(t.status = 'Resolved') AS resolved,
                    ROUND(AVG(CASE WHEN t.status IN ('Resolved', 'Closed') THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) END), 1) AS avg_hours
             FROM departments d LEFT JOIN tickets t ON t.department_id = d.id
             " . ($role === 'department' ? 'WHERE d.id = ?' : '') . '
             GROUP BY d.id ORDER BY total DESC, d.name'
        );
        $departmentReport->execute($role === 'department' ? [$user['department_id']] : []);
        page_heading('Performance insights', 'Complaint reports', 'Review case volumes, resolution counts, and response performance.');
        ?>
        <div class="row g-4"><div class="col-lg-6"><div class="card portal-panel"><div class="card-header"><div><h3>Cases by status</h3><p>Current complaint lifecycle distribution</p></div></div><div class="card-body"><?php if ($byStatus): ?><canvas id="reportChart" height="230"></canvas><?php else: ?><div class="portal-empty">No complaints have been recorded.</div><?php endif; ?></div></div></div><div class="col-lg-6"><div class="card portal-panel"><div class="card-header"><div><h3>Department performance</h3><p>Volume and average completion time</p></div></div><div class="table-responsive"><table class="table portal-table"><thead><tr><th>Department</th><th>Cases</th><th>Resolved</th><th>Avg hours</th></tr></thead><tbody><?php foreach ($departmentReport->fetchAll() as $row): ?><tr><td><?= e($row['name']) ?></td><td><?= (int) $row['total'] ?></td><td><?= (int) $row['resolved'] ?></td><td><?= e($row['avg_hours'] ?? '—') ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></div>
        <?php if ($byStatus): ?><script src="<?= e(asset_url('js/plugins/chart.umd.min.js')) ?>"></script><script>new Chart(document.getElementById('reportChart'),{type:'doughnut',data:{labels:<?= e(json_encode(array_column($byStatus, 'status'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>,datasets:[{data:<?= e(json_encode(array_map('intval', array_column($byStatus, 'total')))) ?>,backgroundColor:['#267348','#c79536','#427caa','#9672be','#4f9d79','#8793a1']}]},options:{responsive:true,plugins:{legend:{position:'bottom'}}}});</script><?php endif; ?>
        <?php
        return true;
    }

    if ($page === 'notifications') {
        $statement = $pdo->prepare('SELECT n.*, t.reference_number FROM notifications n LEFT JOIN tickets t ON t.id = n.ticket_id WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT 100');
        $statement->execute([$user['id']]);
        page_heading('Stay informed', 'Notifications', 'Case assignments, replies, and status changes relevant to your account.');
        ?>
        <div class="card portal-panel"><div class="card-header"><div><h3>Your notifications</h3><p>Only you can see these messages</p></div><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="mark-all-notifications-read"><button class="btn btn-light btn-sm" type="submit">Mark all as read</button></form></div><div class="card-body"><?php $items = $statement->fetchAll(); if (!$items): ?><p class="portal-empty">You have no notifications.</p><?php else: foreach ($items as $item): ?><article class="portal-notification <?= $item['read_at'] ? '' : 'portal-notification-unread' ?>"><div class="d-flex justify-content-between gap-3"><div><strong><?= e($item['title']) ?></strong><p><?= e($item['message']) ?></p><small><?= e(format_date($item['created_at'])) ?></small><?php if ($item['reference_number']): ?><a class="d-block mt-1" href="<?= e(app_url('view-ticket', ['id' => $item['ticket_id']])) ?>">Open <?= e($item['reference_number']) ?></a><?php endif; ?></div><?php if (!$item['read_at']): ?><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="mark-notification-read"><input type="hidden" name="notification_id" value="<?= (int) $item['id'] ?>"><button class="btn btn-light btn-sm" type="submit">Mark read</button></form><?php endif; ?></div></article><?php endforeach; endif; ?></div></div>
        <?php
        return true;
    }

    if ($page === 'profile') {
        $statement = $pdo->prepare('SELECT full_name, email, phone, matric_number, role, department_id FROM users WHERE id = ?');
        $statement->execute([$user['id']]);
        $profile = $statement->fetch();
        page_heading('Account settings', 'My profile', 'Keep your contact details current and change your password when needed.');
        ?>
        <div class="row g-4"><div class="col-lg-7"><div class="card portal-panel"><div class="card-header"><div><h3>Personal details</h3><p>Your registered account information</p></div></div><div class="card-body"><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="save-profile"><div class="mb-3"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" value="<?= e($profile['full_name']) ?>" maxlength="150" required></div><div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" id="email" name="email" type="email" value="<?= e($profile['email']) ?>" maxlength="190" required></div><div class="mb-3"><label class="form-label" for="phone">Phone number</label><input class="form-control" id="phone" name="phone" value="<?= e($profile['phone'] ?? '') ?>" maxlength="30"></div><?php if ($role === 'student'): ?><div class="portal-detail-pair"><span>Matric number</span><strong><?= e($profile['matric_number']) ?></strong></div><?php endif; ?><?php if ($role === 'department'): ?><div class="portal-detail-pair"><span>Department</span><strong><?= e($user['department_name'] ?? '') ?></strong></div><?php endif; ?><button class="btn btn-primary mt-3" type="submit">Save profile</button></form></div></div></div><div class="col-lg-5"><div class="card portal-panel"><div class="card-header"><div><h3>Security</h3><p>Update your account password</p></div></div><div class="card-body"><form method="post" action="<?= e(app_url('change-password')) ?>"><?= csrf_input() ?><input type="hidden" name="action" value="change-password"><div class="mb-3"><label class="form-label" for="current_password">Current password</label><input class="form-control" id="current_password" name="current_password" type="password" autocomplete="current-password" required></div><div class="mb-3"><label class="form-label" for="new_password">New password</label><input class="form-control" id="new_password" name="new_password" type="password" minlength="10" autocomplete="new-password" required></div><div class="mb-3"><label class="form-label" for="confirm_password">Confirm new password</label><input class="form-control" id="confirm_password" name="confirm_password" type="password" minlength="10" autocomplete="new-password" required></div><button class="btn btn-primary" type="submit">Change password</button></form></div></div></div></div>
        <?php
        return true;
    }

    if ($page === 'settings' && $role === 'admin') {
        $settings = $pdo->query('SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key')->fetchAll();
        page_heading('System management', 'System settings', 'Configure local portal details and default complaint handling policies.');
        ?>
        <div class="row g-3"><?php foreach ($settings as $setting): ?><div class="col-md-6"><div class="card portal-panel"><div class="card-header"><h3><?= e(ucwords(str_replace('_', ' ', $setting['setting_key']))) ?></h3></div><div class="card-body"><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="save-setting"><input type="hidden" name="setting_key" value="<?= e($setting['setting_key']) ?>"><label class="form-label" for="setting_<?= e($setting['setting_key']) ?>">Value</label><input class="form-control mb-3" id="setting_<?= e($setting['setting_key']) ?>" name="setting_value" value="<?= e($setting['setting_value']) ?>" maxlength="255" required><button class="btn btn-primary btn-sm" type="submit">Save setting</button></form></div></div></div><?php endforeach; ?></div>
        <?php
        return true;
    }

    if ($page === 'workflow' && $role === 'admin') {
        $statuses = $pdo->query('SELECT * FROM complaint_statuses ORDER BY sort_order, name')->fetchAll();
        $priorities = $pdo->query('SELECT * FROM complaint_priorities ORDER BY sort_order, name')->fetchAll();
        page_heading('System management', 'Statuses and priorities', 'Manage the complaint lifecycle options and response-time targets used by the portal.');
        ?>
        <div class="row g-4"><div class="col-lg-6"><div class="card portal-panel mb-4"><div class="card-header"><div><h3>Add lifecycle status</h3><p>Statuses are recorded in complaint history</p></div></div><div class="card-body"><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="save-workflow"><input type="hidden" name="kind" value="status"><label class="form-label" for="new_status">Status name</label><input class="form-control mb-3" id="new_status" name="name" maxlength="32" required><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_final" value="1" id="is_final"><label class="form-check-label" for="is_final">This is a final status</label></div><button class="btn btn-primary" type="submit">Add status</button></form></div></div><div class="card portal-panel"><div class="card-header"><h3>Complaint statuses</h3></div><div class="table-responsive"><table class="table portal-table"><thead><tr><th>Status</th><th>Final</th><th>Active</th><th></th></tr></thead><tbody><?php foreach ($statuses as $status): ?><tr><td><?= e($status['name']) ?></td><td><?= $status['is_final'] ? 'Yes' : 'No' ?></td><td><?= $status['is_active'] ? 'Active' : 'Inactive' ?></td><td><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="toggle-workflow"><input type="hidden" name="kind" value="status"><input type="hidden" name="option_id" value="<?= (int) $status['id'] ?>"><button class="btn btn-light btn-sm" type="submit"><?= $status['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div>
        <div class="col-lg-6"><div class="card portal-panel mb-4"><div class="card-header"><div><h3>Add response priority</h3><p>Response targets also calculate case due dates</p></div></div><div class="card-body"><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="save-workflow"><input type="hidden" name="kind" value="priority"><label class="form-label" for="new_priority">Priority name</label><input class="form-control mb-3" id="new_priority" name="name" maxlength="20" required><label class="form-label" for="response_hours">Response target (hours)</label><input class="form-control mb-3" id="response_hours" name="response_hours" type="number" min="1" max="720" value="72" required><button class="btn btn-primary" type="submit">Add priority</button></form></div></div><div class="card portal-panel"><div class="card-header"><h3>Response priorities</h3></div><div class="table-responsive"><table class="table portal-table"><thead><tr><th>Priority</th><th>Target</th><th>Active</th><th></th></tr></thead><tbody><?php foreach ($priorities as $priority): ?><tr><td><?= e($priority['name']) ?></td><td><?= (int) $priority['response_hours'] ?> hours</td><td><?= $priority['is_active'] ? 'Active' : 'Inactive' ?></td><td><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="toggle-workflow"><input type="hidden" name="kind" value="priority"><input type="hidden" name="option_id" value="<?= (int) $priority['id'] ?>"><button class="btn btn-light btn-sm" type="submit"><?= $priority['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div></div>
        <?php
        return true;
    }

    if ($page === 'audit' && $role === 'admin') {
        $logs = $pdo->query('SELECT a.*, u.full_name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.created_at DESC LIMIT 250')->fetchAll();
        page_heading('Governance and oversight', 'Audit log', 'Review a recent record of administrative and complaint-management actions.');
        ?>
        <div class="card portal-panel"><div class="table-responsive"><table class="table portal-table"><thead><tr><th>Date</th><th>Actor</th><th>Action</th><th>Record</th><th>Details</th></tr></thead><tbody><?php foreach ($logs as $log): ?><tr><td><?= e(format_date($log['created_at'])) ?></td><td><?= e($log['actor_name'] ?? 'System') ?></td><td><?= e(ucwords(str_replace('_', ' ', $log['action']))) ?></td><td><?= e($log['entity_type']) ?><?= $log['entity_id'] ? ' #' . (int) $log['entity_id'] : '' ?></td><td><?= e($log['details'] ?? '—') ?></td></tr><?php endforeach; if (!$logs): ?><tr><td colspan="5" class="portal-empty">No audit entries have been recorded.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php
        return true;
    }

    return false;
}
