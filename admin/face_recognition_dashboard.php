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

// Fetch suparadmin details
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin_user = $stmt->get_result()->fetch_assoc();
$admin_name = $admin_user['name'] ?? 'Super Admin';
$stmt->close();

// Create uploads directory
$upload_dir = '../uploads/employee_photos';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// ============ EMPLOYEE PHOTOS SECTION ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // UPLOAD PHOTO ACTION
    if ($_POST['action'] === 'upload_photo') {
        $employee_id = intval($_POST['employee_id']);
        
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
                
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if (!in_array($file_extension, $allowed_extensions)) {
                    $message = "✗ Invalid file type. Only JPG, PNG, GIF allowed";
                    $message_type = "danger";
                } else if ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
                    $message = "✗ File size too large. Maximum 5MB allowed";
                    $message_type = "danger";
                } else {
                    $unique_filename = 'emp_' . $employee_id . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . '/' . $unique_filename;
                    
                    // Delete old photo if exists
                    $old_photo_stmt = $conn->prepare("SELECT profile_photo FROM users WHERE id = ?");
                    $old_photo_stmt->bind_param("i", $employee_id);
                    $old_photo_stmt->execute();
                    $old_data = $old_photo_stmt->get_result()->fetch_assoc();
                    $old_photo_stmt->close();
                    
                    if ($old_data && !empty($old_data['profile_photo'])) {
                        $old_path = '../' . $old_data['profile_photo'];
                        if (file_exists($old_path)) {
                            unlink($old_path);
                        }
                    }
                    
                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        $photo_path = 'uploads/employee_photos/' . $unique_filename;
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
            } else {
                $message = "✗ No file selected or upload error";
                $message_type = "danger";
            }
        }
    }
    
    // MANUAL ATTENDANCE ACTION
    else if ($_POST['action'] === 'manual_attendance') {
        $employee_id = intval($_POST['employee_id']);
        $attendance_type = $_POST['attendance_type']; // 'punch_in' or 'punch_out'
        $attendance_date = $_POST['attendance_date'];
        $attendance_time = $_POST['attendance_time'];
        
        // Verify employee exists
        $stmt = $conn->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'employee'");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $message = "✗ Employee not found";
            $message_type = "danger";
        } else {
            $employee = $stmt->get_result()->fetch_assoc();
            
            // Check if attendance record exists for this date
            $check_stmt = $conn->prepare("SELECT id, punch_in, punch_out FROM attendance WHERE user_id = ? AND date = ?");
            $check_stmt->bind_param("is", $employee_id, $attendance_date);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                // Update existing record
                $attendance = $check_result->fetch_assoc();
                
                if ($attendance_type === 'punch_in') {
                    $update_stmt = $conn->prepare("UPDATE attendance SET punch_in = ?, status = 'Present' WHERE user_id = ? AND date = ?");
                } else {
                    $update_stmt = $conn->prepare("UPDATE attendance SET punch_out = ?, status = 'Present' WHERE user_id = ? AND date = ?");
                }
                
                $update_stmt->bind_param("sis", $attendance_time, $employee_id, $attendance_date);
                if ($update_stmt->execute()) {
                    $message = "✓ " . ucfirst(str_replace('_', ' ', $attendance_type)) . " recorded for " . htmlspecialchars($employee['name']);
                    $message_type = "success";
                } else {
                    $message = "✗ Error updating attendance";
                    $message_type = "danger";
                }
                $update_stmt->close();
            } else {
                // Create new record
                if ($attendance_type === 'punch_in') {
                    $insert_stmt = $conn->prepare("INSERT INTO attendance (user_id, date, punch_in, status) VALUES (?, ?, ?, 'Present')");
                } else {
                    $insert_stmt = $conn->prepare("INSERT INTO attendance (user_id, date, punch_out, status) VALUES (?, ?, ?, 'Present')");
                }
                
                $insert_stmt->bind_param("iss", $employee_id, $attendance_date, $attendance_time);
                if ($insert_stmt->execute()) {
                    $message = "✓ " . ucfirst(str_replace('_', ' ', $attendance_type)) . " recorded for " . htmlspecialchars($employee['name']);
                    $message_type = "success";
                } else {
                    $message = "✗ Error recording attendance";
                    $message_type = "danger";
                }
                $insert_stmt->close();
            }
            
            $check_stmt->close();
        }
    }
}

