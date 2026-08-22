<?php
/**
 * Unified Attendance Summary API
 * Returns: first punch_in, last punch_out, and total hours for a user on a given date
 * This merges data from both face attendance and manual punch in/out
 */
include('../config/db.php');
include('../config/api_auth.php');

$authUser = api_authenticate_flexible($conn);

$response = ['success' => false];

// Get parameters
$user_id = intval($_POST['user_id'] ?? $authUser['id']);
$date = $_POST['date'] ?? date('Y-m-d');

// Validate user is accessing their own data or is an admin
$requester_role = $authUser['role'] ?? '';
if ($user_id != $authUser['id'] && $requester_role !== 'admin' && $requester_role !== 'suparadmin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get all attendance records for the user on the given date
    $stmt = $conn->prepare("SELECT punch_in, punch_out FROM attendance WHERE user_id = ? AND date = ? ORDER BY punch_in ASC");
    $stmt->bind_param("is", $user_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $response['success'] = true;
        $response['has_attendance'] = false;
        $response['message'] = 'No attendance record for this date';
        $response['first_punch_in'] = null;
        $response['last_punch_out'] = null;
        $response['total_hours'] = 0;
        $response['total_hours_formatted'] = '0h 0m';
    } else {
        // Get all punch in and out times
        $punch_times = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['punch_in']) {
                $punch_times[] = ['time' => $row['punch_in'], 'type' => 'in'];
            }
            if ($row['punch_out']) {
                $punch_times[] = ['time' => $row['punch_out'], 'type' => 'out'];
            }
        }
        
        // Sort all times chronologically
        usort($punch_times, function($a, $b) {
            return strtotime($a['time']) - strtotime($b['time']);
        });
        
        if (empty($punch_times)) {
            $response['success'] = true;
            $response['has_attendance'] = false;
            $response['message'] = 'No valid punch in/out times';
            $response['first_punch_in'] = null;
            $response['last_punch_out'] = null;
            $response['total_hours'] = 0;
            $response['total_hours_formatted'] = '0h 0m';
        } else {
            // Get first punch_in and last punch_out
            $first_punch_in = null;
            $last_punch_out = null;
            $total_seconds = 0;
            
            // Find first punch_in
            foreach ($punch_times as $punch) {
                if ($punch['type'] === 'in') {
                    $first_punch_in = $punch['time'];
                    break;
                }
            }
            
            // Find last punch_out
            for ($i = count($punch_times) - 1; $i >= 0; $i--) {
                if ($punch_times[$i]['type'] === 'out') {
                    $last_punch_out = $punch_times[$i]['time'];
                    break;
                }
            }
            
            // Calculate total hours: first punch_in to last punch_out
            if ($first_punch_in && $last_punch_out) {
                $punch_in_timestamp = strtotime($date . ' ' . $first_punch_in);
                $punch_out_timestamp = strtotime($date . ' ' . $last_punch_out);
                
                // Handle case where punch_out is next day
                if ($punch_out_timestamp < $punch_in_timestamp) {
                    $punch_out_timestamp = strtotime(date('Y-m-d', strtotime($date . ' +1 day')) . ' ' . $last_punch_out);
                }
                
                $total_seconds = $punch_out_timestamp - $punch_in_timestamp;
            }
            
            // Convert seconds to hours and minutes
            $hours = floor($total_seconds / 3600);
            $minutes = floor(($total_seconds % 3600) / 60);
            $total_hours = round($total_seconds / 3600, 2); // Total hours as decimal
            $total_hours_formatted = sprintf('%dh %dm', $hours, $minutes);
            
            $response['success'] = true;
            $response['has_attendance'] = true;
            $response['first_punch_in'] = $first_punch_in;
            $response['last_punch_out'] = $last_punch_out;
            $response['total_hours'] = $total_hours;
            $response['total_hours_formatted'] = $total_hours_formatted;
            $response['total_seconds'] = $total_seconds;
            $response['all_punches'] = $punch_times;
            $response['punch_count'] = count($punch_times);
        }
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('Attendance Summary Error: ' . $e->getMessage());
}

echo json_encode($response);
?>
