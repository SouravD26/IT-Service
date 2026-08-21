<?php
session_start();
include('../config/db.php');

// Enable authentication check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    header("Location: ../auth/login.php");
    exit();
}

// Maximum number of employees admin/suparadmin are allowed to add. Raise this value if a higher limit is ever needed.
define('MAX_EMPLOYEES_LIMIT', 10);

// Determine dashboard to return to based on 'from' parameter or user role
$from = isset($_GET['from']) ? htmlspecialchars($_GET['from']) : '';
if ($from === 'suparadmin') {
    $back_dashboard = 'dashboard.php';
} elseif ($from === 'admin') {
    $back_dashboard = 'admin_dashboard.php';
} elseif ($from === 'employee') {
    $back_dashboard = '../employee/dashboard.php';
} else {
    // Default based on role
    $back_dashboard = ($_SESSION['role'] === 'suparadmin') ? 'dashboard.php' : 'admin_dashboard.php';
}

$message = "";
$message_type = "";

// Add columns if they don't exist
$check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'employee_id'");
if ($check_col->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN employee_id VARCHAR(50)");
}
$check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'company'");
if ($check_col->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN company VARCHAR(100)");
}
$check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
if ($check_col->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN phone VARCHAR(20) UNIQUE");
}
// Add other columns safely
$columns_to_add = [
    'shift_time' => 'VARCHAR(50)',
    'location' => 'VARCHAR(100)',
    'date_of_joining' => 'DATE',
    'date_of_exit' => 'DATE',
    'status' => "ENUM('Working', 'Resign') DEFAULT 'Working'",
    'sex' => "ENUM('Male', 'Female', 'Other')",
    'week_off' => "ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')",
    'password_set' => 'BOOLEAN DEFAULT FALSE',
    'aadhar_number' => 'VARCHAR(20)',
    'pan_number' => 'VARCHAR(20)',
    'alternate_number' => 'VARCHAR(20)',
    'address' => 'TEXT',
    'bank_account_number' => 'VARCHAR(30)',
    'bank_ifsc_code' => 'VARCHAR(15)',
    'profile_photo' => 'LONGBLOB',
    'profile_photo_type' => 'VARCHAR(50)'
];

foreach ($columns_to_add as $col_name => $col_type) {
    $check_col = $conn->query("SHOW COLUMNS FROM users LIKE '$col_name'");
    if ($check_col->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN $col_name $col_type");
    }
}

// Save an uploaded profile photo to disk (for existing photo-display logic) and as a BLOB in the users table
function save_profile_photo($conn, $user_id) {
    if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
        return;
    }
    $photo = $_FILES['profile_photo'];
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($photo['type'], $allowed) || $photo['size'] > 50 * 1024) {
        return;
    }
    $photoData = file_get_contents($photo['tmp_name']);

    $null = null;
    $stmt = $conn->prepare("UPDATE users SET profile_photo = ?, profile_photo_type = ? WHERE id = ?");
    $stmt->bind_param("bsi", $null, $photo['type'], $user_id);
    $stmt->send_long_data(0, $photoData);
    $stmt->execute();
    $stmt->close();

    $uploadDir = '../uploads/employee_photos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    foreach (glob($uploadDir . $user_id . '.*') as $oldFile) {
        @unlink($oldFile);
    }
    $ext = pathinfo($photo['name'], PATHINFO_EXTENSION) ?: 'jpg';
    file_put_contents($uploadDir . $user_id . '.' . $ext, $photoData);
}

// Create departments table if it doesn't exist
$check_table = $conn->query("SHOW TABLES LIKE 'departments'");
if ($check_table->num_rows === 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS departments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100) UNIQUE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

