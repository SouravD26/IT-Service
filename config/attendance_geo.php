<?php
/**
 * Shared geofencing + reverse-geocoding helpers, extracted from the logic
 * already used in employee/punch_in.php and employee/punch_out.php so the
 * Flutter API endpoints apply identical rules.
 */

function attendance_haversine_meters($lat1, $lon1, $lat2, $lon2) {
    $R = 6371000;
    $phi1 = deg2rad((float)$lat1);
    $phi2 = deg2rad((float)$lat2);
    $dphi = deg2rad((float)$lat2 - (float)$lat1);
    $dlam = deg2rad((float)$lon2 - (float)$lon1);
    $a = sin($dphi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dlam / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Validates the employee is within the allowed radius of their assigned
 * Location (admin/locations.php), if geo_restricted is enabled for them.
 * Each Location carries its own GPS coordinates + radius, so different
 * branches/offices can enforce different radii.
 * Returns ['allowed' => bool, 'message' => string|null, 'distance' => float|null]
 */
function attendance_check_location_allowed(mysqli $conn, int $user_id, $user_lat, $user_lng): array {
    $stmt = $conn->prepare("SELECT geo_restricted, location FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || empty($user['geo_restricted'])) {
        return ['allowed' => true, 'distance' => null];
    }

    if ($user_lat === null || $user_lng === null) {
        return [
            'allowed' => false,
            'message' => 'Location access is required to mark attendance. Please enable GPS and try again.'
        ];
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

    $radius = max(50, (int)($loc['radius_meters'] ?? 100));
    $distance = attendance_haversine_meters($user_lat, $user_lng, $loc['latitude'], $loc['longitude']);

    if ($distance > $radius) {
        return [
            'allowed' => false,
            'message' => 'You are ' . round($distance) . ' m away from ' . $loc['name'] . '. Attendance is only allowed within ' . $radius . ' m.'
        ];
    }

    return ['allowed' => true, 'distance' => round($distance)];
}

function attendance_location_name($latitude, $longitude): string {
    $fallback = "Lat: " . number_format($latitude, 4) . ", Lng: " . number_format($longitude, 4);

    try {
        $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat=" . urlencode($latitude) . "&lon=" . urlencode($longitude) . "&zoom=18&addressdetails=1";
        $context = stream_context_create([
            'http' => ['timeout' => 3, 'user_agent' => 'AttendanceSystem/1.0'],
            'https' => ['timeout' => 3, 'user_agent' => 'AttendanceSystem/1.0'],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false || empty($response)) {
            return $fallback;
        }
        $data = json_decode($response, true);
        if (!$data || !isset($data['address'])) {
            return $fallback;
        }
        $address = $data['address'];
        $parts = [];
        if (!empty($address['house_number'])) $parts[] = $address['house_number'];
        if (!empty($address['road'])) $parts[] = $address['road'];
        if (!empty($address['suburb'])) $parts[] = $address['suburb'];
        if (!empty($address['city'])) $parts[] = $address['city'];
        $name = implode(", ", array_slice($parts, 0, 3));
        return $name !== '' ? $name : $fallback;
    } catch (Exception $e) {
        return $fallback;
    }
}

/** Returns the shift date (5:01 AM - 5:00 AM next day rule used across the app). */
function attendance_shift_date(): string {
    $hour = (int)date('H');
    $minute = (int)date('i');
    if ($hour < 6 || ($hour == 6 && $minute <= 0)) {
        return date('Y-m-d', strtotime('-1 day'));
    }
    return date('Y-m-d');
}

function attendance_save_selfie(string $base64Image, string $prefix, int $user_id): ?string {
    $upload_dir = __DIR__ . '/../uploads/selfies/' . date('Y/m/d');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $image_data = $base64Image;
    $image_data = str_replace('data:image/jpeg;base64,', '', $image_data);
    $image_data = str_replace('data:image/png;base64,', '', $image_data);
    $image_data = str_replace(' ', '+', $image_data);
    $image_binary = base64_decode($image_data);

    if ($image_binary === false) {
        return null;
    }

    $filename = $prefix . '_' . $user_id . '_' . date('YmdHis') . '.jpg';
    $filepath = $upload_dir . '/' . $filename;

    if (file_put_contents($filepath, $image_binary) === false) {
        return null;
    }

    return $filename;
}
