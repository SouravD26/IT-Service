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

// Clear selected employee when clicking "Search Another"
if (isset($_GET['new_search'])) {
    unset($_SESSION['selected_employee_id']);
    unset($_SESSION['selected_employee_name']);
}

// Create uploads directory
$upload_dir = 'uploads/employee_faces';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Handle employee search
$employees = [];
$search_query = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $search = trim($_POST['search']);
    $search_query = $search;
    
    // Search by name or employee ID
    $stmt = $conn->prepare("SELECT id, name FROM users WHERE role = 'employee' AND (name LIKE ? OR employee_id LIKE ?) LIMIT 10");
    $search_param = "%$search%";
    $stmt->bind_param("ss", $search_param, $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $stmt->close();
}

// Get selected employee
$selected_employee = null;
if (isset($_POST['select_employee'])) {
    $emp_id = intval($_POST['employee_id']);
    $stmt = $conn->prepare("SELECT id, name, employee_id FROM users WHERE id = ? AND role = 'employee'");
    $stmt->bind_param("i", $emp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $selected_employee = $result->fetch_assoc();
        $_SESSION['selected_employee_id'] = $selected_employee['id'];
        $_SESSION['selected_employee_name'] = $selected_employee['name'];
    }
    $stmt->close();
}

// Get selected employee from session if exists
if (isset($_SESSION['selected_employee_id']) && !isset($_POST['search'])) {
    $stmt = $conn->prepare("SELECT id, name, employee_id FROM users WHERE id = ? AND role = 'employee'");
    $stmt->bind_param("i", $_SESSION['selected_employee_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $selected_employee = $result->fetch_assoc();
    }
    $stmt->close();
}

// Check if employee has photo
$has_photo = false;
if ($selected_employee) {
    $photo_file = $upload_dir . '/employee_' . $selected_employee['id'] . '.jpg';
    $has_photo = file_exists($photo_file);
}

// Handle canvas photo capture
$canvas_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_canvas_photo') {
    if ($selected_employee && isset($_POST['photo_data'])) {
        $image_data = $_POST['photo_data'];
        $image_data = str_replace('data:image/jpeg;base64,', '', $image_data);
        $image_data = str_replace(' ', '+', $image_data);
        
        $photo_path = $upload_dir . '/employee_' . $selected_employee['id'] . '.jpg';
        
        if (file_put_contents($photo_path, base64_decode($image_data))) {
            $canvas_message = "✅ Photo captured and saved!";
            $has_photo = true;
        } else {
            $canvas_message = "❌ Failed to save captured photo";
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Employee Face</title>
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
        .camera-container {
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            margin: 20px 0;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        video, canvas {
            width: 100%;
            height: auto;
            display: block;
        }
        .photo-status {
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-weight: 600;
        }
        .photo-exists {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .photo-not-exists {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .employee-card {
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            background: #f8f9fa;
            border-left: 4px solid #667eea;
        }
        .back-btn {
            margin-bottom: 20px;
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
            <h1 class="section-title"><i class="fas fa-camera"></i> Add Employee Face</h1>

            <?php if (!$selected_employee): ?>
                <!-- Search Employee -->
                <form method="POST" class="mb-4">
                    <div class="row">
                        <div class="col-md-9">
                            <input type="text" name="search" class="form-control form-control-lg" placeholder="Search by Employee Name or ID" value="<?php echo htmlspecialchars($search_query); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Search Results -->
                <?php if (!empty($employees)): ?>
                    <h4 class="mb-3">Found <?php echo count($employees); ?> Employee(s):</h4>
                    <?php foreach ($employees as $emp): ?>
                        <div class="employee-card">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h5 class="mb-2"><?php echo htmlspecialchars($emp['name']); ?></h5>
                                    <p class="text-muted mb-0">ID: <strong><?php echo $emp['id']; ?></strong></p>
                                </div>
                                <div class="col-md-4 text-end">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                        <button type="submit" name="select_employee" class="btn btn-sm btn-info">
                                            <i class="fas fa-arrow-right"></i> Select & Continue
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($search_query): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-circle"></i> No employees found matching "<?php echo htmlspecialchars($search_query); ?>"
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- Selected Employee Section -->
                <div class="alert alert-info mb-4">
                    <h5 class="mb-2"><i class="fas fa-check-circle"></i> Selected Employee</h5>
                    <strong><?php echo htmlspecialchars($selected_employee['name']); ?></strong>
                    <br>
                    <small>ID: <?php echo $selected_employee['employee_id'] ?? 'N/A'; ?></small>
                </div>

                <?php if ($has_photo): ?>
                    <div class="photo-status photo-exists">
                        <i class="fas fa-check-circle"></i> Photo already exists
                    </div>
                    <div class="text-center">
                        <img src="uploads/employee_faces/employee_<?php echo $selected_employee['id']; ?>.jpg?v=<?php echo time(); ?>" class="img-fluid" style="max-width: 300px; border-radius: 8px; margin: 15px 0;">
                    </div>
                <?php else: ?>
                    <div class="photo-status photo-not-exists">
                        <i class="fas fa-exclamation-circle"></i> Photo does NOT exist - Add now!
                    </div>
                <?php endif; ?>

                <!-- Capture Photo Button -->
                <button class="btn btn-success btn-lg w-100 mb-3" data-bs-toggle="modal" data-bs-target="#cameraModal">
                    <i class="fas fa-camera"></i> <?php echo $has_photo ? 'Replace Photo' : 'Capture Photo'; ?>
                </button>

                <!-- Search Another Button -->
                <a href="?new_search=1" class="btn btn-secondary btn-lg w-100">
                    <i class="fas fa-search"></i> Search Another Employee
                </a>

                <!-- Camera Modal -->
                <div class="modal fade" id="cameraModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5>Capture Photo - <?php echo htmlspecialchars($selected_employee['name']); ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="camera-container">
                                    <video id="video" width="100%" height="400" autoplay playsinline muted></video>
                                </div>
                                <div class="mt-3">
                                    <button class="btn btn-warning" onclick="startCamera()"><i class="fas fa-video"></i> Start Camera</button>
                                    <button class="btn btn-danger" onclick="stopCamera()"><i class="fas fa-stop"></i> Stop</button>
                                    <button class="btn btn-success" onclick="capturePhoto()"><i class="fas fa-camera"></i> Capture</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($canvas_message): ?>
                    <div class="alert alert-info mt-3"><?php echo $canvas_message; ?></div>
                <?php endif; ?>

                <form method="POST" style="display: none;">
                    <input type="hidden" name="action" value="save_canvas_photo">
                    <input type="hidden" name="photo_data" id="photoData">
                    <button type="submit" id="savePhotoBtn"></button>
                </form>

            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let video = null;
        let cameraStream = null;

        function startCamera() {
            video = document.getElementById('video');
            navigator.mediaDevices.getUserMedia({ 
                video: { 
                    facingMode: 'user',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                } 
            })
                .then(stream => {
                    cameraStream = stream;
                    video.srcObject = stream;
                    video.play();
                })
                .catch(err => {
                    alert('Camera error: ' + err.message);
                });
        }

        function stopCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
        }

        function capturePhoto() {
            video = document.getElementById('video');
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            
            const photoData = canvas.toDataURL('image/jpeg');
            document.getElementById('photoData').value = photoData;
            document.getElementById('savePhotoBtn').click();
        }

        // Start camera when modal opens
        document.getElementById('cameraModal').addEventListener('shown.bs.modal', function() {
            setTimeout(() => {
                startCamera();
            }, 500);
        });

        // Stop camera when modal closes
        document.getElementById('cameraModal').addEventListener('hidden.bs.modal', function() {
            stopCamera();
        });
    </script>
</body>
</html>
