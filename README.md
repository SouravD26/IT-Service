# Attendance & HR Management System

A comprehensive attendance and HR management system with face recognition, GPS geofencing, leave/comp-off/OD workflows, salary slip generation, and a REST API layer powering a companion Flutter mobile app. Built with PHP, MySQL, and Face-api.js.

---

## 🎯 Project Overview

This system provides:
- **Face Recognition Attendance**: Automatic punch in/out via browser camera
- **GPS Geofencing**: Restrict punch in/out to within a configurable radius of an employee's assigned Location (multi-branch/multi-office support)
- **Location Tracking**: Records punch in/out locations and live route summaries
- **Multiple Punch Records**: Support for multiple punch in/out sessions per day (first punch-in + last punch-out treated as one day's attendance)
- **Employee Management**: Add, edit, delete employees with conditional fields, bulk import, and photo management
- **Leave, Comp-Off & On-Duty (OD)**: Employee leave applications with admin approval, comp-off requests, and OD marking — all feed into payroll as paid days
- **Salary Slips**: Auto-generated payslips with CTC/Basic/Allowance breakdown, PF/ESI deductions, and loss-of-pay proration based on attendance
- **Monthly Reports**: Paginated, exportable attendance data with first/last punch times
- **Role-Based Access**: SuperAdmin, Admin, Face Operator, and Employee roles
- **Dashboard Views**: Multiple dashboards based on user role
- **Mobile App (Flutter)**: Native Android/iOS app for employees, backed by a token-authenticated REST API reading/writing the same MySQL database as the web app

---

## 🏗️ System Architecture

The web app and the Flutter mobile app are two separate front ends over **one shared MySQL database**, hosted together on a single cPanel server:

```
┌─────────────────┐        ┌──────────────────────┐
│   Web Browser    │──HTML─▶│  PHP Web Pages         │
│ (Admin/Employee)│  forms │  (admin/*, employee/*) │
└─────────────────┘        │        │               │
                            │   PHP session/cookie   │
                            │        auth            │
┌─────────────────┐        │        ▼               │        ┌───────────────┐
│  Flutter App     │──JSON─▶│  REST API (api/*,      │───────▶│  MySQL         │
│ (Android/iOS)    │ +token │  auth/api_*.php)       │        │  Database      │
└─────────────────┘        │  Bearer token auth      │        └───────────────┘
                            └──────────────────────┘
```

- **Web app**: traditional PHP pages, PHP session/cookie authentication.
- **Mobile app**: calls JSON endpoints under `api/` and `auth/api_*.php`, authenticated via a Bearer token (`Authorization: Bearer <token>`) issued at login and stored in the `auth_tokens` table (30-day expiry).
- **Shared endpoints** (e.g. `api/get_employees.php`, `api/get_today_attendance.php`) accept **either** a valid session (web) **or** a Bearer token (mobile) via `api_authenticate_flexible()`, so the same PHP file serves both front ends without duplicating logic.

---

## 🛠️ Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 7.4+ (procedural, mysqli prepared statements) |
| Database | MySQL / MariaDB |
| Web Frontend | HTML, Bootstrap 5, vanilla JavaScript, SweetAlert2 |
| Face Recognition | face-api.js (browser-based, client-side inference) |
| Mobile App | Flutter (Android/iOS), calling the PHP REST API over HTTPS |
| Hosting | cPanel shared hosting (Apache + MySQL) |
| Auth | PHP sessions (web) + Bearer token (`auth_tokens` table) for the mobile API |

---

## ✨ Key Features

### **Employee Management**
- ✅ Add/Edit/Delete employees, bulk import via `admin/import_employees.php`
- ✅ Manage employee photos for face recognition
- ✅ Conditional fields (Date of Exit shows only when Status = "Resign")
- ✅ Password management & reset for employees (`admin/manage_passwords.php`, `admin/reset_password.php`)
- ✅ Search and filter by department

### **Face Attendance System**
- ✅ Real-time face detection using face-api.js
- ✅ Automatic punch in/out on face recognition
- ✅ Manage face operators and face-attendance logins (`admin/manage_face_operators.php`, `admin/manage_face_attendance_logins.php`)
- ✅ Records selfies for audit trail

### **Attendance Recording**
- ✅ Punch in/out with selfie capture and server-authoritative timestamps (client-submitted times are rejected)
- ✅ GPS geofencing: punches outside the configured radius of the employee's assigned Location are blocked when enabled per employee
- ✅ Multiple punch sessions per day, each stored as its own record; punch-out always closes the most recent open session
- ✅ Location tracking with route/distance summary between punch in and punch out
- ✅ Same punch in/out logic (selfie + GPS + geofencing) available to the Flutter app via `api/punch.php`

### **Leave, Comp-Off & On-Duty**
- ✅ Employees submit leave applications (`employee/leave_application.php`, or via the mobile app's `api/leave.php`); admins review/approve (`admin/leave_management.php`)
- ✅ Comp-off requests for working on days off (`admin/comp_off_management.php`)
- ✅ On-Duty (OD) marking for off-site work (`admin/od_management.php`)
- ✅ Approved leave (except Unpaid Leave), OD days, and comp-off adjusted days all count as paid days in salary calculations

### **Salary Slips**
- ✅ Per-employee salary structure: CTC, Basic, Special Allowance, PF, ESI, and custom components (`admin/salary_slip.php`)
- ✅ Paid Days computed per month from actual attendance, week-offs, approved leave, OD, and comp-off — deduplicated by calendar date
- ✅ Loss-of-pay deduction prorated from **gross earnings** (Basic + Allowance + custom components) divided by days in month, not CTC (CTC includes PF/ESI, which are deducted separately)
- ✅ Print and PDF export of generated slips (web); JSON breakdown available via `api/salary_slip.php` (mobile)

### **Attendance Display & Export**
- ✅ WebPage View: Shows all punch records with locations visible
- ✅ Excel Export: First Punch In + Last Punch Out summary, paginated by department with collapsible sections
- ✅ Date-range selection and batch-loaded OD/comp-off data for performance
- ✅ Filter by date range, employee, department

### **Multi-Location GPS Geofencing**
- ✅ Each Location (`admin/locations.php`) has its own GPS coordinates and allowed radius (supports multiple branches/offices, not just one Head Office)
- ✅ Per-employee ON/OFF toggle for restriction (`admin/geo_restriction.php`) checks against that employee's assigned Location
- ✅ Employees with no Location assigned, or a Location without GPS set, are allowed to punch from anywhere

### **Dashboards**
- ✅ SuperAdmin Dashboard: Full system overview with rights management
- ✅ Admin Dashboard: Employee, attendance, leave, and payroll management
- ✅ Face Operator Dashboard: Today's face attendance records
- ✅ Employee Dashboard: Personal punch in/out, attendance history, and leave applications

---

## 📱 Mobile App (Flutter) & REST API

The Flutter app authenticates via a login endpoint that returns a **Bearer token**, then calls JSON endpoints using that token — all reading and writing the same database as the web app.

### **Authentication**
1. `POST auth/api_login.php` with `phone`, `password` → returns `{ success, token, user }`
2. Send `Authorization: Bearer <token>` on every subsequent request
3. `POST auth/api_logout.php` revokes the token

### **API Reference**

| Feature | Method | Endpoint | Key params |
|---|---|---|---|
| Login | POST | `auth/api_login.php` | `phone`, `password` |
| Logout | POST | `auth/api_logout.php` | (token in header) |
| My Profile | GET | `api/profile.php` | — (returns `photo_url` if a photo is on file) |
| Punch In/Out | POST | `api/punch.php` | `action` (`in`/`out`), `selfie_image` (base64), `lat`, `lng` |
| My Attendance History | GET | `api/my_attendance.php` | `month`, `year` |
| Today's Attendance (all) | GET | `api/get_today_attendance.php` | — |
| Attendance Summary | POST | `api/get_attendance_summary.php` | `user_id`, `date` |
| Apply Leave | POST | `api/leave.php` | `leave_type`, `start_date`, `end_date`, `reason` |
| My Leave List | GET | `api/leave.php` | — |
| Salary Slip | GET | `api/salary_slip.php` | `month` (YYYY-MM) |
| Employee List (admin) | GET | `api/get_employees.php` | — |
| Toggle GPS Restriction (admin) | POST | `api/toggle_geo_restrict.php` | `user_id`, `value` |
| Upload/Delete Employee Photo (admin) | POST | `api/upload_employee_photo.php` / `api/delete_employee_photo.php` | `employee_id`, `photo` |

### **Selfie Upload Contract**
`api/punch.php` accepts the selfie as a **base64 data URL string** in a regular form field (`selfie_image`), not multipart file upload — matching the same encoding used by the web app's camera capture.

### **Geofencing Contract**
The Flutter app does not need to know office coordinates. It sends raw `lat`/`lng`; the server checks distance against the employee's assigned Location and returns `allowed`/`message` accordingly.

---

## 📁 Project Structure

```
attendance/
├── admin/
│   ├── admin_dashboard.php          # Admin overview dashboard
│   ├── admin.php                    # Admin panel / rights management
│   ├── attendance.php               # View all attendance records
│   ├── dashboard.php                # SuperAdmin main dashboard
│   ├── employees.php                # Manage employees (with modal edit)
│   ├── import_employees.php         # Bulk employee import
│   ├── companies.php                # Manage companies
│   ├── department.php               # Manage departments
│   ├── shifts.php                   # Manage shifts
│   ├── locations.php                # Manage locations (GPS coords + radius per location)
│   ├── geo_restriction.php          # Per-employee GPS geofencing on/off
│   ├── leave_management.php         # Review/approve employee leave applications
│   ├── comp_off_management.php      # Comp-off requests
│   ├── od_management.php            # On-Duty (OD) marking
│   ├── salary_slip.php              # Salary structure + payslip generation
│   ├── manage_employee_photos.php   # Handle employee photos
│   ├── manage_passwords.php         # Employee password management
│   ├── manage_face_operators.php    # Face operator accounts
│   ├── export_monthly.php           # Export monthly reports
│   └── face_recognition_dashboard.php # Face operator dashboard
│
├── api/                              # JSON REST API (shared by web AJAX + Flutter app)
│   ├── punch.php                    # Punch in/out: selfie + GPS + geofencing
│   ├── my_attendance.php            # My attendance history (month/year filter)
│   ├── profile.php                  # My profile (incl. photo_url)
│   ├── leave.php                    # Apply / list leave applications
│   ├── salary_slip.php              # My salary slip breakdown (JSON)
│   ├── record_attendance.php        # Punch in/out recording (face-recognition kiosk flow)
│   ├── get_employees.php            # Employee list API
│   ├── get_today_attendance.php     # Today's attendance (all employees)
│   ├── get_attendance_summary.php   # Attendance summary API
│   ├── upload_employee_photo.php    # Photo upload API
│   ├── delete_employee_photo.php    # Photo delete API
│   └── toggle_geo_restrict.php      # Admin: toggle GPS restriction per employee
│
├── auth/
│   ├── login.php                    # Login page (web)
│   ├── login_process.php            # Process login (web, session-based)
│   ├── api_login.php                # Login API (mobile, returns Bearer token)
│   ├── api_logout.php               # Logout API (mobile, revokes token)
│   ├── password_reset.php           # Self-service password reset
│   └── logout.php                   # Logout handler (web)
│
├── config/
│   ├── db.php                       # Database connection
│   ├── api_auth.php                 # Token + session auth helpers for api/*.php
│   ├── attendance_geo.php           # Shared geofencing/selfie helpers
│   ├── AttendanceProcessor.php      # Shared attendance processing logic
│   └── db_migration.php             # Database schema migrations
│
├── employee/
│   ├── dashboard.php                # Employee dashboard
│   ├── punch_in.php                 # Punch in with selfie + GPS
│   ├── punch_out.php                # Punch out with selfie + GPS
│   ├── leave_application.php        # Submit leave requests
│   └── my_attendance.php            # Personal attendance view
│
├── uploads/
│   ├── employee_photos/             # Employee face photos
│   ├── employee_faces/              # Face descriptors
│   └── selfies/YYYY/MM/DD/          # Punch in/out selfies (date-partitioned)
│
├── assets/
│   ├── css/                         # Stylesheets
│   └── js/                          # JavaScript functionality
│
├── auto_face_attendance.php         # Main face detection page
├── face_dashboard.php               # Face attendance dashboard
├── user_dashboard.php               # User dashboard
├── index.php                        # Home page / redirects
└── README.md                        # This file
```

---

## 🗄️ Database Schema (key tables)

### **Users**
```sql
id, name, email, password, role (employee/admin/suparadmin/face_operator),
department, employee_id, company, phone, shift_time, location,
date_of_joining, date_of_exit, status (Working/Resign),
sex (Male/Female/Other), week_off, geo_restricted, password_set, created_at
```

### **Attendance**
```sql
id, user_id, date (DATE), punch_in (TIME), punch_out (TIME),
punch_in_location, punch_out_location, status,
selfie_punchin, selfie_punchout, created_at
```

### **Leave Applications**
```sql
id, user_id, leave_type (Casual/Sick/Earned/Maternity/Paternity/Unpaid),
start_date, end_date, days_count, reason, status (Pending/Approved/Rejected),
admin_notes, reviewed_by, reviewed_at, created_at
```

### **Salary Structures**
```sql
id, user_id, template, statutory_component, effective_cycle, salary_ctc,
basic_monthly, special_allowance_monthly, pf_monthly, esi_monthly,
pf_calc, esi_calc, custom_components (JSON)
```

### **Locations**
```sql
id, name, latitude, longitude, radius_meters, created_at
```
Each Location carries its own GPS coordinates and allowed radius; employees are assigned to a Location via `users.location`, and geofencing enforcement (web + mobile) checks against that specific Location's radius.

### **Auth Tokens** (mobile app)
```sql
id, user_id, token, device_info, created_at, expires_at
```
Issued by `auth/api_login.php`, validated by `config/api_auth.php` on every mobile API request, revoked by `auth/api_logout.php`.

### **Other Tables**
- `od_records` — On-Duty dates per employee
- `comp_off_requests` — Comp-off adjusted dates
- `location_tracking`, `route_summary` — Punch session GPS trail
- `departments`, `companies`, `shifts`

---

## 🚀 Installation & Setup

1. **Configure**: Edit `config/db.php` with your database credentials
2. **Initialize**: Run `admin/initialize_database.php` to create required tables
3. **Create Admin**: Run `create_superadmin.php`
4. **Enable Mobile API**: Visit `config/create_auth_tokens_table.php` once to create the `auth_tokens` table, then delete that file
5. **Access**: `http://localhost/attendence/`
6. **Login**: Use the SuperAdmin credentials created above

---

## 👥 User Roles & Permissions

- **SuperAdmin**: Full system control, including salary slips and rights management
- **Admin**: Employee, attendance, leave, comp-off, and OD management
- **Face Operator**: Today's face attendance only
- **Employee**: Personal punch in/out, attendance history, and leave applications (web or Flutter app)

---

## 💻 Usage Guide

### **Employee Management** (Admin)
1. Go to Admin → Employees
2. Click **➕ Add New Employee** or import a batch via **Import Employees**
3. Fill form (mandatory: Name, ID, Phone, Department, Company, Shift, Location, Date of Joining, Status)
4. If Status = "Resign": Date of Exit field appears (mandatory)
5. To Edit: Click **Edit** → Modal opens → Edit → **✓ Update** → Auto-closes

### **Manual Punch In/Out** (Employee — Web or Flutter)
1. Web: Go to **Employee Dashboard** → Punch In/Out. Flutter: open the Punch screen.
2. Capture a selfie and allow GPS (if geo-restriction is enabled for you)
3. Later, punch out — this closes your most recent open punch-in session

### **Leave / Comp-Off / OD**
1. Employee submits a leave request from **Leave Application** (web) or the Leave screen (Flutter)
2. Admin reviews and approves/rejects from **Leave Management**
3. Comp-off and OD are recorded by Admin and automatically count as paid days

### **Salary Slip Generation** (SuperAdmin)
1. Go to **Salary Slip**, search for the employee, pick a month
2. Click **Generate Slip** — Paid Days are computed from attendance + week-offs + approved leave/OD/comp-off for that month, and loss-of-pay is prorated from gross earnings
3. **Print** or **Download PDF** (web); employees can also view the same breakdown in the Flutter app

### **Multi-Location GPS Setup** (Admin)
1. Go to **Location Management** (`admin/locations.php`), add a Location with GPS coordinates and a radius (metres)
2. Assign employees to that Location (Employee record's Location field)
3. Go to **GPS Attendance Restriction** (`admin/geo_restriction.php`) and toggle restriction ON per employee

### **Export Reports**
- Click **Export Excel** on the monthly export page
- Format: First Punch In + Last Punch Out, grouped by department

---

## 🔧 Key Technical Details

### **Server-Authoritative Timestamps**
- Punch in/out always use server time; any client-submitted time parameter is rejected to prevent device-clock tampering.

### **Multiple Punch Sessions Per Day**
- Each punch-in creates a new attendance row for that date; punch-out updates the most recent row for that date with `punch_out IS NULL` (`ORDER BY id DESC LIMIT 1`).
- For payroll purposes, all sessions on the same date are deduplicated to a single paid day.

### **Multi-Location GPS Geofencing**
- When `geo_restricted` is enabled for a user, punch in/out is only allowed within the radius configured on **that employee's assigned Location** (`locations.radius_meters`), using the Haversine formula.
- If the employee has no Location assigned, or their Location has no GPS coordinates set, attendance is allowed from anywhere.

### **Salary Slip Proration**
- Paid Days = week-off days + present/late days + OD days + comp-off adjusted days + approved paid leave days, deduplicated by calendar date.
- Loss-of-pay per day = (Basic + Allowance + custom components) ÷ days in month — **not** CTC, since CTC already includes PF/ESI which are deducted as separate line items.

### **Mobile API Authentication**
- The mobile API issues a random 64-character Bearer token on login (`auth_tokens` table, 30-day expiry).
- Shared endpoints accept either a Bearer token or an existing PHP session (`api_authenticate_flexible()`), so the same file serves both the web app and the Flutter app without duplicated logic.

---

## 🔒 Security

- ✅ Prepared statements (SQL injection prevention)
- ✅ Bcrypt password hashing
- ✅ Session-based authentication (web) + Bearer token authentication (mobile API)
- ✅ Role-based access control
- ✅ Server-side time validation (rejects client-submitted punch times)
- ✅ Input sanitization

---

## 📝 Recent Updates (August 2026)

- ✅ Added a full REST API layer (`api/`, `auth/api_*.php`) for the Flutter mobile app: login/logout, punch in/out, attendance history, leave, salary slip, profile — all Bearer-token authenticated and reading/writing the same database as the web app
- ✅ Fixed a token/session auth detection bug that caused "Missing token" errors on browser-based admin actions
- ✅ Replaced the single global "Head Office" GPS setting with **per-Location** GPS coordinates and radius, supporting multiple branches/offices
- ✅ Added Leave Application & Leave Management modules
- ✅ Added Salary Slip generation with CTC/Basic/Allowance/PF/ESI breakdown
- ✅ Fixed loss-of-pay proration to use gross earnings instead of CTC
- ✅ Added GPS attendance geofencing
- ✅ Added collapsible department sections and employee avatars to monthly export
- ✅ Added pagination and batch loading for monthly export performance
- ✅ Enhanced admin dashboard with rights management and password management

---

## 📞 Requirements

- PHP 7.4+
- MySQL 5.7+
- Apache/cPanel hosting (web) 
- Modern browser (Chrome, Firefox, Edge, Safari) for the web app
- Flutter SDK (Android Studio) for building the mobile app
- Camera and location access for face attendance, mobile punch in/out, and geofencing

---

**Last Updated**: August 2026 | **Status**: ✅ Fully Operational (Web + Mobile API)
