<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
include('../config/db.php');

// Enable authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../auth/login.php");
    exit();
}

$message = "";
$message_type = "";

// Function to get location name from GPS coordinates using reverse geocoding
function getLocationName($latitude, $longitude) {
    // Always return something - either address or coordinates as fallback
    $fallback = "Lat: " . number_format($latitude, 4) . ", Lng: " . number_format($longitude, 4);
    
    try {
        $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat=" . urlencode($latitude) . "&lon=" . urlencode($longitude) . "&zoom=18&addressdetails=1";
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'user_agent' => 'AttendanceSystem/1.0'
            ],
            'https' => [
                'timeout' => 3,
                'user_agent' => 'AttendanceSystem/1.0'
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false || empty($response)) {
            error_log("Nominatim API failed for coordinates: $latitude, $longitude");
            return $fallback;
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['address'])) {
            error_log("Nominatim returned invalid data for: $latitude, $longitude");
            return $fallback;
        }
        
        $address = $data['address'];
        
        // Build location name from address components
        $location_parts = [];
        if (!empty($address['house_number'])) $location_parts[] = $address['house_number'];
        if (!empty($address['road'])) $location_parts[] = $address['road'];
        if (!empty($address['suburb'])) $location_parts[] = $address['suburb'];
        if (!empty($address['city'])) $location_parts[] = $address['city'];
        
        $location_name = implode(", ", array_slice($location_parts, 0, 3));
        
        if (empty($location_name)) {
            error_log("No address components found for: $latitude, $longitude");
            return $fallback;
        }
        
        return $location_name;
    } catch (Exception $e) {
        error_log("Exception in getLocationName: " . $e->getMessage());
        return $fallback;
    }
}

