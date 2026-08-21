<?php
session_start();
include('../config/db.php');

// Check authentication - ONLY for suparadmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'suparadmin') {
    header("Location: ../auth/login.php");
    exit();
}

$message = "";
$message_type = "";

// Fetch suparadmin name
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin_user = $result->fetch_assoc();
$admin_name = $admin_user['name'] ?? 'Super Admin';
$stmt->close();

// Create uploads directory
$upload_dir = '../uploads/employee_photos';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Handle photo upload/capture
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'upload_photo') {
        $employee_id = intval($_POST['employee_id']);
        
        // Verify employee exists
        $stmt = $conn->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'employee'");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $message = "✗ Employee not found";
            $message_type = "danger";
        } else {
            $employee = $result->fetch_assoc();
            
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['photo']['tmp_name'];
                $file_name = $_FILES['photo']['name'];
                $file_error = $_FILES['photo']['error'];
                
                // Validate file
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if (!in_array($file_extension, $allowed_extensions)) {
                    $message = "✗ Invalid file type. Only JPG, PNG, GIF allowed";
                    $message_type = "danger";
                } else if ($_FILES['photo']['size'] > 5 * 1024 * 1024) { // 5MB limit
                    $message = "✗ File size too large. Maximum 5MB allowed";
                    $message_type = "danger";
                } else {
                    // Save as employee_id.jpg (simple naming for face recognition)
                    $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $simple_filename = $employee_id . '.' . $file_extension;
                    $upload_path = $upload_dir . '/' . $simple_filename;
                    
                    // Delete old photo if exists
                    $old_files = glob($upload_dir . '/' . $employee_id . '.*');
                    foreach ($old_files as $old_file) {
                        if (file_exists($old_file)) {
                            unlink($old_file);
                        }
                    }
                    
                    // Move uploaded file
                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        // Update database
                        $photo_path = 'uploads/employee_photos/' . $simple_filename;
                        $update_stmt = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                        $update_stmt->bind_param("si", $photo_path, $employee_id);
                        
                        if ($update_stmt->execute()) {
                            $message = "✓ Photo uploaded successfully for " . htmlspecialchars($employee['name']);
                            $message_type = "success";
                        } else {
                            $message = "✗ Database error: " . $conn->error;
                            $message_type = "danger";
                            unlink($upload_path);
                        }
                        $update_stmt->close();
                    } else {
                        $message = "✗ Error uploading file";
                        $message_type = "danger";
                    }
                }
            } else if (isset($_POST['photo_base64']) && !empty($_POST['photo_base64'])) {
                // Handle base64 image from camera capture
                $base64_image = $_POST['photo_base64'];
                
                // Remove data URL prefix
                if (strpos($base64_image, 'data:image') === 0) {
                    $base64_image = substr($base64_image, strpos($base64_image, ',') + 1);
                }
                
                // Decode base64
                $image_data = base64_decode($base64_image);
                
                if ($image_data === false) {
                    $message = "✗ Invalid image data";
                    $message_type = "danger";
                } else {
                    // Save camera capture as employee_id.png
                    $simple_filename = $employee_id . '.png';
                    $upload_path = $upload_dir . '/' . $simple_filename;
                    
                    // Delete old photo if exists
                    $old_files = glob($upload_dir . '/' . $employee_id . '.*');
                    foreach ($old_files as $old_file) {
                        if (file_exists($old_file)) {
                            unlink($old_file);
                        }
                    }
                    
                    if (file_put_contents($upload_path, $image_data)) {
                        $photo_path = 'uploads/employee_photos/' . $simple_filename;
                        $update_stmt = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                        $update_stmt->bind_param("si", $photo_path, $employee_id);
                        
                        if ($update_stmt->execute()) {
                            $message = "✓ Photo captured and saved successfully for " . htmlspecialchars($employee['name']);
                            $message_type = "success";
                        } else {
                            $message = "✗ Database error: " . $conn->error;
                            $message_type = "danger";
                            unlink($upload_path);
                        }
                        $update_stmt->close();
                    } else {
                        $message = "✗ Error saving photo";
                        $message_type = "danger";
                    }
                }
            } else {
                $message = "✗ Please upload a photo or capture from camera";
                $message_type = "danger";
            }
        }
        $stmt->close();
    } elseif ($_POST['action'] === 'delete_photo') {
        $employee_id = intval($_POST['employee_id']);
        
        // Get old photo path
        $stmt = $conn->prepare("SELECT profile_photo, name FROM users WHERE id = ? AND role = 'employee'");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $message = "✗ Employee not found";
            $message_type = "danger";
        } else {
            $employee = $result->fetch_assoc();
            
            if (!empty($employee['profile_photo'])) {
                $photo_path = '../' . $employee['profile_photo'];
                if (file_exists($photo_path)) {
                    unlink($photo_path);
                }
                
                // Update database
                $update_stmt = $conn->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?");
                $update_stmt->bind_param("i", $employee_id);
                
                if ($update_stmt->execute()) {
                    $message = "✓ Photo deleted successfully for " . htmlspecialchars($employee['name']);
                    $message_type = "success";
                } else {
                    $message = "✗ Database error: " . $conn->error;
                    $message_type = "danger";
                }
                $update_stmt->close();
            } else {
                $message = "✗ No photo found for this employee";
                $message_type = "warning";
            }
        }
        $stmt->close();
    }
}

