<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'setup-admin') {
        if ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0) {
            redirect('login');
        }
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if ($fullName === '' || mb_strlen($fullName) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || strlen($password) < 10) {
            flash('danger', 'Enter a name, a valid email address, and a password of at least 10 characters.');
            redirect('setup');
        }
        $statement = $pdo->prepare("INSERT INTO users (full_name, email, role, password_hash) VALUES (?, ?, 'admin', ?)");
        $statement->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT)]);
        flash('success', 'Administrator account created. You can now sign in.');
        redirect('login');
    }

    if ($action === 'register') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $matric = strtoupper(trim((string) ($_POST['matric_number'] ?? '')));
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
        $password = (string) ($_POST['password'] ?? '');
        $departmentQuery = $pdo->prepare('SELECT id FROM departments WHERE id = ?');
        $departmentQuery->execute([$departmentId]);
        $confirmation = (string) ($_POST['confirm_password'] ?? '');
        if ($fullName === '' || mb_strlen($fullName) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || $matric === '' || mb_strlen($matric) > 50 || !$departmentQuery->fetch() || strlen($password) < 8 || $password !== $confirmation) {
            flash('danger', 'Complete all fields, choose a department, and use a password with at least 8 characters.');
            redirect('register');
        }
        $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = ? OR matric_number = ? LIMIT 1');
        $duplicate->execute([$email, $matric]);
        if ($duplicate->fetch()) {
            flash('danger', 'That email address or matric number is already registered.');
            redirect('register');
        }
        $statement = $pdo->prepare("INSERT INTO users (full_name, email, matric_number, department_id, role, password_hash) VALUES (?, ?, ?, ?, 'student', ?)");
        $statement->execute([$fullName, $email, $matric, $departmentId, password_hash($password, PASSWORD_DEFAULT)]);
        flash('success', 'Your student account has been created. Please sign in.');
        redirect('login');
    }

    if ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $statement = $pdo->prepare(
            'SELECT u.id, u.full_name, u.email, u.matric_number, u.department_id, u.role, u.password_hash, u.is_active, d.name AS department_name
             FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.email = ? LIMIT 1'
        );
        $statement->execute([$email]);
        $user = $statement->fetch();
        if (!$user || !(int) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
            flash('danger', 'The email or password was not recognised.');
            redirect('login');
        }
        session_regenerate_id(true);
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        redirect('home');
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        redirect('login');
    }

    if ($action === 'create-ticket') {
        require_role(['student']);
        $category = trim((string) ($_POST['category'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $categories = ['Academic', 'Facilities and maintenance', 'Finance and fees', 'ICT and online services', 'Student welfare', 'Request', 'Other'];
        if (!in_array($category, $categories, true) || $subject === '' || mb_strlen($subject) > 180 || mb_strlen($description) < 15 || mb_strlen($description) > 10000) {
            flash('danger', 'Choose a topic, enter a subject, and describe your issue in at least 15 characters.');
            redirect('new-ticket');
        }
        $reference = 'FCWM-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(12)));
        $statement = $pdo->prepare('INSERT INTO tickets (reference_number, student_id, category, subject, description) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$reference, current_user()['id'], $category, $subject, $description]);
        redirect('ticket-created', ['ref' => $reference]);
    }

    if ($action === 'route-ticket') {
        require_role(['admin']);
        $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
        $check = $pdo->prepare('SELECT id FROM departments WHERE id = ?');
        $check->execute([$departmentId]);
        if (!$ticketId || !$check->fetch()) {
            flash('danger', 'Select a valid department for this case.');
            redirect('view-ticket', ['id' => $ticketId ?: 0]);
        }
        $statement = $pdo->prepare("UPDATE tickets SET department_id = ?, status = IF(status = 'Open', 'Assigned', status) WHERE id = ?");
        $statement->execute([$departmentId, $ticketId]);
        flash('success', 'The case has been routed to the selected department.');
        redirect('view-ticket', ['id' => $ticketId]);
    }

    if ($action === 'update-case') {
        require_role(['admin', 'department']);
        $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        $response = trim((string) ($_POST['department_response'] ?? ''));
        $allowedStatuses = ['Assigned', 'In Progress', 'Resolved', 'Closed'];
        if (!$ticketId || !in_array($status, $allowedStatuses, true) || mb_strlen($response) > 10000) {
            flash('danger', 'Choose a valid status and keep the response under 10,000 characters.');
            redirect('view-ticket', ['id' => $ticketId ?: 0]);
        }
        if (current_user()['role'] === 'department') {
            $statement = $pdo->prepare('UPDATE tickets SET status = ?, department_response = ? WHERE id = ? AND department_id = ?');
            $statement->execute([$status, $response !== '' ? $response : null, $ticketId, current_user()['department_id']]);
        } else {
            $statement = $pdo->prepare('UPDATE tickets SET status = ?, department_response = ? WHERE id = ?');
            $statement->execute([$status, $response !== '' ? $response : null, $ticketId]);
        }
        if ($statement->rowCount() === 0) {
            $check = $pdo->prepare('SELECT id FROM tickets WHERE id = ?' . (current_user()['role'] === 'department' ? ' AND department_id = ?' : ''));
            $params = current_user()['role'] === 'department' ? [$ticketId, current_user()['department_id']] : [$ticketId];
            $check->execute($params);
            if (!$check->fetch()) {
                http_response_code(404);
                exit('That case does not exist or is not assigned to your department.');
            }
        }
        flash('success', 'Case status and response have been updated.');
        redirect('view-ticket', ['id' => $ticketId]);
    }

    if ($action === 'create-department-user') {
        require_role(['admin']);
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
        $password = (string) ($_POST['password'] ?? '');
        $departmentQuery = $pdo->prepare('SELECT id FROM departments WHERE id = ?');
        $departmentQuery->execute([$departmentId]);
        if ($fullName === '' || mb_strlen($fullName) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || !$departmentQuery->fetch() || strlen($password) < 8) {
            flash('danger', 'Enter a name, valid email, department, and password of at least 8 characters.');
            redirect('staff');
        }
        $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $duplicate->execute([$email]);
        if ($duplicate->fetch()) {
            flash('danger', 'An account already uses that email address.');
            redirect('staff');
        }
        $statement = $pdo->prepare("INSERT INTO users (full_name, email, department_id, role, password_hash) VALUES (?, ?, ?, 'department', ?)");
        $statement->execute([$fullName, $email, $departmentId, password_hash($password, PASSWORD_DEFAULT)]);
        flash('success', 'Department account created.');
        redirect('staff');
    }
}
