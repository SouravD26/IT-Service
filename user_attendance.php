<?php
session_start();

// Check if user is logged in as employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include('config/db.php');

$user_id = $_SESSION['user_id'];
$action  = isset($_POST['action']) ? $_POST['action'] : '';

header('Content-Type: application/json');

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

// --- Validate employee is within allowed radius of their assigned Location ---
function checkLocationAllowed($conn, $user_id, $user_lat, $user_lng) {
    // Check if this employee has geo-restriction enabled
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
        return ['allowed' => false, 'message' => 'Location access is required to mark attendance. Please allow GPS in your browser.'];
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

// Extract GPS sent by the client
$user_lat = (isset($_POST['latitude'])  && $_POST['latitude']  !== '') ? (float)$_POST['latitude']  : null;
$user_lng = (isset($_POST['longitude']) && $_POST['longitude'] !== '') ? (float)$_POST['longitude'] : null;

// Validate location before allowing any punch action
$locCheck = checkLocationAllowed($conn, $user_id, $user_lat, $user_lng);
if (!$locCheck['allowed']) {
    echo json_encode(['success' => false, 'message' => $locCheck['message']]);
    exit();
}

if ($action === 'punch_in') {
    $today = date('Y-m-d');

    // --- Anti-double-tap guard: reject if a punch_in was recorded in the last 30 seconds ---
    $dup_stmt = $conn->prepare(
        "SELECT id FROM attendance
         WHERE user_id = ? AND date = ? AND punch_in IS NOT NULL
         AND TIMESTAMPDIFF(SECOND, CONCAT(date, ' ', punch_in), NOW()) <= 30
         ORDER BY id DESC LIMIT 1"
    );
    $dup_stmt->bind_param("is", $user_id, $today);
    $dup_stmt->execute();
    $dup_result = $dup_stmt->get_result();
    $dup_stmt->close();
    if ($dup_result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Already processed. Please wait a moment before punching in again.']);
        exit();
    }

    // Check existing record for today
    $check = $conn->query("SELECT id, punch_in FROM attendance WHERE user_id = '$user_id' AND date = '$today'");

    if ($check->num_rows > 0) {
        $record = $check->fetch_assoc();
        if ($record['punch_in']) {
            echo json_encode(['success' => false, 'message' => 'Already punched in today']);
            exit();
        }
    } else {
        // Create new attendance record
        $stmt = $conn->prepare("INSERT INTO attendance (user_id, date, status) VALUES (?, ?, 'Present')");
        $stmt->bind_param("is", $user_id, $today);
        $stmt->execute();
    }
    
    // Handle photo upload
    $photo_path = null;
    if (isset($_FILES['photo'])) {
        $upload_dir = 'uploads/selfies/' . date('Y/m/d') . '/';
        
        // Create directory if not exists
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $filename = 'punch_in_' . $user_id . '_' . time() . '.jpg';
        $photo_path = $upload_dir . $filename;
        
        // Save from base64 or file upload
        if ($_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            move_uploaded_file($_FILES['photo']['tmp_name'], $photo_path);
        }
    }
    
    // Record punch in
    $punch_in = date('H:i:s');
    $stmt = $conn->prepare("UPDATE attendance SET punch_in = ?, selfie_punchin = ? WHERE user_id = ? AND date = ?");
    $stmt->bind_param("ssis", $punch_in, $photo_path, $user_id, $today);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Punched in successfully',
            'punch_in_time' => $punch_in,
            'photo' => $photo_path
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating attendance: ' . $conn->error]);
    }
    
} elseif ($action === 'punch_out') {
    // Record punch out
    $today = date('Y-m-d');
    $punch_out = date('H:i:s');
    
    $photo_path = null;
    if (isset($_FILES['photo'])) {
        $upload_dir = 'uploads/selfies/' . date('Y/m/d') . '/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $filename = 'punch_out_' . $user_id . '_' . time() . '.jpg';
        $photo_path = $upload_dir . $filename;
        
        if ($_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            move_uploaded_file($_FILES['photo']['tmp_name'], $photo_path);
        }
    }
    
    $stmt = $conn->prepare("UPDATE attendance SET punch_out = ?, selfie_punchout = ? WHERE user_id = ? AND date = ?");
    $stmt->bind_param("ssis", $punch_out, $photo_path, $user_id, $today);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Punched out successfully',
            'punch_out_time' => $punch_out,
            'photo' => $photo_path
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating attendance: ' . $conn->error]);
    }
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

$conn->close();
?>
