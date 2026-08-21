<?php
session_start();
include('../config/db.php');

// Enable authentication check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    header("Location: ../auth/login.php");
    exit();
}

// Determine dashboard to return to based on 'from' parameter or user role
$from = isset($_GET['from']) ? htmlspecialchars($_GET['from']) : '';
if ($from === 'suparadmin') {
    $back_dashboard = 'dashboard.php';
} elseif ($from === 'admin') {
    $back_dashboard = 'admin_dashboard.php';
} else {
    $back_dashboard = ($_SESSION['role'] === 'suparadmin') ? 'dashboard.php' : 'admin_dashboard.php';
}

$message = "";
$message_type = "";

// Create locations table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Add GPS coordinate columns if missing (safe for all MySQL/MariaDB versions)
$chk_lat = $conn->query("SHOW COLUMNS FROM locations LIKE 'latitude'");
if ($chk_lat && $chk_lat->num_rows === 0) {
    $conn->query("ALTER TABLE locations ADD COLUMN latitude DECIMAL(10,8) DEFAULT NULL");
}
$chk_lng = $conn->query("SHOW COLUMNS FROM locations LIKE 'longitude'");
if ($chk_lng && $chk_lng->num_rows === 0) {
    $conn->query("ALTER TABLE locations ADD COLUMN longitude DECIMAL(11,8) DEFAULT NULL");
}