// Fetch all employees
$employees_query = "SELECT id, name, employee_id, profile_photo, email, department FROM users WHERE role = 'employee' ORDER BY name ASC";
$employees_result = $conn->query($employees_query);
$employees = [];
if ($employees_result) {
    while ($row = $employees_result->fetch_assoc()) {
        $employees[] = $row;
    }
}

// Check if any employees found
if (empty($employees)) {
    $message = "⚠ No employees found in the system. Please add employees first in the admin panel.";
    $message_type = "warning";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employee Photos - Face Recognition</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .navbar-custom {
            background-color: #007bff;
            padding: 0.5rem 1rem;
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
        .photo-card {
            height: 100%;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        .photo-card:hover {
            transform: translateY(-5px);
        }
        .employee-photo {
            width: 100%;
            height: 250px;
            object-fit: cover;
            border-radius: 8px;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: #999;
        }
        .camera-preview {
            width: 100%;
            height: 300px;
            border: 2px solid #007bff;
            border-radius: 8px;
            background-color: #000;
        }
        .capture-button {
            background-color: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        .capture-button:hover {
            background-color: #218838;
        }
        .modal-body {
            max-height: 600px;
            overflow-y: auto;
        }
        .message-container {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container-fluid mt-4">
        <!-- Message Display -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Instructions -->
        <div class="alert alert-info mb-4">
            <h5><i class="fas fa-info-circle"></i> Instructions</h5>
            <ul class="mb-0">
                <li>Upload a clear, front-facing photo of each employee</li>
                <li>Photos will be used for face recognition in the multi-user attendance panel</li>
                <li>Supported formats: JPG, PNG, GIF (Max 5MB)</li>
                <li>You can upload an existing photo or capture one using your camera</li>
                <li>One photo per employee - uploading a new photo will replace the old one</li>
            </ul>
        </div>

        <!-- Upload New Photo Section -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-upload"></i> Upload/Capture Employee Photo</h5>
            </div>
            <div class="card-body">
                <form id="photoUploadForm" method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="employeeSelect" class="form-label">Select Employee:</label>
                                <select id="employeeSelect" name="employee_id" class="form-control" required>
                                    <option value="">-- Choose an employee --</option>
                                    <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>" data-photo="<?php echo !empty($emp['profile_photo']) ? htmlspecialchars($emp['profile_photo']) : ''; ?>">
                                        <?php echo htmlspecialchars($emp['name']); ?> (<?php echo htmlspecialchars($emp['employee_id']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="photoInput" class="form-label">Upload Photo:</label>
                                <input type="file" id="photoInput" name="photo" class="form-control" accept="image/*">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <button type="button" class="btn btn-info w-100" data-bs-toggle="modal" data-bs-target="#cameraModal">
                                <i class="fas fa-camera"></i> Capture from Camera
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="submit" name="action" value="upload_photo" class="btn btn-success w-100">
                                <i class="fas fa-check"></i> Upload Photo
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Employees Photo Gallery -->
        <div class="card">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-images"></i> Employee Photos Gallery (<?php echo count($employees); ?> employees)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($employees)): ?>
                <div class="alert alert-warning">No employees found in the system.</div>
                <?php else: ?>
                <div class="row">
                    <?php foreach ($employees as $emp): ?>
                    <div class="col-md-4 col-lg-3 mb-4">
                        <div class="card photo-card">
                            <div class="position-relative">
                                <?php if (!empty($emp['profile_photo'])): ?>
                                <img src="../<?php echo htmlspecialchars($emp['profile_photo']); ?>" class="card-img-top employee-photo" alt="<?php echo htmlspecialchars($emp['name']); ?>">
                                <span class="badge bg-success position-absolute" style="top: 10px; right: 10px;">
                                    <i class="fas fa-check-circle"></i> Photo Set
                                </span>
                                <?php else: ?>
                                <div class="employee-photo">
                                    <div class="text-center">
                                        <i class="fas fa-user fa-3x text-secondary" style="opacity: 0.5;"></i>
                                        <p class="mt-2">No Photo</p>
                                    </div>
                                </div>
                                <span class="badge bg-warning position-absolute" style="top: 10px; right: 10px;">
                                    <i class="fas fa-exclamation-triangle"></i> Missing
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <h6 class="card-title mb-1"><?php echo htmlspecialchars($emp['name']); ?></h6>
                                <small class="text-muted d-block">ID: <?php echo htmlspecialchars($emp['employee_id']); ?></small>
                                <small class="text-muted d-block"><?php echo htmlspecialchars($emp['department']); ?></small>
                                <small class="text-muted d-block"><?php echo htmlspecialchars($emp['email'] ?? 'N/A'); ?></small>
                            </div>
                            <div class="card-footer bg-light">
                                <button class="btn btn-sm btn-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#uploadModal" 
                                    onclick="setSelectedEmployee(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['name']); ?>')">
                                    <i class="fas fa-edit"></i> Edit Photo
                                </button>
                                <?php if (!empty($emp['profile_photo'])): ?>
                                <form method="POST" style="display: inline-block; width: 100%;">
                                    <input type="hidden" name="action" value="delete_photo">
                                    <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger w-100" onclick="return confirm('Delete photo for <?php echo htmlspecialchars($emp['name']); ?>?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Camera Capture Modal -->
    <div class="modal fade" id="cameraModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-camera"></i> Capture Photo from Camera</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <video id="cameraVideo" class="camera-preview" playsinline autoplay></video>
                    <div class="mt-3" style="text-align: center;">
                        <button type="button" class="btn btn-success" id="captureButton">
                            <i class="fas fa-camera"></i> Take Photo
                        </button>
                        <button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Form Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-upload"></i> Upload Photo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Employee:</label>
                            <p id="selectedEmployeeName" style="font-weight: bold; font-size: 1.1rem;"></p>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Select Photo File:</label>
                            <input type="file" name="photo" class="form-control" accept="image/*" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="employee_id" id="selectedEmployeeId">
                        <input type="hidden" name="action" value="upload_photo">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload Photo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let cameraStream = null;
        let capturedImageData = null;

        // Initialize camera when modal is opened
        document.getElementById('cameraModal').addEventListener('shown.bs.modal', async function() {
            const video = document.getElementById('cameraVideo');
            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({ 
                    video: { facingMode: 'user' } 
                });
                video.srcObject = cameraStream;
            } catch (err) {
                alert('Error accessing camera: ' + err.message);
            }
        });

        // Stop camera when modal is closed
        document.getElementById('cameraModal').addEventListener('hidden.bs.modal', function() {
            const video = document.getElementById('cameraVideo');
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                video.srcObject = null;
            }
        });

        // Capture photo from camera
        document.getElementById('captureButton').addEventListener('click', function() {
            const video = document.getElementById('cameraVideo');
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0);
            
            capturedImageData = canvas.toDataURL('image/png');
            alert('Photo captured! Submit the form to save it.');
        });

        // Set selected employee
        function setSelectedEmployee(id, name) {
            document.getElementById('selectedEmployeeId').value = id;
            document.getElementById('selectedEmployeeName').textContent = name;
        }

        // Handle photo upload form submission
        document.getElementById('photoUploadForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const employeeId = document.getElementById('employeeSelect').value;
            const photoInput = document.getElementById('photoInput');
            
            if (!employeeId) {
                alert('Please select an employee');
                return;
            }
            
            // If no file selected but camera was used, handle camera data
            if (capturedImageData && photoInput.files.length === 0) {
                const formData = new FormData();
                formData.append('action', 'upload_photo');
                formData.append('employee_id', employeeId);
                formData.append('photo_base64', capturedImageData);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                }).then(response => {
                    if (response.ok) {
                        location.reload();
                    }
                });
            } else {
                this.submit();
            }
        });
    </script>
</body>
</html>
