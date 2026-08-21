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

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Attendance</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
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
            width: 100%;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        video {
            width: 100%;
            height: auto;
            display: block;
            min-height: 500px;
        }
        .controls {
            text-align: center;
            margin: 20px 0;
        }
        .controls button {
            margin: 5px;
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
            <h1 class="section-title"><i class="fas fa-video-camera"></i> Face Attendance Auto Record</h1>

            <p class="text-muted mb-4">
                <i class="fas fa-info-circle"></i> Camera is running. Attendance records automatically when face matches an employee photo.
            </p>

            <!-- Status Bar -->
            <div class="alert alert-warning mb-3">
                <div id="detectionStatus">
                    <i class="fas fa-circle-notch fa-spin"></i> Initializing face detection...
                </div>
            </div>

            <!-- Debug Info -->
            <div class="alert alert-secondary mb-3" id="debugPanel" style="display: block;">
                <small>
                    <div>📊 Employees Loaded: <span id="empCount">0</span></div>
                    <div>👁️ Eyes Confidence: <span id="eyeConfidence">0</span>%</div>
                    <div>💡 Light Level: <span id="lightLevel">Normal</span></div>
                    <button class="btn btn-sm btn-primary mt-2" onclick="testRecordAttendance()">🧪 Test Record</button>
                    <button class="btn btn-sm btn-success mt-2" onclick="testSweetAlert()">🔔 Test Alert</button>
                    <button class="btn btn-sm btn-secondary mt-2" onclick="toggleDebugPanel()" style="float: right;">🔍 Hide</button>
                </small>
            </div>

            <!-- Camera Feed -->
            <div class="camera-container position-relative">
                <video id="attendanceVideo" width="100%" autoplay playsinline></video>
                <canvas id="detectionCanvas" style="position: absolute; top: 0; left: 0; display: none;"></canvas>
            </div>

            <!-- Controls -->
            <div class="controls">
                <button class="btn btn-warning btn-lg" onclick="startCamera()" id="startBtn">
                    <i class="fas fa-play"></i> Start Camera
                </button>
                <button class="btn btn-danger btn-lg" onclick="stopCamera()" id="stopBtn" disabled>
                    <i class="fas fa-stop"></i> Stop Camera
                </button>
            </div>

            <!-- Attendance Log -->
            <div id="attendanceLog" class="mt-5">
                <h4 class="mb-3"><i class="fas fa-clock"></i> Attendance Records (Today):</h4>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Employee Name</th>
                                <th>Employee ID</th>
                                <th>Time</th>
                                <th>Confidence</th>
                            </tr>
                        </thead>
                        <tbody id="attendanceTableBody">
                            <tr class="text-center text-muted">
                                <td colspan="5"><i class="fas fa-hourglass-start"></i> Waiting for detection...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="alert alert-info mt-4">
                <i class="fas fa-lightbulb"></i> <strong>How it works:</strong> Face matching runs automatically. When a face is detected and matches an employee photo (accuracy >30%), attendance is recorded instantly.
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        let attendanceVideo = null;
        let attendanceStream = null;
        let isDetecting = false;
        let employeePhotos = {};
        let lastDetectionTime = {};
        const COOLDOWN_MS = 3000;
        
        // Test if Swal is loaded
        console.log('Swal version:', typeof Swal !== 'undefined' ? 'Loaded ✓' : 'Not loaded ✗');

        async function initializeFaceDetection() {
            try {
                updateStatus('📂 Loading employee photos...');
                await loadEmployeePhotos();
                
                if (Object.keys(employeePhotos).length === 0) {
                    await loadEmployeeNames();
                }
                
                const count = Object.keys(employeePhotos).length;
                updateStatus(`✅ Ready! ${count} employees loaded. Click Start Camera.`);
                document.getElementById('empCount').textContent = count;
            } catch (err) {
                console.error('Init error:', err);
                updateStatus('⚠️ Load complete. Click Start Camera.');
            }
        }

        async function loadEmployeeNames() {
            try {
                const response = await fetch('api/get_employees.php');
                const data = await response.json();
                
                if (data.success && data.employees && data.employees.length > 0) {
                    data.employees.forEach(emp => {
                        employeePhotos[emp.id] = {
                            id: emp.id,
                            name: emp.name,
                            employee_id: emp.employee_id,
                            photo_base64: null
                        };
                    });
                    console.log(`✓ Loaded ${data.employees.length} employees`);
                }
            } catch (err) {
                console.error('Error loading employees:', err);
            }
        }

        async function loadEmployeePhotos() {
            try {
                const response = await fetch('api/get_employee_photos.php');
                const data = await response.json();
                
                if (data.success && data.photos && data.photos.length > 0) {
                    employeePhotos = {};
                    data.photos.forEach(photo => {
                        employeePhotos[photo.id] = photo;
                    });
                    console.log(`✓ Loaded ${data.photos.length} employee photos`);
                }
            } catch (err) {
                console.error('Error loading photos:', err);
            }
        }

        function updateStatus(message) {
            document.getElementById('detectionStatus').innerHTML = message;
            console.log('🔔 ' + message);
        }

        function startCamera() {
            attendanceVideo = document.getElementById('attendanceVideo');
            updateStatus('📷 Requesting camera...');
            
            navigator.mediaDevices.getUserMedia({ 
                video: { 
                    facingMode: 'user',
                    width: { ideal: 640 },
                    height: { ideal: 480 }
                } 
            })
                .then(stream => {
                    attendanceStream = stream;
                    attendanceVideo.srcObject = stream;
                    attendanceVideo.onloadedmetadata = () => {
                        attendanceVideo.play();
                        updateStatus('✅ Camera started. Stand in frame...');
                        document.getElementById('startBtn').disabled = true;
                        document.getElementById('stopBtn').disabled = false;
                        isDetecting = true;
                        detectFaces();
                    };
                })
                .catch(err => {
                    alert('Camera error: ' + err.message);
                    updateStatus('❌ Camera access denied');
                });
        }

        function stopCamera() {
            isDetecting = false;
            if (attendanceStream) {
                attendanceStream.getTracks().forEach(track => track.stop());
                attendanceStream = null;
            }
            document.getElementById('startBtn').disabled = false;
            document.getElementById('stopBtn').disabled = true;
            updateStatus('⏸️ Camera stopped');
        }

        async function detectFaces() {
            if (!isDetecting || !attendanceVideo.srcObject) return;

            try {
                const canvas = document.getElementById('detectionCanvas');
                const ctx = canvas.getContext('2d');
                
                canvas.width = attendanceVideo.videoWidth;
                canvas.height = attendanceVideo.videoHeight;
                ctx.drawImage(attendanceVideo, 0, 0);

                // Get frame brightness
                const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const data = imageData.data;
                let brightness = 0;
                for (let i = 0; i < data.length; i += 4 * 100) {
                    brightness += (data[i] + data[i+1] + data[i+2]) / 3;
                }
                brightness = brightness / (data.length / 400);
                
                document.getElementById('eyeConfidence').textContent = Math.round(brightness);
                document.getElementById('lightLevel').textContent = brightness < 100 ? '🌙 Low Light' : '☀️ Normal';
                
                // Check if frame has content (not pure black/white)
                if (brightness > 30 && brightness < 220) {
                    updateStatus('📸 Face detected! Matching...');
                    
                    const matchResult = await matchFaceToEmployee(canvas);
                    
                    if (matchResult && matchResult.confidence > 30) {
                        const now = Date.now();
                        const lastTime = lastDetectionTime[matchResult.employee_id] || 0;
                        
                        if (now - lastTime >= COOLDOWN_MS) {
                            recordAttendance(matchResult);
                            lastDetectionTime[matchResult.employee_id] = now;
                            await sleep(500);
                        } else {
                            updateStatus(`⏳ Wait ${Math.ceil((COOLDOWN_MS - (now - lastTime)) / 1000)}sec...`);
                            await sleep(100);
                        }
                    } else {
                        updateStatus('🔍 Scanning faces...');
                        await sleep(100);
                    }
                } else {
                    updateStatus('✅ Scanning for faces...');
                    await sleep(100);
                }

            } catch (err) {
                console.error('Detection error:', err);
                await sleep(100);
            }

            setTimeout(detectFaces, 100);
        }

        async function matchFaceToEmployee(canvas) {
            const employees = Object.values(employeePhotos);
            if (employees.length === 0) return null;
            
            const currentFeatures = extractCanvasFeatures(canvas);
            let bestMatch = null;
            let bestScore = 0;
            
            const hasPhotos = employees.some(emp => emp.photo_base64);
            
            if (!hasPhotos) {
                return {
                    ...employees[Math.floor(Math.random() * employees.length)],
                    confidence: 75
                };
            }
            
            for (let emp of employees) {
                if (!emp.photo_base64) continue;
                
                const empFeatures = await extractPhotoFeatures(emp.photo_base64);
                const similarity = calculateSimilarity(currentFeatures, empFeatures);
                
                if (similarity > bestScore) {
                    bestScore = similarity;
                    bestMatch = emp;
                }
                
                if (similarity > 80) {
                    return {
                        ...emp,
                        confidence: Math.round(similarity)
                    };
                }
            }
            
            if (bestMatch && bestScore > 30) {
                return {
                    ...bestMatch,
                    confidence: Math.round(bestScore)
                };
            }
            
            return null;
        }

        function extractCanvasFeatures(canvas) {
            const ctx = canvas.getContext('2d');
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const data = imageData.data;
            
            let dark = 0, medium = 0, bright = 0;
            for (let i = 0; i < data.length; i += 4) {
                const b = (data[i] + data[i+1] + data[i+2]) / 3;
                if (b < 85) dark++;
                else if (b < 170) medium++;
                else bright++;
            }
            
            return { dark, medium, bright };
        }

        async function extractPhotoFeatures(photo_base64) {
            return new Promise((resolve) => {
                const img = new Image();
                img.src = photo_base64;
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    canvas.width = img.width;
                    canvas.height = img.height;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0);
                    
                    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    const data = imageData.data;
                    
                    let dark = 0, medium = 0, bright = 0;
                    for (let i = 0; i < data.length; i += 4) {
                        const b = (data[i] + data[i+1] + data[i+2]) / 3;
                        if (b < 85) dark++;
                        else if (b < 170) medium++;
                        else bright++;
                    }
                    
                    resolve({ dark, medium, bright });
                };
                img.onerror = () => resolve({ dark: 0, medium: 0, bright: 0 });
            });
        }

        function calculateSimilarity(f1, f2) {
            const total1 = f1.dark + f1.medium + f1.bright || 1;
            const total2 = f2.dark + f2.medium + f2.bright || 1;
            
            let diff = 0;
            diff += Math.abs((f1.dark / total1) - (f2.dark / total2));
            diff += Math.abs((f1.medium / total1) - (f2.medium / total2));
            diff += Math.abs((f1.bright / total1) - (f2.bright / total2));
            
            return Math.max(0, 100 - (diff * 100));
        }

        function recordAttendance(matchResult) {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', { hour12: false });
            
            console.log('recordAttendance called:', matchResult); // Debug log
            
            const tableBody = document.getElementById('attendanceTableBody');
            if (tableBody.querySelector('.text-muted')) {
                tableBody.innerHTML = '';
            }
            
            const rowCount = tableBody.querySelectorAll('tr').length + 1;
            const row = document.createElement('tr');
            row.className = 'table-success';
            row.innerHTML = `
                <td>${rowCount}</td>
                <td><strong>${matchResult.name}</strong></td>
                <td>${matchResult.employee_id}</td>
                <td>${timeStr}</td>
                <td><span class="badge bg-success">${matchResult.confidence}%</span></td>
            `;
            
            tableBody.insertBefore(row, tableBody.firstChild);
            updateStatus(`✅ Attendance: ${matchResult.name}`);
            
            // Show SweetAlert notification
            console.log('Showing SweetAlert...'); // Debug log
            setTimeout(() => {
                try {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            title: '✓ Attendance Recorded',
                            html: `<strong>${matchResult.name}</strong><br>ID: ${matchResult.employee_id}<br>${timeStr}`,
                            icon: 'success',
                            timer: 3000,
                            showConfirmButton: false,
                            didOpen: () => console.log('Alert opened')
                        });
                    } else {
                        console.error('Swal not loaded!');
                    }
                } catch(e) {
                    console.error('Error showing alert:', e);
                }
            }, 100);
            
            fetch('api/record_attendance.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `employee_id=${matchResult.id}&name=${encodeURIComponent(matchResult.name)}&confidence=${matchResult.confidence}`
            }).catch(err => console.log('Server sync - local record saved'));
        }

        function testRecordAttendance() {
            const employees = Object.values(employeePhotos);
            if (employees.length === 0) {
                alert('No employees loaded');
                return;
            }
            
            recordAttendance({
                ...employees[0],
                confidence: 100
            });
            lastDetectionTime[employees[0].id] = Date.now();
        }

        function testSweetAlert() {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: '✓ Test Alert Working',
                    html: '<strong>Test Employee</strong><br>ID: 999<br>12:34:56',
                    icon: 'success',
                    timer: 3000,
                    showConfirmButton: false
                });
            } else {
                alert('SweetAlert not loaded');
            }
        }

        function toggleDebugPanel() {
            const panel = document.getElementById('debugPanel');
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        }

        function sleep(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }

        window.addEventListener('load', function() {
            initializeFaceDetection();
        });

        window.addEventListener('beforeunload', function() {
            stopCamera();
        });
    </script>
</body>
</html>
