<?php
session_start();
include('../config/db.php');

// Only allow access if authenticated and is admin/suparadmin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'suparadmin'])) {
    header("Location: ../auth/login.php");
    exit();
}

$migration_results = [];
$setup_error = false;

// Run database migration on request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    $migrations = [
        "ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL COMMENT 'Path to employee profile photo for face recognition'",
        "ALTER TABLE attendance ADD COLUMN source ENUM('dashboard', 'face_recognition', 'admin') DEFAULT 'dashboard' COMMENT 'Source of attendance record'",
        "ALTER TABLE attendance ADD COLUMN photo_match_confidence INT DEFAULT NULL COMMENT 'Face recognition match confidence percentage'",
        "ALTER TABLE attendance ADD COLUMN is_first_in BOOLEAN DEFAULT FALSE COMMENT 'Whether this is first punch in of the day'",
        "ALTER TABLE attendance ADD COLUMN is_last_out BOOLEAN DEFAULT FALSE COMMENT 'Whether this is last punch out of the day'",
    ];
    
    foreach ($migrations as $migration) {
        $result = @$conn->query($migration);
        
        if ($result === false) {
            if (strpos($conn->error, 'Duplicate') !== false || strpos($conn->error, 'already exists') !== false) {
                $migration_results[] = [
                    'sql' => $migration,
                    'status' => 'warning',
                    'message' => 'Already exists: ' . $conn->error
                ];
            } else {
                $setup_error = true;
                $migration_results[] = [
                    'sql' => $migration,
                    'status' => 'error',
                    'message' => 'Error: ' . $conn->error
                ];
            }
        } else {
            $migration_results[] = [
                'sql' => $migration,
                'status' => 'success',
                'message' => 'Migration successful'
            ];
        }
    }
}