// Create companies table if it doesn't exist
$check_table = $conn->query("SHOW TABLES LIKE 'companies'");
if ($check_table->num_rows === 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS companies (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100) UNIQUE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

// Create shifts table if it doesn't exist
$check_table = $conn->query("SHOW TABLES LIKE 'shifts'");
if ($check_table->num_rows === 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS shifts (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100) UNIQUE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

// Create locations table if it doesn't exist
$check_table = $conn->query("SHOW TABLES LIKE 'locations'");
if ($check_table->num_rows === 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS locations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100) UNIQUE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

// Create salary_structures table if not exists (full schema)
$check_table = $conn->query("SHOW TABLES LIKE 'salary_structures'");
if ($check_table->num_rows === 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS salary_structures (
        id                        INT PRIMARY KEY AUTO_INCREMENT,
        user_id                   INT NOT NULL,
        template                  VARCHAR(50)    DEFAULT 'Monthly',
        statutory_component       VARCHAR(100),
        effective_cycle           VARCHAR(20),
        salary_ctc                DECIMAL(12,2)  DEFAULT 0,
        basic_monthly             DECIMAL(12,2)  DEFAULT 0,
        special_allowance_monthly DECIMAL(12,2)  DEFAULT 0,
        pf_monthly                DECIMAL(12,2)  DEFAULT 0,
        esi_monthly               DECIMAL(12,2)  DEFAULT 0,
        pf_calc                   VARCHAR(100),
        esi_calc                  VARCHAR(100),
        custom_components         TEXT,
        created_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
} else {
    // Ensure all columns exist on already-created tables (safe migration)
    $sal_cols = [
        'pf_monthly'                => 'DECIMAL(12,2) DEFAULT 0',
        'esi_monthly'               => 'DECIMAL(12,2) DEFAULT 0',
        'pf_calc'                   => 'VARCHAR(100)',
        'esi_calc'                  => 'VARCHAR(100)',
        'custom_components'         => 'TEXT',
        'special_allowance_monthly' => 'DECIMAL(12,2) DEFAULT 0',
        'salary_ctc'                => 'DECIMAL(12,2) DEFAULT 0',
        'template'                  => "VARCHAR(50) DEFAULT 'Monthly'",
        'statutory_component'       => 'VARCHAR(100)',
        'effective_cycle'           => 'VARCHAR(20)',
    ];
    foreach ($sal_cols as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM salary_structures LIKE '$col'");
        if ($chk->num_rows === 0) {
            $conn->query("ALTER TABLE salary_structures ADD COLUMN $col $def");
        }
    }
}

// Fetch all existing departments
$dept_stmt = $conn->prepare("SELECT name FROM departments ORDER BY name");
if (!$dept_stmt) {
    error_log("Department prepare error: " . $conn->error);
    $departments = [];
} else {
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    $departments = [];
    while ($dept_row = $dept_result->fetch_assoc()) {
        $departments[] = $dept_row['name'];
    }
    $dept_stmt->close();
}

// Fetch all existing companies
$comp_stmt = $conn->prepare("SELECT id, name FROM companies ORDER BY name");
if (!$comp_stmt) {
    error_log("Company prepare error: " . $conn->error);
    $companies = [];
} else {
    $comp_stmt->execute();
    $comp_result = $comp_stmt->get_result();
    $companies = [];
    while ($comp_row = $comp_result->fetch_assoc()) {
        $companies[] = $comp_row;
    }
    $comp_stmt->close();
}

// Fetch all existing shifts
$shift_stmt = $conn->prepare("SELECT id, start_time, end_time FROM shifts ORDER BY start_time");
if (!$shift_stmt) {
    error_log("Shift prepare error: " . $conn->error);
    $shifts = [];
} else {
    $shift_stmt->execute();
    $shift_result = $shift_stmt->get_result();
    $shifts = [];
    while ($shift_row = $shift_result->fetch_assoc()) {
        $start_time = date('h:i A', strtotime($shift_row['start_time']));
        $end_time = date('h:i A', strtotime($shift_row['end_time']));
        $shift_row['display_name'] = $start_time . ' - ' . $end_time;
        $shifts[] = $shift_row;
    }
    $shift_stmt->close();
}

// Fetch all existing locations
$loc_stmt = $conn->prepare("SELECT id, name FROM locations ORDER BY name");
if (!$loc_stmt) {
    error_log("Location prepare error: " . $conn->error);
    $locations = [];
} else {
    $loc_stmt->execute();
    $loc_result = $loc_stmt->get_result();
    $locations = [];
    while ($loc_row = $loc_result->fetch_assoc()) {
        $locations[] = $loc_row;
    }
    $loc_stmt->close();
}

// Handle Add Employee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    $name = htmlspecialchars($_POST['name']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $department = htmlspecialchars($_POST['department']);
    $employee_id = htmlspecialchars($_POST['employee_id']);
    $company = htmlspecialchars($_POST['company']);
    $phone = htmlspecialchars($_POST['phone']);
    $shift_time = htmlspecialchars($_POST['shift_time']);
    $location = htmlspecialchars($_POST['location']);
    $date_of_joining = htmlspecialchars($_POST['date_of_joining']);
    $date_of_exit = htmlspecialchars($_POST['date_of_exit'] ?? '');
    $status = htmlspecialchars($_POST['status']);
    $sex = htmlspecialchars($_POST['sex']);
    $week_off = htmlspecialchars($_POST['week_off']);
    $aadhar_number = htmlspecialchars(trim($_POST['aadhar_number'] ?? ''));
    $pan_number = strtoupper(htmlspecialchars(trim($_POST['pan_number'] ?? '')));
    $alternate_number = htmlspecialchars(trim($_POST['alternate_number'] ?? ''));
    $address = htmlspecialchars(trim($_POST['address'] ?? ''));
    $bank_account_number = htmlspecialchars(trim($_POST['bank_account_number'] ?? ''));
    $bank_ifsc_code = strtoupper(htmlspecialchars(trim($_POST['bank_ifsc_code'] ?? '')));

    // Validation
    if (empty($name) || empty($department) || empty($employee_id) ||
        empty($company) || empty($phone) || empty($shift_time) || empty($location) ||
        empty($date_of_joining) || empty($status) || empty($sex) || empty($week_off)) {
        $message = "All mandatory fields are required (Email is optional and Date of Exit is only required when Status is Resign)";
        $message_type = "danger";
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format";
        $message_type = "danger";
    } elseif ($status === 'Resign' && empty($date_of_exit)) {
        $message = "Date of Exit is required when Status is 'Resign'";
        $message_type = "danger";
    } elseif ((int)($conn->query("SELECT COUNT(*) c FROM users WHERE role = 'employee'")->fetch_assoc()['c']) >= MAX_EMPLOYEES_LIMIT) {
        $message = "Maximum employee limit reached (" . MAX_EMPLOYEES_LIMIT . "). Contact the developer to raise this limit before adding more employees.";
        $message_type = "danger";
    } else {
        // Check if phone already exists
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt_check->bind_param("s", $phone);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows > 0) {
            $message = "Phone number already exists";
            $message_type = "danger";
        } else {
            // Create employee WITHOUT password - Super Admin will set it later
            // Use a placeholder password that cannot login (starts with !)
            $placeholder_password = password_hash('!' . uniqid(), PASSWORD_BCRYPT);
            $stmt_insert = $conn->prepare("INSERT INTO users (name, email, password, role, department, employee_id, company, phone, shift_time, location, date_of_joining, date_of_exit, status, sex, week_off, aadhar_number, pan_number, alternate_number, address, bank_account_number, bank_ifsc_code, password_set) VALUES (?, ?, ?, 'employee', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE)");
            $stmt_insert->bind_param("ssssssssssssssssssss", $name, $email, $placeholder_password, $department, $employee_id, $company, $phone, $shift_time, $location, $date_of_joining, $date_of_exit, $status, $sex, $week_off, $aadhar_number, $pan_number, $alternate_number, $address, $bank_account_number, $bank_ifsc_code);

            if ($stmt_insert->execute()) {
                $new_user_id = $conn->insert_id;
                save_profile_photo($conn, $new_user_id);
                // Insert salary structure
                $sal_template   = htmlspecialchars($_POST['sal_template']   ?? 'Monthly');
                $sal_statutory  = htmlspecialchars($_POST['sal_statutory']  ?? '');
                $sal_effective  = htmlspecialchars($_POST['sal_effective']  ?? date('M Y'));
                $sal_ctc        = floatval($_POST['sal_ctc']        ?? 0);
                $sal_basic      = floatval($_POST['sal_basic']      ?? 0);
                $sal_allowance  = floatval($_POST['sal_allowance']  ?? 0);
                $sal_pf_monthly = floatval($_POST['sal_pf_monthly'] ?? 0);
                $sal_esi_monthly= floatval($_POST['sal_esi_monthly']?? 0);
                $sal_pf_calc    = htmlspecialchars($_POST['sal_pf_calc']    ?? '');
                $sal_esi_calc   = htmlspecialchars($_POST['sal_esi_calc']   ?? '');
                // Save custom components as JSON
                $custom_names   = $_POST['custom_comp_name']   ?? [];
                $custom_amounts = $_POST['custom_comp_amount'] ?? [];
                $custom_comps   = [];
                foreach ($custom_names as $i => $cname) {
                    $cname = htmlspecialchars(trim($cname));
                    $camt  = floatval($custom_amounts[$i] ?? 0);
                    if ($cname !== '') $custom_comps[] = ['name'=>$cname,'monthly'=>$camt,'yearly'=>$camt*12];
                }
                $custom_comps_json = json_encode($custom_comps);
                $stmt_sal = $conn->prepare("INSERT INTO salary_structures (user_id, template, statutory_component, effective_cycle, salary_ctc, basic_monthly, special_allowance_monthly, pf_monthly, esi_monthly, pf_calc, esi_calc, custom_components) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_sal->bind_param("isssdddddsss", $new_user_id, $sal_template, $sal_statutory, $sal_effective, $sal_ctc, $sal_basic, $sal_allowance, $sal_pf_monthly, $sal_esi_monthly, $sal_pf_calc, $sal_esi_calc, $custom_comps_json);
                $stmt_sal->execute();
                $stmt_sal->close();
                $message = "✓ Employee added successfully! Super Admin must set password before employee can login.";
                $message_type = "success";
            } else {
                $message = "Error: " . $stmt_insert->error;
                $message_type = "danger";
            }
            $stmt_insert->close();
        }
        $stmt_check->close();
    }
}

// Handle Update Employee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_employee'])) {
    $emp_id = (int)$_POST['emp_id'];
    $name = htmlspecialchars($_POST['name']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $department = htmlspecialchars($_POST['department']);
    $employee_id = htmlspecialchars($_POST['employee_id']);
    $company = htmlspecialchars($_POST['company']);
    $phone = htmlspecialchars($_POST['phone']);
    $shift_time = htmlspecialchars($_POST['shift_time']);
    $location = htmlspecialchars($_POST['location']);
    $date_of_joining = htmlspecialchars($_POST['date_of_joining']);
    $date_of_exit = htmlspecialchars($_POST['date_of_exit'] ?? '');
    $status = htmlspecialchars($_POST['status']);
    $sex = htmlspecialchars($_POST['sex']);
    $week_off = htmlspecialchars($_POST['week_off']);
    $aadhar_number = htmlspecialchars(trim($_POST['aadhar_number'] ?? ''));
    $pan_number = strtoupper(htmlspecialchars(trim($_POST['pan_number'] ?? '')));
    $alternate_number = htmlspecialchars(trim($_POST['alternate_number'] ?? ''));
    $address = htmlspecialchars(trim($_POST['address'] ?? ''));
    $bank_account_number = htmlspecialchars(trim($_POST['bank_account_number'] ?? ''));
    $bank_ifsc_code = strtoupper(htmlspecialchars(trim($_POST['bank_ifsc_code'] ?? '')));

    // Validation
    if (empty($name) || empty($department) || empty($employee_id) || empty($company) ||
        empty($phone) || empty($shift_time) || empty($location) ||
        empty($date_of_joining) || empty($status) || empty($sex) || empty($week_off)) {
        $message = "All mandatory fields are required (Email is optional and Date of Exit is only required when Status is Resign)";
        $message_type = "danger";
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format";
        $message_type = "danger";
    } elseif ($status === 'Resign' && empty($date_of_exit)) {
        $message = "Date of Exit is required when Status is 'Resign'";
        $message_type = "danger";
    } else {
        $stmt_update = $conn->prepare("UPDATE users SET name=?, email=?, department=?, employee_id=?, company=?, phone=?, shift_time=?, location=?, date_of_joining=?, date_of_exit=?, status=?, sex=?, week_off=?, aadhar_number=?, pan_number=?, alternate_number=?, address=?, bank_account_number=?, bank_ifsc_code=? WHERE id=?");
        $stmt_update->bind_param("sssssssssssssssssssi", $name, $email, $department, $employee_id, $company, $phone, $shift_time, $location, $date_of_joining, $date_of_exit, $status, $sex, $week_off, $aadhar_number, $pan_number, $alternate_number, $address, $bank_account_number, $bank_ifsc_code, $emp_id);

        if ($stmt_update->execute()) {
            save_profile_photo($conn, $emp_id);
            $message = "✓ Employee updated successfully!";
            $message_type = "success";
            // ── Update / Insert salary structure ──
            $esal_template    = htmlspecialchars($_POST['edit_sal_template']   ?? 'Monthly');
            $esal_statutory   = htmlspecialchars($_POST['edit_sal_statutory']  ?? '');
            $esal_effective   = htmlspecialchars($_POST['edit_sal_effective']  ?? date('M Y'));
            $esal_ctc         = floatval($_POST['edit_sal_ctc']         ?? 0);
            $esal_basic       = floatval($_POST['edit_sal_basic']       ?? 0);
            $esal_allowance   = floatval($_POST['edit_sal_allowance']   ?? 0);
            $esal_pf_monthly  = floatval($_POST['edit_sal_pf_monthly']  ?? 0);
            $esal_esi_monthly = floatval($_POST['edit_sal_esi_monthly'] ?? 0);
            $esal_pf_calc     = htmlspecialchars($_POST['edit_sal_pf_calc']    ?? '');
            $esal_esi_calc    = htmlspecialchars($_POST['edit_sal_esi_calc']   ?? '');
            $esal_cnames  = $_POST['edit_custom_comp_name']   ?? [];
            $esal_camts   = $_POST['edit_custom_comp_amount'] ?? [];
            $esal_comps   = [];
            foreach ($esal_cnames as $i => $cn) {
                $cn = htmlspecialchars(trim($cn));
                $ca = floatval($esal_camts[$i] ?? 0);
                if ($cn !== '') $esal_comps[] = ['name'=>$cn,'monthly'=>$ca,'yearly'=>$ca*12];
            }
            $esal_json = json_encode($esal_comps);
            $chk = $conn->prepare("SELECT id FROM salary_structures WHERE user_id = ?");
            $chk->bind_param("i", $emp_id); $chk->execute();
            $sal_exists = $chk->get_result()->num_rows > 0; $chk->close();
            if ($sal_exists) {
                $ss = $conn->prepare("UPDATE salary_structures SET template=?,statutory_component=?,effective_cycle=?,salary_ctc=?,basic_monthly=?,special_allowance_monthly=?,pf_monthly=?,esi_monthly=?,pf_calc=?,esi_calc=?,custom_components=? WHERE user_id=?");
                $ss->bind_param("sssdddddsssi",$esal_template,$esal_statutory,$esal_effective,$esal_ctc,$esal_basic,$esal_allowance,$esal_pf_monthly,$esal_esi_monthly,$esal_pf_calc,$esal_esi_calc,$esal_json,$emp_id);
            } else {
                $ss = $conn->prepare("INSERT INTO salary_structures (user_id,template,statutory_component,effective_cycle,salary_ctc,basic_monthly,special_allowance_monthly,pf_monthly,esi_monthly,pf_calc,esi_calc,custom_components) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $ss->bind_param("isssdddddsss",$emp_id,$esal_template,$esal_statutory,$esal_effective,$esal_ctc,$esal_basic,$esal_allowance,$esal_pf_monthly,$esal_esi_monthly,$esal_pf_calc,$esal_esi_calc,$esal_json);
            }
            $ss->execute(); $ss->close();
        } else {
            $message = "Error: " . $stmt_update->error;
            $message_type = "danger";
        }
        $stmt_update->close();
    }
}

// Handle Delete Employee
if (isset($_GET['delete_employee'])) {
    $delete_id = (int)$_GET['delete_employee'];
    
    // Delete the employee (CASCADE will delete related attendance records)
    $stmt_delete = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'employee'");
    $stmt_delete->bind_param("i", $delete_id);
    
    if ($stmt_delete->execute()) {
        if ($stmt_delete->affected_rows > 0) {
            $message = "✓ Employee deleted successfully!";
            $message_type = "success";
        } else {
            $message = "⚠ Employee not found";
            $message_type = "warning";
        }
    } else {
        $message = "✗ Error: " . $stmt_delete->error;
        $message_type = "danger";
    }
    $stmt_delete->close();
}

// Handle AJAX fetch employee data (includes salary)
if (isset($_GET['fetch_employee'])) {
    $fetch_id = (int)$_GET['fetch_employee'];
    $stmt_fetch = $conn->prepare("
        SELECT u.id, u.name, u.email, u.employee_id, u.company, u.phone, u.department,
               u.shift_time, u.location, u.date_of_joining, u.date_of_exit, u.status, u.sex, u.week_off,
               u.aadhar_number, u.pan_number, u.alternate_number, u.address,
               u.bank_account_number, u.bank_ifsc_code,
               COALESCE(s.salary_ctc, 0)                AS salary_ctc,
               COALESCE(s.basic_monthly, 0)             AS basic_monthly,
               COALESCE(s.special_allowance_monthly, 0) AS special_allowance_monthly,
               COALESCE(s.pf_monthly, 0)                AS pf_monthly,
               COALESCE(s.esi_monthly, 0)               AS esi_monthly,
               IFNULL(s.pf_calc, '')                    AS pf_calc,
               IFNULL(s.esi_calc, '')                   AS esi_calc,
               IFNULL(s.template, 'Monthly')            AS sal_template,
               IFNULL(s.statutory_component, '')        AS statutory_component,
               IFNULL(s.effective_cycle, '')            AS effective_cycle,
               IFNULL(s.custom_components, '[]')        AS custom_components
        FROM users u
        LEFT JOIN salary_structures s ON s.user_id = u.id
        WHERE u.id = ? AND u.role = 'employee'
    ");
    $stmt_fetch->bind_param("i", $fetch_id);
    $stmt_fetch->execute();
    $result_fetch = $stmt_fetch->get_result();

    header('Content-Type: application/json');

    if ($result_fetch->num_rows > 0) {
        $emp = $result_fetch->fetch_assoc();
        $photos = glob("../uploads/employee_photos/" . $fetch_id . ".*");
        $emp['photo_url'] = !empty($photos) ? "../uploads/employee_photos/" . basename($photos[0]) : '';
        echo json_encode(['success' => true, 'employee' => $emp]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Employee not found']);
    }
    $stmt_fetch->close();
    exit;
}

// Fetch all employees using prepared statement
$stmt = $conn->prepare("SELECT id, name, email, department, employee_id, company, phone, shift_time, location, date_of_joining, date_of_exit, status, sex, week_off, password_set FROM users WHERE role = 'employee' ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
$current_employee_count = $result->num_rows;
$employee_limit_reached = $current_employee_count >= MAX_EMPLOYEES_LIMIT;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employees - Attendance System</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        .password-input-group {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-input-group .form-control {
            padding-right: 45px;
        }
        .toggle-password-btn {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .toggle-password-btn:hover {
            color: #764ba2;
        }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container mt-5">
        <!-- Back Button -->
        <div class="mb-3">
            <a href="<?php echo htmlspecialchars($back_dashboard); ?>" class="btn btn-secondary btn-sm">
                ← Back to Dashboard
            </a>
        </div>

        <!-- Toggle Button with Import/Export Options -->
        <div class="mb-4">
            <?php if ($employee_limit_reached): ?>
                <button class="btn btn-primary btn-lg" disabled title="Employee limit reached">
                    ➕ Add New Employee
                </button>
                <span class="badge bg-danger align-middle ms-1">⚠ Limit reached: <?php echo $current_employee_count; ?> / <?php echo MAX_EMPLOYEES_LIMIT; ?></span>
            <?php else: ?>
                <button class="btn btn-primary btn-lg" onclick="toggleForm()" id="toggleBtn">
                    ➕ Add New Employee
                </button>
                <span class="badge bg-secondary align-middle ms-1"><?php echo $current_employee_count; ?> / <?php echo MAX_EMPLOYEES_LIMIT; ?> employees</span>
            <?php endif; ?>
            <!-- <a href="import_employees.php" class="btn btn-info btn-lg me-2">📥 Import Excel</a>
            <a href="#" class="btn btn-success btn-lg" onclick="exportEmployees()">📤 Export Excel</a> -->
        </div>

        <!-- Search and Filter Section -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label"><strong>🔍 Search Employee</strong></label>
                        <input type="text" class="form-control" id="searchInput" placeholder="Search by name, email, employee ID, or phone...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><strong>📋 Employee ID</strong></label>
                        <select class="form-control" id="employeeIdFilter">
                            <option value="">No Sort</option>
                            <option value="asc">🔼 First to Last (1-100)</option>
                            <option value="desc">🔽 Last to First (100-1)</option>
                        </select>
                    </div>
                    <!--
                    <div class="col-md-3">
                        <label class="form-label"><strong>🏢 Company</strong></label>
                        <select class="form-control" id="companyFilter">
                            <option value="">All Companies</option>
                            <?php
                            $companies_list = array();
                            foreach ($companies as $comp) {
                                $companies_list[] = $comp['name'];
                            }
                            sort($companies_list);
                            foreach ($companies_list as $comp): ?>
                                <option value="<?php echo htmlspecialchars($comp); ?>"><?php echo htmlspecialchars($comp); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    -->
                    <div class="col-md-3">
                        <label class="form-label"><strong>📍 Location</strong></label>
                        <select class="form-control" id="locationFilter">
                            <option value="">All Locations</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?php echo htmlspecialchars($loc['name']); ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><strong>🏭 Project</strong></label>
                        <select class="form-control" id="departmentFilter">
                            <option value="">All Projects</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <!-- Add Employee Form (Hidden by default) -->
        <div class="card mb-4" id="addForm" style="display: none;">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">➕ Add New Employee</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3" enctype="multipart/form-data">
                    <div class="col-md-3">
                        <label class="form-label">Employee ID</label>
                        <input type="text" name="employee_id" class="form-control" placeholder="e.g., EMP001" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Enter name"
                            required
                            oninput="this.value=this.value.replace(/[^a-zA-Z\s]/g,'');this.value=this.value.replace(/\b\w/g,c=>c.toUpperCase());"
                            onkeypress="return /[a-zA-Z\s]/.test(String.fromCharCode(event.which))"
                            onpaste="event.preventDefault();"
                            title="Name should contain letters only">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Email <span class="text-muted small">(optional)</span></label>
                        <input type="email" name="email" class="form-control" placeholder="e.g., name@example.com"
                            pattern="[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}"
                            oninput="validateEmail(this)"
                            onblur="validateEmail(this)"
                            title="Enter a valid email address">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone" class="form-control" placeholder="10-digit number"
                            required
                            minlength="10" maxlength="10"
                            pattern="[0-9]{10}"
                            oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,10); validatePhone(this);"
                            onkeypress="return /[0-9]/.test(String.fromCharCode(event.which))"
                            onpaste="event.preventDefault()"
                            title="Phone number must be exactly 10 digits">
                        <div class="invalid-feedback">Must be exactly 10 digits.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Alternative Number</label>
                        <input type="tel" name="alternate_number" class="form-control" placeholder="10-digit alternate number"
                            maxlength="10" pattern="[0-9]{10}"
                            oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,10);"
                            title="Alternative number must be exactly 10 digits">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Gender</label>
                        <select name="sex" class="form-control" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Project</label>
                        <select name="department" class="form-control" required>
                            <option value="">Select Project</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Company</label>
                        <select name="company" class="form-control" required>
                            <option value="">Select Company</option>
                            <?php foreach ($companies as $comp): ?>
                                <option value="<?php echo htmlspecialchars($comp['name']); ?>"><?php echo htmlspecialchars($comp['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Aadhar Number</label>
                        <input type="text" name="aadhar_number" class="form-control" placeholder="12-digit Aadhar number"
                            maxlength="12" pattern="[0-9]{12}"
                            oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,12);"
                            title="Aadhar number must be exactly 12 digits">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">PAN Number</label>
                        <input type="text" name="pan_number" class="form-control" placeholder="e.g., ABCDE1234F"
                            maxlength="10" pattern="[A-Za-z]{5}[0-9]{4}[A-Za-z]{1}"
                            oninput="this.value=this.value.toUpperCase().slice(0,10);"
                            title="PAN format: 5 letters, 4 digits, 1 letter">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Shift Time</label>
                        <select name="shift_time" class="form-control" required>
                            <option value="">Select Shift</option>
                            <?php foreach ($shifts as $shift): ?>
                                <option value="<?php echo htmlspecialchars($shift['display_name']); ?>"><?php echo htmlspecialchars($shift['display_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Location</label>
                        <select name="location" class="form-control" required>
                            <option value="">Select Location</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?php echo htmlspecialchars($loc['name']); ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date of Joining</label>
                        <input type="date" name="date_of_joining" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status </label>
                        <select name="status" class="form-control add-status-select" required onchange="toggleExitDateField(this, 'add')">
                            <option value="">Select Status</option>
                            <option value="Working">Working</option>
                            <option value="Resign">Resign</option>
                        </select>
                    </div>
                    <div class="col-md-3" id="addExitDateField" style="display:none;">
                        <label class="form-label">Date of Exit <span class="text-danger"></span></label>
                        <input type="date" name="date_of_exit" class="form-control add-exit-date">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Week Off</label>
                        <select name="week_off" class="form-control" required>
                            <option value="">Select Day</option>
                            <option value="Monday">Monday</option>
                            <option value="Tuesday">Tuesday</option>
                            <option value="Wednesday">Wednesday</option>
                            <option value="Thursday">Thursday</option>
                            <option value="Friday">Friday</option>
                            <option value="Saturday">Saturday</option>
                            <option value="Sunday">Sunday</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2" placeholder="Enter residential address"></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label d-block">Profile Photo</label>
                        <div id="addPhotoDropZone" class="text-center" style="border:2px dashed #ccc; border-radius:10px; padding:12px; background:#f8f9fa; cursor:pointer;" onclick="document.getElementById('addProfilePhotoInput').click();">
                            <img id="addPhotoPreviewImg" src="" alt="Profile Preview" style="display:none; max-width:120px; max-height:120px; border-radius:8px; object-fit:cover;">
                            <div id="addPhotoPlaceholder">
                                <i class="fas fa-image" style="font-size:32px; color:#ccc;"></i>
                                <p class="text-muted small mb-0 mt-1">Click to upload from gallery</p>
                                <p class="text-muted small mb-0">(Max size 50KB)</p>
                            </div>
                        </div>
                        <input type="file" id="addProfilePhotoInput" name="profile_photo" accept="image/*" class="d-none" onchange="previewProfilePhoto(this, 'add')">
                        <button type="button" id="addReuploadBtn" class="btn btn-sm btn-outline-secondary mt-2 d-none w-100" onclick="document.getElementById('addProfilePhotoInput').click();">
                            🔄 Reupload Photo
                        </button>
                    </div>
                    <!-- Account Details Section -->
                    <div class="col-12 mt-3">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header" style="background:#f8f9fa; border-bottom:2px solid #e9ecef;">
                                <h6 class="mb-0 fw-bold">🪪 Account Details</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Bank Account Number</label>
                                        <input type="text" name="bank_account_number" class="form-control" placeholder="Enter bank account number"
                                            maxlength="30" pattern="[0-9]{6,30}"
                                            oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,30);"
                                            title="Bank account number should contain digits only">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">IFSC Code</label>
                                        <input type="text" name="bank_ifsc_code" class="form-control" placeholder="e.g., SBIN0001234"
                                            maxlength="11" pattern="[A-Za-z]{4}0[A-Za-z0-9]{6}"
                                            oninput="this.value=this.value.toUpperCase().slice(0,11);"
                                            title="IFSC format: 4 letters, 0, 6 alphanumeric characters">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Salary Structure Section -->
                    <div class="col-12 mt-3">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header" style="background:#f8f9fa; border-bottom:2px solid #e9ecef;">
                                <h6 class="mb-0 fw-bold">💰 Salary Structure</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Template</label>
                                        <select name="sal_template" id="salTemplate" class="form-select">
                                            <option value="Monthly" selected>Monthly</option>
                                            <option value="Weekly">Weekly</option>
                                            <option value="Bi-Monthly">Bi-Monthly</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Statutory Component Template</label>
                                        <select name="sal_statutory" id="salStatutory" class="form-select" onchange="showStatutoryFields()">
                                            <option value="">Select Statutory Component</option>
                                            <option value="PF+ESI">PF + ESI</option>
                                            <option value="PF Only">PF Only</option>
                                            <option value="ESI Only">ESI Only</option>
                                            <option value="None">None</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Effective Cycle</label>
                                        <select name="sal_effective" class="form-select">
                                            <?php
                                            for ($m = 0; $m < 12; $m++) {
                                                $ts = mktime(0,0,0, date('n') + $m, 1);
                                                $val = date('M Y', $ts);
                                                $sel = ($m === 0) ? 'selected' : '';
                                                echo "<option value=\"$val\" $sel>$val</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                                <!-- CTC Input -->
                                <!-- Statutory Input Panel (shown dynamically) -->
                                <div id="statutoryPanel" style="display:none;" class="mb-3">
                                    <div class="card border-warning">
                                        <div class="card-header bg-warning bg-opacity-10 py-2">
                                            <strong class="small">⚙️ Configure Statutory Deductions</strong>
                                        </div>
                                        <div class="card-body py-3">
                                            <div class="row g-3" id="pfFields" style="display:none;">
                                                <div class="col-12"><h6 class="text-primary mb-2">🔵 Provident Fund (PF)</h6></div>
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold">PF Calculation</label>
                                                    <select id="pfCalcType" class="form-select form-select-sm" onchange="recalcStatutory()">
                                                        <option value="percent">% of Basic</option>
                                                        <option value="fixed">Fixed Amount</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold" id="pfRateLabel">PF Rate (%)</label>
                                                    <div class="input-group input-group-sm">
                                                        <input type="number" id="pfRate" class="form-control" value="12" min="0" step="0.01" oninput="recalcStatutory()">
                                                        <span class="input-group-text" id="pfRateUnit">%</span>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold">PF Monthly (₹)</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text">₹</span>
                                                        <input type="text" id="pfMonthly" class="form-control bg-light" readonly placeholder="0.00">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row g-3 mt-1" id="esiFields" style="display:none;">
                                                <div class="col-12"><h6 class="text-success mb-2">🟢 Employee State Insurance (ESI)</h6></div>
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold">ESI Calculation</label>
                                                    <select id="esiCalcType" class="form-select form-select-sm" onchange="recalcStatutory()">
                                                        <option value="percent">% of Gross</option>
                                                        <option value="fixed">Fixed Amount</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold" id="esiRateLabel">ESI Rate (%)</label>
                                                    <div class="input-group input-group-sm">
                                                        <input type="number" id="esiRate" class="form-control" value="0.75" min="0" step="0.01" oninput="recalcStatutory()">
                                                        <span class="input-group-text" id="esiRateUnit">%</span>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold">ESI Monthly (₹)</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text">₹</span>
                                                        <input type="text" id="esiMonthly" class="form-control bg-light" readonly placeholder="0.00">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                <button type="button" class="btn btn-primary btn-sm px-4" onclick="addStatutoryToTable()">
                                                    ✚ ADD to Components
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Hidden inputs to carry statutory values on form submit -->
                                <input type="hidden" name="sal_pf_monthly" id="hPfMonthly" value="0">
                                <input type="hidden" name="sal_esi_monthly" id="hEsiMonthly" value="0">
                                <input type="hidden" name="sal_pf_calc" id="hPfCalc" value="">
                                <input type="hidden" name="sal_esi_calc" id="hEsiCalc" value="">
                                <input type="hidden" name="sal_ctc" id="hSalCTC" value="0">

                                <!-- Components Table -->
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle">
                                        <thead style="background:#f8f9fa;">
                                            <tr>
                                                <th>COMPONENTS</th>
                                                <th>CALCULATION</th>
                                                <th>MONTHLY AMOUNT</th>
                                                <th>YEARLY AMOUNT</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr class="table-light"><td colspan="4"><strong>Earnings</strong></td></tr>
                                            <tr>
                                                <td>Basic</td>
                                                <td><span class="text-muted small">Fixed Amount</span></td>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text">₹</span>
                                                        <input type="number" name="sal_basic" id="salBasicM" class="form-control" placeholder="0.00" min="0" step="0.01" oninput="calcSalary()">
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text">₹</span>
                                                        <input type="text" id="salBasicY" class="form-control bg-light" placeholder="0" readonly>
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>Special Allowance</td>
                                                <td><span class="text-muted small">Fixed Amount</span></td>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text">₹</span>
                                                        <input type="number" name="sal_allowance" id="salAllowM" class="form-control" placeholder="0.00" min="0" step="0.01" oninput="calcSalary()">
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text">₹</span>
                                                        <input type="text" id="salAllowY" class="form-control bg-light" placeholder="0" readonly>
                                                    </div>
                                                </td>
                                            </tr>
                                            <!-- Statutory rows injected here by JS -->
                                            <tbody id="statutoryRows"></tbody>
                                            <!-- Custom earning rows added by HR -->
                                            <tbody id="customEarningsRows"></tbody>
                                            <!-- Add Component button row -->
                                            <tr id="addCompBtnRow">
                                                <td colspan="4" class="text-start py-2" style="border-top:2px dashed #dee2e6;">
                                                    <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="addCustomComponent()">
                                                        <strong>+</strong> Add Earning Component
                                                    </button>
                                                    <small class="text-muted ms-2">Add HRA, TA, Bonus or any custom earning</small>
                                                </td>
                                            </tr>
                                            <tr class="fw-bold table-secondary">
                                                <td colspan="2" class="text-end">Total CTC</td>
                                                <td id="salTotalM">₹ 0.00</td>
                                                <td id="salTotalY">₹ 0.00</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="alert alert-info" role="alert">
                            <strong>ℹ️ Password Management:</strong> Employee password will be set by Super Admin after employee creation. The employee will not be able to login until password is set.
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="add_employee" class="btn btn-success">✓ Add Employee</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleForm()">✕ Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Employees List -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">All Employees</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="employeesTable">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Employee ID</th>
                                <th>Company</th>
                                <th>Phone</th>
                                <th>Project</th>
                                <th>Photo</th>
                                <th>Pass. Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            while ($row = $result->fetch_assoc()) {
                                $pass_status = $row['password_set'] ? '<span class="badge bg-success">✓ Set</span>' : '<span class="badge bg-warning">✗ Not Set</span>';
                                
                                // Check if employee has photo
                                $photoPath = "../uploads/employee_photos/" . $row['id'] . ".*";
                                $photos = glob($photoPath);
                                $hasPhoto = !empty($photos);
                                
                                echo "<tr data-location=\"" . htmlspecialchars($row['location'] ?? '') . "\">";
                                echo "<td>" . $row['id'] . "</td>";
                                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['email']) . "</td>";
                                echo "<td>" . ($row['employee_id'] ?? 'N/A') . "</td>";
                                echo "<td>" . ($row['company'] ?? 'N/A') . "</td>";
                                echo "<td>" . ($row['phone'] ?? 'N/A') . "</td>";
                                echo "<td>" . ($row['department'] ?? 'N/A') . "</td>";
                                
                                // Photo column
                                echo "<td>";
                                if ($hasPhoto) {
                                    $photoFile = $photos[0];
                                    $photoUrl = "../uploads/employee_photos/" . basename($photoFile);
                                    echo "<img src='" . htmlspecialchars($photoUrl) . "' alt='Photo' style='width: 50px; height: 50px; border-radius: 5px; object-fit: cover; cursor: pointer;' onclick=\"showPhotoModal('" . htmlspecialchars($photoUrl) . "', '" . htmlspecialchars($row['name']) . "')\" title='Click to view'>";
                                } else {
                                    echo "<span class='text-muted'>No photo</span>";
                                }
                                echo "</td>";
                                
                                echo "<td>" . $pass_status . "</td>";
                                echo "<td>";
                                echo "<button type='button' class='btn btn-sm btn-info' onclick='openViewModal(" . $row['id'] . ")'>View</button> ";
                                echo "<button type='button' class='btn btn-sm btn-warning' onclick=\"openEditModalFromTable(" . $row['id'] . ")\">Edit</button> ";
                                echo "<button type='button' onclick=\"openPhotoUpload(" . $row['id'] . ", '" . htmlspecialchars($row['name']) . "')\" class='btn btn-sm btn-secondary'>📸 Photo</button> ";
                                // if ($hasPhoto) {
                                //     echo "<button type='button' class='btn btn-sm btn-danger' onclick=\"deleteEmployeePhoto(" . $row['id'] . ", '" . htmlspecialchars($row['name']) . "')\">🗑️ Del</button> ";
                                // }
                                echo "<button type='button' onclick=\"confirmDeleteEmployee(" . $row['id'] . ")\" class='btn btn-sm btn-danger'>Delete</button>";
                                echo "</td>";
                                echo "</tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Employee Details Section -->
        <?php
        if (isset($_GET['view_employee'])) {
            $view_id = (int)$_GET['view_employee'];
            $stmt_view = $conn->prepare("SELECT id, name, email, employee_id, company, phone, department, shift_time, location, date_of_joining, date_of_exit, status, sex, week_off, aadhar_number, pan_number, alternate_number, address, bank_account_number, bank_ifsc_code FROM users WHERE id = ? AND role = 'employee'");
            $stmt_view->bind_param("i", $view_id);
            $stmt_view->execute();
            $result_view = $stmt_view->get_result();

            if ($result_view->num_rows > 0) {
                $emp = $result_view->fetch_assoc();
                $viewPhotos = glob("../uploads/employee_photos/" . $emp['id'] . ".*");
                $emp['photo_url'] = !empty($viewPhotos) ? "../uploads/employee_photos/" . basename($viewPhotos[0]) : '';
                $is_edit = isset($_GET['edit']) && $_GET['edit'] == '1';
                ?>
                <div class="card mt-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">📋 Employee <?php echo $is_edit ? 'Edit' : 'Details'; ?></h5>
                    </div>
                    <div class="card-body">
                        <?php if (!$is_edit): ?>
                            <!-- Employee Photo Section -->
                            <div class="row mb-4">
                                <div class="col-md-3 text-center">
                                    <?php 
                                    $photoPath = "../uploads/employee_photos/" . $emp['id'] . ".*";
                                    $photos = glob($photoPath);
                                    if (!empty($photos)) {
                                        $photoFile = $photos[0];
                                        $photoUrl = "../uploads/employee_photos/" . basename($photoFile);
                                        ?>
                                        <div style="border: 2px solid #007bff; border-radius: 10px; padding: 10px; background: #f8f9fa;">
                                            <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="Employee Photo" style="max-width: 100%; max-height: 300px; border-radius: 8px; object-fit: cover;">
                                            <p class="text-muted mt-2"><small>✅ Face photo available</small></p>
                                            <button type="button" class="btn btn-sm btn-danger mt-2" onclick="deleteEmployeePhoto(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['name']); ?>')">
                                                <i class="fas fa-trash"></i> Delete Photo
                                            </button>
                                        </div>
                                        <?php
                                    } else {
                                        ?>
                                        <div style="border: 2px dashed #ccc; border-radius: 10px; padding: 30px; background: #f8f9fa; text-align: center;">
                                            <i class="fas fa-image" style="font-size: 50px; color: #ccc;"></i>
                                            <p class="text-muted mt-3">❌ No photo uploaded</p>
                                            <a href="employees.php" class="btn btn-sm btn-info">← Back to Upload</a>
                                        </div>
                                        <?php
                                    }
                                    ?>
                                </div>
                                <div class="col-md-9">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>Name:</strong> <?php echo htmlspecialchars($emp['name']); ?></p>
                                            <p><strong>Employee ID:</strong> <?php echo htmlspecialchars($emp['employee_id']); ?></p>
                                            <p><strong>Sex:</strong> <?php echo htmlspecialchars($emp['sex']); ?></p>
                                            <p><strong>Email:</strong> <?php echo htmlspecialchars($emp['email']); ?></p>
                                            <p><strong>Phone Number:</strong> <?php echo htmlspecialchars($emp['phone']); ?></p>
                                            <p><strong>Project:</strong> <?php echo htmlspecialchars($emp['department'] ?? 'N/A'); ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>Company:</strong> <?php echo htmlspecialchars($emp['company']); ?></p>
                                            <p><strong>Shift Time:</strong> <?php echo htmlspecialchars($emp['shift_time']); ?></p>
                                            <p><strong>Location:</strong> <?php echo htmlspecialchars($emp['location']); ?></p>
                                            <p><strong>Date of Joining:</strong> <?php echo htmlspecialchars($emp['date_of_joining']); ?></p>
                                            <p><strong>Status:</strong> <span class="badge <?php echo $emp['status'] === 'Working' ? 'bg-success' : 'bg-danger'; ?>"><?php echo htmlspecialchars($emp['status']); ?></span></p>
                                            <?php if ($emp['status'] === 'Resign' && !empty($emp['date_of_exit'])): ?>
                                            <p><strong>Date of Exit:</strong> <?php echo htmlspecialchars($emp['date_of_exit']); ?></p>
                                            <?php endif; ?>
                                            <p><strong>Week Off:</strong> <span class="badge bg-info"><?php echo htmlspecialchars($emp['week_off'] ?? 'N/A'); ?></span></p>
                                            <p><strong>User ID:</strong> <?php echo $emp['id']; ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <hr>
                            <h6 class="fw-bold mb-3">🪪 Account Details</h6>
                            <div class="row mb-3">
                                <div class="col-md-4"><strong>Aadhar Number:</strong> <?php echo htmlspecialchars($emp['aadhar_number'] ?? '') ?: 'N/A'; ?></div>
                                <div class="col-md-4"><strong>PAN Number:</strong> <?php echo htmlspecialchars($emp['pan_number'] ?? '') ?: 'N/A'; ?></div>
                                <div class="col-md-4"><strong>Alternative Number:</strong> <?php echo htmlspecialchars($emp['alternate_number'] ?? '') ?: 'N/A'; ?></div>
                                <div class="col-md-6"><strong>Bank Account Number:</strong> <?php echo htmlspecialchars($emp['bank_account_number'] ?? '') ?: 'N/A'; ?></div>
                                <div class="col-md-6"><strong>IFSC Code:</strong> <?php echo htmlspecialchars($emp['bank_ifsc_code'] ?? '') ?: 'N/A'; ?></div>
                                <div class="col-md-12 mt-2"><strong>Address:</strong> <?php echo htmlspecialchars($emp['address'] ?? '') ?: 'N/A'; ?></div>
                            </div>
                            <a href="?view_employee=<?php echo $emp['id']; ?>" class="btn btn-info">View Details</a>
                            <button type="button" class="btn btn-warning" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($emp)); ?>)">✎ Edit Employee</button>
                            <a href="employees.php" class="btn btn-secondary">Back to List</a>
                        <?php else: ?>
                            <!-- Edit form shown in modal, not here -->
                        <?php endif; ?>
                    </div>
                </div>
                <?php
            }
            $stmt_view->close();
        }
        ?>

        <!-- <div class="mt-3">
            <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
        </div> -->
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function calcSalary() {
            const basic  = parseFloat(document.getElementById('salBasicM').value)  || 0;
            const allow  = parseFloat(document.getElementById('salAllowM').value)  || 0;
            document.getElementById('salBasicY').value  = (basic * 12).toLocaleString('en-IN');
            document.getElementById('salAllowY').value  = (allow * 12).toLocaleString('en-IN');
            recalcStatutory();
            updateCTCTotal();
        }
        function syncCTC() { /* manual override disabled - CTC auto-calculated */ }

        // Show/hide PF/ESI input fields based on statutory dropdown
        function showStatutoryFields() {
            const val = document.getElementById('salStatutory').value;
            const panel = document.getElementById('statutoryPanel');
            const pfF   = document.getElementById('pfFields');
            const esiF  = document.getElementById('esiFields');
            if (!val || val === 'None') {
                panel.style.display = 'none';
                pfF.style.display   = 'none';
                esiF.style.display  = 'none';
            } else {
                panel.style.display = 'block';
                pfF.style.display   = (val === 'PF Only' || val === 'PF+ESI') ? 'flex' : 'none';
                esiF.style.display  = (val === 'ESI Only' || val === 'PF+ESI') ? 'flex' : 'none';
            }
            recalcStatutory();
        }

        // Recalculate PF/ESI amounts based on current basic/gross
        function recalcStatutory() {
            const basic = parseFloat(document.getElementById('salBasicM').value) || 0;
            const allow = parseFloat(document.getElementById('salAllowM').value) || 0;
            const gross = basic + allow;

            // PF
            const pfType = document.getElementById('pfCalcType') ? document.getElementById('pfCalcType').value : 'percent';
            const pfRate = parseFloat(document.getElementById('pfRate') ? document.getElementById('pfRate').value : 12) || 0;
            let pfAmt = 0;
            if (pfType === 'percent') {
                pfAmt = (basic * pfRate) / 100;
                document.getElementById('pfRateLabel').textContent = 'PF Rate (%)';
                document.getElementById('pfRateUnit').textContent  = '%';
            } else {
                pfAmt = pfRate;
                document.getElementById('pfRateLabel').textContent = 'PF Fixed Amount (₹)';
                document.getElementById('pfRateUnit').textContent  = '₹';
            }
            if (document.getElementById('pfMonthly')) {
                document.getElementById('pfMonthly').value = pfAmt.toFixed(2);
                document.getElementById('hPfMonthly').value = pfAmt.toFixed(2);
                document.getElementById('hPfCalc').value = pfType === 'percent' ? pfRate + '% of Basic' : 'Fixed ₹' + pfRate;
            }

            // ESI
            const esiType = document.getElementById('esiCalcType') ? document.getElementById('esiCalcType').value : 'percent';
            const esiRate = parseFloat(document.getElementById('esiRate') ? document.getElementById('esiRate').value : 0.75) || 0;
            let esiAmt = 0;
            if (esiType === 'percent') {
                esiAmt = (gross * esiRate) / 100;
                document.getElementById('esiRateLabel').textContent = 'ESI Rate (%)';
                document.getElementById('esiRateUnit').textContent  = '%';
            } else {
                esiAmt = esiRate;
                document.getElementById('esiRateLabel').textContent = 'ESI Fixed Amount (₹)';
                document.getElementById('esiRateUnit').textContent  = '₹';
            }
            if (document.getElementById('esiMonthly')) {
                document.getElementById('esiMonthly').value = esiAmt.toFixed(2);
                document.getElementById('hEsiMonthly').value = esiAmt.toFixed(2);
                document.getElementById('hEsiCalc').value = esiType === 'percent' ? esiRate + '% of Gross' : 'Fixed ₹' + esiRate;
            }
        }

        // Add statutory components into the table
        function addStatutoryToTable() {
            const val = document.getElementById('salStatutory').value;
            if (!val || val === 'None') return;

            // Clear previous statutory rows
            document.getElementById('statutoryRows').innerHTML = '';

            const fmt = n => parseFloat(n).toLocaleString('en-IN', {minimumFractionDigits:2});

            if (val === 'PF Only' || val === 'PF+ESI') {
                const pfM = parseFloat(document.getElementById('pfMonthly').value) || 0;
                const pfY = pfM * 12;
                const pfCalc = document.getElementById('hPfCalc').value;
                const row = `<tr class="table-warning">
                    <td>Provident Fund (PF)</td>
                    <td><span class="text-primary small">${pfCalc}</span></td>
                    <td><div class="input-group input-group-sm"><span class="input-group-text">₹</span><input type="text" class="form-control bg-light" value="${fmt(pfM)}" readonly></div></td>
                    <td><div class="input-group input-group-sm"><span class="input-group-text">₹</span><input type="text" class="form-control bg-light" value="${fmt(pfY)}" readonly></div></td>
                </tr>`;
                document.getElementById('statutoryRows').insertAdjacentHTML('beforeend', row);
            }
            if (val === 'ESI Only' || val === 'PF+ESI') {
                const esiM = parseFloat(document.getElementById('esiMonthly').value) || 0;
                const esiY = esiM * 12;
                const esiCalc = document.getElementById('hEsiCalc').value;
                const row = `<tr class="table-success">
                    <td>Employee State Insurance (ESI)</td>
                    <td><span class="text-success small">${esiCalc}</span></td>
                    <td><div class="input-group input-group-sm"><span class="input-group-text">₹</span><input type="text" class="form-control bg-light" value="${fmt(esiM)}" readonly></div></td>
                    <td><div class="input-group input-group-sm"><span class="input-group-text">₹</span><input type="text" class="form-control bg-light" value="${fmt(esiY)}" readonly></div></td>
                </tr>`;
                document.getElementById('statutoryRows').insertAdjacentHTML('beforeend', row);
            }
            updateCTCTotal();
        }

        function updateCTCTotal() {
            const basic  = parseFloat(document.getElementById('salBasicM').value)  || 0;
            const allow  = parseFloat(document.getElementById('salAllowM').value)  || 0;
            const pf     = parseFloat(document.getElementById('hPfMonthly').value) || 0;
            const esi    = parseFloat(document.getElementById('hEsiMonthly').value)|| 0;

            // Sum all custom component monthly amounts
            let customTotal = 0;
            document.querySelectorAll('.custom-comp-monthly').forEach(inp => {
                customTotal += parseFloat(inp.value) || 0;
            });

            // CTC = Basic + Allowance + PF + ESI + Custom Components
            const totalM = basic + allow + pf + esi + customTotal;
            const totalY = totalM * 12;

            const fmt = (n) => n.toLocaleString('en-IN', {minimumFractionDigits:2});
            document.getElementById('salTotalM').textContent = '₹ ' + fmt(totalM);
            document.getElementById('salTotalY').textContent = '₹ ' + fmt(totalY);

            // Keep hidden sal_ctc in sync
            const hCtc = document.getElementById('hSalCTC');
            if (hCtc) hCtc.value = totalM.toFixed(2);
        }

        // Add a new custom earning component row
        function addCustomComponent() {
            const tbody = document.getElementById('customEarningsRows');
            const uid = Date.now();
            const row = `<tr class="custom-earning-row" id="cr_${uid}">
                <td>
                    <input type="text" name="custom_comp_name[]" class="form-control form-control-sm"
                        placeholder="e.g., HRA, TA, Bonus..." required>
                </td>
                <td><span class="text-muted small">Fixed Amount</span></td>
                <td>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">₹</span>
                        <input type="number" name="custom_comp_amount[]" class="form-control custom-comp-monthly"
                            placeholder="0.00" min="0" step="0.01"
                            oninput="calcCustomRow(this)">
                    </div>
                </td>
                <td>
                    <div class="d-flex align-items-center gap-1">
                        <div class="input-group input-group-sm flex-grow-1">
                            <span class="input-group-text">₹</span>
                            <input type="text" class="form-control bg-light custom-comp-yearly" placeholder="0" readonly>
                        </div>
                        <button type="button" class="btn btn-danger btn-sm" style="min-width:30px;"
                            onclick="removeCustomComponent(this)" title="Remove">&#x2715;</button>
                    </div>
                </td>
            </tr>`;
            tbody.insertAdjacentHTML('beforeend', row);
            // Focus the name field
            tbody.querySelector(`#cr_${uid} input[type=text]`).focus();
        }

        function calcCustomRow(input) {
            const monthly = parseFloat(input.value) || 0;
            const row = input.closest('tr');
            row.querySelector('.custom-comp-yearly').value =
                (monthly * 12).toLocaleString('en-IN', {minimumFractionDigits:2});
            updateCTCTotal();
        }

        function removeCustomComponent(btn) {
            btn.closest('tr').remove();
            updateCTCTotal();
        }

        // ── Field validation helpers ──
        function validateEmail(input) {
            const val = input.value.trim();
            if (val === '') {
                input.classList.remove('is-valid', 'is-invalid');
                return;
            }
            const ok = /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/.test(val);
            input.classList.toggle('is-valid',   ok);
            input.classList.toggle('is-invalid', !ok);
        }

        function validatePhone(input) {
            const val = input.value;
            const ok  = /^[0-9]{10}$/.test(val);
            input.classList.toggle('is-valid',   ok);
            input.classList.toggle('is-invalid', !ok && val.length > 0);
        }

        // Preview a gallery-selected profile photo and reveal the "Reupload" option
        function previewProfilePhoto(input, prefix) {
            const file = input.files && input.files[0];
            if (!file) return;
            if (!file.type.startsWith('image/')) {
                Swal.fire('Error', 'Please select an image file', 'error');
                input.value = '';
                return;
            }
            if (file.size > 50 * 1024) {
                Swal.fire('Error', 'Image must be under 50KB (selected file is ' + Math.ceil(file.size / 1024) + 'KB)', 'error');
                input.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.getElementById(prefix + 'PhotoPreviewImg');
                const placeholder = document.getElementById(prefix + 'PhotoPlaceholder');
                const reuploadBtn = document.getElementById(prefix + 'ReuploadBtn');
                img.src = e.target.result;
                img.style.display = 'inline-block';
                placeholder.style.display = 'none';
                reuploadBtn.classList.remove('d-none');
            };
            reader.readAsDataURL(file);
        }

        // ── Edit Salary functions ──
        function populateEditSalary(e) {
            const fmt = n => parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2});
            document.getElementById('editSalTemplate').value  = e.sal_template || 'Monthly';
            document.getElementById('editSalStatutory').value = e.statutory_component || '';
            // Set effective cycle if month exists in options
            const efSel = document.getElementById('editSalEffective');
            for (let opt of efSel.options) { if (opt.value === e.effective_cycle) { opt.selected = true; break; } }
            // Basic / Allowance
            document.getElementById('editSalBasicM').value = parseFloat(e.basic_monthly||0) || '';
            document.getElementById('editSalBasicY').value = fmt(parseFloat(e.basic_monthly||0)*12);
            document.getElementById('editSalAllowM').value = parseFloat(e.special_allowance_monthly||0) || '';
            document.getElementById('editSalAllowY').value = fmt(parseFloat(e.special_allowance_monthly||0)*12);
            // Hidden statutory
            document.getElementById('editHPfMonthly').value  = e.pf_monthly  || 0;
            document.getElementById('editHEsiMonthly').value = e.esi_monthly || 0;
            document.getElementById('editHPfCalc').value     = e.pf_calc     || '';
            document.getElementById('editHEsiCalc').value    = e.esi_calc    || '';
            // Pre-fill PF rate from saved calc string
            if (e.pf_calc) {
                const pm = e.pf_calc.match(/^(\d+\.?\d*)%/);
                if (pm) { document.getElementById('editPfCalcType').value='percent'; document.getElementById('editPfRate').value=parseFloat(pm[1]); }
                else { const fm=e.pf_calc.match(/Fixed [^\ d]*(\d+\.?\d*)/); if(fm){document.getElementById('editPfCalcType').value='fixed';document.getElementById('editPfRate').value=parseFloat(fm[1]);} }
            }
            if (e.esi_calc) {
                const em = e.esi_calc.match(/^(\d+\.?\d*)%/);
                if (em) { document.getElementById('editEsiCalcType').value='percent'; document.getElementById('editEsiRate').value=parseFloat(em[1]); }
                else { const fm=e.esi_calc.match(/Fixed [^\ d]*(\d+\.?\d*)/); if(fm){document.getElementById('editEsiCalcType').value='fixed';document.getElementById('editEsiRate').value=parseFloat(fm[1]);} }
            }
            // Show/hide statutory panel
            showEditStatutoryFields();
            // Populate statutory rows from saved data
            const sRows = document.getElementById('editStatutoryRows');
            sRows.innerHTML = '';
            if (parseFloat(e.pf_monthly||0)>0) {
                sRows.insertAdjacentHTML('beforeend',`<tr class="table-warning">
                    <td>Provident Fund (PF)</td><td class="text-primary small">${e.pf_calc||''}</td>
                    <td>\u20b9 ${fmt(e.pf_monthly)}</td><td>\u20b9 ${fmt(parseFloat(e.pf_monthly||0)*12)}</td></tr>`);
            }
            if (parseFloat(e.esi_monthly||0)>0) {
                sRows.insertAdjacentHTML('beforeend',`<tr class="table-success">
                    <td>ESI</td><td class="text-success small">${e.esi_calc||''}</td>
                    <td>\u20b9 ${fmt(e.esi_monthly)}</td><td>\u20b9 ${fmt(parseFloat(e.esi_monthly||0)*12)}</td></tr>`);
            }
            // Custom components
            const cBody = document.getElementById('editCustomEarningsRows');
            cBody.innerHTML = '';
            try { JSON.parse(e.custom_components||'[]').forEach(c=>addEditCustomComponentWithData(c.name,c.monthly)); } catch(ex){}
            updateEditCTCTotal();
        }

        function calcEditSalary() {
            const b = parseFloat(document.getElementById('editSalBasicM').value)||0;
            const a = parseFloat(document.getElementById('editSalAllowM').value)||0;
            const fmt = n => (n*12).toLocaleString('en-IN');
            document.getElementById('editSalBasicY').value = fmt(b);
            document.getElementById('editSalAllowY').value = fmt(a);
            recalcEditStatutory(); updateEditCTCTotal();
        }

        function showEditStatutoryFields() {
            const val=document.getElementById('editSalStatutory').value;
            const p=document.getElementById('editStatutoryPanel');
            const pf=document.getElementById('editPfFields');
            const es=document.getElementById('editEsiFields');
            if(!val){p.style.display='none';pf.style.display='none';es.style.display='none';return;}
            p.style.display='block';
            pf.style.display=(val==='PF Only'||val==='PF+ESI')?'flex':'none';
            es.style.display=(val==='ESI Only'||val==='PF+ESI')?'flex':'none';
            recalcEditStatutory();
        }

        function recalcEditStatutory() {
            const b=parseFloat(document.getElementById('editSalBasicM').value)||0;
            const a=parseFloat(document.getElementById('editSalAllowM').value)||0;
            const g=b+a;
            const pfT=document.getElementById('editPfCalcType').value;
            const pfR=parseFloat(document.getElementById('editPfRate').value)||0;
            const pfA=pfT==='percent'?(b*pfR/100):pfR;
            document.getElementById('editPfRateLabel').textContent=pfT==='percent'?'PF Rate (%)':'PF Fixed (\u20b9)';
            document.getElementById('editPfRateUnit').textContent=pfT==='percent'?'%':'\u20b9';
            document.getElementById('editPfMonthly').value=pfA.toFixed(2);
            document.getElementById('editHPfMonthly').value=pfA.toFixed(2);
            document.getElementById('editHPfCalc').value=pfT==='percent'?pfR+'% of Basic':'Fixed \u20b9'+pfR;
            const esT=document.getElementById('editEsiCalcType').value;
            const esR=parseFloat(document.getElementById('editEsiRate').value)||0;
            const esA=esT==='percent'?(g*esR/100):esR;
            document.getElementById('editEsiRateLabel').textContent=esT==='percent'?'ESI Rate (%)':'ESI Fixed (\u20b9)';
            document.getElementById('editEsiRateUnit').textContent=esT==='percent'?'%':'\u20b9';
            document.getElementById('editEsiMonthly').value=esA.toFixed(2);
            document.getElementById('editHEsiMonthly').value=esA.toFixed(2);
            document.getElementById('editHEsiCalc').value=esT==='percent'?esR+'% of Gross':'Fixed \u20b9'+esR;
        }

        function addEditStatutoryToTable() {
            const val=document.getElementById('editSalStatutory').value;
            if(!val) return;
            const fmt=n=>parseFloat(n).toLocaleString('en-IN',{minimumFractionDigits:2});
            const sRows=document.getElementById('editStatutoryRows'); sRows.innerHTML='';
            if(val==='PF Only'||val==='PF+ESI'){
                const pfM=parseFloat(document.getElementById('editPfMonthly').value)||0;
                const pfC=document.getElementById('editHPfCalc').value;
                sRows.insertAdjacentHTML('beforeend',`<tr class="table-warning"><td>Provident Fund (PF)</td><td class="text-primary small">${pfC}</td><td>\u20b9 ${fmt(pfM)}</td><td>\u20b9 ${fmt(pfM*12)}</td></tr>`);}
            if(val==='ESI Only'||val==='PF+ESI'){
                const esM=parseFloat(document.getElementById('editEsiMonthly').value)||0;
                const esC=document.getElementById('editHEsiCalc').value;
                sRows.insertAdjacentHTML('beforeend',`<tr class="table-success"><td>ESI</td><td class="text-success small">${esC}</td><td>\u20b9 ${fmt(esM)}</td><td>\u20b9 ${fmt(esM*12)}</td></tr>`);}
            updateEditCTCTotal();
        }

        function updateEditCTCTotal() {
            const b=parseFloat(document.getElementById('editSalBasicM').value)||0;
            const a=parseFloat(document.getElementById('editSalAllowM').value)||0;
            const pf=parseFloat(document.getElementById('editHPfMonthly').value)||0;
            const es=parseFloat(document.getElementById('editHEsiMonthly').value)||0;
            let cust=0; document.querySelectorAll('.edit-custom-comp-monthly').forEach(i=>cust+=parseFloat(i.value)||0);
            const tot=b+a+pf+es+cust;
            const fmt=n=>n.toLocaleString('en-IN',{minimumFractionDigits:2});
            document.getElementById('editSalTotalM').textContent='\u20b9 '+fmt(tot);
            document.getElementById('editSalTotalY').textContent='\u20b9 '+fmt(tot*12);
            document.getElementById('editHSalCTC').value=tot.toFixed(2);
        }

        function addEditCustomComponentWithData(name, amount) {
            const tbody=document.getElementById('editCustomEarningsRows');
            const uid=Date.now()+Math.random();
            const m=parseFloat(amount)||0;
            const y=(m*12).toLocaleString('en-IN',{minimumFractionDigits:2});
            tbody.insertAdjacentHTML('beforeend',`<tr class="custom-earning-row" id="ecr_${uid}">
                <td><input type="text" name="edit_custom_comp_name[]" class="form-control form-control-sm" value="${name||''}" placeholder="e.g. HRA, TA..."></td>
                <td><span class="text-muted small">Fixed Amount</span></td>
                <td><div class="input-group input-group-sm"><span class="input-group-text">\u20b9</span>
                    <input type="number" name="edit_custom_comp_amount[]" class="form-control edit-custom-comp-monthly" value="${m||''}" placeholder="0.00" min="0" step="0.01" oninput="calcEditCustomRow(this)"></div></td>
                <td><div class="d-flex align-items-center gap-1">
                    <div class="input-group input-group-sm flex-grow-1"><span class="input-group-text">\u20b9</span>
                        <input type="text" class="form-control bg-light edit-custom-comp-yearly" value="${y}" readonly></div>
                    <button type="button" class="btn btn-danger btn-sm" style="min-width:30px;" onclick="removeEditCustomComponent(this)">&#x2715;</button>
                </div></td></tr>`);
        }
        function addEditCustomComponent() { addEditCustomComponentWithData('',0); }
        function calcEditCustomRow(inp) {
            const m=parseFloat(inp.value)||0;
            inp.closest('tr').querySelector('.edit-custom-comp-yearly').value=(m*12).toLocaleString('en-IN',{minimumFractionDigits:2});
            updateEditCTCTotal();
        }
        function removeEditCustomComponent(btn) { btn.closest('tr').remove(); updateEditCTCTotal(); }

        // ── View Employee Modal ──
        function openViewModal(empId) {
            Swal.fire({ title: 'Loading...', html: 'Fetching details...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            fetch('employees.php?fetch_employee=' + empId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                Swal.close();
                if (!data.success) { Swal.fire('Error', data.error || 'Not found', 'error'); return; }
                const e = data.employee;
                const fmt = n => parseFloat(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });

                // Basic info
                document.getElementById('viewName').textContent      = e.name || '-';
                document.getElementById('viewEmpId').textContent     = e.employee_id || '-';
                document.getElementById('viewSex').textContent       = e.sex || '-';
                document.getElementById('viewEmail').textContent     = e.email || '-';
                document.getElementById('viewPhone').textContent     = e.phone || '-';
                document.getElementById('viewDept').textContent      = e.department || '-';
                document.getElementById('viewCompany').textContent   = e.company || '-';
                document.getElementById('viewShift').textContent     = e.shift_time || '-';
                document.getElementById('viewLocation').textContent  = e.location || '-';
                document.getElementById('viewJoining').textContent   = e.date_of_joining || '-';
                document.getElementById('viewWeekOff').textContent   = e.week_off || '-';
                document.getElementById('viewAadhar').textContent    = e.aadhar_number || '-';
                document.getElementById('viewPan').textContent       = e.pan_number || '-';
                document.getElementById('viewAltPhone').textContent  = e.alternate_number || '-';
                document.getElementById('viewBankAccount').textContent = e.bank_account_number || '-';
                document.getElementById('viewIfsc').textContent      = e.bank_ifsc_code || '-';
                document.getElementById('viewAddress').textContent   = e.address || '-';
                const viewPhotoImg = document.getElementById('viewPhotoImg');
                if (e.photo_url) {
                    viewPhotoImg.src = e.photo_url;
                    viewPhotoImg.style.display = 'inline-block';
                    document.getElementById('viewPhotoPlaceholder').style.display = 'none';
                } else {
                    viewPhotoImg.style.display = 'none';
                    document.getElementById('viewPhotoPlaceholder').style.display = 'block';
                }
                const statusEl = document.getElementById('viewStatus');
                statusEl.textContent  = e.status || '-';
                statusEl.className    = 'badge ' + (e.status === 'Working' ? 'bg-success' : 'bg-danger');
                const exitRow = document.getElementById('viewExitRow');
                exitRow.style.display = (e.status === 'Resign' && e.date_of_exit) ? '' : 'none';
                document.getElementById('viewExit').textContent = e.date_of_exit || '-';

                // Salary
                document.getElementById('viewSalTemplate').textContent  = e.sal_template || '-';
                document.getElementById('viewSalCycle').textContent     = e.effective_cycle || '-';
                document.getElementById('viewSalStatutory').textContent = e.statutory_component || 'None';
                document.getElementById('viewBasicM').textContent = '\u20b9 ' + fmt(e.basic_monthly);
                document.getElementById('viewBasicY').textContent = '\u20b9 ' + fmt(parseFloat(e.basic_monthly || 0) * 12);
                document.getElementById('viewAllowM').textContent = '\u20b9 ' + fmt(e.special_allowance_monthly);
                document.getElementById('viewAllowY').textContent = '\u20b9 ' + fmt(parseFloat(e.special_allowance_monthly || 0) * 12);

                // PF row
                const pfRow = document.getElementById('viewPfRow');
                if (parseFloat(e.pf_monthly || 0) > 0) {
                    pfRow.style.display = '';
                    document.getElementById('viewPfCalc').textContent = e.pf_calc || '';
                    document.getElementById('viewPfM').textContent    = '\u20b9 ' + fmt(e.pf_monthly);
                    document.getElementById('viewPfY').textContent    = '\u20b9 ' + fmt(parseFloat(e.pf_monthly || 0) * 12);
                } else { pfRow.style.display = 'none'; }

                // ESI row
                const esiRow = document.getElementById('viewEsiRow');
                if (parseFloat(e.esi_monthly || 0) > 0) {
                    esiRow.style.display = '';
                    document.getElementById('viewEsiCalc').textContent = e.esi_calc || '';
                    document.getElementById('viewEsiM').textContent    = '\u20b9 ' + fmt(e.esi_monthly);
                    document.getElementById('viewEsiY').textContent    = '\u20b9 ' + fmt(parseFloat(e.esi_monthly || 0) * 12);
                } else { esiRow.style.display = 'none'; }

                // Custom components
                const custBody = document.getElementById('viewCustomRows');
                custBody.innerHTML = '';
                try {
                    const comps = JSON.parse(e.custom_components || '[]');
                    comps.forEach(c => {
                        custBody.insertAdjacentHTML('beforeend',
                            `<tr><td>${c.name}</td><td class="text-muted small">Fixed Amount</td><td>\u20b9 ${fmt(c.monthly)}</td><td>\u20b9 ${fmt(c.yearly)}</td></tr>`);
                    });
                } catch(ex) {}

                // Total CTC
                document.getElementById('viewCtcM').textContent = '\u20b9 ' + fmt(e.salary_ctc);
                document.getElementById('viewCtcY').textContent = '\u20b9 ' + fmt(parseFloat(e.salary_ctc || 0) * 12);

                new bootstrap.Modal(document.getElementById('viewEmployeeModal')).show();
            })
            .catch(err => Swal.fire('Error', 'Failed: ' + err.message, 'error'));
        }

        function toggleForm() {
            const form = document.getElementById('addForm');
            const btn = document.getElementById('toggleBtn');
            
            if (form.style.display === 'none') {
                form.style.display = 'block';
                btn.textContent = '✕ Close Form';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-danger');
            } else {
                form.style.display = 'none';
                btn.textContent = '➕ Add New Employee';
                btn.classList.remove('btn-danger');
                btn.classList.add('btn-primary');
            }
        }
        
        function togglePasswordField(fieldId) {
            const field = document.getElementById(fieldId);
            const btn = event.target.closest('.toggle-password-btn');
            const icon = btn.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        function confirmDeleteEmployee(employeeId) {
            Swal.fire({
                title: 'Are you sure?',
                text: 'This employee and all their attendance records will be deleted permanently!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?delete_employee=' + employeeId;
                }
            });
        }
        
        <?php
        if ($message) {
            $icon = 'success';
            $title = 'Success';
            if ($message_type === 'danger') {
                $icon = 'error';
                $title = 'Error';
            } elseif ($message_type === 'warning') {
                $icon = 'warning';
                $title = 'Warning';
            }
            $message_escaped = addslashes($message);
            $then_reload = ($icon === 'success') ? ".then(()=>{ location.reload(); })" : "";
            echo "Swal.fire({
                icon: '$icon',
                title: '$title',
                text: '$message_escaped',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK'
            })$then_reload;";
        }
        ?>

        // Search and Filter functionality
        document.getElementById('searchInput').addEventListener('keyup', filterTable);
        document.getElementById('employeeIdFilter').addEventListener('change', filterTable);
        document.getElementById('locationFilter').addEventListener('change', filterTable);
        document.getElementById('departmentFilter').addEventListener('change', filterTable);

        function filterTable() {
            const searchInput = document.getElementById('searchInput').value.toLowerCase();
            const employeeIdSort = document.getElementById('employeeIdFilter').value;
            const locationFilter = document.getElementById('locationFilter').value.toLowerCase();
            const departmentFilter = document.getElementById('departmentFilter').value.toLowerCase();
            const table = document.getElementById('employeesTable');
            const tbody = table.getElementsByTagName('tbody')[0];
            let rows = Array.from(tbody.getElementsByTagName('tr'));

            // Filter rows based on search and other filters
            rows = rows.filter(row => {
                const cells = row.getElementsByTagName('td');
                const name = cells[1].textContent.toLowerCase();
                const email = cells[2].textContent.toLowerCase();
                const employeeId = cells[3].textContent.toLowerCase();
                const phone = cells[5].textContent.toLowerCase();
                const department = cells[6].textContent.toLowerCase();
                const location = (row.dataset.location || '').toLowerCase();

                const matchesSearch = searchInput === '' ||
                    name.includes(searchInput) ||
                    email.includes(searchInput) ||
                    employeeId.includes(searchInput) ||
                    phone.includes(searchInput);

                const matchesLocation = locationFilter === '' ||
                    location === locationFilter;

                const matchesDepartment = departmentFilter === '' ||
                    department === departmentFilter;

                return matchesSearch && matchesLocation && matchesDepartment;
            });

            // Sort by Employee ID if selected, otherwise sort by Name (A-Z)
            if (employeeIdSort === 'asc' || employeeIdSort === 'desc') {
                // Sort by Employee ID
                rows.sort((a, b) => {
                    const cellsA = a.getElementsByTagName('td');
                    const cellsB = b.getElementsByTagName('td');
                    
                    // Extract numeric part from employee ID
                    const idA = parseInt(cellsA[3].textContent.replace(/\D/g, '')) || 0;
                    const idB = parseInt(cellsB[3].textContent.replace(/\D/g, '')) || 0;
                    
                    return employeeIdSort === 'asc' ? idA - idB : idB - idA;
                });
            } else {
                // Default: Sort by Name A-Z
                rows.sort((a, b) => {
                    const cellsA = a.getElementsByTagName('td');
                    const cellsB = b.getElementsByTagName('td');
                    
                    const nameA = cellsA[1].textContent.toLowerCase();
                    const nameB = cellsB[1].textContent.toLowerCase();
                    
                    return nameA.localeCompare(nameB);
                });
            }

            // Clear tbody and re-append sorted rows
            const allRows = Array.from(tbody.getElementsByTagName('tr'));
            allRows.forEach(row => row.style.display = 'none');

            rows.forEach(row => {
                row.style.display = '';
                tbody.appendChild(row);
            });
        }

        // Export employees with optional filters
        function exportEmployees() {
            const department = document.getElementById('departmentFilter').value;
            const location = document.getElementById('locationFilter').value;
            const employeeId = document.getElementById('employeeIdFilter').value;

            let url = 'export_employees.php?';
            if (department) url += 'department=' + encodeURIComponent(department) + '&';
            if (location) url += 'location=' + encodeURIComponent(location) + '&';
            if (employeeId) url += 'employee_id=' + encodeURIComponent(employeeId);
            
            window.location.href = url;
        }

        // Photo upload modal functions
        function openPhotoUpload(employeeId, employeeName) {
            document.getElementById('photoEmployeeId').value = employeeId;
            document.getElementById('photoEmployeeName').textContent = employeeName;
            const photoModal = new bootstrap.Modal(document.getElementById('photoUploadModal'));
            photoModal.show();
            // Start camera after modal is shown
            setTimeout(startPhotoCamera, 500);
        }

        function uploadEmployeePhoto() {
            const employeeId = document.getElementById('photoEmployeeId').value;
            const canvas = document.getElementById('photoCanvas');
            const video = document.getElementById('cameraVideo');
            
            if (!canvas || !video.srcObject) {
                Swal.fire('Error', 'Camera not active. Please try again', 'error');
                return;
            }

            const ctx = canvas.getContext('2d');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            canvas.toBlob(function(blob) {
                const formData = new FormData();
                formData.append('employee_id', employeeId);
                formData.append('photo', blob, 'camera_capture.jpg');

                Swal.fire({
                    title: 'Uploading...',
                    html: 'Processing captured photo...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                fetch('../api/upload_employee_photo.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Stop camera
                        if (video.srcObject) {
                            video.srcObject.getTracks().forEach(track => track.stop());
                        }
                        
                        // Close the modal and show success
                        const photoModal = bootstrap.Modal.getInstance(document.getElementById('photoUploadModal'));
                        photoModal.hide();
                        
                        // Clean up backdrop
                        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
                        document.body.classList.remove('modal-open');
                        
                        // Show success message
                        Swal.fire('Success!', 'Photo captured successfully! The face descriptor will be extracted when the employee opens the face attendance app.', 'success');
                    } else {
                        // Keep modal open, show error
                        Swal.fire('Error', data.error || data.message || 'Failed to upload photo', 'error');
                    }
                })
                .catch(error => {
                    console.error('Upload error:', error);
                    Swal.fire('Error', 'Upload failed: ' + error.message, 'error');
                });
            }, 'image/jpeg', 0.95);
        }

        function deleteEmployeePhoto(employeeId, employeeName) {
            Swal.fire({
                title: 'Delete Photo?',
                text: `Are you sure you want to delete ${employeeName}'s photo?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Deleting...',
                        html: 'Please wait...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    // Send delete request
                    fetch('../api/delete_employee_photo.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            employee_id: employeeId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Deleted!', 'Photo deleted successfully.', 'success').then(() => {
                                // Reload the page
                                location.reload();
                            });
                        } else {
                            Swal.fire('Error', data.message || 'Failed to delete photo', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Delete error:', error);
                        Swal.fire('Error', 'Delete failed: ' + error.message, 'error');
                    });
                }
            });
        }

        // Show Photo Modal
        function showPhotoModal(photoUrl, employeeName) {
            Swal.fire({
                title: employeeName + "'s Photo",
                imageUrl: photoUrl,
                imageWidth: 400,
                imageHeight: 400,
                imageAlt: 'Employee Photo',
                showCloseButton: true,
                confirmButtonText: 'Close',
                didOpen: () => {
                    const swalImage = document.querySelector('.swal2-image');
                    if (swalImage) {
                        swalImage.style.objectFit = 'cover';
                        swalImage.style.borderRadius = '10px';
                    }
                }
            });
        }

        function startPhotoCamera() {
            const video = document.getElementById('cameraVideo');
            
            if (video.srcObject) {
                // Camera already running
                return;
            }

            navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'user',
                    width: { ideal: 640 },
                    height: { ideal: 480 }
                }
            })
            .then(stream => {
                video.srcObject = stream;
                video.onloadedmetadata = () => {
                    video.play();
                };
            })
            .catch(err => {
                Swal.fire('Camera Error', 'Cannot access camera: ' + err.message, 'error');
            });
        }

        function stopPhotoCamera() {
            const video = document.getElementById('cameraVideo');
            if (video.srcObject) {
                video.srcObject.getTracks().forEach(track => track.stop());
            }
        }

        // Stop camera when modal is closed
        document.getElementById('photoUploadModal')?.addEventListener('hide.bs.modal', stopPhotoCamera);

        // Toggle Date of Exit field based on Status selection
        function toggleExitDateField(selectElement, formType) {
            const exitDateField = document.getElementById(formType + 'ExitDateField');
            const exitDateInput = document.querySelector('.' + formType + '-exit-date');
            const status = selectElement.value;

            if (status === 'Resign') {
                // Show exit date field and make it required
                exitDateField.style.display = 'block';
                exitDateInput.setAttribute('required', 'required');
            } else {
                // Hide exit date field, remove required, and clear value
                exitDateField.style.display = 'none';
                exitDateInput.removeAttribute('required');
                exitDateInput.value = '';
            }
        }

        // Initialize on form load if in edit mode with Resign status
        document.addEventListener('DOMContentLoaded', function() {
            // Check if we're on the edit form
            const editStatusSelect = document.querySelector('.edit-status-select');
            if (editStatusSelect && editStatusSelect.value === 'Resign') {
                // Already handled by PHP display, but ensure input is marked required
                const exitDateInput = document.querySelector('.edit-exit-date');
                if (exitDateInput) {
                    exitDateInput.setAttribute('required', 'required');
                }
            }

            // Populate select dropdown values for modal
            populateModalSelects();
        });

        // Populate dropdown options in the modal
        function populateModalSelects() {
            const departments = <?php echo json_encode($departments); ?>;
            const companies = <?php echo json_encode(array_column($companies, 'name')); ?>;
            const shifts = <?php echo json_encode(array_column($shifts, 'display_name')); ?>;
            const locations = <?php echo json_encode(array_column($locations, 'name')); ?>;

            // Fill Department dropdown
            const deptSelect = document.getElementById('modalDepartment');
            departments.forEach(dept => {
                const option = document.createElement('option');
                option.value = dept;
                option.textContent = dept;
                deptSelect.appendChild(option);
            });

            // Fill Company dropdown
            const compSelect = document.getElementById('modalCompany');
            companies.forEach(comp => {
                const option = document.createElement('option');
                option.value = comp;
                option.textContent = comp;
                compSelect.appendChild(option);
            });

            // Fill Shift Time dropdown
            const shiftSelect = document.getElementById('modalShiftTime');
            shifts.forEach(shift => {
                const option = document.createElement('option');
                option.value = shift;
                option.textContent = shift;
                shiftSelect.appendChild(option);
            });

            // Fill Location dropdown
            const locSelect = document.getElementById('modalLocation');
            locations.forEach(loc => {
                const option = document.createElement('option');
                option.value = loc;
                option.textContent = loc;
                locSelect.appendChild(option);
            });
        }

        // Open Edit Modal from table row - fetch employee data via AJAX
        function openEditModalFromTable(empId) {
            Swal.fire({
                title: 'Loading...',
                html: 'Fetching employee details...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // Fetch employee data
            fetch('employees.php?fetch_employee=' + empId, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                if (data.success && data.employee) {
                    openEditModal(data.employee);
                } else {
                    Swal.fire('Error', data.error || 'Failed to fetch employee data', 'error');
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                Swal.fire('Error', 'Failed to fetch employee: ' + error.message, 'error');
            });
        }

        // Open Edit Modal and populate with employee data
        function openEditModal(empData) {
            // Populate form fields
            document.getElementById('modalEmpId').value = empData.id;
            document.getElementById('modalEmployeeId').value = empData.employee_id;
            document.getElementById('modalName').value = empData.name;
            document.getElementById('modalEmail').value = empData.email;
            document.getElementById('modalPhone').value = empData.phone;
            document.getElementById('modalSex').value = empData.sex;
            document.getElementById('modalDepartment').value = empData.department;
            document.getElementById('modalCompany').value = empData.company;
            document.getElementById('modalShiftTime').value = empData.shift_time;
            document.getElementById('modalLocation').value = empData.location;
            document.getElementById('modalDateOfJoining').value = empData.date_of_joining;
            document.getElementById('modalStatus').value = empData.status;
            document.getElementById('modalDateOfExit').value = empData.date_of_exit || '';
            document.getElementById('modalWeekOff').value = empData.week_off;
            document.getElementById('modalAadhar').value = empData.aadhar_number || '';
            document.getElementById('modalPan').value = empData.pan_number || '';
            document.getElementById('modalAltPhone').value = empData.alternate_number || '';
            document.getElementById('modalAddress').value = empData.address || '';
            document.getElementById('modalBankAccount').value = empData.bank_account_number || '';
            document.getElementById('modalIfsc').value = empData.bank_ifsc_code || '';

            // Reset file input and show existing photo (if any) as the preview
            document.getElementById('editProfilePhotoInput').value = '';
            const editImg = document.getElementById('editPhotoPreviewImg');
            const editPlaceholder = document.getElementById('editPhotoPlaceholder');
            const editReuploadBtn = document.getElementById('editReuploadBtn');
            if (empData.photo_url) {
                editImg.src = empData.photo_url;
                editImg.style.display = 'inline-block';
                editPlaceholder.style.display = 'none';
                editReuploadBtn.classList.remove('d-none');
            } else {
                editImg.style.display = 'none';
                editPlaceholder.style.display = 'block';
                editReuploadBtn.classList.add('d-none');
            }

            // Toggle exit date field based on status
            if (empData.status === 'Resign') {
                document.getElementById('modalExitDateField').style.display = 'block';
                document.getElementById('modalDateOfExit').setAttribute('required', 'required');
            } else {
                document.getElementById('modalExitDateField').style.display = 'none';
                document.getElementById('modalDateOfExit').removeAttribute('required');
            }

            // Open modal
            const modal = new bootstrap.Modal(document.getElementById('editEmployeeModal'));
            modal.show();
            // Populate salary section
            populateEditSalary(empData);
        }

        // Toggle Date of Exit field in modal
        function toggleExitDateFieldModal(selectElement) {
            const exitDateField = document.getElementById('modalExitDateField');
            const exitDateInput = document.getElementById('modalDateOfExit');
            const status = selectElement.value;

            if (status === 'Resign') {
                exitDateField.style.display = 'block';
                exitDateInput.setAttribute('required', 'required');
            } else {
                exitDateField.style.display = 'none';
                exitDateInput.removeAttribute('required');
                exitDateInput.value = '';
            }
        }

        // Submit the edit form
        function submitEditForm() {
            const form = document.getElementById('editEmployeeForm');
            
            // Validate form
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return;
            }

            // Show loading
            Swal.fire({
                title: 'Updating...',
                html: 'Please wait while employee is being updated...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // Submit form via AJAX
            const formData = new FormData(form);
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // Check if update was successful by looking for the success message in response
                if (data.includes('Employee updated successfully')) {
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('editEmployeeModal'));
                    modal.hide();

                    // Show success message
                    Swal.fire('Success!', '✓ Employee updated successfully!', 'success').then(() => {
                        // Reload the page to refresh employee details
                        location.reload();
                    });
                } else if (data.includes('All mandatory fields are required') || data.includes('Invalid email format') || data.includes('Date of Exit is required')) {
                    // Extract error message from response
                    const errorMatch = data.match(/<p[^>]*>([\s\S]*?)<\/p>/);
                    const errorMsg = errorMatch ? errorMatch[1] : 'Validation error occurred';
                    Swal.fire('Error', errorMsg, 'error');
                } else {
                    Swal.fire('Error', 'Failed to update employee. Please try again.', 'error');
                }
            })
            .catch(error => {
                console.error('Submit error:', error);
                Swal.fire('Error', 'Submit failed: ' + error.message, 'error');
            });
        }
    </script>

    <!-- View Employee Modal -->
    <div class="modal fade" id="viewEmployeeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">👤 Employee Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Basic Info -->
                    <div class="row g-3 mb-3">
                        <div class="col-md-2 text-center">
                            <img id="viewPhotoImg" src="" alt="Profile Photo" style="display:none; width:100%; max-width:120px; aspect-ratio:1/1; border-radius:8px; object-fit:cover; border:2px solid #17a2b8;">
                            <div id="viewPhotoPlaceholder" style="border:2px dashed #ccc; border-radius:8px; padding:15px 5px; background:#f8f9fa;">
                                <i class="fas fa-image" style="font-size:28px; color:#ccc;"></i>
                                <p class="text-muted small mb-0">No photo</p>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <table class="table table-sm table-borderless mb-0">
                                <tr><th width="42%">Name</th><td id="viewName">-</td></tr>
                                <tr><th>Employee ID</th><td id="viewEmpId">-</td></tr>
                                <tr><th>Gender</th><td id="viewSex">-</td></tr>
                                <tr><th>Email</th><td id="viewEmail">-</td></tr>
                                <tr><th>Phone</th><td id="viewPhone">-</td></tr>
                                <tr><th>Project</th><td id="viewDept">-</td></tr>
                            </table>
                        </div>
                        <div class="col-md-5">
                            <table class="table table-sm table-borderless mb-0">
                                <tr><th width="42%">Company</th><td id="viewCompany">-</td></tr>
                                <tr><th>Shift Time</th><td id="viewShift">-</td></tr>
                                <tr><th>Location</th><td id="viewLocation">-</td></tr>
                                <tr><th>Date of Joining</th><td id="viewJoining">-</td></tr>
                                <tr><th>Status</th><td><span id="viewStatus" class="badge bg-success">-</span></td></tr>
                                <tr id="viewExitRow" style="display:none;"><th>Date of Exit</th><td id="viewExit">-</td></tr>
                                <tr><th>Week Off</th><td><span id="viewWeekOff" class="badge bg-info">-</span></td></tr>
                            </table>
                        </div>
                    </div>
                    <hr class="my-3">
                    <!-- Account Details -->
                    <h6 class="fw-bold mb-3">🪪 Account Details</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><strong>Aadhar Number:</strong> <span id="viewAadhar">-</span></div>
                        <div class="col-md-4"><strong>PAN Number:</strong> <span id="viewPan">-</span></div>
                        <div class="col-md-4"><strong>Alternative Number:</strong> <span id="viewAltPhone">-</span></div>
                        <div class="col-md-6"><strong>Bank Account Number:</strong> <span id="viewBankAccount">-</span></div>
                        <div class="col-md-6"><strong>IFSC Code:</strong> <span id="viewIfsc">-</span></div>
                        <div class="col-md-12"><strong>Address:</strong> <span id="viewAddress">-</span></div>
                    </div>
                    <hr class="my-3">
                    <!-- Salary Structure -->
                    <h6 class="fw-bold mb-3">💰 Salary Structure</h6>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><strong>Template:</strong> <span id="viewSalTemplate">-</span></div>
                        <div class="col-md-4"><strong>Effective Cycle:</strong> <span id="viewSalCycle">-</span></div>
                        <div class="col-md-4"><strong>Statutory:</strong> <span id="viewSalStatutory">-</span></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle">
                            <thead class="table-light">
                                <tr><th>COMPONENTS</th><th>CALCULATION</th><th>MONTHLY AMOUNT</th><th>YEARLY AMOUNT</th></tr>
                            </thead>
                            <tbody>
                                <tr class="table-light"><td colspan="4"><strong>Earnings</strong></td></tr>
                                <tr>
                                    <td>Basic</td><td class="text-muted small">Fixed Amount</td>
                                    <td id="viewBasicM">₹ 0.00</td><td id="viewBasicY">₹ 0.00</td>
                                </tr>
                                <tr>
                                    <td>Special Allowance</td><td class="text-muted small">Fixed Amount</td>
                                    <td id="viewAllowM">₹ 0.00</td><td id="viewAllowY">₹ 0.00</td>
                                </tr>
                                <tr id="viewPfRow">
                                    <td>Provident Fund (PF)</td>
                                    <td class="text-primary small" id="viewPfCalc">-</td>
                                    <td id="viewPfM">₹ 0.00</td><td id="viewPfY">₹ 0.00</td>
                                </tr>
                                <tr id="viewEsiRow">
                                    <td>Employee State Insurance (ESI)</td>
                                    <td class="text-success small" id="viewEsiCalc">-</td>
                                    <td id="viewEsiM">₹ 0.00</td><td id="viewEsiY">₹ 0.00</td>
                                </tr>
                                <tbody id="viewCustomRows"></tbody>
                                <tr class="fw-bold table-secondary">
                                    <td colspan="2" class="text-end">Total CTC</td>
                                    <td id="viewCtcM">₹ 0.00</td><td id="viewCtcY">₹ 0.00</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Photo Upload Modal -->
    <div class="modal fade" id="photoUploadModal" tabindex="-1" aria-labelledby="photoUploadModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="photoUploadModalLabel">📸 Capture Employee Photo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><strong>Employee:</strong></label>
                        <p id="photoEmployeeName" class="form-control-plaintext" style="font-weight: bold;"></p>
                    </div>

                    <!-- Camera Capture Section -->
                    <div>
                        <div class="mb-3">
                            <label class="form-label"><strong>Camera Preview</strong></label>
                            <div class="position-relative" style="max-width: 100%; background: #000; border-radius: 8px; overflow: hidden;">
                                <video id="cameraVideo" style="width: 100%; height: 400px; object-fit: cover; display: block;" playsinline></video>
                                <canvas id="photoCanvas" style="display: none;"></canvas>
                            </div>
                            <small class="text-muted d-block mt-2">✅ Look directly at the camera for best results. Your photo will be captured when you click "Capture Photo".</small>
                        </div>
                    </div>

                    <input type="hidden" id="photoEmployeeId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-info" onclick="uploadEmployeePhoto()">
                        <i class="fas fa-camera"></i> Capture Photo
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Employee Modal -->
    <div class="modal fade" id="editEmployeeModal" tabindex="-1" aria-labelledby="editEmployeeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editEmployeeModalLabel">✎ Edit Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editEmployeeForm" method="POST" class="row g-3" enctype="multipart/form-data">
                        <input type="hidden" name="emp_id" id="modalEmpId">
                        <input type="hidden" name="update_employee" value="1">
                        
                        <div class="col-md-4">
                            <label class="form-label">Employee ID</label>
                            <input type="text" name="employee_id" id="modalEmployeeId" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" id="modalName" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="modalEmail" class="form-control">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" id="modalPhone" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Alternative Number</label>
                            <input type="tel" name="alternate_number" id="modalAltPhone" class="form-control" placeholder="10-digit alternate number"
                                maxlength="10" pattern="[0-9]{10}"
                                oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,10);"
                                title="Alternative number must be exactly 10 digits">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gender</label>
                            <select name="sex" id="modalSex" class="form-control" required>
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Project</label>
                            <select name="department" id="modalDepartment" class="form-control" required>
                                <option value="">Select Project</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Company</label>
                            <select name="company" id="modalCompany" class="form-control" required>
                                <option value="">Select Company</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Aadhar Number</label>
                            <input type="text" name="aadhar_number" id="modalAadhar" class="form-control" placeholder="12-digit Aadhar number"
                                maxlength="12" pattern="[0-9]{12}"
                                oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,12);"
                                title="Aadhar number must be exactly 12 digits">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">PAN Number</label>
                            <input type="text" name="pan_number" id="modalPan" class="form-control" placeholder="e.g., ABCDE1234F"
                                maxlength="10" pattern="[A-Za-z]{5}[0-9]{4}[A-Za-z]{1}"
                                oninput="this.value=this.value.toUpperCase().slice(0,10);"
                                title="PAN format: 5 letters, 4 digits, 1 letter">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Shift Time</label>
                            <select name="shift_time" id="modalShiftTime" class="form-control" required>
                                <option value="">Select Shift</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Location</label>
                            <select name="location" id="modalLocation" class="form-control" required>
                                <option value="">Select Location</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Date of Joining</label>
                            <input type="date" name="date_of_joining" id="modalDateOfJoining" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" id="modalStatus" class="form-control modal-status-select" required onchange="toggleExitDateFieldModal(this)">
                                <option value="">Select Status</option>
                                <option value="Working">Working</option>
                                <option value="Resign">Resign</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="modalExitDateField" style="display:none;">
                            <label class="form-label">Date of Exit <span class="text-danger">*</span></label>
                            <input type="date" name="date_of_exit" id="modalDateOfExit" class="form-control modal-exit-date">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Week Off</label>
                            <select name="week_off" id="modalWeekOff" class="form-control" required>
                                <option value="">Select Day</option>
                                <option value="Monday">Monday</option>
                                <option value="Tuesday">Tuesday</option>
                                <option value="Wednesday">Wednesday</option>
                                <option value="Thursday">Thursday</option>
                                <option value="Friday">Friday</option>
                                <option value="Saturday">Saturday</option>
                                <option value="Sunday">Sunday</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Address</label>
                            <textarea name="address" id="modalAddress" class="form-control" rows="2" placeholder="Enter residential address"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label d-block">Profile Photo</label>
                            <div id="editPhotoDropZone" class="text-center" style="border:2px dashed #ccc; border-radius:10px; padding:12px; background:#f8f9fa; cursor:pointer;" onclick="document.getElementById('editProfilePhotoInput').click();">
                                <img id="editPhotoPreviewImg" src="" alt="Profile Preview" style="display:none; max-width:120px; max-height:120px; border-radius:8px; object-fit:cover;">
                                <div id="editPhotoPlaceholder">
                                    <i class="fas fa-image" style="font-size:32px; color:#ccc;"></i>
                                    <p class="text-muted small mb-0 mt-1">Click to upload from gallery</p>
                                    <p class="text-muted small mb-0">(Max size 50KB)</p>
                                </div>
                            </div>
                            <input type="file" id="editProfilePhotoInput" name="profile_photo" accept="image/*" class="d-none" onchange="previewProfilePhoto(this, 'edit')">
                            <button type="button" id="editReuploadBtn" class="btn btn-sm btn-outline-secondary mt-2 d-none w-100" onclick="document.getElementById('editProfilePhotoInput').click();">
                                🔄 Reupload Photo
                            </button>
                        </div>

                        <!-- ── Account Details (Edit) ── -->
                        <div class="col-12 mt-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header" style="background:#f8f9fa;border-bottom:2px solid #e9ecef;">
                                    <h6 class="mb-0 fw-bold">🪪 Account Details</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Bank Account Number</label>
                                            <input type="text" name="bank_account_number" id="modalBankAccount" class="form-control" placeholder="Enter bank account number"
                                                maxlength="30" pattern="[0-9]{6,30}"
                                                oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,30);"
                                                title="Bank account number should contain digits only">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">IFSC Code</label>
                                            <input type="text" name="bank_ifsc_code" id="modalIfsc" class="form-control" placeholder="e.g., SBIN0001234"
                                                maxlength="11" pattern="[A-Za-z]{4}0[A-Za-z0-9]{6}"
                                                oninput="this.value=this.value.toUpperCase().slice(0,11);"
                                                title="IFSC format: 4 letters, 0, 6 alphanumeric characters">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ── Salary Structure (Edit) ── -->
                        <div class="col-12 mt-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header" style="background:#f8f9fa;border-bottom:2px solid #e9ecef;">
                                    <h6 class="mb-0 fw-bold">💰 Salary Structure</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Template</label>
                                            <select name="edit_sal_template" id="editSalTemplate" class="form-select form-select-sm">
                                                <option value="Monthly">Monthly</option>
                                                <option value="Weekly">Weekly</option>
                                                <option value="Bi-Monthly">Bi-Monthly</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Statutory Component</label>
                                            <select name="edit_sal_statutory" id="editSalStatutory" class="form-select form-select-sm" onchange="showEditStatutoryFields()">
                                                <option value="">None</option>
                                                <option value="PF+ESI">PF + ESI</option>
                                                <option value="PF Only">PF Only</option>
                                                <option value="ESI Only">ESI Only</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold">Effective Cycle</label>
                                            <select name="edit_sal_effective" id="editSalEffective" class="form-select form-select-sm">
                                                <?php for($m=0;$m<12;$m++){$ts=mktime(0,0,0,date('n')+$m,1);$v=date('M Y',$ts);$s=($m===0)?'selected':'';echo "<option value=\"$v\" $s>$v</option>";} ?>
                                            </select>
                                        </div>
                                    </div>
                                    <!-- Edit Statutory Panel -->
                                    <div id="editStatutoryPanel" style="display:none;" class="mb-3">
                                        <div class="card border-warning">
                                            <div class="card-header bg-warning bg-opacity-10 py-2"><strong class="small">⚙️ Configure Statutory Deductions</strong></div>
                                            <div class="card-body py-3">
                                                <div class="row g-3" id="editPfFields" style="display:none;">
                                                    <div class="col-12"><h6 class="text-primary mb-2">🔵 Provident Fund (PF)</h6></div>
                                                    <div class="col-md-4"><label class="form-label small fw-semibold">PF Calculation</label>
                                                        <select id="editPfCalcType" class="form-select form-select-sm" onchange="recalcEditStatutory()">
                                                            <option value="percent">% of Basic</option><option value="fixed">Fixed Amount</option>
                                                        </select></div>
                                                    <div class="col-md-4"><label class="form-label small fw-semibold" id="editPfRateLabel">PF Rate (%)</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" id="editPfRate" class="form-control" value="12" min="0" step="0.01" oninput="recalcEditStatutory()">
                                                            <span class="input-group-text" id="editPfRateUnit">%</span>
                                                        </div></div>
                                                    <div class="col-md-4"><label class="form-label small fw-semibold">PF Monthly (₹)</label>
                                                        <div class="input-group input-group-sm"><span class="input-group-text">₹</span>
                                                            <input type="text" id="editPfMonthly" class="form-control bg-light" readonly placeholder="0.00">
                                                        </div></div>
                                                </div>
                                                <div class="row g-3 mt-1" id="editEsiFields" style="display:none;">
                                                    <div class="col-12"><h6 class="text-success mb-2">🟢 ESI</h6></div>
                                                    <div class="col-md-4"><label class="form-label small fw-semibold">ESI Calculation</label>
                                                        <select id="editEsiCalcType" class="form-select form-select-sm" onchange="recalcEditStatutory()">
                                                            <option value="percent">% of Gross</option><option value="fixed">Fixed Amount</option>
                                                        </select></div>
                                                    <div class="col-md-4"><label class="form-label small fw-semibold" id="editEsiRateLabel">ESI Rate (%)</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" id="editEsiRate" class="form-control" value="0.75" min="0" step="0.01" oninput="recalcEditStatutory()">
                                                            <span class="input-group-text" id="editEsiRateUnit">%</span>
                                                        </div></div>
                                                    <div class="col-md-4"><label class="form-label small fw-semibold">ESI Monthly (₹)</label>
                                                        <div class="input-group input-group-sm"><span class="input-group-text">₹</span>
                                                            <input type="text" id="editEsiMonthly" class="form-control bg-light" readonly placeholder="0.00">
                                                        </div></div>
                                                </div>
                                                <div class="mt-3">
                                                    <button type="button" class="btn btn-primary btn-sm px-4" onclick="addEditStatutoryToTable()">✚ ADD to Components</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Hidden inputs -->
                                    <input type="hidden" name="edit_sal_pf_monthly" id="editHPfMonthly" value="0">
                                    <input type="hidden" name="edit_sal_esi_monthly" id="editHEsiMonthly" value="0">
                                    <input type="hidden" name="edit_sal_pf_calc" id="editHPfCalc" value="">
                                    <input type="hidden" name="edit_sal_esi_calc" id="editHEsiCalc" value="">
                                    <input type="hidden" name="edit_sal_ctc" id="editHSalCTC" value="0">
                                    <!-- Components Table -->
                                    <div class="table-responsive">
                                        <table class="table table-bordered align-middle table-sm">
                                            <thead style="background:#f8f9fa;">
                                                <tr><th>COMPONENTS</th><th>CALCULATION</th><th>MONTHLY</th><th>YEARLY</th></tr>
                                            </thead>
                                            <tbody>
                                                <tr class="table-light"><td colspan="4"><strong>Earnings</strong></td></tr>
                                                <tr>
                                                    <td>Basic</td><td class="text-muted small">Fixed Amount</td>
                                                    <td><div class="input-group input-group-sm"><span class="input-group-text">₹</span>
                                                        <input type="number" name="edit_sal_basic" id="editSalBasicM" class="form-control" placeholder="0.00" min="0" step="0.01" oninput="calcEditSalary()">
                                                    </div></td>
                                                    <td><div class="input-group input-group-sm"><span class="input-group-text">₹</span>
                                                        <input type="text" id="editSalBasicY" class="form-control bg-light" readonly>
                                                    </div></td>
                                                </tr>
                                                <tr>
                                                    <td>Special Allowance</td><td class="text-muted small">Fixed Amount</td>
                                                    <td><div class="input-group input-group-sm"><span class="input-group-text">₹</span>
                                                        <input type="number" name="edit_sal_allowance" id="editSalAllowM" class="form-control" placeholder="0.00" min="0" step="0.01" oninput="calcEditSalary()">
                                                    </div></td>
                                                    <td><div class="input-group input-group-sm"><span class="input-group-text">₹</span>
                                                        <input type="text" id="editSalAllowY" class="form-control bg-light" readonly>
                                                    </div></td>
                                                </tr>
                                                <tbody id="editStatutoryRows"></tbody>
                                                <tbody id="editCustomEarningsRows"></tbody>
                                                <tr>
                                                    <td colspan="4" class="text-start py-2" style="border-top:2px dashed #dee2e6;">
                                                        <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="addEditCustomComponent()">
                                                            <strong>+</strong> Add Earning Component
                                                        </button>
                                                    </td>
                                                </tr>
                                                <tr class="fw-bold table-secondary">
                                                    <td colspan="2" class="text-end">Total CTC</td>
                                                    <td id="editSalTotalM">₹ 0.00</td>
                                                    <td id="editSalTotalY">₹ 0.00</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="submitEditForm()">✓ Update Employee</button>
                </div>
            </div>
        </div>
    </div>

<?php
$stmt->close();
?>
?>

