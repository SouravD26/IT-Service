<?php
session_start();

// Check if user is logged in as 'employee' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header('Location: auth/login.php');
    exit();
}

include('config/db.php');

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// Get today's attendance record (unified - first punch_in and last punch_out)
$today = date('Y-m-d');
$check_attendance = $conn->query("SELECT 
    MIN(punch_in) as first_punch_in, 
    MAX(punch_out) as last_punch_out, 
    MAX(CASE WHEN punch_in IS NOT NULL THEN 1 ELSE 0 END) as has_punch_in,
    MAX(CASE WHEN punch_out IS NOT NULL THEN 1 ELSE 0 END) as has_punch_out,
    COUNT(*) as punch_count,
    status 
FROM attendance 
WHERE user_id = '$user_id' AND date = '$today'");
$today_attendance = $check_attendance->fetch_assoc();

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

// Calculate total hours for today
$today_hours = calculateHours($today_attendance['first_punch_in'] ?? null, $today_attendance['last_punch_out'] ?? null, $today);

// Get attendance history
$history = $conn->query("SELECT 
    date, 
    MIN(punch_in) as first_punch_in, 
    MAX(punch_out) as last_punch_out, 
    status 
FROM attendance 
WHERE user_id = '$user_id' 
GROUP BY date 
ORDER BY date DESC 
LIMIT 30");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Face Attendance - <?php echo htmlspecialchars($user_name); ?></title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        :root {
            --primary: #667eea;
            --success: #48bb78;
            --danger: #f56565;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-info h2 {
            margin: 0;
            color: #333;
            font-weight: 600;
        }
        
        .user-info p {
            margin: 0;
            color: #666;
            font-size: 0.95rem;
        }
        
        .btn-logout {
            background: #f56565;
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
        }
        
        .btn-logout:hover {
            background: #e53e3e;
            color: white;
        }
        
        .camera-section {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .camera-container {
            position: relative;
            border: 3px solid var(--primary);
            border-radius: 12px;
            overflow: hidden;
            background: #000;
            margin-bottom: 20px;
        }
        
        #videoFeed {
            width: 100%;
            height: 400px;
            object-fit: cover;
            transform: scaleX(-1);
        }
        
        .canvas-hidden {
            display: none;
        }
        
        .camera-controls {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-control {
            padding: 12px 20px;
            font-size: 1rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-start {
            background: var(--primary);
            color: white;
        }
        
        .btn-start:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }
        
        .btn-stop {
            background: #f56565;
            color: white;
        }
        
        .btn-stop:hover {
            background: #e53e3e;
            transform: translateY(-2px);
        }
        
        .btn-punch {
            background: var(--success);
            color: white;
            font-size: 1.1rem;
        }
        
        .btn-punch:hover {
            background: #38a169;
            transform: translateY(-2px);
        }
        
        .status-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .status-box h4 {
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .status-time {
            font-size: 2rem;
            font-weight: bold;
            font-family: 'Courier New', monospace;
        }
        
        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            margin-top: 10px;
            font-weight: 600;
        }
        
        .badge-punched-in {
            background: rgba(72, 187, 120, 0.3);
            color: #48bb78;
        }
        
        .badge-punched-out {
            background: rgba(245, 101, 101, 0.3);
            color: #f56565;
        }
        
        .badge-not-started {
            background: rgba(255, 193, 7, 0.3);
            color: #ffc107;
        }
        
        .detection-info {
            background: #f0f7ff;
            border-left: 4px solid var(--primary);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }
        
        .detection-info i {
            color: var(--primary);
            margin-right: 8px;
        }
        
        .attendance-section {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .attendance-table th {
            background: var(--primary);
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        
        .attendance-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        .attendance-table tr:hover {
            background: #f5f5f5;
        }
        
        .status-present {
            color: var(--success);
            font-weight: 600;
        }
        
        .status-absent {
            color: var(--danger);
            font-weight: 600;
        }
        
        h3 {
            color: #333;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .face-detected {
            border-color: var(--success) !important;
            background: rgba(72, 187, 120, 0.1) !important;
        }
        
        .detection-log {
            background: #f0f7ff;
            padding: 15px;
            border-radius: 8px;
            max-height: 150px;
            overflow-y: auto;
            font-size: 0.9rem;
            font-family: 'Courier New', monospace;
        }
        
        .log-entry {
            margin: 5px 0;
            padding: 5px;
            color: #666;
        }
        
        .log-success {
            color: var(--success);
            font-weight: 600;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="page-header">
        <div class="user-info">
            <i class="fas fa-user-circle" style="font-size: 2.5rem; color: var(--primary);"></i>
            <div>
                <h2><?php echo htmlspecialchars($user_name); ?></h2>
                <p>👤 Employee | Face Attendance System</p>
            </div>
        </div>
        <a href="auth/logout.php" class="btn-logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
    
    <div class="container-fluid">
        <!-- Today's Status -->
        <div class="status-box">
            <h4><i class="fas fa-calendar-today"></i> Today's Status - <?php echo date('d M Y'); ?></h4>
            
            <?php if ($today_attendance && $today_attendance['first_punch_in']): ?>
                <div style="margin: 15px 0;">
                    <div class="status-badge <?php echo ($today_attendance['last_punch_out']) ? 'badge-punched-out' : 'badge-punched-in'; ?>">
                        <?php 
                        if ($today_attendance['last_punch_out']) {
                            echo '🔴 Punched Out';
                        } else {
                            echo '🟢 Punched In';
                        }
                        ?>
                    </div>
                </div>
                
                <div style="background: rgba(255,255,255,0.15); padding: 15px; border-radius: 8px; margin-top: 15px; font-size: 0.95rem;">
                    <p style="margin: 8px 0;">
                        <strong>⏰ First Punch In:</strong> <?php echo substr($today_attendance['first_punch_in'], 0, 5); ?>
                    </p>
                    <p style="margin: 8px 0;">
                        <strong>⏹️ Last Punch Out:</strong> 
                        <?php echo $today_attendance['last_punch_out'] ? substr($today_attendance['last_punch_out'], 0, 5) : 'Pending...'; ?>
                    </p>
                    <p style="margin: 8px 0; font-size: 1.1rem; font-weight: bold;">
                        <strong>⏳ Total Hours:</strong> 
                        <span style="background: rgba(255,255,255,0.3); padding: 5px 10px; border-radius: 5px;">
                            <?php echo $today_hours['formatted']; ?>
                        </span>
                    </p>
                    <?php if ($today_attendance['punch_count'] > 1): ?>
                    <p style="margin: 8px 0; font-size: 0.85rem; opacity: 0.9;">
                        <i class="fas fa-info-circle"></i> Multiple punch in/out detected (<?php echo $today_attendance['punch_count']; ?> records)
                    </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="status-badge badge-not-started">
                    ⏳ Not Started Yet
                </div>
                <p style="margin-top: 15px; font-size: 0.95rem;">Start Camera and record your punch in</p>
            <?php endif; ?>
        </div>
        
        <div class="row">
            <!-- Camera Section -->
            <div class="col-lg-8">
                <div class="camera-section">
                    <h3><i class="fas fa-camera"></i> Face Attendance Detection</h3>
                    
                    <div class="detection-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>How to use:</strong> Press "Start Camera", stand in front of the camera for 2 seconds. Your face will be detected and you'll be automatically punched in.
                    </div>
                    
                    <div style="position: relative;">
                        <div class="camera-container">
                            <video id="videoFeed" playsinline></video>
                            <canvas id="canvas" class="canvas-hidden"></canvas>
                        </div>
                        
                        <div class="camera-controls">
                            <button class="btn-control btn-start" id="startCameraBtn" onclick="startCamera()">
                                <i class="fas fa-play"></i> Start Camera
                            </button>
                            <button class="btn-control btn-stop" id="stopCameraBtn" onclick="stopCamera()" disabled>
                                <i class="fas fa-stop"></i> Stop Camera
                            </button>
                            <?php if ($today_attendance && $today_attendance['first_punch_in'] && !$today_attendance['last_punch_out']): ?>
                            <button class="btn-control" id="punchOutBtn" onclick="manualPunchOut()"
                                style="background:#f56565;color:white;font-size:1.1rem;font-weight:600;">
                                <i class="fas fa-sign-out-alt"></i> Punch Out
                            </button>
                            <?php else: ?>
                            <button class="btn-control btn-punch" id="manualPunchBtn" onclick="manualPunchIn()" disabled>
                                <i class="fas fa-hand-paper"></i> Manual Punch
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <h5>Detection Log:</h5>
                        <div class="detection-log" id="detectionLog">
                            <div class="log-entry">Waiting for camera...</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Status Panel -->
            <div class="col-lg-4">
                <div class="camera-section">
                    <h3><i class="fas fa-clock"></i> Time Tracker</h3>
                    
                    <div style="text-align: center; margin: 30px 0;">
                        <div class="status-time" id="currentTime">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                    </div>
                    
                    <div style="background: #f0f7ff; padding: 15px; border-radius: 8px; text-align: center;">
                        <p style="margin: 0; color: #666; font-size: 0.9rem;">Current Status</p>
                        <div id="currentStatus" style="font-size: 1.2rem; font-weight: 600; color: var(--primary); margin-top: 8px;">
                            <?php 
                            if ($today_attendance && $today_attendance['first_punch_in'] && !$today_attendance['last_punch_out']) {
                                echo '🟢 Active (Punched In)';
                            } elseif ($today_attendance && $today_attendance['last_punch_out']) {
                                echo '🔴 Inactive (Punched Out)';
                            } else {
                                echo '⏳ Waiting for Punch In';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 15px;">
                        <h5 style="margin-bottom: 12px; margin-top: 0;">Quick Info</h5>
                        <p style="margin: 8px 0; font-size: 0.9rem;">
                            <i class="fas fa-id-card" style="color: var(--primary);"></i> 
                            <strong>Employee ID:</strong> <?php echo $_SESSION['employee_id'] ?? 'N/A'; ?>
                        </p>
                        <p style="margin: 8px 0; font-size: 0.9rem;">
                            <i class="fas fa-building" style="color: var(--primary);"></i> 
                            <strong>Department:</strong> <?php echo $_SESSION['department'] ?? 'N/A'; ?>
                        </p>
                        <p style="margin: 8px 0; font-size: 0.9rem;">
                            <i class="fas fa-calendar"></i> 
                            <strong>Date:</strong> <?php echo date('d M Y'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Attendance History -->
        <div class="attendance-section mt-4">
            <h3><i class="fas fa-history"></i> Attendance History (Last 30 Days)</h3>
            
            <div class="table-container">
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>First Punch In</th>
                            <th>Last Punch Out</th>
                            <th>Total Hours</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($history->num_rows > 0) {
                            while ($row = $history->fetch_assoc()) {
                                $punch_in = $row['first_punch_in'] ? substr($row['first_punch_in'], 0, 5) : '-';
                                $punch_out = $row['last_punch_out'] ? substr($row['last_punch_out'], 0, 5) : '-';
                                
                                // Calculate duration
                                $hours_calc = calculateHours($row['first_punch_in'], $row['last_punch_out'], $row['date']);
                                $duration = $hours_calc['formatted'];
                                
                                $status_class = ($row['status'] === 'Present') ? 'status-present' : 'status-absent';
                                $status = $row['status'] ?: 'Absent';
                                ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($row['date'])); ?></td>
                                    <td><strong><?php echo $punch_in; ?></strong></td>
                                    <td><?php echo $punch_out; ?></td>
                                    <td><span style="background: #e8f4f8; padding: 4px 8px; border-radius: 4px; font-weight: 600;"><?php echo $duration; ?></span></td>
                                    <td class="<?php echo $status_class; ?>"><?php echo $status; ?></td>
                                </tr>
                                <?php
                            }
                        } else {
                            ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #999; padding: 30px;">
                                    No attendance records yet
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <canvas id="canvas" class="canvas-hidden"></canvas>
    
    <script>
        let stream = null;
        let detectionActive = false;
        let faceDetected = false;
        let faceDetectionTimeout = null;
        let isPunching = false; // global guard — prevents any double-tap submission
        
        // Update current time
        function updateTime() {
            document.getElementById('currentTime').textContent = new Date().toLocaleTimeString();
        }
        setInterval(updateTime, 1000);
        updateTime();
        
        // Add log entry
        function addLog(message, success = false) {
            const log = document.getElementById('detectionLog');
            const entry = document.createElement('div');
            entry.className = 'log-entry' + (success ? ' log-success' : '');
            entry.textContent = '[' + new Date().toLocaleTimeString() + '] ' + message;
            log.insertBefore(entry, log.firstChild);
            
            // Keep only last 10 entries
            while (log.children.length > 10) {
                log.removeChild(log.lastChild);
            }
        }
        
        // Start Camera
        function startCamera() {
            const video = document.getElementById('videoFeed');
            
            navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user' },
                audio: false
            }).then(strm => {
                stream = strm;
                video.srcObject = stream;
                video.play();
                
                document.getElementById('startCameraBtn').disabled = true;
                document.getElementById('stopCameraBtn').disabled = false;
                document.getElementById('manualPunchBtn').disabled = false;
                
                detectionActive = true;
                addLog('Camera started successfully', true);
                
                // Start face detection loop
                startFaceDetection();
                
            }).catch(err => {
                addLog('Camera error: ' + err.message);
                alert('Cannot access camera. Please check permissions.');
            });
        }
        
        // Stop Camera
        function stopCamera() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            
            document.getElementById('videoFeed').srcObject = null;
            document.getElementById('startCameraBtn').disabled = false;
            document.getElementById('stopCameraBtn').disabled = false;
            document.getElementById('manualPunchBtn').disabled = true;
            
            detectionActive = false;
            addLog('Camera stopped');
        }
        
        // Face Detection Loop (Placeholder - ready for ML integration)
        function startFaceDetection() {
            if (!detectionActive) return;
            
            const video = document.getElementById('videoFeed');
            const canvas = document.getElementById('canvas');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0);
            
            // TODO: Integrate face detection library here
            // For now, we have manual punch button as fallback
            
            setTimeout(startFaceDetection, 100);
        }
        
        // Manual Punch In
        function manualPunchIn() {
            if (isPunching) return;  // block any second tap
            isPunching = true;

            const btn = document.getElementById('manualPunchBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting location...';

            if (!navigator.geolocation) {
                Swal.fire('GPS Not Supported', 'Your device does not support geolocation. Cannot mark attendance.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-hand-paper"></i> Manual Punch';
                return;
            }

            navigator.geolocation.getCurrentPosition(
                pos => {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    const canvas = document.getElementById('canvas');
                    canvas.toBlob(blob => {
                        const formData = new FormData();
                        formData.append('action', 'punch_in');
                        formData.append('latitude',  pos.coords.latitude);
                        formData.append('longitude', pos.coords.longitude);
                        formData.append('photo', blob, 'selfie.jpg');

                        fetch('user_attendance.php', { method: 'POST', body: formData })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                addLog('✅ Punched In Successfully!', true);
                                document.getElementById('currentStatus').innerHTML = '🟢 Active (Punched In)';
                                stopCamera();
                                setTimeout(() => location.reload(), 2000);
                            } else {
                                addLog('❌ ' + data.message);
                                Swal.fire('Cannot Punch In', data.message, 'warning');
                                isPunching = false;
                                btn.disabled = false;
                                btn.innerHTML = '<i class="fas fa-hand-paper"></i> Manual Punch';
                            }
                        })
                        .catch(err => {
                            addLog('Error: ' + err.message);
                            isPunching = false;
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-hand-paper"></i> Manual Punch';
                        });
                    }, 'image/jpeg', 0.95);
                },
                err => {
                    Swal.fire('Location Required', 'GPS access was denied. You must allow location to mark attendance.', 'error');
                    isPunching = false;
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-hand-paper"></i> Manual Punch';
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        }

        // Manual Punch Out
        function manualPunchOut() {
            if (isPunching) return;  // block double-tap
            isPunching = true;

            const btn = document.getElementById('punchOutBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting location...';

            if (!navigator.geolocation) {
                Swal.fire('GPS Not Supported', 'Your device does not support geolocation. Cannot mark attendance.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-sign-out-alt"></i> Punch Out';
                return;
            }

            navigator.geolocation.getCurrentPosition(
                pos => {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    const canvas = document.getElementById('canvas');
                    canvas.toBlob(blob => {
                        const formData = new FormData();
                        formData.append('action', 'punch_out');
                        formData.append('latitude',  pos.coords.latitude);
                        formData.append('longitude', pos.coords.longitude);
                        formData.append('photo', blob, 'selfie.jpg');

                        fetch('user_attendance.php', { method: 'POST', body: formData })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                addLog('✅ Punched Out Successfully!', true);
                                document.getElementById('currentStatus').innerHTML = '🔴 Inactive (Punched Out)';
                                setTimeout(() => location.reload(), 2000);
                            } else {
                                addLog('❌ ' + data.message);
                                Swal.fire('Cannot Punch Out', data.message, 'warning');
                                isPunching = false;
                                btn.disabled = false;
                                btn.innerHTML = '<i class="fas fa-sign-out-alt"></i> Punch Out';
                            }
                        })
                        .catch(err => {
                            addLog('Error: ' + err.message);
                            isPunching = false;
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-sign-out-alt"></i> Punch Out';
                        });
                    }, 'image/jpeg', 0.95);
                },
                err => {
                    Swal.fire('Location Required', 'GPS access was denied. You must allow location to mark attendance.', 'error');
                    isPunching = false;
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-sign-out-alt"></i> Punch Out';
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        }
    </script>
</body>
</html>
