# HRMS Attendance Management System
## Technical Documentation

**Document Type:** Technical Project Documentation  
**System Name:** HRMS – Attendance Management System  
**Technology Stack:** PHP · MySQL · Bootstrap 5 · JavaScript  
**Environment:** XAMPP (Apache + MySQL) · Windows Server  
**Version:** 2.0 (Multi-Role, Face Recognition, GPS Geofencing)

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Objectives](#2-objectives)
3. [Technology Stack](#3-technology-stack)
4. [System Architecture](#4-system-architecture)
5. [User Roles & Access Control](#5-user-roles--access-control)
6. [Module Descriptions](#6-module-descriptions)
   - 6.1 Super Admin Module
   - 6.2 Admin Module
   - 6.3 Employee Module
   - 6.4 Face Operator Module
7. [Attendance & Punch Workflow](#7-attendance--punch-workflow)
8. [GPS Geofencing](#8-gps-geofencing)
9. [Face Recognition Attendance](#9-face-recognition-attendance)
10. [Leave, OD & Comp-Off Management](#10-leave-od--comp-off-management)
11. [Database Structure](#11-database-structure)
12. [Reports & Excel Exports](#12-reports--excel-exports)
13. [Authentication & Authorization](#13-authentication--authorization)
14. [Admin Rights Management](#14-admin-rights-management)
15. [Employee Import (Bulk Upload)](#15-employee-import-bulk-upload)
16. [Implementation Details](#16-implementation-details)
17. [Testing](#17-testing)
18. [Deployment](#18-deployment)
19. [Challenges & Solutions](#19-challenges--solutions)

---

## 1. Project Overview

The **HRMS Attendance Management System** is a web-based application designed to digitize and automate employee attendance tracking for organizations with multiple companies, departments, branches, and shift patterns.

The system eliminates paper-based registers and manual spreadsheets by providing a centralized platform where employees can punch in/out via smartphone browser (with GPS verification), while HR administrators can monitor, manage, and export attendance data in real time.

The platform supports four distinct user roles — Super Admin, Admin, Employee, and Face Operator — each with their own secure dashboard and set of capabilities. An advanced Rights Management system allows the Super Admin to precisely control which modules each Admin can access.

---

## 2. Objectives

| # | Objective |
|---|-----------|
| 1 | Automate daily attendance recording with GPS-verified punch-in/out |
| 2 | Support multiple companies, departments, locations, and shift schedules |
| 3 | Provide a four-tier role-based access control system |
| 4 | Enable face-recognition-based attendance as an alternative to phone-based punch |
| 5 | Allow HR to manage Compensatory Off (Comp-Off) and Out-of-Station Duty (OD) |
| 6 | Generate downloadable monthly attendance reports in Excel (XLSX) format |
| 7 | Support bulk import of employee master data from Excel |
| 8 | Enforce GPS geofencing so employees can only punch from within the office radius |
| 9 | Provide a granular Admin rights system to limit module access per admin |
| 10 | Maintain a full audit trail of all attendance records with timestamps and source |

---

## 3. Technology Stack

| Layer | Technology |
|-------|------------|
| **Server-side Language** | PHP 8.x |
| **Database** | MySQL 8.x (via MySQLi with prepared statements) |
| **Frontend Framework** | Bootstrap 5.3.2 |
| **Icons** | Font Awesome 6.0 |
| **Alerts/Modals** | SweetAlert2 11 |
| **Excel Read/Write** | PhpSpreadsheet (via Composer) |
| **Face Recognition** | Python (OpenCV + face_recognition library), called via PHP |
| **Geolocation** | HTML5 Geolocation API + Nominatim (OpenStreetMap) reverse geocoding |
| **Web Server** | Apache 2.4 (XAMPP) |
| **Session Management** | PHP native sessions |
| **Password Hashing** | PHP `password_hash()` / `password_verify()` with BCRYPT |

---

## 4. System Architecture

```
┌──────────────────────────────────────────────────────────┐
│                     Web Browser (Client)                 │
│     Bootstrap 5 UI · JavaScript · HTML5 Geolocation      │
└─────────────────────────┬────────────────────────────────┘
                          │ HTTP/HTTPS
┌─────────────────────────▼────────────────────────────────┐
│              Apache Web Server (XAMPP)                   │
│                  PHP 8.x Application                     │
│                                                          │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌─────────┐ │
│  │  /admin  │  │/employee │  │  /auth   │  │  /api   │ │
│  │  module  │  │  module  │  │  module  │  │ module  │ │
│  └──────────┘  └──────────┘  └──────────┘  └─────────┘ │
│                                                          │
│  ┌──────────────────────────────────────────────────┐   │
│  │              /config/db.php                      │   │
│  │         MySQLi Connection Layer                  │   │
│  └──────────────────────┬───────────────────────────┘   │
└─────────────────────────┼────────────────────────────────┘
                          │
┌─────────────────────────▼────────────────────────────────┐
│                 MySQL Database                           │
│           Database: attendance                           │
│                                                          │
│  users · attendance · departments · companies            │
│  locations · shifts · od_records · comp_off_requests     │
│  office_settings                                         │
└──────────────────────────────────────────────────────────┘
                          │
┌─────────────────────────▼────────────────────────────────┐
│         Python Face Recognition Service                  │
│      OpenCV · face_recognition · NumPy                   │
│      (invoked by PHP via shell_exec / API call)          │
└──────────────────────────────────────────────────────────┘
```

### Directory Structure

```
/attendence
├── /admin              ← Admin & Super Admin pages
├── /employee           ← Employee-facing pages
├── /auth               ← Login / Logout
├── /api                ← JSON API endpoints
├── /config             ← Database connection (db.php)
├── /assets             ← CSS, JS, images
├── /uploads            ← Employee profile photos
└── /vendor             ← Composer packages (PhpSpreadsheet)
```

---

## 5. User Roles & Access Control

The system defines four roles stored in the `users.role` ENUM column:

| Role | Dashboard File | Description |
|------|---------------|-------------|
| `suparadmin` | `admin/dashboard.php` | Full system access. Creates admins, manages all settings. |
| `admin` | `admin/admin_dashboard.php` | Operational HR tasks. Access limited by Rights configuration. |
| `employee` | `employee/dashboard.php` | Can punch in/out and view own attendance log. |
| `face_operator` | `face_dashboard.php` | Operates face-recognition attendance terminal. |

### Role Routing (Login Flow)

```
User submits credentials
        │
        ▼
Verify phone + password (password_verify / BCRYPT)
        │
        ├─ role = suparadmin  ──► admin/dashboard.php
        ├─ role = admin       ──► admin/admin_dashboard.php
        ├─ role = employee    ──► employee/dashboard.php
        └─ role = face_operator ─► face_dashboard.php
```

---

## 6. Module Descriptions

### 6.1 Super Admin Module (`admin/dashboard.php`)

The Super Admin has unrestricted access to every feature:

| Card | File | Purpose |
|------|------|---------|
| Manage Admins | `admin/admin.php` | Create, edit, delete admin accounts and assign rights |
| Manage Employees | `admin/employees.php` | Full employee CRUD |
| Manage Passwords | `admin/manage_passwords.php` | Reset employee login passwords |
| Manage Departments | `admin/department.php` | Create/delete departments |
| View Attendance | `admin/attendance.php` | View all records with filters |
| Manual Attendance | `admin/manual_attendance.php` | Mark attendance without smartphone |
| Comp Off Management | `admin/comp_off_management.php` | Assign compensatory off days |
| Export Reports | `admin/export_monthly.php` | Download Excel reports |
| Manage Companies | `admin/companies.php` | Multi-company setup |
| Manage Shifts | `admin/shifts.php` | Define shift timings |
| Manage Locations | `admin/locations.php` | Branch/office locations |
| OD Management | `admin/od_management.php` | Mark out-of-station duty |
| Face Operators | `admin/manage_face_operators.php` | Create face attendance operator accounts |
| GPS Restriction | `admin/geo_restriction.php` | Set office coordinates and per-employee GPS enforcement |

### 6.2 Admin Module (`admin/admin_dashboard.php`)

Admins have the same operational pages as Super Admin, but the cards shown on their dashboard are controlled by the **Rights** field set by the Super Admin. If no rights are assigned, the admin sees all cards (backward-compatible default).

Available rights keys:

```
manage_employees · manage_departments · view_attendance · manual_attendance
comp_off · export_reports · manage_companies · manage_shifts
manage_locations · od_management · gps_restriction · manage_passwords
```

### 6.3 Employee Module (`employee/dashboard.php`)

| Feature | File | Description |
|---------|------|-------------|
| Punch In | `employee/punch_in.php` | Captures GPS coordinates, reverse-geocodes location, records punch-in time |
| Punch Out | `employee/punch_out.php` | Records punch-out time and updates attendance record |
| My Attendance | `employee/my_attendance.php` | Shows employee's own monthly attendance history |

### 6.4 Face Operator Module

The face operator logs into a dedicated terminal interface, selects or scans an employee face, and the system logs attendance automatically. Confidence scores are stored alongside each record.

---

## 7. Attendance & Punch Workflow

### Employee Punch-In Process

```
Employee opens Punch In page
        │
        ▼
HTML5 Geolocation API requests GPS coordinates
        │
        ├─ GPS denied? ──► Error: "Please enable location"
        │
        ▼
GPS coordinates captured (latitude, longitude)
        │
        ▼
If geo_restricted = 1 for this employee:
    Calculate Haversine distance from office_settings coordinates
    │
    ├─ Distance > radius_meters? ──► Block punch with distance message
    │
    └─ Within radius? ──► Continue
        │
        ▼
Reverse geocode coordinates via Nominatim API
(road, suburb, city → human-readable location string)
        │
        ▼
INSERT into attendance table:
  user_id, date, punch_in_time, punch_in_lat, punch_in_lng,
  punch_in_location, source='dashboard'
  (or UPDATE if record for today exists – multiple punches)
        │
        ▼
Success message shown with location name and time
```

### Attendance Record Structure (per day per employee)

```
date          → working date
punch_in      → first punch-in time
punch_out     → last punch-out time
punch_in_lat/lng  → GPS coordinates at punch-in
punch_in_location → Reverse-geocoded address
source        → 'dashboard' | 'face_recognition' | 'admin'
status        → auto-calculated: Present / Absent / OD / Comp-Off
```

### Multiple Punches

The system supports multiple punch-in/out events per day. The `attendance` table uses a `UNIQUE KEY (user_id, date)` — a single row stores the first punch-in and last punch-out, while intermediate events are tracked in a separate punch log table.

---

## 8. GPS Geofencing

Managed via `admin/geo_restriction.php`.

**Office settings** are stored in the `office_settings` table:
- `latitude` / `longitude` — office GPS coordinates
- `radius_meters` — allowed radius (minimum 50 m, maximum 5000 m, default 100 m)

**Per-employee enforcement** is controlled by the `users.geo_restricted` column (TINYINT, 0 or 1). Only employees with `geo_restricted = 1` are subject to the distance check.

**Distance calculation** uses the Haversine formula implemented in PHP:

```
distance = 2 × R × arcsin(√(sin²(Δlat/2) + cos(lat1)×cos(lat2)×sin²(Δlng/2)))
```

Where R = 6371000 metres (Earth radius).

---

## 9. Face Recognition Attendance

The face recognition subsystem is a secondary attendance channel alongside mobile punch-in.

### Components

| Component | Technology |
|-----------|------------|
| Photo capture | HTML5 `<video>` / `getUserMedia()` |
| Photo storage | `/uploads/` directory, linked to `users.profile_photo` |
| Recognition engine | Python (`face_recognition`, OpenCV) |
| Confidence score | Stored in `attendance.photo_match_confidence` (integer %) |
| Attendance source | `attendance.source = 'face_recognition'` |

### Setup Flow

1. Super Admin runs database migration via `admin/face_recognition_setup.php` to add required columns (`profile_photo`, `source`, `photo_match_confidence`, `is_first_in`, `is_last_out`)
2. Admin uploads employee photos via `admin/manage_employee_photos.php`
3. Face Operator logs in and uses the terminal to mark attendance

---

## 10. Leave, OD & Comp-Off Management

### Out-of-Station Duty (OD)

**File:** `admin/od_management.php`  
**Table:** `od_records`

Admins mark an employee as OD for a specific date. On that date, the employee's attendance status is shown as **OD** even without a punch-in record. This prevents the day from being counted as absent.

```sql
CREATE TABLE od_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    od_date DATE NOT NULL,
    marked_by INT NOT NULL,
    marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_od (user_id, od_date)
);
```

### Compensatory Off (Comp-Off)

**File:** `admin/comp_off_management.php`  
**Table:** `comp_off_requests`

When an employee works on a designated off-day (e.g., Sunday), the admin marks a Comp-Off for a future date. On that future date, attendance is recorded as **Comp-Off** (paid leave).

```sql
CREATE TABLE comp_off_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    comp_off_date DATE NOT NULL,
    earned_date DATE,
    marked_by INT NOT NULL,
    marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_comp_off (user_id, comp_off_date)
);
```

---

## 11. Database Structure

### Core Tables

#### `users`
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK AUTO_INCREMENT | Primary key |
| name | VARCHAR(100) | Full name |
| email | VARCHAR(150) UNIQUE | Login email |
| phone | VARCHAR(20) UNIQUE | Login phone number |
| password | VARCHAR(255) | BCRYPT hashed password |
| role | ENUM('suparadmin','admin','employee','face_operator') | Access role |
| employee_id | VARCHAR(50) | HR employee code |
| department | VARCHAR(100) | Department name |
| company | VARCHAR(100) | Company name |
| location | VARCHAR(100) | Branch/office location |
| designation | VARCHAR(100) | Job title |
| shift_id | INT FK | Assigned shift |
| status | VARCHAR(20) | 'Working' / 'Resigned' |
| geo_restricted | TINYINT(1) | GPS enforcement flag |
| profile_photo | VARCHAR(255) | Path to face photo |
| rights | TEXT | JSON array of allowed module keys (admin only) |
| created_at | TIMESTAMP | Account creation time |
| updated_at | TIMESTAMP | Last update time |

#### `attendance`
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK AUTO_INCREMENT | Primary key |
| user_id | INT FK | References users.id |
| date | DATE | Working date |
| punch_in | TIME | First punch-in time |
| punch_out | TIME | Last punch-out time |
| punch_in_lat | DECIMAL(11,8) | GPS latitude at punch-in |
| punch_in_lng | DECIMAL(11,8) | GPS longitude at punch-in |
| punch_in_location | VARCHAR(255) | Reverse-geocoded address |
| source | ENUM('dashboard','face_recognition','admin') | How record was created |
| photo_match_confidence | INT | Face recognition confidence % |
| is_first_in | BOOLEAN | First punch flag |
| is_last_out | BOOLEAN | Last punch flag |
| created_at | TIMESTAMP | Record creation time |
| updated_at | TIMESTAMP | Last modification time |

**Unique constraint:** `UNIQUE KEY unique_daily_record (user_id, date)`

#### `departments`
| Column | Type |
|--------|------|
| id | INT PK |
| name | VARCHAR(100) UNIQUE |
| created_at | TIMESTAMP |

#### `companies`
| Column | Type |
|--------|------|
| id | INT PK |
| name | VARCHAR(100) UNIQUE |
| created_at | TIMESTAMP |

#### `locations`
| Column | Type |
|--------|------|
| id | INT PK |
| name | VARCHAR(100) UNIQUE |
| created_at | TIMESTAMP |

#### `shifts`
| Column | Type |
|--------|------|
| id | INT PK |
| name | VARCHAR(100) UNIQUE |
| start_time | TIME |
| end_time | TIME |
| created_at | TIMESTAMP |

#### `office_settings`
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | |
| office_name | VARCHAR(100) | Display name |
| latitude | DECIMAL(11,8) | Office GPS latitude |
| longitude | DECIMAL(11,8) | Office GPS longitude |
| radius_meters | INT | Geofence radius (50–5000 m) |
| updated_at | TIMESTAMP | |

#### `od_records`
Stores Out-of-Station Duty assignments (see Section 10).

#### `comp_off_requests`
Stores Compensatory Off grants (see Section 10).

---

## 12. Reports & Excel Exports

All exports use the **PhpSpreadsheet** library (loaded via Composer).

| Export | File | Output |
|--------|------|--------|
| Monthly Attendance | `export_monthly.php` | XLSX with employee × date grid, with Present/Absent/OD/Comp-Off status per cell |
| Employee Master List | `export_employee_excel.php` | XLSX listing all employees with department, location, shift, designation, DOJ |
| Location-wise Report | `export_location_excel.php` | XLSX filtered by branch/location |
| All Departments | `export_all_departments.php` | Combined XLSX across all departments |
| Attendance Records | `export_attendance.php` | Raw punch-in/out records with GPS data |

### Monthly Report Logic

1. Admin selects month, year, department, location, and company filters
2. System fetches all working employees matching the filter
3. For each employee, iterates over every calendar day in the month
4. Per day: checks attendance, od_records, comp_off_requests tables
5. Assigns status: **P** (Present) / **A** (Absent) / **OD** / **CO** (Comp-Off) / **–** (Off-day/weekend)
6. Outputs XLSX with colour-coded cells and summary counts

---

## 13. Authentication & Authorization

### Login (`auth/login.php`)

- Users log in with **phone number + password**
- Password verified using `password_verify($input, $stored_bcrypt_hash)`
- On success: session variables set (`user_id`, `role`, `name`)
- Role-based redirect to appropriate dashboard

### Session Guards

Every protected page starts with a guard:

```php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
```

Pages accessible to multiple roles check with `in_array()`:

```php
if (!in_array($_SESSION['role'], ['admin', 'suparadmin'])) {
    header("Location: ../auth/login.php");
    exit();
}
```

### SQL Injection Prevention

All database queries use **MySQLi prepared statements** with bound parameters:

```php
$stmt = $conn->prepare("SELECT id FROM users WHERE phone = ? AND role = ?");
$stmt->bind_param("ss", $phone, $role);
$stmt->execute();
```

### XSS Prevention

All user-supplied output is escaped with `htmlspecialchars()` before rendering in HTML.

### Password Security

- Passwords stored using `password_hash($password, PASSWORD_BCRYPT)`
- Minimum password length enforced (6 characters)
- Admins reset employee passwords via a dedicated page; passwords are never shown in plaintext

---

## 14. Admin Rights Management

The system implements a fine-grained permissions model for admin accounts, controlled exclusively by the Super Admin.

### How It Works

1. When creating or editing an admin in `admin/admin.php`, the Super Admin sees a **Rights** checkbox panel listing all 12 available module cards
2. Selected rights are stored as a **JSON array** in `users.rights` (TEXT column)

**Example stored value:**
```json
["manage_employees","view_attendance","export_reports","manage_passwords"]
```

3. When the admin logs in, `admin_dashboard.php` decodes the JSON and only renders cards that are in the array
4. If `rights` is `NULL` (pre-existing admins without rights set), all cards are shown — fully backward compatible

### Rights Keys

| Key | Card |
|-----|------|
| `manage_employees` | Manage Employees |
| `manage_departments` | Manage Departments |
| `view_attendance` | View Attendance |
| `manual_attendance` | Manual Attendance |
| `comp_off` | Comp Off Management |
| `export_reports` | Export Reports |
| `manage_companies` | Manage Companies |
| `manage_shifts` | Manage Shifts |
| `manage_locations` | Manage Locations |
| `od_management` | OD Management |
| `gps_restriction` | GPS Restriction |
| `manage_passwords` | Manage Passwords |

### Page-Level Guard

Rights are also enforced at the **page level** for sensitive modules (e.g., `manage_passwords.php`):

```php
if ($_SESSION['role'] === 'admin') {
    $stmt = $conn->prepare("SELECT rights FROM users WHERE id = ?");
    // ... fetch rights, decode JSON, check key exists
    if (!in_array('manage_passwords', $rights)) {
        header("Location: ../auth/login.php");
        exit();
    }
}
```

---

## 15. Employee Import (Bulk Upload)

**File:** `admin/import_employees.php`  
**Library:** PhpSpreadsheet (`IOFactory::load()`)

### Excel Format Expected

| Column | Field |
|--------|-------|
| A | Serial No. |
| B | Employee Name |
| C | Department |
| D | Location |
| E | Designation |
| F | Company |
| G | Date of Joining (DOJ) |
| H | Phone Number |
| I | Off Day |

### Import Logic

1. Admin uploads `.xlsx` or `.xls` file
2. System skips row 0 (title) and row 1 (headers); data starts from row 2
3. For each row: validates phone number uniqueness, inserts new employee with role `employee` and a default password (phone number hashed)
4. Duplicate phone numbers are skipped and counted separately
5. Summary shown: imported count / skipped count / error list

---

## 16. Implementation Details

### Multi-Company Support

The system supports an unlimited number of companies. Each employee is assigned to one company. All reports and filters support multi-company selection via checkbox arrays.

### Shift Management

Shifts have a name, start time, and end time. Each employee can be assigned a shift. Shift data is used in attendance reports to calculate late arrivals or early departures.

### Off-Day Configuration

Each employee has a configurable `off_day` field (e.g., `Sunday`). The export engine checks this to correctly mark weekends as non-working days rather than absent.

### Back-Button Routing

Pages accept a `?from=suparadmin` or `?from=admin` GET parameter to correctly route the "Back to Dashboard" button, since many admin pages are shared between both roles.

### Automatic Column Migration

Rather than requiring a manual database migration every deployment, the system auto-detects missing columns on page load:

```php
$check = $conn->query("SHOW COLUMNS FROM users LIKE 'rights'");
if ($check->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN rights TEXT DEFAULT NULL");
}
```

This makes zero-downtime schema updates possible.

---

## 17. Testing

| Test Type | Approach |
|-----------|----------|
| **Authentication** | Verified login/logout for all 4 roles; confirmed unauthorized page access redirects to login |
| **Session expiry** | Confirmed that closing the browser ends the session and requires re-login |
| **Punch-in / Punch-out** | Tested from mobile device with GPS on and off; verified GPS-blocked users cannot punch from outside |
| **GPS Geofencing** | Set test coordinates; confirmed accurate Haversine distance calculation blocks/allows punches |
| **Rights system** | Created admin with subset of rights; confirmed only selected cards appear on dashboard; confirmed direct URL access to restricted pages redirects to login |
| **Excel export** | Generated monthly reports for departments with 50+ employees; verified all status calculations (Present/Absent/OD/CO) are correct |
| **Bulk import** | Imported 200-row employee Excel file; verified duplicates were skipped, all valid rows inserted |
| **SQL injection** | Verified all inputs go through prepared statements; tested with common injection strings |
| **XSS** | Verified all rendered user data is escaped via `htmlspecialchars()` |
| **Password hashing** | Confirmed no plaintext passwords exist in database |

---

## 18. Deployment

### Requirements

- PHP 8.0+
- MySQL 8.0+ or MariaDB 10.4+
- Apache 2.4 with `mod_rewrite`
- Composer (for PhpSpreadsheet dependency)
- Python 3.8+ with `face_recognition`, `opencv-python`, `numpy` (for face recognition module)

### Setup Steps

1. Clone / copy project to Apache `htdocs` directory
2. Create MySQL database: `CREATE DATABASE attendance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
3. Run initial SQL: import base schema or navigate to `admin/initialize_database.php`
4. Install PHP dependencies: `composer install`
5. Configure `config/db.php` with database credentials
6. Create first Super Admin: run `create_superadmin.php` once, then delete the file
7. Log in at `/auth/login.php` with Super Admin phone and password
8. Run database migration if upgrading: navigate to `admin/setup.php`

### Production Hardening

- Move `config/db.php` credentials to environment variables
- Restrict `config/`, `vendor/`, and setup scripts from public access via `.htaccess`
- Enable HTTPS (SSL certificate)
- Disable PHP error display in `php.ini` (`display_errors = Off`)
- Set up daily MySQL backups

---

## 19. Challenges & Solutions

### Challenge 1: Multiple Punch-In/Out Per Day

**Problem:** The original schema had a `UNIQUE KEY (user_id, date)` on the attendance table, which caused a duplicate entry error when an employee tried to punch in a second time on the same day.

**Solution:** The system was redesigned to use `INSERT ... ON DUPLICATE KEY UPDATE` so that only a single row per employee per day is maintained — the first punch-in and the last punch-out are preserved in the same record. A separate migration script (`fix_multiple_punches.php`) was created to apply this to existing installations without data loss.

---

### Challenge 2: GPS Accuracy on Mobile Devices

**Problem:** HTML5 Geolocation can return inaccurate coordinates indoors or on older devices, causing legitimate employees to be blocked by the geofence.

**Solution:** The `getCurrentPosition()` call uses `enableHighAccuracy: true` and a timeout of 10 seconds. The radius can be adjusted per installation (up to 5000 m) to accommodate inaccurate GPS environments. Employees who cannot use GPS can be exempted from geofencing by setting `geo_restricted = 0`.

---

### Challenge 3: Backward Compatibility for Admin Rights

**Problem:** When the Rights feature was added, existing admin accounts had no rights data. If `NULL` rights were treated as "no access", all existing admins would lose dashboard access.

**Solution:** A `NULL` rights value is explicitly treated as "all access" (backward compatible). Only admins with a non-empty JSON array have restrictions applied. New admins default to `NULL` unless the Super Admin explicitly assigns rights.

---

### Challenge 4: Reverse Geocoding Reliability

**Problem:** The Nominatim API (OpenStreetMap) is a free service that can be slow or unavailable, causing the punch-in page to hang.

**Solution:** The API call uses a 3-second timeout and is wrapped in a `try/catch` with error suppression via `@file_get_contents()`. If the API fails, the system falls back to displaying raw coordinates (`Lat: xx.xxxx, Lng: yy.yyyy`) — the attendance record is still saved successfully regardless of the geocoding result.

---

### Challenge 5: Excel Import Column Variability

**Problem:** Employee Excel files from different HR teams had varying column orders and extra header rows.

**Solution:** The import parser skips the first two rows (title + header) and maps columns by fixed index positions rather than by header name. Import errors and skipped rows are reported in detail so HR staff can correct the file and re-import.

---

*End of Document*
