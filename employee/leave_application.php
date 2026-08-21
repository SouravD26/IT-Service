<?php
session_start();
include('../config/db.php');

// Enable login requirement
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Create table if needed
$conn->query("CREATE TABLE IF NOT EXISTS leave_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    leave_type ENUM('Casual Leave','Sick Leave','Earned Leave','Maternity Leave','Paternity Leave','Unpaid Leave') NOT NULL,
    start_date DATE NOT NULL, end_date DATE NOT NULL, days_count INT NOT NULL DEFAULT 1,
    reason TEXT, status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    admin_notes TEXT, reviewed_by INT, reviewed_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$leave_msg = ''; $leave_type = '';
// Handle submit
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['apply_leave'])) {
    $lt = htmlspecialchars($_POST['leave_type']);
    $sd = $_POST['start_date']; $ed = $_POST['end_date'];
    $rs = htmlspecialchars($_POST['reason'] ?? '');
    if ($sd > $ed) { $leave_msg='End date must be after start date.'; $leave_type='danger'; }
    else {
        $days = (int)((strtotime($ed)-strtotime($sd))/86400)+1;
        $s=$conn->prepare("INSERT INTO leave_applications (user_id,leave_type,start_date,end_date,days_count,reason) VALUES (?,?,?,?,?,?)");
        $s->bind_param("isssis",$user_id,$lt,$sd,$ed,$days,$rs);
        $s->execute(); $s->close();
        $leave_msg="✓ Leave application submitted successfully!"; $leave_type='success';
    }
}
// Fetch my leaves
$myLeaves = $conn->prepare("SELECT * FROM leave_applications WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$myLeaves->bind_param("i",$user_id); $myLeaves->execute();
$myLeaves = $myLeaves->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <title>Leave Application</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand mb-0 h1">&#128197; Leave Application</span>
        <div>
            <a href="dashboard.php" class="btn btn-secondary btn-sm me-2">&larr; Back to Dashboard</a>
            <a href="../auth/logout.php" class="btn btn-danger btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div id="leaveSection" class="container mt-4 mb-5">
    <div class="row g-4">
        <!-- Apply Form -->
        <div class="col-md-5">
            <div class="card shadow-sm border-0">
                <div class="card-header" style="background:#fd7e14;color:#fff;">
                    <h6 class="mb-0">&#128197; Apply for Leave</h6>
                </div>
                <div class="card-body">
                    <?php if ($leave_msg): ?>
                    <div class="alert alert-<?= $leave_type ?> py-2"><?= htmlspecialchars($leave_msg) ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Leave Type</label>
                            <select name="leave_type" class="form-select" required>
                                <option value="">Select type</option>
                                <?php foreach(['Casual Leave','Sick Leave','Earned Leave','Maternity Leave','Paternity Leave','Unpaid Leave'] as $lt): ?>
                                <option value="<?= $lt ?>"><?= $lt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">End Date</label>
                                <input type="date" name="end_date" class="form-control" required min="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Reason</label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="Briefly describe the reason..."></textarea>
                        </div>
                        <button type="submit" name="apply_leave" class="btn w-100" style="background:#fd7e14;color:#fff;">Submit Application</button>
                    </form>
                </div>
            </div>
        </div>
        <!-- My Leave History -->
        <div class="col-md-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-dark text-white">
                    <h6 class="mb-0">&#128203; My Leave Applications</h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($myLeaves)): ?>
                    <p class="text-muted p-3">No leave applications yet.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Note</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($myLeaves as $l):
                                $badge = $l['status']==='Approved'?'bg-success':($l['status']==='Rejected'?'bg-danger':'bg-warning text-dark');
                            ?>
                            <tr>
                                <td><small><?= htmlspecialchars($l['leave_type']) ?></small></td>
                                <td><small><?= date('d/m',strtotime($l['start_date'])) ?> – <?= date('d/m',strtotime($l['end_date'])) ?></small></td>
                                <td><small><?= $l['days_count'] ?>d</small></td>
                                <td><span class="badge <?= $badge ?>"><?= $l['status'] ?></span></td>
                                <td><small class="text-muted"><?= htmlspecialchars(mb_substr($l['admin_notes']??'',0,30)) ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
