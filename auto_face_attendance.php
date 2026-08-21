<?php
session_start();
include('config/db.php');

// Check if operator is logged in
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
    <title>Auto Face Attendance - Multiple Punch In/Out</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script async src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.js"></script>
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
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            margin-bottom: 30px;
        }
        .camera-container {
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            margin: 20px 0;
            width: 100%;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }
        video {
            width: 100%;
            height: auto;
            display: block;
            min-height: 400px;
        }
        .stats-box {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin: 10px 0;
            text-align: center;
        }
        .punch-record {
            background: #f8f9fa;
            padding: 10px;
            margin: 5px 0;
            border-left: 4px solid #28a745;
            border-radius: 5px;
        }
        .punch-record.out {
            border-left-color: #dc3545;
        }
        .punch-record.in {
            border-left-color: #28a745;
            background: #f0fff4;
        }
        .punch-record.out {
            border-left-color: #dc3545;
            background: #fff5f5;
        }
        .punch-type-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-weight: bold;
            font-size: 12px;
            margin-right: 10px;
        }
        .punch-type-badge.in {
            background: #28a745;
            color: white;
        }
        .punch-type-badge.out {
            background: #dc3545;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-dark">
        <div class="container-fluid">
            <a href="face_dashboard.php" class="navbar-brand">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <span class="text-white"><i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
        </div>
    </nav>

    <div class="container">
        <!-- Main Card -->
        <div class="content-card">
            <h1><i class="fas fa-video"></i> Auto Face Attendance System</h1>
            <p class="text-muted">Continuous face detection with multiple punch in/out support</p>

            <!-- Status -->
            <div class="alert alert-warning mb-3">
                <div id="statusDiv">
                    <i class="fas fa-circle-notch fa-spin"></i> Initializing face recognition system...
                </div>
            </div>

            <!-- Next Action Indicator -->
            <div class="alert alert-info" id="nextActionAlert" style="display: none; margin-bottom: 15px;">
                <strong><i class="fas fa-arrow-right"></i> Next Action:</strong>
                <span id="nextActionText">Waiting for first face detection...</span>
            </div>

            <!-- Stats -->
            <div class="row">
                <div class="col-md-6">
                    <div class="stats-box">
                        <div style="font-size: 12px; opacity: 0.9;">Employees Loaded</div>
                        <div style="font-size: 24px; font-weight: bold;"><span id="empCount">0</span></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stats-box">
                        <div style="font-size: 12px; opacity: 0.9;">Records Today</div>
                        <div style="font-size: 24px; font-weight: bold;"><span id="recordCount">0</span></div>
                    </div>
                </div>
            </div>

            <!-- Camera -->
            <div class="camera-container position-relative">
                <video id="attendanceVideo" autoplay playsinline></video>
                <canvas id="detectionCanvas" style="position: absolute; top: 0; left: 0; display: none;"></canvas>
            </div>

            <!-- Controls -->
            <div class="text-center" style="margin: 20px 0;">
                <button class="btn btn-success btn-lg" onclick="startCamera()" id="startBtn">
                    <i class="fas fa-play"></i> Start Auto Detection
                </button>
                <button class="btn btn-danger btn-lg" onclick="stopCamera()" id="stopBtn" disabled>
                    <i class="fas fa-stop"></i> Stop
                </button>
                <!-- <button class="btn btn-info btn-lg" onclick="viewRecords()">
                    <i class="fas fa-clipboard-list"></i> View Records
                </button> -->
            </div>

            <!-- Attendance Records Today -->
            <h4 class="mt-5"><i class="fas fa-clock"></i> Today's Attendance Records:</h4>
            <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 10px; padding: 10px;">
                <div id="attendanceLog" style="min-height: 100px;">
                    <p class="text-muted text-center"><i class="fas fa-hourglass-start"></i> No records yet</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        let attendanceVideo = null;
        let attendanceStream = null;
        let isDetecting = false;
        let employeePhotos = {};
        let employeeDescriptors = {};
        let lastDetectionTime = {};
        const COOLDOWN_MS = 6000; // 6 seconds between detections
        let modelsLoaded = false;
        let todayRecords = [];

        async function initializeFaceDetection() {
            try {
                console.log('🚀 Initializing Face Detection System...');
                
                if (typeof faceapi === 'undefined') {
                    updateStatus('⏳ Loading face API library...');
                    setTimeout(initializeFaceDetection, 1000);
                    return;
                }
                
                console.log('Loading face-api models...');
                updateStatus('⏳ Loading models (10-15 seconds)...');
                
                const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model/';
                
                await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
                await new Promise(resolve => setTimeout(resolve, 500));
                
                await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
                await new Promise(resolve => setTimeout(resolve, 500));
                
                await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
                await new Promise(resolve => setTimeout(resolve, 500));
                
                await faceapi.nets.faceExpressionNet.loadFromUri(MODEL_URL);
                await new Promise(resolve => setTimeout(resolve, 1000));
                
                console.log('✅ Models loaded');
                await new Promise(resolve => setTimeout(resolve, 3000));
                modelsLoaded = true;

                // Load employees
                await loadEmployeePhotos();
                
                const count = Object.keys(employeePhotos).length;
                document.getElementById('empCount').textContent = count;
                
                // Load today's records from database
                await loadTodayRecords();
                
                if (count > 0) {
                    updateStatus(`✅ Ready! ${count} employees loaded. Click "Start Auto Detection"`);
                } else {
                    updateStatus('❌ No employees found');
                }

            } catch (err) {
                console.error('Init error:', err);
                updateStatus('❌ Initialization error. Retrying...');
                setTimeout(initializeFaceDetection, 2000);
            }
        }

        async function loadTodayRecords() {
            try {
                const response = await fetch('api/get_today_attendance.php');
                const data = await response.json();
                
                if (data.success && data.records && data.records.length > 0) {
                    const logDiv = document.getElementById('attendanceLog');
                    logDiv.innerHTML = '';
                    
                    todayRecords = [];
                    
                    // Sort by punch_in ascending (oldest first) - will be inserted at top, so newest appears first
                    data.records.sort((a, b) => new Date(a.punch_in) - new Date(b.punch_in));
                    
                    data.records.forEach((record, index) => {
                        // Add to our records array
                        todayRecords.push({
                            name: record.name,
                            employee_id: record.employee_id,
                            time: new Date(record.punch_in).toLocaleTimeString('en-US', { hour12: false }),
                            confidence: 95 // Database records treated as high confidence
                        });
                        
                        // Create HTML for record
                        const isPunchIn = index % 2 === 0;
                        const punchType = isPunchIn ? 'in' : 'out';
                        const punchLabel = isPunchIn ? 'PUNCH IN' : 'PUNCH OUT';
                        const punchIcon = isPunchIn ? 'fa-sign-in-alt' : 'fa-sign-out-alt';
                        
                        const recordDiv = document.createElement('div');
                        recordDiv.className = `punch-record ${punchType}`;
                        
                        const punchTime = new Date(record.punch_in).toLocaleTimeString('en-US', { hour12: false });
                        recordDiv.innerHTML = `
                            <span class="punch-type-badge ${punchType}">
                                <i class="fas ${punchIcon}"></i> ${punchLabel}
                            </span>
                            <strong>${record.name}</strong> (#${record.employee_id})<br>
                            <i class="fas fa-clock"></i> ${punchTime}
                            <span class="badge bg-info float-end">Saved</span>
                        `;
                        logDiv.insertBefore(recordDiv, logDiv.firstChild);
                    });
                    
                    document.getElementById('recordCount').textContent = todayRecords.length;
                }
            } catch (err) {
                console.log('Note: Could not load previous records:', err);
            }
        }

        async function loadEmployeePhotos() {
            try {
                const response = await fetch('api/get_employees.php');
                const data = await response.json();
                
                if (data.success && data.employees) {
                    const photoResponse = await fetch('api/get_employee_photos.php');
                    const photoData = await photoResponse.json();
                    
                    let photoCount = 0;
                    let noPhotoCount = 0;
                    
                    for (let i = 0; i < data.employees.length; i++) {
                        const emp = data.employees[i];
                        const photoItem = photoData.photos ? photoData.photos.find(p => p.id === emp.id) : null;
                        
                        // Only add employees with photos to recognition system
                        if (photoItem && photoItem.photo_base64) {
                            employeePhotos[emp.id] = {
                                id: emp.id,
                                name: emp.name,
                                employee_id: emp.employee_id,
                                photo_base64: photoItem.photo_base64
                            };
                            
                            // Extract descriptor from photo
                            await extractDescriptorFromPhoto(emp.id, photoItem.photo_base64);
                            photoCount++;
                        } else {
                            // Employee has no photo - exclude from face recognition
                            console.warn(`⚠️ Employee ${emp.name} (#${emp.employee_id}) has no photo - will not be matched`);
                            noPhotoCount++;
                        }
                    }
                    
                    console.log(`✅ Loaded ${photoCount} employees with photos. ${noPhotoCount} employees excluded (no photo).`);
                }
            } catch (err) {
                console.error('Error loading employees:', err);
            }
        }

        async function extractDescriptorFromPhoto(empId, photoBase64) {
            try {
                if (!modelsLoaded) return;
                
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                const img = new Image();
                
                await new Promise((resolve, reject) => {
                    img.onload = () => {
                        canvas.width = img.width;
                        canvas.height = img.height;
                        ctx.drawImage(img, 0, 0);
                        resolve();
                    };
                    img.onerror = reject;
                    img.src = photoBase64;
                });

                const detection = await faceapi
                    .detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptor();
                
                if (detection && detection.descriptor && detection.descriptor.length > 0) {
                    employeeDescriptors[empId] = Array.from(detection.descriptor);
                    employeePhotos[empId].descriptor = Array.from(detection.descriptor);
                    console.log(`✅ Descriptor extracted for employee ID: ${empId}`);
                } else {
                    // No face detected in photo - remove from recognition system
                    delete employeePhotos[empId];
                    console.error(`❌ No face detected in photo for employee ID: ${empId} - Excluded`);
                }
            } catch (err) {
                console.error(`❌ Error extracting descriptor for employee ID: ${empId}:`, err);
                // Remove employee from recognition on extraction error
                delete employeePhotos[empId];
            }
        }

        function updateStatus(message) {
            document.getElementById('statusDiv').innerHTML = message;
            console.log(message);
        }

        function startCamera() {
            attendanceVideo = document.getElementById('attendanceVideo');
            updateStatus('📷 Requesting camera access...');
            
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
                        document.getElementById('startBtn').disabled = true;
                        document.getElementById('stopBtn').disabled = false;
                        isDetecting = true;
                        updateStatus('✅ Camera active - Auto detecting faces...');
                        detectFaces();
                    };
                })
                .catch(err => {
                    alert('Camera error: ' + err.message);
                    console.error('Camera error:', err);
                });
        }

        function stopCamera() {
            isDetecting = false;
            if (attendanceStream) {
                attendanceStream.getTracks().forEach(track => track.stop());
            }
            document.getElementById('startBtn').disabled = false;
            document.getElementById('stopBtn').disabled = true;
            updateStatus('⏸️ Auto detection stopped');
        }

        async function detectFaces() {
            if (!isDetecting || !attendanceVideo.srcObject) {
                setTimeout(detectFaces, 100);
                return;
            }

            try {
                if (!modelsLoaded) {
                    setTimeout(detectFaces, 100);
                    return;
                }

                const canvas = document.getElementById('detectionCanvas');
                const ctx = canvas.getContext('2d', { willReadFrequently: true });
                
                canvas.width = attendanceVideo.videoWidth;
                canvas.height = attendanceVideo.videoHeight;
                ctx.drawImage(attendanceVideo, 0, 0);

                // Check cooldown
                const now = Date.now();
                let inCooldown = false;
                for (let empId in lastDetectionTime) {
                    if (now - lastDetectionTime[empId] < COOLDOWN_MS) {
                        inCooldown = true;
                        break;
                    }
                }

                if (!inCooldown) {
                    const matchResult = await matchFaceToEmployee(canvas);
                    
                    if (matchResult && matchResult.confidence >= 75) {
                        console.log(`✅ Match found: ${matchResult.name} (Confidence: ${matchResult.confidence}%)`);
                        recordAttendance(matchResult);
                        lastDetectionTime[matchResult.id] = now;
                    } else if (matchResult) {
                        console.log(`⚠️ Low confidence match: ${matchResult.name} (${matchResult.confidence}%) - Rejected`);
                    }
                }

            } catch (err) {
                console.error('Detection error:', err);
            }

            setTimeout(detectFaces, 100);
        }

        async function matchFaceToEmployee(canvas) {
            try {
                const detection = await faceapi
                    .detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptor();
                
                if (!detection) return null;

                const liveDescriptor = Array.from(detection.descriptor);
                let bestMatch = null;
                let bestDistance = Infinity;
                const DISTANCE_THRESHOLD = 0.45;  // More strict: was 0.6, now 0.45
                const MIN_CONFIDENCE = 75;         // Minimum 75% confidence required

                for (let empId in employeeDescriptors) {
                    const empDescriptor = employeeDescriptors[empId];
                    
                    // Only match if employee has a valid photo/descriptor
                    if (!empDescriptor || empDescriptor.length === 0) {
                        continue;
                    }
                    
                    const distance = calculateDistance(liveDescriptor, empDescriptor);
                    
                    if (distance < bestDistance && distance < DISTANCE_THRESHOLD) {
                        bestDistance = distance;
                        bestMatch = employeePhotos[empId];
                    }
                }

                if (bestMatch) {
                    // Calculate confidence more accurately
                    const confidence = Math.round(75 + ((DISTANCE_THRESHOLD - bestDistance) / DISTANCE_THRESHOLD) * 25);
                    
                    // Only return match if confidence meets minimum threshold
                    if (confidence >= MIN_CONFIDENCE) {
                        return { ...bestMatch, confidence: Math.min(100, confidence) };
                    }
                }

                return null;
            } catch (err) {
                return null;
            }
        }

        function calculateDistance(desc1, desc2) {
            let distance = 0;
            for (let i = 0; i < desc1.length; i++) {
                const diff = desc1[i] - desc2[i];
                distance += diff * diff;
            }
            return Math.sqrt(distance);
        }

        function recordAttendance(matchResult) {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', { hour12: false });
            
            // Add to records
            todayRecords.push({
                name: matchResult.name,
                employee_id: matchResult.employee_id,
                time: timeStr,
                confidence: matchResult.confidence
            });
            document.getElementById('recordCount').textContent = todayRecords.length;

            // Update UI
            const logDiv = document.getElementById('attendanceLog');
            if (logDiv.querySelector('.text-muted')) {
                logDiv.innerHTML = '';
            }

            // Determine if this is punch in or punch out (alternating)
            const recordIndex = todayRecords.length - 1;  // Use 0-based index
            const isPunchIn = recordIndex % 2 === 0; // 0, 2, 4 = punch in (1st, 3rd, 5th...)
            const punchType = isPunchIn ? 'in' : 'out';
            const punchLabel = isPunchIn ? 'PUNCH IN' : 'PUNCH OUT';
            const punchIcon = isPunchIn ? 'fa-sign-in-alt' : 'fa-sign-out-alt';

            const record = document.createElement('div');
            record.className = `punch-record ${punchType}`;
            record.innerHTML = `
                <span class="punch-type-badge ${punchType}">
                    <i class="fas ${punchIcon}"></i> ${punchLabel}
                </span>
                <strong>${matchResult.name}</strong> (${matchResult.employee_id})<br>
                <i class="fas fa-clock"></i> ${timeStr}
                <span class="badge bg-success float-end">${matchResult.confidence}%</span>
            `;
            logDiv.insertBefore(record, logDiv.firstChild);

            // Update next action indicator
            const nextActionAlert = document.getElementById('nextActionAlert');
            const nextActionText = document.getElementById('nextActionText');
            const nextIsPunchIn = (recordIndex + 1) % 2 === 0;
            const nextLabel = nextIsPunchIn ? 'PUNCH IN' : 'PUNCH OUT';
            const nextIcon = nextIsPunchIn ? 'fa-sign-in-alt' : 'fa-sign-out-alt';
            
            nextActionAlert.style.display = 'block';
            nextActionAlert.className = nextIsPunchIn ? 'alert alert-success' : 'alert alert-danger';
            nextActionText.innerHTML = `<i class="fas ${nextIcon}"></i> <strong>${nextLabel}</strong> on next face detection`;

            // Show alert with better messaging
            const alertTitle = isPunchIn ? '✓ Punch In Recorded' : '✓ Punch Out Recorded';
            
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: alertTitle,
                    html: `<strong>${matchResult.name}</strong><br>${punchLabel}<br><strong>${timeStr}</strong>`,
                    icon: 'success',
                    timer: 5000,
                    showConfirmButton: false,
                    background: isPunchIn ? '#f0fff4' : '#fff5f5',
                    iconColor: isPunchIn ? '#28a745' : '#dc3545'
                });
            }

            // Send to server with browser time
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const browserDateTime = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
            
            fetch('api/record_attendance.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `employee_id=${matchResult.id}&name=${encodeURIComponent(matchResult.name)}&confidence=${matchResult.confidence}&browser_time=${encodeURIComponent(browserDateTime)}`
            }).catch(err => console.log('Sync error:', err));
        }

        // Initialize on load
        window.addEventListener('load', initializeFaceDetection);
        window.addEventListener('beforeunload', stopCamera);

        function viewRecords() {
            const today = new Date().toISOString().split('T')[0];
            window.location.href = `/attendence/admin/employee_attendance.php?date=${today}`;
        }
    </script>
</body>
</html>
