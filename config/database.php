<?php
declare(strict_types=1);

$databaseHost = getenv('FCWM_DB_HOST') ?: '127.0.0.1';
$databasePort = getenv('FCWM_DB_PORT') ?: '3306';
$databaseName = getenv('FCWM_DB_NAME') ?: 'fcwm_complaints';
$databaseUser = getenv('FCWM_DB_USER') ?: 'root';
$databasePassword = getenv('FCWM_DB_PASSWORD') ?: '';

if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $databaseName)) {
    throw new InvalidArgumentException('FCWM_DB_NAME may contain only letters, numbers, and underscores.');
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$dsn = "mysql:host={$databaseHost};port={$databasePort};dbname={$databaseName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $databaseUser, $databasePassword, $options);
} catch (PDOException $exception) {
    if ((int) ($exception->errorInfo[1] ?? 0) !== 1049) {
        throw $exception;
    }

    $server = new PDO(
        "mysql:host={$databaseHost};port={$databasePort};charset=utf8mb4",
        $databaseUser,
        $databasePassword,
        $options
    );
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO($dsn, $databaseUser, $databasePassword, $options);
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS departments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL UNIQUE,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(150) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        matric_number VARCHAR(50) NULL UNIQUE,
        department_id INT UNSIGNED NULL,
        role ENUM('student', 'department', 'admin') NOT NULL DEFAULT 'student',
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS tickets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reference_number VARCHAR(40) NOT NULL UNIQUE,
        student_id INT UNSIGNED NOT NULL,
        department_id INT UNSIGNED NULL,
        category VARCHAR(80) NOT NULL,
        subject VARCHAR(180) NOT NULL,
        description TEXT NOT NULL,
        status ENUM('Open', 'Assigned', 'In Progress', 'Resolved', 'Closed') NOT NULL DEFAULT 'Open',
        department_response TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_tickets_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_tickets_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
        INDEX idx_tickets_student (student_id),
        INDEX idx_tickets_department_status (department_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$ensureColumn = static function (string $table, string $column, string $definition) use ($pdo): void {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $statement->execute([$table, $column]);
    if ((int) $statement->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
};

$ensureColumn('users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
$ensureColumn('users', 'phone', 'VARCHAR(30) NULL');
$ensureColumn('departments', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
$ensureColumn('tickets', 'category_id', 'INT UNSIGNED NULL');
$ensureColumn('tickets', 'topic_id', 'INT UNSIGNED NULL');
$ensureColumn('tickets', 'priority', "VARCHAR(20) NOT NULL DEFAULT 'Normal'");
$ensureColumn('tickets', 'desired_resolution', 'TEXT NULL');
$ensureColumn('tickets', 'confirmed_accurate', 'TINYINT(1) NOT NULL DEFAULT 1');
$ensureColumn('tickets', 'assigned_by', 'INT UNSIGNED NULL');
$ensureColumn('tickets', 'due_at', 'DATETIME NULL');
$ensureColumn('tickets', 'closed_at', 'DATETIME NULL');
$statusType = $pdo->query(
    "SELECT DATA_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'status'"
)->fetchColumn();
if ($statusType === 'enum') {
    $pdo->exec("ALTER TABLE tickets MODIFY status VARCHAR(32) NOT NULL DEFAULT 'Open'");
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS complaint_categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS complaint_topics (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category_id INT UNSIGNED NOT NULL,
        name VARCHAR(150) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_topics_category_name (category_id, name),
        CONSTRAINT fk_topics_category FOREIGN KEY (category_id) REFERENCES complaint_categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS complaint_statuses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(32) NOT NULL UNIQUE,
        is_final TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS complaint_priorities (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(20) NOT NULL UNIQUE,
        response_hours INT UNSIGNED NOT NULL DEFAULT 72,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS ticket_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ticket_id BIGINT UNSIGNED NOT NULL,
        author_id INT UNSIGNED NULL,
        message TEXT NOT NULL,
        visibility ENUM('public', 'internal') NOT NULL DEFAULT 'public',
        event_type VARCHAR(32) NOT NULL DEFAULT 'reply',
        old_value VARCHAR(255) NULL,
        new_value VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
        CONSTRAINT fk_messages_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_messages_ticket_date (ticket_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS ticket_files (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ticket_id BIGINT UNSIGNED NOT NULL,
        message_id BIGINT UNSIGNED NULL,
        uploader_id INT UNSIGNED NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(80) NOT NULL UNIQUE,
        mime_type VARCHAR(100) NOT NULL,
        file_size INT UNSIGNED NOT NULL,
        visibility ENUM('public', 'internal') NOT NULL DEFAULT 'public',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_files_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
        CONSTRAINT fk_files_message FOREIGN KEY (message_id) REFERENCES ticket_messages(id) ON DELETE SET NULL,
        CONSTRAINT fk_files_uploader FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        ticket_id BIGINT UNSIGNED NULL,
        title VARCHAR(180) NOT NULL,
        message VARCHAR(500) NOT NULL,
        read_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_notifications_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
        INDEX idx_notifications_user_read (user_id, read_at, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor_id INT UNSIGNED NULL,
        action VARCHAR(80) NOT NULL,
        entity_type VARCHAR(50) NOT NULL,
        entity_id BIGINT UNSIGNED NULL,
        details TEXT NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_audit_created (created_at),
        INDEX idx_audit_actor (actor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$categories = [
    'Academic Issues' => ['Missing examination result', 'Incorrect course registration', 'Course or timetable concern'],
    'Examination and Results' => ['Examination result query', 'Examination timetable issue', 'Exam registration problem'],
    'Registration and Matriculation' => ['Registration error', 'Matriculation number issue', 'Request for official documentation'],
    'Fees and Payments' => ['Payment confirmation problem', 'Fee statement query', 'Other payment issue'],
    'Hostel and Accommodation' => ['Hostel allocation issue', 'Room maintenance issue', 'Other accommodation concern'],
    'Library Services' => ['Library access issue', 'Book or resource request', 'Other library concern'],
    'ICT and Portal Problems' => ['Student portal access problem', 'Password reset request', 'Other ICT issue'],
    'Departmental Issues' => ['Academic advising concern', 'Department communication issue', 'Other departmental concern'],
    'Student Welfare' => ['Welfare support request', 'Accessibility support request', 'Other welfare concern'],
    'Facilities and Maintenance' => ['Complaint about a facility', 'Water or electricity issue', 'Classroom or equipment repair'],
    'Harassment or Misconduct' => ['Harassment concern', 'Staff or student conduct concern', 'Other confidential concern'],
    'General Requests' => ['Request for official documentation', 'Information request', 'Other general request'],
    'Other' => ['Other academic or administrative concern'],
];
$insertCategory = $pdo->prepare('INSERT IGNORE INTO complaint_categories (name, sort_order) VALUES (?, ?)');
$insertTopic = $pdo->prepare('INSERT IGNORE INTO complaint_topics (category_id, name) VALUES (?, ?)');
$categoryIndex = 0;
foreach ($categories as $categoryOrder => $topics) {
    $categoryName = $categoryOrder;
    $insertCategory->execute([$categoryName, ++$categoryIndex]);
    $categoryIdQuery = $pdo->prepare('SELECT id FROM complaint_categories WHERE name = ?');
    $categoryIdQuery->execute([$categoryName]);
    $categoryId = (int) $categoryIdQuery->fetchColumn();
    foreach ($topics as $topic) {
        $insertTopic->execute([$categoryId, $topic]);
    }
}

$insertStatus = $pdo->prepare('INSERT IGNORE INTO complaint_statuses (name, is_final, sort_order) VALUES (?, ?, ?)');
foreach ([
    ['Open', 0],
    ['Acknowledged', 0],
    ['In Progress', 0],
    ['Awaiting Student', 0],
    ['Resolved', 1],
    ['Closed', 1],
] as $order => [$statusName, $isFinal]) {
    $insertStatus->execute([$statusName, $isFinal, $order]);
}
if ((int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'Assigned'")->fetchColumn() > 0) {
    $pdo->exec("UPDATE tickets SET status = 'Acknowledged' WHERE status = 'Assigned'");
}
$insertPriority = $pdo->prepare('INSERT IGNORE INTO complaint_priorities (name, response_hours, sort_order) VALUES (?, ?, ?)');
foreach ([['Low', 120], ['Normal', 72], ['High', 24], ['Urgent', 8]] as $order => [$priorityName, $responseHours]) {
    $insertPriority->execute([$priorityName, $responseHours, $order]);
}
$pdo->exec(
    "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
     ('institution_name', 'Federal College of Wildlife Management'),
     ('institution_location', 'New Bussa, Niger State, Nigeria'),
     ('max_upload_mb', '5'),
     ('overdue_reminder_days', '3')"
);

$departmentCount = (int) $pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();
if ($departmentCount === 0) {
    $insertDepartment = $pdo->prepare('INSERT INTO departments (name) VALUES (?)');
    foreach ([
        'Wildlife Management',
        'Fisheries Technology',
        'Forestry Technology',
        'Ecotourism and Wildlife Management',
        'General Studies',
        'Student Affairs',
        'Registry',
        'Bursary',
        'ICT',
        'Works and Maintenance',
        'Library',
    ] as $departmentName) {
        $insertDepartment->execute([$departmentName]);
    }
}