// Handle Add Location
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_location'])) {
    $location_name = htmlspecialchars(trim($_POST['location_name']));
    
    if (empty($location_name)) {
        $message = "Location name cannot be empty";
        $message_type = "danger";
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM locations WHERE name = ?");
        $stmt_check->bind_param("s", $location_name);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            $message = "This location already exists";
            $message_type = "warning";
        } else {
            $loc_lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
            $loc_lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
            $stmt_insert = $conn->prepare("INSERT INTO locations (name, latitude, longitude) VALUES (?, ?, ?)");
            $stmt_insert->bind_param("sdd", $location_name, $loc_lat, $loc_lng);
            
            if ($stmt_insert->execute()) {
                $message = "✓ Location added successfully!";
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

// Handle Update Coordinates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_coords'])) {
    $upd_id  = (int)$_POST['loc_id'];
    $upd_lat = isset($_POST['upd_latitude'])  && $_POST['upd_latitude']  !== '' ? (float)$_POST['upd_latitude']  : null;
    $upd_lng = isset($_POST['upd_longitude']) && $_POST['upd_longitude'] !== '' ? (float)$_POST['upd_longitude'] : null;
    $stmt_upd = $conn->prepare("UPDATE locations SET latitude = ?, longitude = ? WHERE id = ?");
    $stmt_upd->bind_param("ddi", $upd_lat, $upd_lng, $upd_id);
    if ($stmt_upd->execute()) {
        $message = "✓ Office GPS coordinates updated successfully!";
        $message_type = "success";
    } else {
        $message = "Error: " . $stmt_upd->error;
        $message_type = "danger";
    }
    $stmt_upd->close();
}

// Handle Delete Location
if (isset($_GET['delete_location'])) {
    $location_id = (int)$_GET['delete_location'];
    
    // Check if location is being used by any employee
    $stmt_check = $conn->prepare("SELECT id FROM users WHERE location = (SELECT name FROM locations WHERE id = ?) LIMIT 1");
    $stmt_check->bind_param("i", $location_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $message = "Cannot delete location that is assigned to employees";
        $message_type = "danger";
    } else {
        $stmt_delete = $conn->prepare("DELETE FROM locations WHERE id = ?");
        $stmt_delete->bind_param("i", $location_id);
        
        if ($stmt_delete->execute()) {
            $message = "✓ Location deleted successfully!";
            $message_type = "success";
        } else {
            $message = "✗ Error: " . $stmt_delete->error;
            $message_type = "danger";
        }
        $stmt_delete->close();
    }
    $stmt_check->close();
}

// Fetch all locations
$stmt = $conn->prepare("SELECT id, name, latitude, longitude, created_at FROM locations ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Location Management - Attendance System</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container mt-5">
        <!-- Add Location Form -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">➕ Add New Location</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Location Name</label>
                        <input type="text" name="location_name" class="form-control" placeholder="e.g., Head Office, Branch Office" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Latitude <small class="text-muted">(optional)</small></label>
                        <input type="number" step="any" name="latitude" id="add_lat" class="form-control" placeholder="e.g., 22.5726">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Longitude <small class="text-muted">(optional)</small></label>
                        <input type="number" step="any" name="longitude" id="add_lng" class="form-control" placeholder="e.g., 88.3639">
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-secondary w-100" onclick="fillMyLocation('add_lat','add_lng')" title="Use my current GPS location">📍 GPS</button>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" name="add_location" class="btn btn-success w-100">✓ Add Location</button>
                    </div>
                </form>
                <div class="mt-2 text-muted small">
                    <i class="fas fa-info-circle"></i> Set GPS coordinates to enforce <strong>100-metre attendance radius</strong>. Leave blank to allow attendance from anywhere.
                </div>
            </div>
        </div>

        <!-- Locations List -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">All Locations</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Location Name</th>
                                <th>Office GPS Coordinates</th>
                                <th>Radius</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    $hasCoords = ($row['latitude'] !== null && $row['longitude'] !== null);
                                    echo "<tr>";
                                    echo "<td>" . $row['id'] . "</td>";
                                    echo "<td><strong>" . htmlspecialchars($row['name']) . "</strong></td>";
                                    if ($hasCoords) {
                                        echo "<td><span class='badge bg-success'>✓ Set</span> <small class='text-muted'>" . $row['latitude'] . ", " . $row['longitude'] . "</small></td>";
                                        echo "<td><span class='badge bg-primary'>100 m</span></td>";
                                    } else {
                                        echo "<td><span class='badge bg-warning text-dark'>⚠ Not Set</span> <small class='text-muted'>No restriction</small></td>";
                                        echo "<td><span class='badge bg-secondary'>None</span></td>";
                                    }
                                    echo "<td>" . date('d-m-Y H:i', strtotime($row['created_at'])) . "</td>";
                                    echo "<td>";
                                    echo "<button type='button' onclick=\"openSetCoords(" . $row['id'] . ", '" . htmlspecialchars($row['name'], ENT_QUOTES) . "', '" . $row['latitude'] . "', '" . $row['longitude'] . "')\" class='btn btn-sm btn-info me-1'>📍 Set GPS</button>";
                                    echo "<button type='button' onclick=\"confirmDeleteLocation(" . $row['id'] . ", '" . htmlspecialchars($row['name'], ENT_QUOTES) . "')\" class='btn btn-sm btn-danger'>Delete</button>";
                                    echo "</td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='6' class='text-center text-muted'>No locations found. Add one to get started!</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-3">
            <a href="<?php echo htmlspecialchars($back_dashboard); ?>" class="btn btn-secondary">Back to Dashboard</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Set GPS Coordinates Modal -->
    <div class="modal fade" id="setCoordsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="loc_id" id="modal_loc_id">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title">📍 Set Office GPS Coordinates</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Setting coordinates enables the <strong>100-metre attendance restriction</strong>. Employees outside this radius cannot mark attendance.</p>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Location: <span id="modal_loc_name" class="text-primary"></span></label>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label">Latitude</label>
                                <input type="number" step="any" name="upd_latitude" id="modal_lat" class="form-control" placeholder="e.g., 22.5726" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Longitude</label>
                                <input type="number" step="any" name="upd_longitude" id="modal_lng" class="form-control" placeholder="e.g., 88.3639" required>
                            </div>
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="fillMyLocation('modal_lat','modal_lng')">
                                📍 Use My Current GPS Location
                            </button>
                            <small class="text-muted ms-2">Your browser will ask for permission</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_coords" class="btn btn-success">💾 Save Coordinates</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openSetCoords(id, name, lat, lng) {
            document.getElementById('modal_loc_id').value   = id;
            document.getElementById('modal_loc_name').textContent = name;
            document.getElementById('modal_lat').value      = (lat && lat !== 'null') ? lat : '';
            document.getElementById('modal_lng').value      = (lng && lng !== 'null') ? lng : '';
            new bootstrap.Modal(document.getElementById('setCoordsModal')).show();
        }

        function fillMyLocation(latFieldId, lngFieldId) {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your browser.');
                return;
            }
            navigator.geolocation.getCurrentPosition(
                pos => {
                    document.getElementById(latFieldId).value = pos.coords.latitude.toFixed(8);
                    document.getElementById(lngFieldId).value = pos.coords.longitude.toFixed(8);
                },
                err => {
                    alert('Could not get location: ' + err.message + '\nPlease enter coordinates manually.');
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }

        function confirmDeleteLocation(locationId, locationName) {
            Swal.fire({
                title: 'Are you sure?',
                text: 'Delete location: ' + locationName,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?delete_location=' + locationId;
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
            echo "Swal.fire({
                icon: '$icon',
                title: '$title',
                text: '$message_escaped',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK'
            });";
        }
        ?>
    </script>
</body>
</html>

<?php
$stmt->close();
?>