// Check which columns exist
$column_checks = [];
$result = $conn->query("DESCRIBE attendance");
if ($result) {
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[$row['Field']] = true;
    }
    
    $column_checks = [
        'source' => isset($columns['source']),
        'profile_photo' => isset($columns['profile_photo']),
        'photo_match_confidence' => isset($columns['photo_match_confidence']),
        'is_first_in' => isset($columns['is_first_in']),
        'is_last_out' => isset($columns['is_last_out'])
    ];
} else {
    // Try to check users table for profile_photo
    $result_users = $conn->query("DESCRIBE users");
    if ($result_users) {
        $columns_users = [];
        while ($row = $result_users->fetch_assoc()) {
            $columns_users[$row['Field']] = true;
        }
        $column_checks['profile_photo'] = isset($columns_users['profile_photo']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Recognition Setup & Guide</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background-color: #f5f7fa;
        }
        .navbar-custom {
            background-color: #007bff !important;
            color: white;
            padding: 1rem;
        }
        .step-card {
            margin-bottom: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        .step-card:hover {
            transform: translateY(-5px);
        }
        .step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: #007bff;
            color: white;
            font-weight: bold;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }
        .step-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
        }
        .status-badge {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 5px;
            font-weight: bold;
            margin-top: 10px;
        }
        .status-success {
            background-color: #d4edda;
            color: #155724;
        }
        .status-warning {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-error {
            background-color: #f8d7da;
            color: #721c24;
        }
        .feature-list {
            list-style: none;
            padding: left: 0;
        }
        .feature-list li {
            padding: 8px 0;
            padding-left: 30px;
            position: relative;
        }
        .feature-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #28a745;
            font-weight: bold;
        }
        .warning-box {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .success-box {
            background-color: #d4edda;
            border: 1px solid #28a745;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .code-block {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 12px;
            margin: 10px 0;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            overflow-x: auto;
        }
        .toc {
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 30px;
        }
        .toc ul {
            margin-bottom: 0;
        }
        .toc a {
            color: #007bff;
            text-decoration: none;
        }
        .toc a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container mt-4 mb-5">
        <!-- Table of Contents -->
        <div class="toc">
            <h5><i class="fas fa-list"></i> Table of Contents</h5>
            <ul>
                <li><a href="#step1">Step 1: Run Database Migration</a></li>
                <li><a href="#step2">Step 2: Upload Employee Photos</a></li>
                <li><a href="#step3">Step 3: Start Using Face Recognition Panel</a></li>
                <li><a href="#features">Features Overview</a></li>
                <li><a href="#faq">FAQ & Troubleshooting</a></li>
            </ul>
        </div>

        <!-- Status Check -->
        <div id="step1" class="row mb-4">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-database"></i> Step 1: Database Setup Status</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-3">The system requires certain database columns for face recognition features. Check your current setup status below:</p>
                        
                        <table class="table mb-3">
                            <thead>
                                <tr>
                                    <th>Column</th>
                                    <th>Table</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($column_checks as $col => $exists): ?>
                                <tr>
                                    <td><code><?php echo $col; ?></code></td>
                                    <td><?php echo strpos($col, 'profile_photo') !== false ? 'users' : 'attendance'; ?></td>
                                    <td>
                                        <?php if ($exists): ?>
                                        <span class="badge bg-success"><i class="fas fa-check"></i> Present</span>
                                        <?php else: ?>
                                        <span class="badge bg-danger"><i class="fas fa-times"></i> Missing</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if (array_search(false, $column_checks) !== false): ?>
                        <form method="POST" style="margin-top: 20px;">
                            <button type="submit" name="run_migration" class="btn btn-primary btn-lg">
                                <i class="fas fa-cogs"></i> Run Database Migration
                            </button>
                        </form>
                        
                        <?php if (!empty($migration_results)): ?>
                        <div style="margin-top: 20px;">
                            <h6>Migration Results:</h6>
                            <?php foreach ($migration_results as $result): ?>
                            <div class="alert alert-<?php echo $result['status'] === 'success' ? 'success' : ($result['status'] === 'warning' ? 'warning' : 'danger'); ?> mb-2">
                                <strong><?php echo ucfirst($result['status']); ?>:</strong> <?php echo htmlspecialchars($result['message']); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="success-box">
                            <i class="fas fa-check-circle"></i> <strong>All database columns are present!</strong> Your system is ready for face recognition features.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2 -->
        <div id="step2" class="row mb-4">
            <div class="col-lg-12">
                <div class="card step-card">
                    <div class="card-header bg-success text-white">
                        <div class="step-number" style="background-color: #28a745; margin-bottom: 0; margin-right: 15px;">2</div>
                        <span class="step-title" style="display: inline-block; margin-bottom: 0;">Upload Employee Photos</span>
                    </div>
                    <div class="card-body">
                        <p><strong>Purpose:</strong> Employee photos are used for face recognition matching in the attendance panel.</p>
                        
                        <div class="warning-box">
                            <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> Clear, front-facing photos work best for accurate face recognition. Make sure employees are looking directly at the camera.
                        </div>

                        <h6>How to Upload Photos:</h6>
                        <ol>
                            <li>Go to <strong>📸 Employee Photos</strong> section from the Super Admin Dashboard (Suparadmin only)</li>
                            <li>Select an employee from the dropdown</li>
                            <li>Either upload an image file or capture one using your camera</li>
                            <li>Click "Upload Photo" to save</li>
                            <li>The photo will be used for face matching in the attendance panel</li>
                        </ol>

                        <p class="mt-3">
                            <?php if ($_SESSION['role'] === 'suparadmin'): ?>
                            <a href="manage_employee_photos.php" class="btn btn-success">
                                <i class="fas fa-camera"></i> Go to Employee Photos
                            </a>
                            <?php else: ?>
                            <span class="text-muted"><em>Only Supadmins can manage employee photos.</em></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3 -->
        <div id="step3" class="row mb-4">
            <div class="col-lg-12">
                <div class="card step-card">
                    <div class="card-header bg-info text-white">
                        <div class="step-number" style="background-color: #17a2b8; margin-bottom: 0; margin-right: 15px;">3</div>
                        <span class="step-title" style="display: inline-block; margin-bottom: 0;">Use the Face Recognition Panel</span>
                    </div>
                    <div class="card-body">
                        <p><strong>Purpose:</strong> The multi-user attendance panel allows multiple employees to mark attendance using face recognition at the same station.</p>

                        <h6>How It Works:</h6>
                        <ol>
                            <li>Open the <strong>🎭 Face Recognition Panel</strong> from the dashboard</li>
                            <li>The panel will request camera access - click "Allow"</li>
                            <li>Stand in front of the camera and wait for detection</li>
                            <li>When your face matches an employee photo (75%+ confidence), attendance is automatically marked</li>
                            <li>The panel shows:
                                <ul>
                                    <li>Live camera feed with face detection boxes</li>
                                    <li>Number of faces detected</li>
                                    <li>Real-time attendance log on the right side</li>
                                    <li>Which employee was matched and at what time</li>
                                </ul>
                            </li>
                            <li>Step back, and the panel is ready to detect the next person</li>
                        </ol>

                        <div class="warning-box" style="margin-top: 15px;">
                            <i class="fas fa-info-circle"></i> <strong>Note:</strong> 
                            <ul class="mb-0">
                                <li>Multiple people can use the same panel simultaneously</li>
                                <li>First face detected gets matched with employee photos</li>
                                <li>There's a 30-second cooldown to prevent duplicate detection</li>
                                <li>Photos must be uploaded before the panel can detect employees</li>
                            </ul>
                        </div>

                        <p class="mt-3">
                            <a href="../multi_user_attendance.php" class="btn btn-info" target="_blank">
                                <i class="fas fa-external-link-alt"></i> Open Face Recognition Panel
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Features -->
        <div id="features" class="row mb-4">
            <div class="col-lg-12">
                <div class="card step-card">
                    <div class="card-header bg-warning text-white">
                        <h5 class="mb-0"><i class="fas fa-star"></i> System Features</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="fas fa-camera"></i> Face Recognition</h6>
                                <ul class="feature-list">
                                    <li>Real-time face detection using AI</li>
                                    <li>Matches detected faces with employee photos</li>
                                    <li>Shows confidence percentage (aim for 75%+)</li>
                                    <li>Works in various lighting conditions</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-layer-group"></i> Multi-User Support</h6>
                                <ul class="feature-list">
                                    <li>Multiple employees at one station</li>
                                    <li>Automatic attendance marking</li>
                                    <li>Real-time attendance log display</li>
                                    <li>Detection cooldown to prevent duplicates</li>
                                </ul>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <h6><i class="fas fa-clock"></i> First-In-Last-Out Logic</h6>
                                <ul class="feature-list">
                                    <li>Tracks all attendance sources</li>
                                    <li>Calculates first punch-in time</li>
                                    <li>Calculates last punch-out time</li>
                                    <li>Works across multiple attendance methods</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-code-branch"></i> Multiple Sources</h6>
                                <ul class="feature-list">
                                    <li>Employee dashboard manual punch</li>
                                    <li>Face recognition automatic detection</li>
                                    <li>Admin manual entry</li>
                                    <li>All sources merged intelligently</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- FAQ & Troubleshooting -->
        <div id="faq" class="row mb-4">
            <div class="col-lg-12">
                <div class="card step-card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fas fa-question-circle"></i> FAQ & Troubleshooting</h5>
                    </div>
                    <div class="card-body">
                        <div class="accordion" id="faqAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                        Why isn't my face being detected?
                                    </button>
                                </h2>
                                <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        <ul>
                                            <li><strong>No photo uploaded:</strong> Make sure your employee photo is uploaded in the Employee Photos section</li>
                                            <li><strong>Poor lighting:</strong> The face detection works better in adequate lighting</li>
                                            <li><strong>Wrong angle:</strong> Make sure you're facing the camera directly</li>
                                            <li><strong>Camera not accessible:</strong> Check browser permissions - you must allow camera access</li>
                                            <li><strong>Models still loading:</strong> Wait for "Face detection models loaded" message</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                        What if face recognition fails, can I still punch in manually?
                                    </button>
                                </h2>
                                <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Yes! The system supports multiple attendance sources:
                                        <ul>
                                            <li>Use the <strong>Employee Dashboard</strong> to manually punch in/out</li>
                                            <li>An <strong>Admin</strong> can manually create attendance records</li>
                                            <li>All sources are merged using first-in-last-out logic</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                        What is "First-In-Last-Out" logic?
                                    </button>
                                </h2>
                                <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        If an employee records attendance multiple times (from different sources), the system:
                                        <ul>
                                            <li><strong>FIRST punch-in time:</strong> The earliest detection across all sources</li>
                                            <li><strong>LAST punch-out time:</strong> The latest detection across all sources</li>
                                        </ul>
                                        <strong>Example:</strong> If John marks attendance on dashboard at 8:15 AM and face recognition detects him at 8:10 AM, the official punch-in is 8:10 AM.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                        Confidence percentage - what does it mean?
                                    </button>
                                </h2>
                                <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        The confidence percentage shows how closely the detected face matches the employee's stored photo:
                                        <ul>
                                            <li><strong>75%+:</strong> Good match - attendance will be marked automatically</li>
                                            <li><strong>50-75%:</strong> Moderate match - may not mark automatically</li>
                                            <li><strong>&lt;50%:</strong> Poor match - won't mark attendance</li>
                                        </ul>
                                        Better photos = better detection! Clear, front-facing photos give best results.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                                        Can multiple people be detected at once?
                                    </button>
                                </h2>
                                <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        The system can detect multiple faces in the camera frame and shows "Faces Detected: X", but:
                                        <ul>
                                            <li>Only the <strong>first/primary face</strong> is matched with employee photos</li>
                                            <li>After a successful detection, there's a 30-second cooldown to prevent duplicates</li>
                                            <li>Other people in frame are ignored until the cooldown expires</li>
                                            <li>This helps prevent accidental duplicate attendance marking</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6">
                                        Do employees need to upload their own photos?
                                    </button>
                                </h2>
                                <div id="faq6" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        No. <strong>Only Supadmins</strong> can upload/manage employee photos. The process:
                                        <ul>
                                            <li>Go to <strong>📸 Manage Employee Photos</strong> (Suparadmin panel)</li>
                                            <li>Select an employee from the dropdown</li>
                                            <li>Upload their photo or capture one using your camera</li>
                                            <li>Photos are automatically stored and used for face recognition</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq7">
                                        What file formats are supported for employee photos?
                                    </button>
                                </h2>
                                <div id="faq7" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Supported formats:
                                        <ul>
                                            <li>JPG / JPEG</li>
                                            <li>PNG</li>
                                            <li>GIF</li>
                                        </ul>
                                        <strong>File size limit:</strong> 5 MB maximum
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Implementation Summary -->
        <div class="row">
            <div class="col-lg-12">
                <div class="alert alert-info">
                    <h5><i class="fas fa-check-circle"></i> Implementation Complete!</h5>
                    <p class="mb-0">Your face recognition multi-user attendance system is ready. The system integrates seamlessly with your existing attendance system and tracks attendance from multiple sources using first-in-last-out logic.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
