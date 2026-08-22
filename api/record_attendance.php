<?php
include('../config/db.php');
include('../config/api_auth.php');

$authUser = api_authenticate_flexible($conn);

$response = ['success' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $name = $_POST['name'] ?? '';
    $confidence = intval($_POST['confidence'] ?? 0);
    
    // DEBUG: Log what we received
    $browser_time = $_POST['browser_time'] ?? null;
    
    error_log("=== FACE ATTENDANCE DEBUG ===");
    error_log("POST employee_id raw: " . ($_POST['employee_id'] ?? 'MISSING'));
    error_log("POST employee_id intval: " . $employee_id);
    error_log("POST name: " . $name);
    error_log("POST confidence: " . $confidence);
    error_log("Browser time received: " . ($browser_time ?? 'NOT SENT'));
    error_log("Authenticated user_id: " . ($authUser['id'] ?? 'NOT SET'));
    
    error_log("Face Attendance: employee_id=$employee_id, name=$name, confidence=$confidence, browser_time=$browser_time");
    
    if ($employee_id > 0) {
        // Use browser time if provided, otherwise fallback to server time
        if ($browser_time) {
            $current_datetime = $browser_time;
            $current_time = substr($browser_time, 11, 8); // Extract HH:MM:SS from datetime
            $date = substr($browser_time, 0, 10); // Extract Y-M-D from datetime
        } else {
            // Fallback to server time (for legacy/API testing)
            $current_hour = (int)date('H');
            $current_minute = (int)date('i');
            if ($current_hour < 6 || ($current_hour == 6 && $current_minute <= 0)) {
                $date = date('Y-m-d', strtotime('-1 day'));
            } else {
                $date = date('Y-m-d');
            }
            $current_datetime = date('Y-m-d H:i:s');
            $current_time = date('H:i:s');
        }
        
        // Check if attendance record exists for the day - get LAST record only
        $check_stmt = $conn->prepare("SELECT id, punch_in, punch_out FROM attendance WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
        $check_stmt->bind_param("is", $employee_id, $date);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        error_log("Query: WHERE user_id = $employee_id AND date = $date ORDER BY id DESC LIMIT 1");
        error_log("Existing records found: " . $check_result->num_rows);
        
        if ($check_result->num_rows > 0) {
            // Record exists - need to decide if it's punch_in or punch_out
            $attendance = $check_result->fetch_assoc();
            
            if (is_null($attendance['punch_in'])) {
                // No punch_in yet - update as punch_in with Office location
                $office_location = "Office";
                $update_stmt = $conn->prepare("UPDATE attendance SET punch_in = ?, punch_in_location = ? WHERE id = ?");
                $update_stmt->bind_param("ssi", $current_datetime, $office_location, $attendance['id']);
                
                if ($update_stmt->execute()) {
                    $response['success'] = true;
                    $response['type'] = 'punch_in';
                    $response['message'] = "✓ Punch In recorded for $name at $current_time (Confidence: $confidence%)";
                    $response['punch_in'] = $current_time;
                    error_log("Punch IN recorded via Face for employee_id=$employee_id at $current_datetime");
                } else {
                    $response['message'] = 'Database error: ' . $update_stmt->error;
                    error_log("Punch IN failed: " . $update_stmt->error);
                }
                $update_stmt->close();
                
            } else if (is_null($attendance['punch_out'])) {
                // Has punch_in but no punch_out - update as punch_out
                $office_location = "Office";
                $update_stmt = $conn->prepare("UPDATE attendance SET punch_out = ?, punch_out_location = ? WHERE id = ?");
                $update_stmt->bind_param("ssi", $current_datetime, $office_location, $attendance['id']);
                
                if ($update_stmt->execute()) {
                    $response['success'] = true;
                    $response['type'] = 'punch_out';
                    $response['message'] = "✓ Punch Out recorded for $name at $current_time (Confidence: $confidence%)";
                    $response['punch_out'] = $current_time;
                    error_log("Punch OUT recorded via Face for employee_id=$employee_id at $current_datetime");
                } else {
                    $response['message'] = 'Database error: ' . $update_stmt->error;
                    error_log("Punch OUT failed: " . $update_stmt->error);
                }
                $update_stmt->close();
                
            } else {
                // Already has both punch_in and punch_out
                // Create NEW record for next punch in (multiple punches support)
                $office_location = "Office";
                $insert_stmt = $conn->prepare("INSERT INTO attendance (user_id, date, punch_in, punch_in_location, status) VALUES (?, ?, ?, ?, 'Present')");
                $insert_stmt->bind_param("isss", $employee_id, $date, $current_datetime, $office_location);
                
                if ($insert_stmt->execute()) {
                    $response['success'] = true;
                    $response['type'] = 'punch_in';
                    $response['message'] = "✓ Punch In recorded for $name at $current_time (Confidence: $confidence%) - Record #" . $insert_stmt->insert_id;
                    $response['punch_in'] = $current_time;
                    error_log("New attendance record created (multiple punch) for employee_id=$employee_id at $current_datetime");
                } else {
                    $response['message'] = 'Database error: ' . $insert_stmt->error;
                    error_log("Multiple punch insert failed: " . $insert_stmt->error);
                }
                $insert_stmt->close();
            }
        } else {
            // No record exists for today - create new attendance with punch_in and Office location
            $office_location = "Office";
            $insert_stmt = $conn->prepare("INSERT INTO attendance (user_id, date, punch_in, punch_in_location, status) VALUES (?, ?, ?, ?, 'Present')");
            $insert_stmt->bind_param("isss", $employee_id, $date, $current_datetime, $office_location);
            
            if ($insert_stmt->execute()) {
                $response['success'] = true;
                $response['type'] = 'punch_in';
                $response['message'] = "✓ Punch In recorded for $name at $current_time (Confidence: $confidence%)";
                $response['punch_in'] = $current_time;
                error_log("New attendance record created with Punch IN for employee_id=$employee_id at $current_datetime");
            } else {
                $response['message'] = 'Database error: ' . $insert_stmt->error;
                error_log("New attendance record creation failed: " . $insert_stmt->error);
            }
            $insert_stmt->close();
        }
        
        $check_stmt->close();
    } else {
        $response['message'] = 'Invalid employee_id';
        error_log("Invalid employee_id: $employee_id");
    }
}

echo json_encode($response);
?>