// --- Haversine distance (meters) ---
function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $R     = 6371000;
    $phi1  = deg2rad((float)$lat1);
    $phi2  = deg2rad((float)$lat2);
    $dphi  = deg2rad((float)$lat2 - (float)$lat1);
    $dlam  = deg2rad((float)$lon2 - (float)$lon1);
    $a     = sin($dphi/2)**2 + cos($phi1)*cos($phi2)*sin($dlam/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

// --- Validate employee is within allowed radius of their assigned Location (if geo_restricted is ON) ---
function checkLocationAllowed($conn, $user_id, $user_lat, $user_lng) {
    $stmt = $conn->prepare("SELECT geo_restricted, location FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // No restriction → allow from anywhere
    if (!$user || empty($user['geo_restricted'])) {
        return ['allowed' => true, 'distance' => null];
    }

    // Restriction is ON — GPS is mandatory
    if ($user_lat === null || $user_lng === null) {
        return ['allowed' => false, 'message' => 'Location access is required to mark attendance. Please allow GPS in your browser and try again.'];
    }

    $locationName = $user['location'] ?? '';
    if ($locationName === '') {
        // No Location assigned to this employee — nothing to enforce against.
        return ['allowed' => true, 'distance' => null];
    }

    $stmt2 = $conn->prepare("SELECT name, latitude, longitude, radius_meters FROM locations WHERE name = ? LIMIT 1");
    $stmt2->bind_param("s", $locationName);
    $stmt2->execute();
    $loc = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();

    if (!$loc || $loc['latitude'] === null || $loc['longitude'] === null) {
        // Location has no GPS configured yet — allow from anywhere until an admin sets it.
        return ['allowed' => true, 'distance' => null];
    }

    $radius   = max(50, (int)($loc['radius_meters'] ?? 100));
    $distance = haversineDistance($user_lat, $user_lng, $loc['latitude'], $loc['longitude']);

    if ($distance > $radius) {
        return [
            'allowed' => false,
            'message' => 'You are ' . round($distance) . ' m away from ' . $loc['name'] . '. Attendance is only allowed within ' . $radius . ' m.'
        ];
    }
    return ['allowed' => true, 'distance' => round($distance)];
}

// Create uploads directory with date-based subfolder (YYYY/MM/DD)
$upload_dir = '../uploads/selfies/' . date('Y/m/d');
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ============================================================
    // SECURITY: TIMESTAMP VALIDATION - Server Time is Authoritative
    // ============================================================
    // IMPORTANT: Employee phones cannot manipulate the punch time.
    // We ALWAYS use SERVER time, NEVER client-submitted time.
    // This prevents employees from changing their device time to fake attendance.
    // ============================================================
    
    // Reject if any client tries to send time parameters
    if (!empty($_POST['punch_time']) || !empty($_POST['client_time']) || 
        !empty($_POST['submitted_time']) || !empty($_POST['time_sent'])) {
        $message = "✗ Invalid request: Time manipulation detected. Server uses authoritative time only.";
        $message_type = "danger";
        error_log("SECURITY: User {$_SESSION['user_id']} attempted to submit client time in punch_in");
    } else {
        $user_id = $_SESSION['user_id'];
        // Server time is ALWAYS used - this cannot be changed by client
        $current_time = date('H:i:s');
        $server_timestamp = time(); // Unix timestamp for audit trail
    
    // Get GPS location data
    $punch_in_lat = isset($_POST['punch_in_lat']) ? floatval($_POST['punch_in_lat']) : null;
    $punch_in_lng = isset($_POST['punch_in_lng']) ? floatval($_POST['punch_in_lng']) : null;
    $punch_in_accuracy = isset($_POST['punch_in_accuracy']) ? floatval($_POST['punch_in_accuracy']) : null;

    // Enforce GPS geofencing if this employee has restriction enabled
    $locCheck = checkLocationAllowed($conn, $user_id, $punch_in_lat, $punch_in_lng);

    if (!$locCheck['allowed']) {
        $message = "✗ " . $locCheck['message'];
        $message_type = "danger";
    }
    // Check if selfie is provided (location is optional)
    else if (empty($_POST['selfie_image'])) {
            $message = "✗ Please capture a selfie before punching in";
            $message_type = "danger";
    } else {
        // Check if user status is Resign
        $status_check = $conn->prepare("SELECT status FROM users WHERE id = ?");
        $status_check->bind_param("i", $user_id);
        $status_check->execute();
        $status_result = $status_check->get_result();
        $user_status = $status_result->fetch_assoc();
        $status_check->close();
        
        if ($user_status && $user_status['status'] === 'Resign') {
            $message = "✗ Your account has been marked as Resign. You cannot punch in.";
            $message_type = "danger";
        } else {
        // Shift logic: 5:01 AM to next day 5:00 AM
        $current_hour = (int)date('H');
        $current_minute = (int)date('i');
        if ($current_hour < 6 || ($current_hour == 6 && $current_minute <= 0)) {
            $date = date('Y-m-d', strtotime('-1 day'));
        } else {
            $date = date('Y-m-d');
        }
        
        // Display date is the actual current date
        $display_date = date('Y-m-d');
        
        // Prepare initial check statement (for validation only)
        $stmt = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
        if (!$stmt) {
            $message = "✗ Database Error: " . $conn->error;
            $message_type = "danger";
        } else {
            $stmt->bind_param("is", $user_id, $date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            // Allow multiple punch ins per day - just insert a new record
            // Process and save selfie image
            $image_data = $_POST['selfie_image'];
            $image_data = str_replace('data:image/jpeg;base64,', '', $image_data);
            $image_data = str_replace('data:image/png;base64,', '', $image_data);
            $image_data = str_replace(' ', '+', $image_data);
            $image_binary = base64_decode($image_data);
            
            // Create unique filename
            $filename = 'selfie_' . $user_id . '_' . date('YmdHis') . '.jpg';
            $filepath = $upload_dir . '/' . $filename;
            
            if (file_put_contents($filepath, $image_binary)) {
                    // Get GPS location from form and convert to address
                    $punch_in_lat = isset($_POST['punch_in_lat']) ? floatval($_POST['punch_in_lat']) : null;
                    $punch_in_lng = isset($_POST['punch_in_lng']) ? floatval($_POST['punch_in_lng']) : null;
                    
                    if ($punch_in_lat && $punch_in_lng) {
                        $punch_in_location = getLocationName($punch_in_lat, $punch_in_lng);
                    } else {
                        $punch_in_location = "Office"; // Fallback if GPS not available
                    }
                    
                    // Check if already has a punch_in for today
                    $stmt_check = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ? AND punch_in IS NOT NULL");
                    $stmt_check->bind_param("is", $user_id, $date);
                    $stmt_check->execute();
                    $check_result = $stmt_check->get_result();
                    
                    if ($check_result->num_rows == 0) {
                        // First punch in - create new attendance record
                        $stmt2 = $conn->prepare("INSERT INTO attendance (user_id, date, punch_in, status, selfie_punchin, punch_in_location) VALUES (?, ?, ?, 'Present', ?, ?)");
                        if (!$stmt2) {
                            $message = "✗ Database Error: " . $conn->error;
                            $message_type = "danger";
                        } else {
                            $stmt2->bind_param("issss", $user_id, $date, $current_time, $filename, $punch_in_location);
                        if ($stmt2->execute()) {
                            // Log audit trail with server timestamp
                            error_log("PUNCH_IN: User {$user_id} punched in at {$current_time} on {$date} (Server timestamp: {$server_timestamp})");
                            $message = "✓ Punch In Successful at " . $current_time . " on " . $display_date . " with selfie";
                            $message_type = "success";
                        } else {
                            // Check if it's a UNIQUE constraint error
                            if (strpos($stmt2->error, 'unique_daily_attendance') !== false || strpos($stmt2->error, 'Duplicate entry') !== false) {
                                $message = "✗ Database constraint issue detected. Please ask your admin to run system setup at <strong>admin/setup.php</strong> to enable multiple punch in/out per day.";
                            } else {
                                $message = "✗ Error: " . $stmt2->error;
                            }
                            $message_type = "danger";
                        }
                        $stmt2->close();
                    }
                } else {
                    // Multiple punch in - insert as separate event with Office location
                    $stmt3 = $conn->prepare("INSERT INTO attendance (user_id, date, punch_in, status, selfie_punchin, punch_in_location) VALUES (?, ?, ?, 'Present', ?, ?)");
                    if (!$stmt3) {
                        $message = "✗ Database Error: " . $conn->error;
                        $message_type = "danger";
                    } else {
                        $stmt3->bind_param("issss", $user_id, $date, $current_time, $filename, $punch_in_location);
                        
                        if ($stmt3->execute()) {
                            // Log audit trail with server timestamp
                            error_log("PUNCH_IN_MULTIPLE: User {$user_id} recorded additional punch in at {$current_time} on {$date} (Server timestamp: {$server_timestamp})");
                            $message = "✓ Punch In Recorded at " . $current_time . " on " . $display_date . " with selfie (Multiple punch-ins)";
                            $message_type = "success";
                        } else {
                            // Check if it's a UNIQUE constraint error
                            if (strpos($stmt3->error, 'unique_daily_attendance') !== false || strpos($stmt3->error, 'Duplicate entry') !== false) {
                                $message = "✗ Database constraint issue detected. Please ask your admin to run system setup at <strong>admin/setup.php</strong> to enable multiple punch in/out per day.";
                            } else {
                                $message = "✗ Error: " . $stmt3->error;
                            }
                            $message_type = "danger";
                        }
                        $stmt3->close();
                    }
                }
                $stmt_check->close();
            } else {
                $message = "✗ Error saving selfie image";
                $message_type = "danger";
            }
            $stmt->close();
        }
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Punch In</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        #video {
            width: 100%;
            border-radius: 8px;
            background-color: #000;
        }
        #canvas {
            display: none;
        }
        .camera-section {
            margin: 20px 0;
        }
        .button-group {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        .btn-camera {
            flex: 1;
        }
        .selfie-preview {
            margin-top: 20px;
            text-align: center;
        }
        .selfie-preview img {
            max-width: 100%;
            border-radius: 8px;
            border: 2px solid #28a745;
            padding: 5px;
        }
        .location-enable-btn {
            background: none;
            border: none;
            color: #0d6efd;
            text-decoration: underline;
            cursor: pointer;
            font-weight: bold;
            padding: 0;
            margin-top: 5px;
        }
        .location-enable-btn:hover {
            color: #0b5ed7;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand mb-0 h1">Punch In</span>
        <div>
            <a href="dashboard.php" class="btn btn-secondary btn-sm me-2">Dashboard</a>
            <a href="../auth/logout.php" class="btn btn-danger btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Punch In with Selfie</h5>
                </div>
                <div class="card-body">
                    <!-- Date & Time Display -->
                    <div style="margin-bottom: 20px; text-align: center;">
                        <p class="text-muted mb-1">Current Date & Time:</p>
                        <p style="font-size: 24px; font-weight: bold; color: #28a745;">
                            <span id="liveDate"></span>
                        </p>
                        <p style="font-size: 18px; font-weight: bold; color: #17a2b8;">
                            <span id="liveTime"></span>
                        </p>
                    </div>

                    <!-- Camera Section -->
                    <div class="camera-section">
                        <label class="form-label"><strong>📷 Camera Feed</strong></label>
                        <video id="video" playsinline></video>
                        <canvas id="canvas"></canvas>
                        
                        <div class="button-group">
                            <button type="button" class="btn btn-warning btn-camera" onclick="startCamera()" id="startBtn">
                                🎥 Start Camera
                            </button>
                            <button type="button" class="btn btn-danger btn-camera" onclick="stopCamera()" id="stopBtn" style="display: none;">
                                ⏹️ Stop Camera
                            </button>
                            <button type="button" class="btn btn-info btn-camera" onclick="capturePhoto()" id="captureBtn" style="display: none;">
                                📸 Capture Selfie
                            </button>
                        </div>
                    </div>

                    <!-- Selfie Preview -->
                    <div class="selfie-preview" id="selfiePreview" style="display: none;">
                        <p class="text-success"><strong>✓ Selfie Captured</strong></p>
                        <img id="capturedImage" src="" alt="Captured Selfie">
                        <div class="mt-2">
                            <button type="button" class="btn btn-warning btn-sm" onclick="retakePhoto()">
                                🔄 Retake
                            </button>
                        </div>
                    </div>

                    <!-- Punch In Form -->
                    <form method="POST" class="text-center" id="punchForm" style="margin-top: 20px;">
                        <input type="hidden" id="selfie_image" name="selfie_image">
                        <input type="hidden" id="punch_in_lat" name="punch_in_lat">
                        <input type="hidden" id="punch_in_lng" name="punch_in_lng">
                        <input type="hidden" id="punch_in_accuracy" name="punch_in_accuracy">
                        
                        <!-- GPS Status Display -->
                        <div id="gpsStatus" class="alert alert-warning" style="margin-bottom: 15px;">
                            <div style="font-size: 14px;">
                                <span id="gpsIcon">📍</span>
                                <strong id="gpsText">Fetching Location...</strong>
                                <div id="gpsDetails" style="font-size: 12px; margin-top: 5px; color: #666;"></div>
                                <button type="button" class="location-enable-btn" id="enableLocationBtn" onclick="retryLocationCapture()" style="display: none;">
                                    ↓ Click to enable location access
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-success btn-lg" id="punchInBtn" disabled>
                            🔓 Punch In Now
                        </button>
                        <p class="text-muted small mt-2">⚠️ Please capture a selfie and allow location access to enable punch in</p>
                    </form>
                </div>
            </div>
            <div class="mt-3">
                <a href="dashboard.php" class="btn btn-secondary w-100">Back to Dashboard</a>
            </div>
        </div>
    </div>
</div>

<script>
    let stream = null;
    let captured_image = null;

    // Live clock function
    function updateClock() {
        const now = new Date();
        const dateOptions = { year: 'numeric', month: 'long', day: 'numeric' };
        const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
        
        const dateStr = now.toLocaleDateString('en-US', dateOptions);
        const timeStr = now.toLocaleTimeString('en-US', timeOptions);
        
        document.getElementById('liveDate').innerText = dateStr;
        document.getElementById('liveTime').innerText = timeStr;
    }
    
    // Update clock immediately and then every 1 second
    updateClock();
    setInterval(updateClock, 1000);

    // Camera Functions
    function startCamera() {
        const video = document.getElementById('video');
        const startBtn = document.getElementById('startBtn');
        const stopBtn = document.getElementById('stopBtn');
        const captureBtn = document.getElementById('captureBtn');

        // Check if mediaDevices is available (requires HTTPS or localhost)
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            Swal.fire({
                icon: 'error',
                title: 'Camera Not Available',
                text: 'Camera API requires HTTPS connection.\n\nPlease access via:\nhttps://192.168.4.29\n\nOr contact admin to enable SSL.',
                confirmButtonColor: '#3085d6'
            });
            return;
        }

        // Request camera access
        navigator.mediaDevices.getUserMedia({ 
            video: { facingMode: 'user' },
            audio: false 
        })
        .then(function(mediaStream) {
            stream = mediaStream;
            video.srcObject = stream;
            video.play();
            
            startBtn.style.display = 'none';
            stopBtn.style.display = 'block';
            captureBtn.style.display = 'block';
        })
        .catch(function(err) {
            Swal.fire({
                icon: 'error',
                title: 'Camera Error: ' + err.name,
                text: err.message + '\n\nPlease check camera permissions.',
                confirmButtonColor: '#3085d6'
            });
        });
    }

    function stopCamera() {
        const video = document.getElementById('video');
        const startBtn = document.getElementById('startBtn');
        const stopBtn = document.getElementById('stopBtn');
        const captureBtn = document.getElementById('captureBtn');

        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
        
        video.srcObject = null;
        startBtn.style.display = 'block';
        stopBtn.style.display = 'none';
        captureBtn.style.display = 'none';
    }

    function compressImage(dataUrl, maxSizeKB = 10) {
        let quality = 0.8;
        let compressed = dataUrl;
        let sizeKB = (compressed.length * 0.90) / 1024; // Approximate size in KB
        
        // Keep reducing quality until under max size
        while (sizeKB > maxSizeKB && quality > 0.1) {
            quality -= 0.1;
            // Create temporary canvas to compress
            const tempCanvas = document.createElement('canvas');
            const tempCtx = tempCanvas.getContext('2d');
            const img = new Image();
            
            img.onload = function() {
                tempCanvas.width = img.width;
                tempCanvas.height = img.height;
                tempCtx.drawImage(img, 0, 0);
                compressed = tempCanvas.toDataURL('image/jpeg', quality);
                sizeKB = (compressed.length * 0.75) / 1024;
            };
            img.src = dataUrl;
        }
        
        return compressed;
    }

    function capturePhoto() {
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const context = canvas.getContext('2d');

        // Resize canvas to reduce image size (480x360 or maintain aspect ratio)
        const maxWidth = 480;
        const maxHeight = 360;
        let width = video.videoWidth;
        let height = video.videoHeight;
        
        // Calculate aspect ratio
        const aspectRatio = width / height;
        if (width > maxWidth) {
            width = maxWidth;
            height = width / aspectRatio;
        }
        if (height > maxHeight) {
            height = maxHeight;
            width = height * aspectRatio;
        }
        
        canvas.width = width;
        canvas.height = height;

        // Draw video frame to canvas (resized)
        context.drawImage(video, 0, 0, width, height);

        // Convert to JPEG with compression to reduce file size (<10KB)
        let imageData = canvas.toDataURL('image/jpeg', 0.5);
        
        // Compress further if still over 10KB
        const sizeKB = (imageData.length * 0.75) / 1024;
        if (sizeKB > 10) {
            let quality = 0.4;
            while ((imageData.length * 0.75) / 1024 > 10 && quality > 0.1) {
                quality -= 0.05;
                imageData = canvas.toDataURL('image/jpeg', quality);
            }
        }
        
        captured_image = imageData;

        // Display preview
        const preview = document.getElementById('selfiePreview');
        const capturedImg = document.getElementById('capturedImage');
        capturedImg.src = imageData;
        preview.style.display = 'block';

        // Set hidden input
        document.getElementById('selfie_image').value = imageData;

        // Enable punch in button only if GPS location is also available
        const latitude = document.getElementById('punch_in_lat').value;
        const longitude = document.getElementById('punch_in_lng').value;
        if (latitude && longitude) {
            document.getElementById('punchInBtn').disabled = false;
        }

        // Stop camera
        stopCamera();

        Swal.fire({
            icon: 'success',
            title: 'Selfie Captured',
            text: 'Click "Punch In Now" to complete',
            confirmButtonColor: '#3085d6',
            timer: 2000
        });
    }

    function retakePhoto() {
        captured_image = null;
        document.getElementById('selfie_image').value = '';
        document.getElementById('selfiePreview').style.display = 'none';
        document.getElementById('punchInBtn').disabled = true;
        startCamera();
    }

    // Form submission validation
    document.getElementById('punchForm').addEventListener('submit', function(e) {
        const selfieImage = document.getElementById('selfie_image').value;
        const latitude = document.getElementById('punch_in_lat').value;
        const longitude = document.getElementById('punch_in_lng').value;
        
        if (!selfieImage) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Please capture a selfie before punching in',
                confirmButtonColor: '#3085d6'
            });
        } else if (!latitude || !longitude) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Location Required',
                text: 'Location access is required. Please allow GPS permission and try again.',
                confirmButtonColor: '#3085d6'
            });
        }
    });
    
    // GPS Location Capture Function
    let gpsLocation = null;
    let locationPopupShown = false; // Flag to track if popup was already shown
    
    function captureGPSLocation() {
        const gpsStatus = document.getElementById('gpsStatus');
        const gpsText = document.getElementById('gpsText');
        const gpsIcon = document.getElementById('gpsIcon');
        const gpsDetails = document.getElementById('gpsDetails');
        const punchBtn = document.getElementById('punchInBtn');
        
        if (navigator.geolocation) {
            gpsText.innerText = 'Fetching Location...';
            gpsIcon.innerText = '⏳';
            gpsStatus.className = 'alert alert-warning';
            
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    // Success - location obtained
                    const latitude = position.coords.latitude;
                    const longitude = position.coords.longitude;
                    const accuracy = position.coords.accuracy;
                    
                    document.getElementById('punch_in_lat').value = latitude;
                    document.getElementById('punch_in_lng').value = longitude;
                    document.getElementById('punch_in_accuracy').value = accuracy;
                    
                    gpsLocation = { lat: latitude, lng: longitude, accuracy: accuracy };
                    
                    gpsText.innerText = '✓ Location Captured';
                    gpsIcon.innerText = '✅';
                    gpsStatus.className = 'alert alert-success';
                    gpsDetails.innerHTML = `Latitude: ${latitude.toFixed(6)}<br>Longitude: ${longitude.toFixed(6)}<br>Accuracy: ${accuracy.toFixed(2)}m`;
                    
                    // Enable punch button if selfie is also captured
                    if (captured_image) {
                        punchBtn.disabled = false;
                    }
                    
                    // Mark popup as shown since location was fetched
                    locationPopupShown = true;
                },
                function(error) {
                    // Error - location denied or unavailable
                    let errorMsg = 'Location access denied';
                    if (error.code === error.PERMISSION_DENIED) {
                        errorMsg = 'Location permission denied. Please enable GPS in your browser settings.';
                    } else if (error.code === error.POSITION_UNAVAILABLE) {
                        errorMsg = 'Location information not available.';
                    } else if (error.code === error.TIMEOUT) {
                        errorMsg = 'Location request timed out. Please try again.';
                    }
                    
                    gpsText.innerText = '✗ ' + errorMsg;
                    gpsIcon.innerText = '❌';
                    gpsStatus.className = 'alert alert-danger';
                    gpsDetails.innerText = 'Please enable location access';
                    document.getElementById('enableLocationBtn').style.display = 'block';
                    punchBtn.disabled = true;
                    
                    // Mark popup as shown so it doesn't auto-trigger
                    locationPopupShown = true;
                },
                {
                    enableHighAccuracy: true,
                    timeout: 5000,
                    maximumAge: 0
                }
            );
        } else {
            gpsText.innerText = '✗ GPS Not Supported';
            gpsIcon.innerText = '❌';
            gpsStatus.className = 'alert alert-danger';
            gpsDetails.innerText = 'Your browser does not support Geolocation API';
        }
    }
    
    // Auto-capture GPS on page load with 5-second timeout
    // If location loads successfully, no popup shown
    // If it fails/times out, popup shown only once
    document.addEventListener('DOMContentLoaded', function() {
        captureGPSLocation();
    });
    
    // Map functions
    let userLocation = null;
    
    function retryLocationCapture() {
        document.getElementById('enableLocationBtn').style.display = 'none';
        captureGPSLocation();
    }
    
    function allowLocation() {
        closeLocationModal();
        // Request geolocation - this will show browser permission dialog
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    // Success - location obtained
                    const latitude = position.coords.latitude;
                    const longitude = position.coords.longitude;
                    const accuracy = position.coords.accuracy;
                    
                    document.getElementById('punch_in_lat').value = latitude;
                    document.getElementById('punch_in_lng').value = longitude;
                    document.getElementById('punch_in_accuracy').value = accuracy;
                    
                    gpsLocation = { lat: latitude, lng: longitude, accuracy: accuracy };
                    
                    // Update GPS status to success
                    const gpsStatus = document.getElementById('gpsStatus');
                    const gpsText = document.getElementById('gpsText');
                    const gpsIcon = document.getElementById('gpsIcon');
                    const gpsDetails = document.getElementById('gpsDetails');
                    const punchBtn = document.getElementById('punchInBtn');
                    
                    gpsText.innerText = '✓ Location Captured';
                    gpsIcon.innerText = '✅';
                    gpsStatus.className = 'alert alert-success';
                    gpsDetails.innerHTML = `Latitude: ${latitude.toFixed(6)}<br>Longitude: ${longitude.toFixed(6)}<br>Accuracy: ${accuracy.toFixed(2)}m`;
                    document.getElementById('enableLocationBtn').style.display = 'none';
                    
                    // Enable punch button if selfie is also captured
                    if (captured_image) {
                        punchBtn.disabled = false;
                    }
                },
                function(error) {
                    // Error
                    console.error('Location error:', error);
                    Swal.fire('Error', 'Unable to access location. Please check browser settings.', 'error');
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }
    }
    
    function denyLocation() {
        closeLocationModal();
    }
    
    function retryLocation() {
        showLocationModal();
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('locationModal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    };
    
    function retryLocation() {
        document.getElementById('enableLocationBtn').style.display = 'none';
        captureGPSLocation();
        closeMapModal();
    }
    
    // Close modal when clicking outside of it
    window.onclick = function(event) {
        const modal = document.getElementById('mapModal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    };

    
    // Location Tracking Variables
    let locationTrackingInterval = null;
    let isTracking = false;
    
    // Function to capture and send location to backend
    function captureAndSendLocation() {
        if (!navigator.geolocation) {
            console.log('Geolocation not available');
            return;
        }
        
        navigator.geolocation.getCurrentPosition(
            function(position) {
                const latitude = position.coords.latitude;
                const longitude = position.coords.longitude;
                const accuracy = position.coords.accuracy;
                
                // Get address from Nominatim
                fetch(`https://nominatim.openstreetmap.org/reverse?lat=${latitude}&lon=${longitude}&format=json`)
                    .then(response => response.json())
                    .then(data => {
                        // Get location name from address
                        let address = 'Location';
                        if (data.address) {
                            address = data.address.city || data.address.town || data.address.village || 'Location';
                        }
                        
                        // Send location to save_location.php API
                        return fetch('../api/save_location.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                latitude: latitude,
                                longitude: longitude,
                                accuracy: accuracy,
                                address: address
                            })
                        });
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log('Location saved:', data);
                    })
                    .catch(error => console.log('Error sending location:', error));
            },
            function(error) {
                console.log('Location capture error:', error.message);
            },
            {
                enableHighAccuracy: true,
                timeout: 5000,
                maximumAge: 0
            }
        );
    }
    
    // Function to start location tracking (every 5 minutes)
    function startLocationTracking() {
        if (isTracking) return; // Already tracking
        
        isTracking = true;
        console.log('Location tracking started');
        
        // Capture immediately
        captureAndSendLocation();
        
        // Capture every 5 minutes (300000 milliseconds)
        locationTrackingInterval = setInterval(function() {
            if (isTracking) {
                captureAndSendLocation();
            }
        }, 5 * 60 * 1000); // 5 minutes
    }
    
    // Function to stop location tracking
    function stopLocationTracking() {
        if (locationTrackingInterval) {
            clearInterval(locationTrackingInterval);
            locationTrackingInterval = null;
        }
        isTracking = false;
        console.log('Location tracking stopped');
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
        
        // If punch-in was successful, start location tracking
        $start_tracking = ($message_type === 'success' && strpos($message, 'Punch In Successful') !== false) ? 'true' : 'false';
        
        echo "Swal.fire({
            icon: '$icon',
            title: '$title',
            text: '$message_escaped',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'OK'
        }).then(() => {
            if ($start_tracking === 'true') {
                startLocationTracking();
            }
        });";
    }
    ?>
</script>
</body>
</html>