<?php
session_start();
include('../config/db.php');

// Enable login requirement
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../auth/login.php");
    exit();
}

// Fetch user info
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Profile photo (stored on disk at upload time; DB also keeps a copy in users.profile_photo)
$__photos = glob("../uploads/employee_photos/" . $user_id . ".*");
$user_photo_url = !empty($__photos) ? "../uploads/employee_photos/" . basename($__photos[0]) : '';

// Ensure leave_applications table exists (for KPI stats below)
$conn->query("CREATE TABLE IF NOT EXISTS leave_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    leave_type ENUM('Casual Leave','Sick Leave','Earned Leave','Maternity Leave','Paternity Leave','Unpaid Leave') NOT NULL,
    start_date DATE NOT NULL, end_date DATE NOT NULL, days_count INT NOT NULL DEFAULT 1,
    reason TEXT, status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    admin_notes TEXT, reviewed_by INT, reviewed_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// KPI stats
$__today = date('Y-m-d');
$__ts = $conn->prepare("SELECT status FROM attendance WHERE user_id=? AND date=?");
$__ts->bind_param("is", $user_id, $__today);
$__ts->execute();
$stat_today_status = $__ts->get_result()->fetch_assoc()['status'] ?? 'Not Marked';
$__ts->close();

$__pd = $conn->prepare("SELECT COUNT(*) c FROM attendance WHERE user_id=? AND status IN ('Present','Late') AND MONTH(date)=MONTH(CURDATE()) AND YEAR(date)=YEAR(CURDATE())");
$__pd->bind_param("i", $user_id);
$__pd->execute();
$stat_present_days = (int)($__pd->get_result()->fetch_assoc()['c'] ?? 0);
$__pd->close();

$__pl = $conn->prepare("SELECT COUNT(*) c FROM leave_applications WHERE user_id=? AND status='Pending'");
$__pl->bind_param("i", $user_id);
$__pl->execute();
$stat_pending_leaves = (int)($__pl->get_result()->fetch_assoc()['c'] ?? 0);
$__pl->close();

$__al = $conn->prepare("SELECT COALESCE(SUM(days_count),0) c FROM leave_applications WHERE user_id=? AND status='Approved' AND MONTH(start_date)=MONTH(CURDATE()) AND YEAR(start_date)=YEAR(CURDATE())");
$__al->bind_param("i", $user_id);
$__al->execute();
$stat_leave_days = (int)($__al->get_result()->fetch_assoc()['c'] ?? 0);
$__al->close();

$dm_hour = (int)date('G');
$dm_greeting = $dm_hour < 12 ? 'Good morning' : ($dm_hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <!-- <title class="titel">Employee Dashboard</title> -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard-modern.css">
    <style>
        /* Navbar Styling */
        .navbar-custom {
            position: relative;
        }
        
        .navbar-logo {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            height: 50px;
            z-index: 10;
        }
        
        .navbar-logo img {
            height: 100%;
            width: auto;
            max-width: 200px;
        }
        
        .navbar-welcome {
            position: absolute;
            left: 20px;
            display: flex;
            align-items: center;
            height: 100%;
            color: white;
        }
        
        .navbar-logout {
            margin-left: auto;
            padding-right: 20px;
        }

        .dashboard-card {
            border-left: 5px solid;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
        }
        .card-punch-in {
            border-left-color: #28a745;
        }
        .card-punch-out {
            border-left-color: #dc3545;
        }
        .card-attendance {
            border-left-color: #17a2b8;
        }
        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        .card-links {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .card-links a {
            flex: 1;
            min-width: 100px;
        }

                /* Responsive adjustments */
        @media (max-width: 768px) {
            .navbar-welcome {
                display: none;
            }
            
            .navbar-logo {
                height: 45px;
            }
            
            .navbar-logo img {
                max-width: 150px;
            }
        }
    </style>
</head>
<?php
$__status_icon = ['Present' => '✅', 'Late' => '⏰', 'Leave' => '🗓️', 'Absent' => '❌', 'Not Marked' => '➖'];
$__status_color = ['Present' => 'c-green', 'Late' => 'c-orange', 'Leave' => 'c-purple', 'Absent' => 'c-red', 'Not Marked' => 'c-blue'];
?>
<body class="modern-dash">

<!-- Centered Logo Navbar -->
<nav class="navbar navbar-dark bg-dark navbar-custom" style="min-height: 100px;">
    <div class="container-fluid position-relative">
        <!-- Welcome Text (Left) -->
        <div class="navbar-welcome">
            <?php if ($user_photo_url): ?>
                <img src="<?php echo htmlspecialchars($user_photo_url); ?>" alt="Profile" style="width:32px;height:32px;border-radius:50%;object-fit:cover;margin-right:8px;border:1px solid rgba(255,255,255,.4);">
            <?php else: ?>
                <i class="fas fa-user-circle" style="font-size:28px;margin-right:8px;opacity:.85;"></i>
            <?php endif; ?>
            <span class="text-white">Welcome, <?php echo htmlspecialchars($user['name'] ?? 'Employee'); ?></span>
        </div>

        <!-- Centered Logo -->
        <div class="navbar-logo">
            <img src="../assets/images/logo.png" alt="Company Logo">
        </div>

        <!-- Logout Button (Right) -->
        <div class="navbar-logout">
            <a href="../auth/logout.php" class="btn btn-danger btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container mt-5 mb-5">
    <div class="dm-header">
        <div class="d-flex align-items-center gap-3">
            <?php if ($user_photo_url): ?>
                <img src="<?= htmlspecialchars($user_photo_url) ?>" alt="Profile" style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #e0e0e0;flex-shrink:0;">
            <?php else: ?>
                <i class="fas fa-user-circle text-muted" style="font-size:56px;flex-shrink:0;"></i>
            <?php endif; ?>
            <div>
                <h1 class="mb-0"><?= htmlspecialchars($dm_greeting) ?>, <?= htmlspecialchars($user['name'] ?? 'Employee') ?> 👋</h1>
                <div class="dm-sub">Here's your attendance and leave summary.</div>
            </div>
        </div>
        <div class="dm-date"><?= date('l, d M Y') ?></div>
    </div>

    <div class="dm-stats">
        <div class="dm-stat <?= $__status_color[$stat_today_status] ?? 'c-blue' ?>">
            <div class="dm-stat-icon"><?= $__status_icon[$stat_today_status] ?? '➖' ?></div>
            <div><div class="dm-stat-value" style="font-size:1.15rem;"><?= htmlspecialchars($stat_today_status) ?></div><div class="dm-stat-label">Today's Status</div></div>
        </div>
        <div class="dm-stat c-green">
            <div class="dm-stat-icon">📅</div>
            <div><div class="dm-stat-value"><?= $stat_present_days ?></div><div class="dm-stat-label">Present This Month</div></div>
        </div>
        <div class="dm-stat c-orange">
            <div class="dm-stat-icon">🗓️</div>
            <div><div class="dm-stat-value"><?= $stat_leave_days ?></div><div class="dm-stat-label">Leave Days This Month</div></div>
        </div>
        <div class="dm-stat c-red">
            <div class="dm-stat-icon">⏳</div>
            <div><div class="dm-stat-value"><?= $stat_pending_leaves ?></div><div class="dm-stat-label">Pending Leave Requests</div></div>
        </div>
    </div>

    <h3 class="mb-4 text-center">Employee Dashboard</h3>
    <div class="row g-4">
        
        <!-- Punch In Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card card-punch-in shadow">
                <div class="card-body">
                    <h5 class="card-title">🔓 Punch In</h5>
                    <p class="card-text text-muted">Mark your attendance by punching in when you arrive at the office.</p>
                    <div class="card-links">
                        <a href="punch_in.php" class="btn btn-success btn-sm">Punch In</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Punch Out Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card card-punch-out shadow">
                <div class="card-body">
                    <h5 class="card-title">🔒 Punch Out</h5>
                    <p class="card-text text-muted">Mark your departure by punching out when you leave the office.</p>
                    <div class="card-links">
                        <a href="punch_out.php" class="btn btn-danger btn-sm">Punch Out</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- My Attendance Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card card-attendance shadow">
                <div class="card-body">
                    <h5 class="card-title">&#128203; My Attendance</h5>
                    <p class="card-text text-muted">View your attendance history and check your punch records.</p>
                    <div class="card-links">
                        <a href="my_attendance.php" class="btn btn-info btn-sm">View Attendance</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Leave Application Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card shadow" style="border-left:5px solid #fd7e14;">
                <div class="card-body">
                    <h5 class="card-title">&#128197; Leave Application</h5>
                    <p class="card-text text-muted">Apply for leave and view the status of your applications.</p>
                    <div class="card-links">
                        <a href="leave_application.php" class="btn btn-sm" style="background:#fd7e14;color:#fff;">Apply / View</a>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>