# FCWM Student Complaint and Support Portal

Core PHP 8.1+, PDO, MySQL/MariaDB, Bootstrap 5, and the dashboard assets already present in `dist/assets`. No Composer, Node.js, or framework is required.

## Run locally with XAMPP

1. Place the project folder under `C:\xampp\htdocs`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/datta-able-bootstrap-dashboard/` (adjust the folder name if you renamed the project).
4. On first run, the application creates or upgrades the `fcwm_complaints` schema, seeds departments, complaint categories/topics, statuses, and priorities, then prompts for the first administrator account.
5. Sign in as the administrator, create department-officer accounts, and route incoming complaints from **All complaints**.

The default XAMPP connection is `127.0.0.1:3306`, user `root`, with an empty password. Configure `FCWM_DB_HOST`, `FCWM_DB_PORT`, `FCWM_DB_NAME`, `FCWM_DB_USER`, and `FCWM_DB_PASSWORD` to override it. The MySQL account needs permission to create the database and tables on first launch. The schema setup is additive so existing portal data is retained.

## Portal workspaces

- **Students** can register with their name, email, matric number, and database-backed department; submit categorized complaints with a topic, desired resolution, confirmation, and optional evidence; track status; review conversation history; manage their profile/password; and view notifications. Public reference tracking also requires the student's matric number to prevent reference-number enumeration.
- **Department officers** see only cases assigned to their own department. They can acknowledge, investigate, request more information, resolve cases, post student-visible replies, and add private internal notes.
- **Administrators** review and search all cases, filter unassigned work, route/reassign departments, set priorities and deadlines, manage departments and accounts, edit complaint categories/topics and workflow statuses, review reports/audit history, export CSV, and update portal settings.

Complaint references use `FCWM-YYYY-000123` format, derived from the newly inserted database ticket sequence within a transaction and protected by a unique index. Case assignments, status changes, student replies, officer replies, and internal notes are recorded in the complaint history. Response targets determine case due dates; overdue cases are highlighted on dashboards.

## Supporting documents and privacy

Attachments are limited to PDF, JPEG, or PNG and the configured upload-size limit. The default private directory is `C:\xampp\private\fcwm-uploads`, outside the public webroot; set `FCWM_UPLOAD_DIR` to use another private location. Ensure the Apache/PHP service account can create and write to that directory. Downloads go through an authenticated handler with student ownership, officer department-scope, and internal-note visibility checks.

## Security and deployment

Passwords are hashed with PHP's password API. PDO prepared statements, session regeneration, role and case-scope checks, CSRF tokens, MIME-checked private uploads, output escaping, download authorization, and CSV formula-injection protection are used. Apache access to `config/` and `includes/` is denied.

For deployment beyond a trusted local workstation, configure HTTPS, a least-privilege database account, backups, monitored private upload storage, account recovery/retention policies, and institution-approved privacy and records practices. Use a current PHP/MySQL release and review Apache's `AllowOverride` settings so the included access-control files take effect.