// Fetch all employees for dropdowns
$employees_query = "SELECT id, name, employee_id, profile_photo FROM users WHERE role = 'employee' ORDER BY name ASC";
$employees_result = $conn->query($employees_query);
$employees = [];
if ($employees_result) {
    while ($row = $employees_result->fetch_assoc()) {
        $employees[] = $row;
    }
}

// Fetch employees with photos for face recognition
$face_employees_query = "SELECT id, name, employee_id, profile_photo FROM users WHERE role = 'employee' AND profile_photo IS NOT NULL AND profile_photo != '' ORDER BY name ASC";
$face_employees_result = $conn->query($face_employees_query);
$face_employees = [];
$face_employees_json = [];
if ($face_employees_result) {
    while ($row = $face_employees_result->fetch_assoc()) {
        $face_employees[] = $row;
        $face_employees_json[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'employee_id' => $row['employee_id'],
            'photo_path' => '../' . $row['profile_photo']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - 🎭 Face Recognition & 📸 Employee Photos</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2em;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 0.95em;
            opacity: 0.9;
        }
        
        .tabs {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            background: #f5f5f5;
        }
        
        .tab-button {
            flex: 1;
            padding: 15px 20px;
            text-align: center;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1em;
            font-weight: 500;
            color: #666;
            transition: all 0.3s ease;
        }
        
        .tab-button:hover {
            background: #e8e8e8;
        }
        
        .tab-button.active {
            color: #667eea;
            border-bottom: 3px solid #667eea;
            background: white;
        }
        
        .tab-content {
            display: none;
            padding: 30px;
            animation: fadeIn 0.3s ease;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .message {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 5px;
            border-left: 4px solid;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border-color: #28a745;
        }
        
        .message.danger {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        
        .message.warning {
            background: #fff3cd;
            color: #856404;
            border-color: #ffeaa7;
        }
        
        .form-section {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1em;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        #camera-feed {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            display: block;
            border-radius: 8px;
            background: #000;
        }
        
        .employee-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .employee-item {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: background 0.2s;
        }
        
        .employee-item:hover {
            background: #f0f0f0;
        }
        
        .employee-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background: #e0e0e0;
        }
        
        .employee-info {
            flex: 1;
        }
        
        .employee-info .name {
            font-weight: 600;
            color: #333;
        }
        
        .employee-info .id {
            font-size: 0.85em;
            color: #666;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab-button {
                border-bottom: none;
                border-left: 3px solid transparent;
            }
            
            .tab-button.active {
                border-bottom: none;
                border-left: 3px solid #667eea;
            }
        }
        
        .face-canvas {
            display: none;
        }
        
        .recognition-result {
            padding: 15px;
            background: #e3f2fd;
            border-radius: 5px;
            margin-top: 15px;
            display: none;
        }
        
        .recognition-result.show {
            display: block;
        }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>
    <div class="container">
        <div class="header">
            <h1>🎭 Face Recognition & 📸 Employee Photos Panel</h1>
            <p>Welcome, <?php echo htmlspecialchars($admin_name); ?></p>
        </div>
        
        <?php if ($message): ?>
        <div style="padding: 30px 30px 0 30px;">
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="tabs">
            <button class="tab-button active" onclick="switchTab('face-recognition')">🎭 Face Recognition</button>
            <button class="tab-button" onclick="switchTab('employee-photos')">📸 Employee Photos</button>
            <button class="tab-button" onclick="switchTab('manual-entry')">✍️ Manual Entry</button>
        </div>
        
        <!-- ========== FACE RECOGNITION TAB ========== -->
        <div id="face-recognition" class="tab-content active" style="padding: 30px;">
            <div class="form-section">
                <h3 style="margin-bottom: 20px; color: #333;">🎭 Real-time Face Recognition - Auto Punch In/Out</h3>
                
                <div class="form-group">
                    <label>📷 Camera Feed</label>
                    <video id="camera-feed" autoplay playsinline muted></video>
                    <canvas class="face-canvas" id="face-canvas"></canvas>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
                    <button class="btn-primary" id="start-camera-btn" onclick="startCamera()">▶️ Start Camera</button>
                    <button class="btn-secondary" id="stop-camera-btn" onclick="stopCamera()" style="display: none;">⏹️ Stop Camera</button>
                </div>
                
                <div class="recognition-result" id="recognition-result">
                    <strong>Recognition Result:</strong>
                    <p id="recognition-message"></p>
                    <p id="recognition-employee"></p>
                    <p id="recognition-time" style="font-size: 0.9em; color: #666;"></p>
                </div>
            </div>
        </div>
        
        <!-- ========== EMPLOYEE PHOTOS TAB ========== -->
        <div id="employee-photos" class="tab-content" style="padding: 30px;">
            <div class="form-section">
                <h3 style="margin-bottom: 20px; color: #333;">📸 Manage Employee Photos</h3>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>🔍 Search Employee (Name or ID)</label>
                        <input type="text" id="employee-search" placeholder="Type name or employee ID..." onkeyup="searchEmployees()">
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <label style="font-weight: 600; margin-bottom: 10px; display: block;">Selected Employees:</label>
                    <div class="employee-list" id="employee-list">
                        <?php foreach ($employees as $emp): ?>
                        <div class="employee-item" onclick="selectEmployee(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['name']); ?>')">
                            <?php if ($emp['profile_photo']): ?>
                                <img src="../<?php echo htmlspecialchars($emp['profile_photo']); ?>" alt="<?php echo htmlspecialchars($emp['name']); ?>" class="employee-photo">
                            <?php else: ?>
                                <div class="employee-photo" style="display: flex; align-items: center; justify-content: center; font-size: 20px;">👤</div>
                            <?php endif; ?>
                            <div class="employee-info">
                                <div class="name"><?php echo htmlspecialchars($emp['name']); ?></div>
                                <div class="id">ID: <?php echo htmlspecialchars($emp['employee_id']); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div id="photo-upload-section" style="display: none; margin-top: 30px; padding-top: 30px; border-top: 2px solid #ddd;">
                    <h4 style="margin-bottom: 15px;">Upload Photo for: <span id="selected-employee-name" style="color: #667eea;"></span></h4>
                    
                    <form method="POST" enctype="multipart/form-data" id="photo-form">
                        <input type="hidden" name="action" value="upload_photo">
                        <input type="hidden" name="employee_id" id="upload-employee-id" value="">
                        
                        <div class="form-group">
                            <label>Select Photo (JPG, PNG, GIF - Max 5MB)</label>
                            <input type="file" name="photo" id="photo-input" accept="image/*" required>
                        </div>
                        
                        <button type="submit" class="btn-primary">📤 Upload Photo</button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- ========== MANUAL ENTRY TAB ========== -->
        <div id="manual-entry" class="tab-content" style="padding: 30px;">
            <div class="form-section">
                <h3 style="margin-bottom: 20px; color: #333;">✍️ Manual Attendance Entry</h3>
                <p style="color: #666; margin-bottom: 20px;">⚠️ Only Suparadmin can access this. Mark attendance for employees who couldn't use the employee dashboard.</p>
                
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label>👤 Select Employee</label>
                            <select name="employee_id" required>
                                <option value="">-- Select Employee --</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>">
                                    <?php echo htmlspecialchars($emp['name']) . ' (' . htmlspecialchars($emp['employee_id']) . ')'; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>📅 Date</label>
                            <input type="date" name="attendance_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>🕐 Punch In Time</label>
                            <input type="time" name="attendance_time" placeholder="HH:MM">
                        </div>
                        
                        <div class="form-group">
                            <label>📝 Type</label>
                            <select name="attendance_type" required>
                                <option value="">-- Select Type --</option>
                                <option value="punch_in">Punch In</option>
                                <option value="punch_out">Punch Out</option>
                            </select>
                        </div>
                    </div>
                    
                    <input type="hidden" name="action" value="manual_attendance">
                    <button type="submit" class="btn-primary">✓ Record Attendance</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        let cameraStream = null;
        
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
            
            // Stop camera if switching away from face recognition
            if (tabName !== 'face-recognition') {
                stopCamera();
            }
        }
        
        function selectEmployee(employeeId, employeeName) {
            document.getElementById('selected-employee-name').textContent = employeeName;
            document.getElementById('upload-employee-id').value = employeeId;
            document.getElementById('photo-upload-section').style.display = 'block';
        }
        
        function searchEmployees() {
            const searchInput = document.getElementById('employee-search').value.toLowerCase();
            const employeeItems = document.querySelectorAll('.employee-item');
            
            employeeItems.forEach(item => {
                const name = item.querySelector('.name').textContent.toLowerCase();
                const id = item.querySelector('.id').textContent.toLowerCase();
                
                if (name.includes(searchInput) || id.includes(searchInput)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }
        
        async function startCamera() {
            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({ 
                    video: { facingMode: 'user' } 
                });
                const video = document.getElementById('camera-feed');
                video.srcObject = cameraStream;
                
                document.getElementById('start-camera-btn').style.display = 'none';
                document.getElementById('stop-camera-btn').style.display = 'inline-block';
                
                // Start face recognition
                setTimeout(() => {
                    captureFaceFrame();
                }, 2000);
                
            } catch (error) {
                alert('Error accessing camera: ' + error.message);
            }
        }
        
        function stopCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                document.getElementById('camera-feed').srcObject = null;
            }
            
            document.getElementById('start-camera-btn').style.display = 'inline-block';
            document.getElementById('stop-camera-btn').style.display = 'none';
            document.getElementById('recognition-result').classList.remove('show');
        }
        
        function captureFaceFrame() {
            const video = document.getElementById('camera-feed');
            const canvas = document.getElementById('face-canvas');
            const ctx = canvas.getContext('2d');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0);
            
            // Get image data
            const imageData = canvas.toDataURL('image/jpeg');
            
            // Simulate face recognition (in real implementation, use face-api.js or TensorFlow.js)
            // For now, show placeholder
            setTimeout(() => {
                recognizeEmployee(imageData);
            }, 2000);
        }
        
        function recognizeEmployee(imageData) {
            const resultDiv = document.getElementById('recognition-result');
            const messageEl = document.getElementById('recognition-message');
            const employeeEl = document.getElementById('recognition-employee');
            const timeEl = document.getElementById('recognition-time');
            
            // Send to server for face recognition
            fetch('../admin/process_face_recognition.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    image: imageData
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageEl.textContent = '✅ ' + data.message;
                    employeeEl.textContent = 'Employee: ' + data.employee_name + ' (' + data.employee_id + ')';
                    timeEl.textContent = 'Time: ' + new Date().toLocaleTimeString();
                    resultDiv.classList.add('show');
                    
                    // Continue capturing
                    setTimeout(() => {
                        if (cameraStream) captureFaceFrame();
                    }, 3000);
                } else {
                    messageEl.textContent = '❌ ' + data.message;
                    resultDiv.classList.add('show');
                    
                    setTimeout(() => {
                        if (cameraStream) captureFaceFrame();
                    }, 2000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                messageEl.textContent = '⚠️ Recognition error';
                resultDiv.classList.add('show');
                
                setTimeout(() => {
                    if (cameraStream) captureFaceFrame();
                }, 2000);
            });
        }
    </script>
</body>
</html>
