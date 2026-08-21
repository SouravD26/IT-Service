<?php
session_start();
include('../config/db.php');

// Enable authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user's department
$user_stmt = $conn->prepare("SELECT department FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();
$user_dept = $user_data['department'] ?? '';
$user_stmt->close();

// Get filter values
$filter_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$filter_year = isset($_GET['year']) ? (int)$_GET['year'] : 2026;

// Build SQL query to get ALL attendance records (not grouped, show each punch)
$sql = "SELECT 
    id,
    date,
    punch_in,
    punch_out,
    punch_in_location,
    punch_out_location,
    status,
    selfie_punchin,
    selfie_punchout
FROM attendance 
WHERE user_id = ?";

$params = [$user_id];
$types = "i";

// Apply month and year filter
$sql .= " AND MONTH(date) = ? AND YEAR(date) = ?";
$params[] = $filter_month;
$params[] = $filter_year;
$types .= "ii";

$sql .= " ORDER BY date DESC, punch_in DESC";

// Execute query
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Function to calculate hours between two times
function calculateHours($punch_in, $punch_out, $date) {
    if (!$punch_in || !$punch_out) {
        return ['hours' => 0, 'formatted' => '-', 'seconds' => 0];
    }
    
    $punch_in_timestamp = strtotime($date . ' ' . $punch_in);
    $punch_out_timestamp = strtotime($date . ' ' . $punch_out);
    
    // Handle case where punch_out is next day
    if ($punch_out_timestamp < $punch_in_timestamp) {
        $punch_out_timestamp = strtotime(date('Y-m-d', strtotime($date . ' +1 day')) . ' ' . $punch_out);
    }
    
    $total_seconds = max(0, $punch_out_timestamp - $punch_in_timestamp);
    
    // Convert seconds to hours and minutes
    $hours = floor($total_seconds / 3600);
    $minutes = floor(($total_seconds % 3600) / 60);
    $total_hours = round($total_seconds / 3600, 2);
    $formatted = sprintf('%dh %dm', $hours, $minutes);
    
    return ['hours' => $total_hours, 'formatted' => $formatted, 'seconds' => $total_seconds];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        .selfie-thumbnail {
            max-width: 80px;
            max-height: 80px;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.2s;
            border: 2px solid #ddd;
        }
        .selfie-thumbnail:hover {
            transform: scale(1.05);
            border-color: #007bff;
        }
        .selfie-modal-img {
            max-width: 100%;
            border-radius: 8px;
            margin-top: 20px;
        }
        .no-selfie {
            color: #999;
            font-style: italic;
            font-size: 12px;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand mb-0 h1">My Attendance Records</span>
        <div>
            <a href="dashboard.php" class="btn btn-secondary btn-sm me-2">← Back to Dashboard</a>
            <a href="../auth/logout.php" class="btn btn-danger btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <h3 class="mb-4">📋 My Attendance Records</h3>
    
    <!-- Filter Card -->
    <div class="card mb-4 shadow">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">🔍 Filter Attendance</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Department</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_dept); ?>" disabled>
                    <small class="text-muted">Your Department</small>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Month</label>
                    <select name="month" class="form-control">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $filter_month === $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Year</label>
                    <select name="year" class="form-control">
                        <?php 
                        $current_year = 2026;
                        for ($y = $current_year - 5; $y <= $current_year; $y++): 
                        ?>
                            <option value="<?php echo $y; ?>" <?php echo $filter_year === $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary w-100">🔎 Filter</button>
                    </div>
                </div>
            </form>
            <div class="mt-2">
                <a href="my_attendance.php" class="btn btn-secondary btn-sm">↺ Reset</a>
            </div>
        </div>
    </div>

    <!-- Attendance Table -->
    <div class="card shadow">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">📊 Records</h5>
        </div>
        <div class="card-body">
            <?php if ($result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Date & Time</th>
                                <th>Punch In Location</th>
                                <th>Punch Out Location</th>
                                <th>Punch In Selfie</th>
                                <th>Punch Out Selfie</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $current_date = '';
                            while ($row = $result->fetch_assoc()): 
                                // Add separator if date changes
                                if ($current_date !== $row['date']) {
                                    if ($current_date !== '') {
                                        echo "<tr class='table-secondary'><td colspan='6'>&nbsp;</td></tr>";
                                    }
                                    $current_date = $row['date'];
                                    echo "<tr class='table-light'>";
                                    echo "<td colspan='6'><strong>📅 " . htmlspecialchars($row['date']) . "</strong></td>";
                                    echo "</tr>";
                                }
                            ?>
                                <tr>
                                    <td>
                                        <div><strong>🕐 IN:</strong> <?php echo htmlspecialchars($row['punch_in'] ?? '-'); ?></div>
                                        <?php if ($row['punch_out']): ?>
                                            <div><strong>🕑 OUT:</strong> <?php echo htmlspecialchars($row['punch_out']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td>
                                        <span title="<?php echo htmlspecialchars($row['punch_in_location'] ?? '-'); ?>" style="cursor: help;">
                                            <?php echo htmlspecialchars($row['punch_in_location'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    
                                    <td>
                                        <span title="<?php echo htmlspecialchars($row['punch_out_location'] ?? '-'); ?>" style="cursor: help; color: #d9534f; font-weight: 500;">
                                            <?php echo htmlspecialchars($row['punch_out_location'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    
                                    <!-- Punch In Selfie -->
                                    <td>
                                        <?php if (!empty($row['selfie_punchin'])): ?>
                                            <img 
                                                src="../uploads/selfies/<?php echo str_replace('-', '/', htmlspecialchars($row['date'])); ?>/<?php echo htmlspecialchars($row['selfie_punchin']); ?>" 
                                                alt="Punch In Selfie" 
                                                class="selfie-thumbnail"
                                                onclick="viewSelfie('<?php echo htmlspecialchars($row['selfie_punchin']); ?>', 'Punch In', '<?php echo htmlspecialchars($row['date']); ?>')"
                                                title="Click to view punch-in selfie"
                                            >
                                        <?php else: ?>
                                            <span class="no-selfie">-</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Punch Out Selfie -->
                                    <td>
                                        <?php if (!empty($row['selfie_punchout'])): ?>
                                            <img 
                                                src="../uploads/selfies/<?php echo str_replace('-', '/', htmlspecialchars($row['date'])); ?>/<?php echo htmlspecialchars($row['selfie_punchout']); ?>" 
                                                alt="Punch Out Selfie" 
                                                class="selfie-thumbnail"
                                                onclick="viewSelfie('<?php echo htmlspecialchars($row['selfie_punchout']); ?>', 'Punch Out', '<?php echo htmlspecialchars($row['date']); ?>')"
                                                title="Click to view punch-out selfie"
                                            >
                                        <?php else: ?>
                                            <span class="no-selfie">-</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Status -->
                                    <td>
                                        <?php 
                                        if ($row['punch_in'] && $row['punch_out']) {
                                            echo '<span class="badge bg-success">✓ Complete</span>';
                                        } elseif ($row['punch_in']) {
                                            echo '<span class="badge bg-warning">Punch In</span>';
                                        } elseif ($row['punch_out']) {
                                            echo '<span class="badge bg-info">Punch Out</span>';
                                        } else {
                                            echo '<span class="badge bg-secondary">-</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info" role="alert">
                    ℹ️ No attendance records found for the selected filters.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Selfie Modal -->
    <div class="modal fade" id="selfieModal" tabindex="-1" aria-labelledby="selfieModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="selfieModalLabel">📷 Selfie View</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <p id="selfieInfo" class="text-muted"></p>
                    <img id="selfieImage" src="" alt="Selfie" class="selfie-modal-img">
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    function viewSelfie(filename, type, date) {
        // Set modal content
        document.getElementById('selfieInfo').innerText = `${type} - ${date}`;
        const img = document.getElementById('selfieImage');
        // Convert date from YYYY-MM-DD to YYYY/MM/DD for folder path
        const datePath = date.replace(/-/g, '/');
        img.src = `../uploads/selfies/${datePath}/${filename}`;
        
        // Handle image loading errors (fallback for old pictures)
        img.onerror = function() {
            // Try loading from old flat folder structure
            this.src = `../uploads/selfies/${filename}`;
            this.onerror = null; // Remove handler to prevent infinite loop
        };
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('selfieModal'));
        modal.show();
    }
</script>

</body>
</html>

<?php
$stmt->close();
?>