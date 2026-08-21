<?php
session_start();
include('config/db.php');

// Check if operator is logged in and has correct role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'face_operator') {
    header("Location: auth/login.php");
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: auth/login.php");
    exit;
}

$upload_dir = 'uploads/employee_faces';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Handle search by employee ID
$search_query = '';
$search_results = [];
$show_all = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_id'])) {
    $search_id = trim($_POST['search_id']);
    $search_query = $search_id;
    $show_all = false;
    
    if (!empty($search_id)) {
        // Search by employee ID
        $stmt = $conn->prepare("SELECT id, name, employee_id FROM users WHERE role = 'employee' AND employee_id LIKE ? ORDER BY name");
        $search_param = "%$search_id%";
        $stmt->bind_param("s", $search_param);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $photo_file = $upload_dir . '/employee_' . $row['id'] . '.jpg';
            $row['has_photo'] = file_exists($photo_file);
            $search_results[] = $row;
        }
        $stmt->close();
    }
}

// Fetch all employees with their photo status
$all_employees_with_status = [];
if ($show_all) {
    $stmt = $conn->prepare("SELECT id, name, employee_id FROM users WHERE role = 'employee' ORDER BY name");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $photo_file = $upload_dir . '/employee_' . $row['id'] . '.jpg';
        $row['has_photo'] = file_exists($photo_file);
        $all_employees_with_status[] = $row;
    }
    $stmt->close();
}

// Use search results if search was performed, otherwise use all employees
$employees_to_display = $show_all ? $all_employees_with_status : $search_results;

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Photo Status</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .navbar-brand {
            color: white !important;
            font-weight: 700;
            font-size: 1.3rem;
        }
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            margin-bottom: 30px;
        }
        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            color: #333;
        }
        .search-box {
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid">
            <a href="face_dashboard.php" class="navbar-brand">
                <i class="fas fa-arrow-left"></i> Face Attendance Dashboard
            </a>
            <div>
                <span class="text-white me-3">
                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </span>
                <a href="?logout=1" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="content-card">
            <h1 class="section-title"><i class="fas fa-list-check"></i> Employee Photo Status</h1>

            <!-- Search Bar -->
            <div class="search-box">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-9">
                            <input type="text" name="search_id" class="form-control form-control-lg" placeholder="Search by Employee ID..." value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-success btn-lg w-100">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </div>
                </form>
                <?php if (!$show_all && count($search_results) === 0 && $search_query): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="fas fa-exclamation-circle"></i> No employees found with ID "<?php echo htmlspecialchars($search_query); ?>"
                    </div>
                <?php endif; ?>
            </div>

            <!-- Employees Table -->
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Employee Name</th>
                            <th>Employee ID</th>
                            <th>Photo Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($employees_to_display) > 0): ?>
                            <?php foreach ($employees_to_display as $idx => $emp): ?>
                            <tr>
                                <td><?php echo $idx + 1; ?></td>
                                <td><?php echo htmlspecialchars($emp['name']); ?></td>
                                <td><strong><?php echo htmlspecialchars($emp['employee_id'] ?? 'N/A'); ?></strong></td>
                                <td>
                                    <?php if ($emp['has_photo']): ?>
                                        <span class="badge bg-success"><i class="fas fa-check-circle"></i> Photo Exists</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning"><i class="fas fa-times-circle"></i> No Photo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="face_add_employee_face.php" class="btn btn-sm btn-primary" onclick="selectEmployee(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['name']); ?>')">
                                        <?php echo $emp['has_photo'] ? '🔄 Replace' : '➕ Add'; ?> Photo
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">
                                    <?php echo $show_all ? 'No employees found' : 'No match found'; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($employees_to_display) > 0): ?>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i> Showing <strong><?php echo count($employees_to_display); ?></strong> employee(s)
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function selectEmployee(empId, empName) {
            // Store employee info and redirect
            localStorage.setItem('selected_emp_id', empId);
            localStorage.setItem('selected_emp_name', empName);
        }
    </script>
</body>
</html>
