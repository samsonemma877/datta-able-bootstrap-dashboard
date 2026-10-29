<?php
declare(strict_types=1);

$extendedActions = [
    'create-ticket',
    'ticket-reply',
    'update-case',
    'route-ticket',
    'save-profile',
    'change-password',
    'mark-notification-read',
    'mark-all-notifications-read',
    'save-department',
    'toggle-department',
    'toggle-user',
    'update-officer',
    'update-student',
    'save-category',
    'toggle-category',
    'save-setting',
    'save-workflow',
    'toggle-workflow',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', $extendedActions, true)) {
    verify_csrf();
    $action = (string) $_POST['action'];

    if ($action === 'create-ticket') {
        require_role(['student']);
        $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
        $topicId = filter_var($_POST['topic_id'] ?? null, FILTER_VALIDATE_INT);
        $priority = trim((string) ($_POST['priority'] ?? 'Normal'));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $desiredResolution = trim((string) ($_POST['desired_resolution'] ?? ''));
        $confirmed = ($_POST['confirmed_accurate'] ?? '') === '1';

        $topicQuery = $pdo->prepare(
            'SELECT c.id, c.name AS category_name, t.name AS topic_name
             FROM complaint_topics t
             JOIN complaint_categories c ON c.id = t.category_id
             WHERE t.id = ? AND c.id = ? AND t.is_active = 1 AND c.is_active = 1'
        );
        $topicQuery->execute([$topicId, $categoryId]);
        $topic = $topicQuery->fetch();
        $priorityQuery = $pdo->prepare('SELECT name, response_hours FROM complaint_priorities WHERE name = ? AND is_active = 1');
        $priorityQuery->execute([$priority]);
        $selectedPriority = $priorityQuery->fetch();

        if (
            !$topic
            || !$selectedPriority
            || $subject === ''
            || mb_strlen($subject) > 180
            || mb_strlen($description) < 20
            || mb_strlen($description) > 10000
            || mb_strlen($desiredResolution) > 5000
            || !$confirmed
        ) {
            flash('danger', 'Choose a category and topic, describe the issue in at least 20 characters, confirm your statement, and check the other fields.');
            redirect('new-ticket');
        }

        $pdo->beginTransaction();
        $ticketId = null;
        $storedUpload = null;
        try {
            $placeholder = 'FCWM-' . date('Y') . '-PENDING-' . strtoupper(bin2hex(random_bytes(8)));
            $dueAt = (new DateTimeImmutable())
                ->modify('+' . (int) $selectedPriority['response_hours'] . ' hours')
                ->format('Y-m-d H:i:s');
            $statement = $pdo->prepare(
                "INSERT INTO tickets
                 (reference_number, student_id, category, category_id, topic_id, priority, subject, description, desired_resolution, confirmed_accurate, status, due_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'Open', ?)"
            );
            $statement->execute([
                $placeholder,
                current_user()['id'],
                $topic['category_name'],
                $categoryId,
                $topicId,
                $priority,
                $subject,
                $description,
                $desiredResolution !== '' ? $desiredResolution : null,
                $dueAt,
            ]);
            $ticketId = (int) $pdo->lastInsertId();
            $reference = sprintf('FCWM-%s-%06d', date('Y'), $ticketId);
            $updateReference = $pdo->prepare('UPDATE tickets SET reference_number = ? WHERE id = ?');
            $updateReference->execute([$reference, $ticketId]);

            $messageId = record_ticket_event(
                $ticketId,
                (int) current_user()['id'],
                'Complaint submitted. Status: Open.',
                'public',
                'created',
                null,
                'Open'
            );
            if (isset($_FILES['attachment'])) {
                $storedUpload = store_ticket_upload($_FILES['attachment'], $ticketId, (int) current_user()['id'], $messageId);
            }
            audit_log('complaint_created', 'ticket', $ticketId, ['reference' => $reference]);
            $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $adminId) {
                notify_user((int) $adminId, 'New student complaint', $reference . ' is ready for review.', $ticketId);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($storedUpload !== null) {
                $path = upload_directory() . DIRECTORY_SEPARATOR . basename($storedUpload);
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if ($exception instanceof RuntimeException) {
                flash('danger', $exception->getMessage());
                redirect('new-ticket');
            }
            throw $exception;
        }

        redirect('ticket-created', ['ref' => $reference]);
    }

    if ($action === 'ticket-reply') {
        require_role(['student', 'admin', 'department']);
        $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $message = trim((string) ($_POST['message'] ?? ''));
        $visibility = current_user()['role'] === 'admin' || current_user()['role'] === 'department'
            ? (($_POST['visibility'] ?? 'public') === 'internal' ? 'internal' : 'public')
            : 'public';
        $ticketQuery = $pdo->prepare('SELECT id, reference_number, student_id, department_id, status FROM tickets WHERE id = ?');
        $ticketQuery->execute([$ticketId]);
        $ticket = $ticketQuery->fetch();
        $hasUpload = isset($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (
            !$ticket
            || (current_user()['role'] === 'student' && (int) $ticket['student_id'] !== (int) current_user()['id'])
            || (current_user()['role'] === 'department' && (int) $ticket['department_id'] !== (int) current_user()['department_id'])
        ) {
            http_response_code(404);
            exit('That complaint is unavailable in your workspace.');
        }
        if ($message === '' || mb_strlen($message) > 10000) {
            flash('danger', 'Write a reply of no more than 10,000 characters.');
            redirect('view-ticket', ['id' => $ticketId]);
        }
        $pdo->beginTransaction();
        try {
            $messageId = record_ticket_event(
                (int) $ticketId,
                (int) current_user()['id'],
                $message,
                $visibility,
                current_user()['role'] === 'student' ? 'student_reply' : ($visibility === 'internal' ? 'internal_note' : 'reply')
            );
            $storedUpload = null;
            if ($hasUpload) {
                $storedUpload = store_ticket_upload($_FILES['attachment'], (int) $ticketId, (int) current_user()['id'], $messageId, $visibility);
            }
            if ($visibility === 'public') {
                if (current_user()['role'] === 'student') {
                    $officers = $pdo->prepare("SELECT id FROM users WHERE role = 'department' AND department_id = ? AND is_active = 1");
                    if ($ticket['department_id']) {
                        $officers->execute([$ticket['department_id']]);
                        foreach ($officers->fetchAll(PDO::FETCH_COLUMN) as $officerId) {
                            notify_user((int) $officerId, 'Student replied', $ticket['reference_number'] . ' has a new student reply.', (int) $ticketId);
                        }
                    }
                } else {
                    notify_ticket_student((int) $ticketId, 'Complaint update', $ticket['reference_number'] . ' has a new response.');
                }
            }
            audit_log($visibility === 'internal' ? 'internal_note_added' : 'complaint_reply', 'ticket', (int) $ticketId, ['visibility' => $visibility]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (isset($storedUpload) && $storedUpload !== null) {
                $path = upload_directory() . DIRECTORY_SEPARATOR . basename($storedUpload);
                if (is_file($path)) {
                    unlink($path);
                }
            }
            throw $exception;
        }
        flash('success', $visibility === 'internal' ? 'Internal note added.' : 'Your reply has been added.');
        redirect('view-ticket', ['id' => $ticketId]);
    }

    if ($action === 'update-case') {
        require_role(['admin', 'department']);
        $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $status = trim((string) ($_POST['status'] ?? ''));
        $response = trim((string) ($_POST['department_response'] ?? ''));
        $validStatus = $pdo->prepare('SELECT name FROM complaint_statuses WHERE name = ? AND is_active = 1');
        $validStatus->execute([$status]);
        $ticketQuery = $pdo->prepare('SELECT id, reference_number, student_id, department_id, status FROM tickets WHERE id = ?');
        $ticketQuery->execute([$ticketId]);
        $ticket = $ticketQuery->fetch();
        if (
            !$ticket
            || !$validStatus->fetch()
            || mb_strlen($response) > 10000
            || (current_user()['role'] === 'department' && (int) $ticket['department_id'] !== (int) current_user()['department_id'])
        ) {
            http_response_code(404);
            exit('The case is unavailable or the selected status is not valid.');
        }
        if (current_user()['role'] === 'department' && !in_array($status, ['Acknowledged', 'In Progress', 'Awaiting Student', 'Resolved', 'Closed'], true)) {
            flash('danger', 'Choose an allowed case status.');
            redirect('view-ticket', ['id' => $ticketId]);
        }
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                "UPDATE tickets SET status = ?, closed_at = IF(? IN ('Resolved', 'Closed'), NOW(), NULL)
                 WHERE id = ?"
            );
            $statement->execute([$status, $status, $ticketId]);
            if ($ticket['status'] !== $status) {
                record_ticket_event(
                    (int) $ticketId,
                    (int) current_user()['id'],
                    'Status changed from ' . $ticket['status'] . ' to ' . $status . '.',
                    'public',
                    'status',
                    $ticket['status'],
                    $status
                );
            }
            if ($response !== '') {
                record_ticket_event((int) $ticketId, (int) current_user()['id'], $response, 'public', 'reply');
                notify_ticket_student((int) $ticketId, 'Complaint update', $ticket['reference_number'] . ' has a new response.');
            }
            if ($ticket['status'] !== $status) {
                notify_ticket_student((int) $ticketId, 'Complaint status updated', $ticket['reference_number'] . ' is now ' . $status . '.');
            }
            audit_log('complaint_status_changed', 'ticket', (int) $ticketId, ['from' => $ticket['status'], 'to' => $status]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        flash('success', 'Case status has been updated and recorded in the complaint history.');
        redirect('view-ticket', ['id' => $ticketId]);
    }

    if ($action === 'route-ticket') {
        require_role(['admin']);
        $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
        $priority = trim((string) ($_POST['priority'] ?? 'Normal'));
        $departmentQuery = $pdo->prepare('SELECT id, name FROM departments WHERE id = ? AND is_active = 1');
        $departmentQuery->execute([$departmentId]);
        $department = $departmentQuery->fetch();
        $priorityQuery = $pdo->prepare('SELECT name, response_hours FROM complaint_priorities WHERE name = ? AND is_active = 1');
        $priorityQuery->execute([$priority]);
        $priorityRow = $priorityQuery->fetch();
        $ticketQuery = $pdo->prepare('SELECT id, reference_number, department_id, status FROM tickets WHERE id = ?');
        $ticketQuery->execute([$ticketId]);
        $ticket = $ticketQuery->fetch();
        if (!$ticketId || !$department || !$priorityRow || !$ticket) {
            flash('danger', 'Select a valid department and priority for this case.');
            redirect('cases');
        }
        $pdo->beginTransaction();
        try {
            $oldDepartmentQuery = $pdo->prepare('SELECT name FROM departments WHERE id = ?');
            $oldDepartmentQuery->execute([$ticket['department_id']]);
            $oldDepartment = $oldDepartmentQuery->fetchColumn() ?: 'Unassigned';
            $dueAt = (new DateTimeImmutable())->modify('+' . (int) $priorityRow['response_hours'] . ' hours')->format('Y-m-d H:i:s');
            $newStatus = in_array($ticket['status'], ['Open', 'Assigned'], true) ? 'Acknowledged' : $ticket['status'];
            $statement = $pdo->prepare('UPDATE tickets SET department_id = ?, assigned_by = ?, priority = ?, due_at = ?, status = ? WHERE id = ?');
            $statement->execute([$departmentId, current_user()['id'], $priority, $dueAt, $newStatus, $ticketId]);
            record_ticket_event(
                (int) $ticketId,
                (int) current_user()['id'],
                'Case routed to ' . $department['name'] . '.',
                'public',
                'assignment',
                (string) $oldDepartment,
                $department['name']
            );
            if ($newStatus !== $ticket['status']) {
                record_ticket_event((int) $ticketId, (int) current_user()['id'], 'Status changed from ' . $ticket['status'] . ' to ' . $newStatus . '.', 'public', 'status', $ticket['status'], $newStatus);
            }
            $officers = $pdo->prepare("SELECT id FROM users WHERE role = 'department' AND department_id = ? AND is_active = 1");
            $officers->execute([$departmentId]);
            foreach ($officers->fetchAll(PDO::FETCH_COLUMN) as $officerId) {
                notify_user((int) $officerId, 'New case assigned', $ticket['reference_number'] . ' has been routed to ' . $department['name'] . '.', (int) $ticketId);
            }
            notify_ticket_student((int) $ticketId, 'Complaint assigned', $ticket['reference_number'] . ' has been routed to ' . $department['name'] . '.');
            audit_log('complaint_routed', 'ticket', (int) $ticketId, ['from' => $oldDepartment, 'to' => $department['name'], 'priority' => $priority]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        flash('success', 'Complaint routed to ' . $department['name'] . '.');
        redirect('view-ticket', ['id' => $ticketId]);
    }

    if ($action === 'save-profile') {
        require_role(['student', 'department', 'admin']);
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || mb_strlen($phone) > 30) {
            flash('danger', 'Enter a valid name, email address, and phone number.');
            redirect('profile');
        }
        $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
        $duplicate->execute([$email, current_user()['id']]);
        if ($duplicate->fetch()) {
            flash('danger', 'Another account already uses that email address.');
            redirect('profile');
        }
        $statement = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?');
        $statement->execute([$name, $email, $phone !== '' ? $phone : null, current_user()['id']]);
        $_SESSION['user']['full_name'] = $name;
        $_SESSION['user']['email'] = $email;
        audit_log('profile_updated', 'user', (int) current_user()['id']);
        flash('success', 'Profile updated.');
        redirect('profile');
    }

    if ($action === 'change-password') {
        require_role(['student', 'department', 'admin']);
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['confirm_password'] ?? '');
        $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $statement->execute([current_user()['id']]);
        $hash = (string) $statement->fetchColumn();
        if (!password_verify($currentPassword, $hash) || strlen($newPassword) < 10 || $newPassword !== $confirmation) {
            flash('danger', 'Check your current password, ensure both new passwords match, and use at least 10 characters.');
            redirect('change-password');
        }
        $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), current_user()['id']]);
        audit_log('password_changed', 'user', (int) current_user()['id']);
        flash('success', 'Password changed successfully.');
        redirect('profile');
    }

    if ($action === 'mark-notification-read') {
        require_role(['student', 'department', 'admin']);
        $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        $statement = $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?');
        $statement->execute([$notificationId, current_user()['id']]);
        redirect('notifications');
    }

    if ($action === 'mark-all-notifications-read') {
        require_role(['student', 'department', 'admin']);
        $statement = $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
        $statement->execute([current_user()['id']]);
        redirect('notifications');
    }

    if ($action === 'save-department') {
        require_role(['admin']);
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            flash('danger', 'Enter a department name of no more than 120 characters.');
            redirect('departments');
        }
        $isNew = !$departmentId;
        try {
            if ($departmentId) {
                $statement = $pdo->prepare('UPDATE departments SET name = ? WHERE id = ?');
                $statement->execute([$name, $departmentId]);
            } else {
                $statement = $pdo->prepare('INSERT INTO departments (name) VALUES (?)');
                $statement->execute([$name]);
                $departmentId = (int) $pdo->lastInsertId();
            }
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                flash('danger', 'A department with that name already exists.');
                redirect('departments');
            }
            throw $exception;
        }
        audit_log($isNew ? 'department_created' : 'department_saved', 'department', (int) $departmentId, ['name' => $name]);
        flash('success', 'Department saved.');
        redirect('departments');
    }

    if ($action === 'toggle-department') {
        require_role(['admin']);
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
        $statement = $pdo->prepare('UPDATE departments SET is_active = 1 - is_active WHERE id = ?');
        $statement->execute([$departmentId]);
        audit_log('department_toggled', 'department', $departmentId ?: null);
        redirect('departments');
    }

    if ($action === 'toggle-user') {
        require_role(['admin']);
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $statement = $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ? AND role <> 'admin'");
        $statement->execute([$userId]);
        audit_log('account_toggled', 'user', $userId ?: null);
        redirect('users');
    }

    if ($action === 'update-officer') {
        require_role(['admin']);
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
        $departmentQuery = $pdo->prepare('SELECT id FROM departments WHERE id = ? AND is_active = 1');
        $departmentQuery->execute([$departmentId]);
        $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
        $duplicate->execute([$email, $userId]);
        if ($name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || !$departmentQuery->fetch() || $duplicate->fetch()) {
            flash('danger', 'Check the officer name, unique email address, and active department.');
            redirect('staff');
        }
        $statement = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, department_id = ? WHERE id = ? AND role = 'department'");
        $statement->execute([$name, $email, $departmentId, $userId]);
        audit_log('officer_updated', 'user', $userId ?: null, ['department_id' => $departmentId]);
        flash('success', 'Department officer details updated.');
        redirect('staff');
    }

    if ($action === 'update-student') {
        require_role(['admin']);
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
        $departmentQuery = $pdo->prepare('SELECT id FROM departments WHERE id = ? AND is_active = 1');
        $departmentQuery->execute([$departmentId]);
        $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
        $duplicate->execute([$email, $userId]);
        if ($name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || !$departmentQuery->fetch() || $duplicate->fetch()) {
            flash('danger', 'Check the student name, unique email address, and active department.');
            redirect('users');
        }
        $statement = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, department_id = ? WHERE id = ? AND role = 'student'");
        $statement->execute([$name, $email, $departmentId, $userId]);
        audit_log('student_updated', 'user', $userId ?: null);
        flash('success', 'Student account updated.');
        redirect('users');
    }

    if ($action === 'save-category') {
        require_role(['admin']);
        $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            flash('danger', 'Enter a category name of no more than 120 characters.');
            redirect('categories');
        }
        $isNew = !$categoryId;
        try {
            if ($categoryId) {
                $statement = $pdo->prepare('UPDATE complaint_categories SET name = ? WHERE id = ?');
                $statement->execute([$name, $categoryId]);
            } else {
                $statement = $pdo->prepare('INSERT INTO complaint_categories (name) VALUES (?)');
                $statement->execute([$name]);
                $categoryId = (int) $pdo->lastInsertId();
            }
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                flash('danger', 'A complaint category with that name already exists.');
                redirect('categories');
            }
            throw $exception;
        }
        $topicNames = array_filter(array_map('trim', explode("\n", (string) ($_POST['topics'] ?? ''))));
        $pdo->prepare('UPDATE complaint_topics SET is_active = 0 WHERE category_id = ?')->execute([$categoryId]);
        $insertTopic = $pdo->prepare('INSERT INTO complaint_topics (category_id, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE is_active = 1');
        foreach (array_unique($topicNames) as $topicName) {
            if (mb_strlen($topicName) <= 150) {
                $insertTopic->execute([$categoryId, $topicName]);
            }
        }
        audit_log($isNew ? 'category_created' : 'category_saved', 'category', (int) $categoryId, ['name' => $name]);
        flash('success', 'Category and complaint topics saved.');
        redirect('categories');
    }

    if ($action === 'toggle-category') {
        require_role(['admin']);
        $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
        $statement = $pdo->prepare('UPDATE complaint_categories SET is_active = 1 - is_active WHERE id = ?');
        $statement->execute([$categoryId]);
        audit_log('category_toggled', 'category', $categoryId ?: null);
        redirect('categories');
    }

    if ($action === 'save-setting') {
        require_role(['admin']);
        $key = (string) ($_POST['setting_key'] ?? '');
        $value = trim((string) ($_POST['setting_value'] ?? ''));
        if (!in_array($key, ['institution_name', 'institution_location', 'max_upload_mb', 'overdue_reminder_days'], true)) {
            http_response_code(400);
            exit('Unknown system setting.');
        }
        if (in_array($key, ['max_upload_mb', 'overdue_reminder_days'], true) && (!ctype_digit($value) || (int) $value < 1 || (int) $value > 30)) {
            flash('danger', 'Enter a whole number between 1 and 30.');
            redirect('settings');
        }
        $statement = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $statement->execute([$key, $value]);
        audit_log('setting_updated', 'setting', null, ['key' => $key]);
        flash('success', 'Setting updated.');
        redirect('settings');
    }

    if ($action === 'save-workflow') {
        require_role(['admin']);
        $kind = (string) ($_POST['kind'] ?? '');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 32) {
            flash('danger', 'Enter a name of no more than 32 characters.');
            redirect('workflow');
        }
        try {
            if ($kind === 'status') {
                $isFinal = isset($_POST['is_final']) ? 1 : 0;
                $statement = $pdo->prepare(
                    'INSERT INTO complaint_statuses (name, is_final, sort_order) VALUES (?, ?, 99)
                     ON DUPLICATE KEY UPDATE is_final = VALUES(is_final), is_active = 1'
                );
                $statement->execute([$name, $isFinal]);
            } elseif ($kind === 'priority') {
                $hours = filter_var($_POST['response_hours'] ?? null, FILTER_VALIDATE_INT);
                if (!$hours || $hours < 1 || $hours > 720) {
                    flash('danger', 'Set a response target between 1 and 720 hours.');
                    redirect('workflow');
                }
                $statement = $pdo->prepare(
                    'INSERT INTO complaint_priorities (name, response_hours, sort_order) VALUES (?, ?, 99)
                     ON DUPLICATE KEY UPDATE response_hours = VALUES(response_hours), is_active = 1'
                );
                $statement->execute([$name, $hours]);
            } else {
                http_response_code(400);
                exit('Unknown workflow setting.');
            }
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                flash('danger', 'That workflow option already exists.');
                redirect('workflow');
            }
            throw $exception;
        }
        audit_log($kind . '_saved', 'workflow', null, ['name' => $name]);
        flash('success', ucfirst($kind) . ' saved.');
        redirect('workflow');
    }

    if ($action === 'toggle-workflow') {
        require_role(['admin']);
        $kind = (string) ($_POST['kind'] ?? '');
        $id = filter_var($_POST['option_id'] ?? null, FILTER_VALIDATE_INT);
        $table = match ($kind) {
            'status' => 'complaint_statuses',
            'priority' => 'complaint_priorities',
            default => null,
        };
        if ($table === null) {
            http_response_code(400);
            exit('Unknown workflow setting.');
        }
        $statement = $pdo->prepare("UPDATE {$table} SET is_active = 1 - is_active WHERE id = ?");
        $statement->execute([$id]);
        audit_log($kind . '_toggled', 'workflow', $id ?: null);
        redirect('workflow');
    }
}
