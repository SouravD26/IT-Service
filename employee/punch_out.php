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
        error_log("SECURITY: User {$_SESSION['user_id']} attempted to submit client time in punch_out");
    } else {
        $user_id = $_SESSION['user_id'];
        // Server time is ALWAYS used - this cannot be changed by client
        $current_time = date('H:i:s');
        $server_timestamp = time(); // Unix timestamp for audit trail
    
    // Get GPS location data
    $punch_out_lat = isset($_POST['punch_out_lat']) ? floatval($_POST['punch_out_lat']) : null;
    $punch_out_lng = isset($_POST['punch_out_lng']) ? floatval($_POST['punch_out_lng']) : null;
    $punch_out_accuracy = isset($_POST['punch_out_accuracy']) ? floatval($_POST['punch_out_accuracy']) : null;

    // Enforce GPS geofencing if this employee has restriction enabled
    $locCheck = checkLocationAllowed($conn, $user_id, $punch_out_lat, $punch_out_lng);

    if (!$locCheck['allowed']) {
        $message = "✗ " . $locCheck['message'];
        $message_type = "danger";
    }
        // Check if selfie is provided (location is optional)
        else if (empty($_POST['selfie_image'])) {
            $message = "✗ Please capture a selfie before punching out";
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
            $message = "✗ Your account has been marked as Resign. You cannot punch out.";
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
        
        // Check if punch in record exists for today
        $check_stmt = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
        if (!$check_stmt) {
            $message = "✗ Database Error: " . $conn->error;
            $message_type = "danger";
        } else {
            $check_stmt->bind_param("is", $user_id, $date);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows == 0) {
                $message = "⚠ No punch in record found for today. Please punch in first.";
                $message_type = "warning";
            } else {
                // Process and save selfie image
                $image_data = $_POST['selfie_image'];
                $image_data = str_replace('data:image/jpeg;base64,', '', $image_data);
                $image_data = str_replace('data:image/png;base64,', '', $image_data);
                $image_data = str_replace(' ', '+', $image_data);
                $image_binary = base64_decode($image_data);
                
                // Create unique filename for punch out selfie
                $filename = 'selfie_punchout_' . $user_id . '_' . date('YmdHis') . '.jpg';
                $filepath = $upload_dir . '/' . $filename;
                
                if (file_put_contents($filepath, $image_binary)) {
                    // Get location name from GPS coordinates
                    $punch_out_location = getLocationName($punch_out_lat, $punch_out_lng);
                    
                    // Update the LAST punch_in record that doesn't have a punch_out yet
                    // This allows multiple punch in/out cycles
                    $stmt = $conn->prepare("
                        UPDATE attendance 
                        SET punch_out = ?, selfie_punchout = ?, punch_out_location = ? 
                        WHERE user_id = ? AND date = ? AND punch_out IS NULL 
                        ORDER BY id DESC 
                        LIMIT 1
                    ");
                    if (!$stmt) {
                        $message = "✗ Database Error: " . $conn->error;
                        $message_type = "danger";
                    } else {
                        $stmt->bind_param("sssis", $current_time, $filename, $punch_out_location, $user_id, $date);
                        
                        if ($stmt->execute()) {
                            if ($stmt->affected_rows > 0) {
                                // Get the attendance record ID that was just updated
                                $get_id_stmt = $conn->prepare("
                                    SELECT id FROM attendance 
                                    WHERE user_id = ? AND date = ? AND punch_out = ? 
                                    ORDER BY id DESC LIMIT 1
                                ");
                                $get_id_stmt->bind_param("iss", $user_id, $date, $current_time);
                                $get_id_stmt->execute();
                                $id_result = $get_id_stmt->get_result();
                                $punch_in_id = null;
                                $distance_km = 0;
                                
                                if ($row = $id_result->fetch_assoc()) {
                                    $punch_in_id = $row['id'];
                                    
                                    // Get all location points for this punch_in session
                                    $loc_stmt = $conn->prepare("
                                        SELECT latitude, longitude 
                                        FROM location_tracking 
                                        WHERE user_id = ? AND punch_in_id = ? 
                                        ORDER BY timestamp ASC
                                    ");
                                    $loc_stmt->bind_param("ii", $user_id, $punch_in_id);
                                    $loc_stmt->execute();
                                    $loc_result = $loc_stmt->get_result();
                                    
                                    if ($loc_result->num_rows > 0) {
                                        // Fetch all locations
                                        $locations = [];
                                        while ($loc_row = $loc_result->fetch_assoc()) {
                                            $locations[] = [
                                                'latitude' => (float)$loc_row['latitude'],
                                                'longitude' => (float)$loc_row['longitude']
                                            ];
                                        }
                                        
                                        // Calculate distance using Haversine formula
                                        if (count($locations) > 1) {
                                            $total_meters = 0;
                                            for ($i = 0; $i < count($locations) - 1; $i++) {
                                                $lat1 = deg2rad($locations[$i]['latitude']);
                                                $lon1 = deg2rad($locations[$i]['longitude']);
                                                $lat2 = deg2rad($locations[$i+1]['latitude']);
                                                $lon2 = deg2rad($locations[$i+1]['longitude']);
                                                
                                                $dlat = $lat2 - $lat1;
                                                $dlon = $lon2 - $lon1;
                                                
                                                $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlon/2) * sin($dlon/2);
                                                $c = 2 * asin(sqrt($a));
                                                $distance = 6371000 * $c; // Earth radius in meters
                                                
                                                $total_meters += $distance;
                                            }
                                            $distance_km = round($total_meters / 1000, 2);
                                        }
                                        
                                        // Save route summary
                                        $route_data = json_encode($locations);
                                        $summary_stmt = $conn->prepare("
                                            INSERT INTO route_summary (user_id, punch_in_id, total_distance_km, route_data, date) 
                                            VALUES (?, ?, ?, ?, ?)
                                        ");
                                        $summary_stmt->bind_param("iidss", $user_id, $punch_in_id, $distance_km, $route_data, $date);
                                        $summary_stmt->execute();
                                        $summary_stmt->close();
                                    }
                                    $loc_stmt->close();
                                }
                                $get_id_stmt->close();
                                
                                // Log audit trail with server timestamp
                                error_log("PUNCH_OUT: User {$user_id} punched out at {$current_time} on {$date} (Server timestamp: {$server_timestamp}, Distance: {$distance_km}km)");
                                $message = "✓ Punch Out Successful at " . $current_time . " on " . $display_date . " with selfie and location";
                                if ($distance_km > 0) {
                                    $message .= "\n📍 Total Distance Traveled: " . $distance_km . " km";
                                }
                                $message_type = "success";
                            } else {
                                $message = "⚠ No active punch in found. Please punch in first or re-check your last punch.";
                                $message_type = "warning";
                            }
                        } else {
                            $message = "✗ Error: " . $stmt->error;
                            $message_type = "danger";
                        }
                        $stmt->close();
                    }
                } else {
                    $message = "✗ Error saving selfie image";
                    $message_type = "danger";
                }
            }
            $check_stmt->close();
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
    <title>Punch Out</title>
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
            border: 2px solid #dc3545;
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
        <span class="navbar-brand mb-0 h1">Punch Out</span>
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
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">Punch Out with Selfie</h5>
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
                        <p class="text-danger"><strong>✓ Selfie Captured</strong></p>
                        <img id="capturedImage" src="" alt="Captured Selfie">
                        <div class="mt-2">
                            <button type="button" class="btn btn-warning btn-sm" onclick="retakePhoto()">
                                🔄 Retake
                            </button>
                        </div>
                    </div>

                    <!-- Punch Out Form -->
                    <form method="POST" class="text-center" id="punchForm" style="margin-top: 20px;">
                        <input type="hidden" id="selfie_image" name="selfie_image">
                        <input type="hidden" id="punch_out_lat" name="punch_out_lat">
                        <input type="hidden" id="punch_out_lng" name="punch_out_lng">
                        <input type="hidden" id="punch_out_accuracy" name="punch_out_accuracy">
                        
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
                        
                        <button type="submit" class="btn btn-danger btn-lg" id="punchOutBtn" disabled>
                            🔒 Punch Out Now
                        </button>
                        <p class="text-muted small mt-2">⚠️ Please capture a selfie and allow location access to enable punch out</p>
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

    function compressImage(dataUrl, maxSizeKB = 50) {
        let quality = 0.8;
        let compressed = dataUrl;
        let sizeKB = (compressed.length * 0.75) / 1024; // Approximate size in KB
        
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

        // Enable punch out button only if GPS location is also available
        const latitude = document.getElementById('punch_out_lat').value;
        const longitude = document.getElementById('punch_out_lng').value;
        if (latitude && longitude) {
            document.getElementById('punchOutBtn').disabled = false;
        }

        // Stop camera
        stopCamera();

        Swal.fire({
            icon: 'success',
            title: 'Selfie Captured',
            text: 'Click "Punch Out Now" to complete',
            confirmButtonColor: '#3085d6',
            timer: 2000
        });
    }

    function retakePhoto() {
        captured_image = null;
        document.getElementById('selfie_image').value = '';
        document.getElementById('selfiePreview').style.display = 'none';
        document.getElementById('punchOutBtn').disabled = true;
        startCamera();
    }

    // Form submission validation
    document.getElementById('punchForm').addEventListener('submit', function(e) {
        const selfieImage = document.getElementById('selfie_image').value;
        const latitude = document.getElementById('punch_out_lat').value;
        const longitude = document.getElementById('punch_out_lng').value;
        
        if (!selfieImage) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Please capture a selfie before punching out',
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
        const punchBtn = document.getElementById('punchOutBtn');
        
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
                    
                    document.getElementById('punch_out_lat').value = latitude;
                    document.getElementById('punch_out_lng').value = longitude;
                    document.getElementById('punch_out_accuracy').value = accuracy;
                    
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
        // Request geolocation - this will show browser permission dialog
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    // Success - location obtained
                    const latitude = position.coords.latitude;
                    const longitude = position.coords.longitude;
                    const accuracy = position.coords.accuracy;
                    
                    document.getElementById('punch_out_lat').value = latitude;
                    document.getElementById('punch_out_lng').value = longitude;
                    document.getElementById('punch_out_accuracy').value = accuracy;
                    
                    gpsLocation = { lat: latitude, lng: longitude, accuracy: accuracy };
                    
                    // Update GPS status to success
                    const gpsStatus = document.getElementById('gpsStatus');
                    const gpsText = document.getElementById('gpsText');
                    const gpsIcon = document.getElementById('gpsIcon');
                    const gpsDetails = document.getElementById('gpsDetails');
                    const punchBtn = document.getElementById('punchOutBtn');
                    
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
    }
    
    function retryLocation() {
        document.getElementById('enableLocationBtn').style.display = 'none';
        captureGPSLocation();
    }
    
    // Location Tracking - Stop function (from punch_in.php)
    let isTracking = false;
    let locationTrackingInterval = null;
    
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
        
        // If punch-out was successful, stop location tracking
        $stop_tracking = ($message_type === 'success' && strpos($message, 'Punch Out Successful') !== false) ? 'true' : 'false';
        
        echo "Swal.fire({
            icon: '$icon',
            title: '$title',
            text: '$message_escaped',
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'OK'
        }).then(() => {
            if ($stop_tracking === 'true') {
                stopLocationTracking();
            }
        });";
    }
    ?>
</script>
</body>
</html>