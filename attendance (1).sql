-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 12, 2026 at 12:59 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `attendance`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `punch_in` time DEFAULT NULL,
  `punch_out` time DEFAULT NULL,
  `status` enum('Present','Absent','Leave','Late') DEFAULT 'Absent',
  `selfie_punchin` varchar(255) DEFAULT NULL,
  `selfie_punchout` varchar(255) DEFAULT NULL,
  `punch_in_lat` decimal(10,8) DEFAULT NULL,
  `punch_in_lng` decimal(11,8) DEFAULT NULL,
  `punch_in_accuracy` float DEFAULT NULL,
  `punch_out_lat` decimal(10,8) DEFAULT NULL,
  `punch_out_lng` decimal(11,8) DEFAULT NULL,
  `punch_out_accuracy` float DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `punch_in_location` varchar(255) DEFAULT NULL,
  `punch_out_location` varchar(255) DEFAULT NULL,
  `punch_in_by` int(11) DEFAULT NULL,
  `punch_out_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `user_id`, `date`, `punch_in`, `punch_out`, `status`, `selfie_punchin`, `selfie_punchout`, `punch_in_lat`, `punch_in_lng`, `punch_in_accuracy`, `punch_out_lat`, `punch_out_lng`, `punch_out_accuracy`, `created_at`, `updated_at`, `punch_in_location`, `punch_out_location`, `punch_in_by`, `punch_out_by`) VALUES
(295, 11, '2026-08-21', '15:25:21', '17:29:43', 'Present', 'selfie_11_20260821152521.jpg', 'selfie_punchout_11_20260821172943.jpg', NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-21 09:55:22', '2026-08-21 11:59:44', 'Tapuriaghat, Kolkata', 'Tapuriaghat, Kolkata', NULL, NULL),
(297, 11, '2026-08-25', '17:33:21', '17:35:23', 'Present', 'selfie_11_20260825173322.jpg', 'selfie_punchout_11_20260825173525.jpg', NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-25 12:03:22', '2026-08-25 12:05:25', 'Tapuriaghat, Kolkata', 'Tapuriaghat, Kolkata', 23, 23);

-- --------------------------------------------------------

--
-- Table structure for table `attendance_policy`
--

CREATE TABLE `attendance_policy` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `single_punch_absent` tinyint(1) NOT NULL DEFAULT 1,
  `half_day_min_hours` decimal(4,2) NOT NULL DEFAULT 5.00,
  `full_day_basis` enum('shift','fixed') NOT NULL DEFAULT 'shift',
  `full_day_fixed_hours` decimal(4,2) NOT NULL DEFAULT 8.00,
  `sandwich_absent` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_policy`
--

INSERT INTO `attendance_policy` (`id`, `single_punch_absent`, `half_day_min_hours`, `full_day_basis`, `full_day_fixed_hours`, `sandwich_absent`, `updated_at`) VALUES
(1, 1, 5.00, 'shift', 8.00, 1, '2026-08-27 11:06:53');

-- --------------------------------------------------------

--
-- Table structure for table `auth_tokens`
--

CREATE TABLE `auth_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `device_info` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `name`, `created_at`) VALUES
(3, 'IT Service', '2026-08-21 10:00:32');

-- --------------------------------------------------------

--
-- Table structure for table `comp_off_requests`
--

CREATE TABLE `comp_off_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comp_off_date` date NOT NULL,
  `earned_date` date DEFAULT NULL,
  `marked_by` int(11) NOT NULL,
  `marked_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `comp_off_requests`
--

INSERT INTO `comp_off_requests` (`id`, `user_id`, `comp_off_date`, `earned_date`, `marked_by`, `marked_at`) VALUES
(4, 5, '2026-03-24', '2026-03-01', 1, '2026-03-21 08:50:02'),
(5, 7, '2026-03-23', '2026-03-22', 1, '2026-03-21 08:50:53'),
(6, 3, '2026-03-21', '2026-03-20', 1, '2026-03-21 09:10:47');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `created_at`) VALUES
(1, 'HR', '2026-03-01 10:18:31'),
(2, 'IT', '2026-03-01 10:18:37');

-- --------------------------------------------------------

--
-- Table structure for table `employee_leave_balances`
--

CREATE TABLE `employee_leave_balances` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `leave_type` varchar(100) NOT NULL,
  `year` int(11) NOT NULL,
  `days_allowed` decimal(5,1) NOT NULL DEFAULT 0.0,
  `days_used` decimal(5,1) NOT NULL DEFAULT 0.0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employee_leave_balances`
--

INSERT INTO `employee_leave_balances` (`id`, `user_id`, `leave_type`, `year`, `days_allowed`, `days_used`, `created_at`, `updated_at`) VALUES
(1, 11, 'Casual Leave', 2026, 12.0, 2.0, '2026-09-01 14:34:24', '2026-09-01 14:34:24'),
(2, 26, 'Casual Leave', 2026, 12.0, 0.0, '2026-09-01 14:34:24', '2026-09-01 14:34:24'),
(3, 11, 'Sick Leave', 2026, 12.0, 0.0, '2026-09-01 14:34:24', '2026-09-01 14:34:24'),
(4, 26, 'Sick Leave', 2026, 12.0, 0.0, '2026-09-01 14:34:24', '2026-09-01 14:34:24'),
(5, 11, 'Earned Leave', 2026, 12.0, 0.0, '2026-09-01 14:34:24', '2026-09-01 14:34:24'),
(6, 26, 'Earned Leave', 2026, 12.0, 0.0, '2026-09-01 14:34:24', '2026-09-01 14:34:24'),
(7, 11, 'Maternity Leave', 2026, 12.0, 0.0, '2026-09-01 14:34:24', '2026-09-01 14:34:24'),
(8, 26, 'Maternity Leave', 2026, 12.0, 0.0, '2026-09-01 14:34:24', '2026-09-01 14:34:24'),
(9, 11, 'Paternity Leave', 2026, 12.0, 0.0, '2026-09-01 14:34:24', '2026-09-01 14:34:24'),
(10, 26, 'Paternity Leave', 2026, 12.0, 0.0, '2026-09-01 14:34:24', '2026-09-01 14:34:24'),
(11, 11, 'Unpaid Leave', 2026, 12.0, 0.0, '2026-09-01 14:34:24', '2026-09-01 14:34:24'),
(12, 26, 'Unpaid Leave', 2026, 12.0, 0.0, '2026-09-01 14:34:24', '2026-09-01 14:34:24');

-- --------------------------------------------------------

--
-- Table structure for table `employee_tracking_settings`
--

CREATE TABLE `employee_tracking_settings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `enable_tracking` tinyint(1) DEFAULT 0,
  `geofence_radius` int(11) DEFAULT 500,
  `tracking_interval` int(11) DEFAULT 300,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_applications`
--

CREATE TABLE `leave_applications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `leave_type` enum('Casual Leave','Sick Leave','Earned Leave','Maternity Leave','Paternity Leave','Unpaid Leave') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days_count` int(11) NOT NULL DEFAULT 1,
  `reason` text DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `admin_notes` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_applications`
--

INSERT INTO `leave_applications` (`id`, `user_id`, `leave_type`, `start_date`, `end_date`, `days_count`, `reason`, `status`, `admin_notes`, `reviewed_by`, `reviewed_at`, `created_at`) VALUES
(1, 11, 'Casual Leave', '2026-08-21', '2026-08-22', 2, '', 'Approved', 'accept this time , Not agin', 1, '2026-08-20 13:03:24', '2026-08-20 13:02:26');

-- --------------------------------------------------------

--
-- Table structure for table `leave_policies`
--

CREATE TABLE `leave_policies` (
  `id` int(11) NOT NULL,
  `leave_type` varchar(100) NOT NULL,
  `days_allowed` int(11) NOT NULL DEFAULT 0,
  `year` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_policies`
--

INSERT INTO `leave_policies` (`id`, `leave_type`, `days_allowed`, `year`, `created_at`, `updated_at`) VALUES
(1, 'Casual Leave', 12, 2026, '2026-08-20 11:34:52', '2026-08-20 11:35:01'),
(2, 'Sick Leave', 12, 2026, '2026-08-20 11:34:52', '2026-08-20 11:35:01'),
(3, 'Earned Leave', 12, 2026, '2026-08-20 11:34:52', '2026-08-20 11:35:01'),
(4, 'Maternity Leave', 12, 2026, '2026-08-20 11:34:52', '2026-08-20 11:35:01'),
(5, 'Paternity Leave', 12, 2026, '2026-08-20 11:34:52', '2026-08-20 11:35:01'),
(6, 'Unpaid Leave', 12, 2026, '2026-08-20 11:34:52', '2026-08-20 11:35:01');

-- --------------------------------------------------------

--
-- Table structure for table `locations`
--

CREATE TABLE `locations` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `locations`
--

INSERT INTO `locations` (`id`, `name`, `created_at`, `latitude`, `longitude`) VALUES
(1, 'Kolkata', '2026-03-01 10:15:19', NULL, NULL),
(2, 'Delhi', '2026-03-01 10:15:26', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `location_tracking`
--

CREATE TABLE `location_tracking` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `punch_in_id` int(11) DEFAULT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `accuracy` float DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `od_records`
--

CREATE TABLE `od_records` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `od_date` date NOT NULL,
  `marked_by` int(11) NOT NULL,
  `marked_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `office_settings`
--

CREATE TABLE `office_settings` (
  `id` int(11) NOT NULL,
  `office_name` varchar(100) NOT NULL DEFAULT 'Head Office',
  `latitude` decimal(11,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `radius_meters` int(11) NOT NULL DEFAULT 100,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `office_settings`
--

INSERT INTO `office_settings` (`id`, `office_name`, `latitude`, `longitude`, `radius_meters`, `updated_at`) VALUES
(1, 'Head Office', 22.55075955, 88.39922009, 150, '2026-07-28 09:45:15');

-- --------------------------------------------------------

--
-- Table structure for table `project_holidays`
--

CREATE TABLE `project_holidays` (
  `id` int(11) NOT NULL,
  `project` varchar(100) NOT NULL,
  `holiday_date` date NOT NULL,
  `title` varchar(150) NOT NULL DEFAULT 'Holiday',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `route_summary`
--

CREATE TABLE `route_summary` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `punch_in_id` int(11) DEFAULT NULL,
  `punch_out_id` int(11) DEFAULT NULL,
  `total_distance_km` float DEFAULT NULL,
  `travel_time` int(11) DEFAULT NULL,
  `start_location` varchar(255) DEFAULT NULL,
  `end_location` varchar(255) DEFAULT NULL,
  `route_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`route_data`)),
  `date` date DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salary_structures`
--

CREATE TABLE `salary_structures` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `template` varchar(50) DEFAULT 'Monthly',
  `statutory_component` varchar(100) DEFAULT NULL,
  `effective_cycle` varchar(20) DEFAULT NULL,
  `salary_ctc` decimal(12,2) DEFAULT 0.00,
  `basic_monthly` decimal(12,2) DEFAULT 0.00,
  `special_allowance_monthly` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pf_monthly` decimal(12,2) DEFAULT 0.00,
  `esi_monthly` decimal(12,2) DEFAULT 0.00,
  `pf_calc` varchar(100) DEFAULT NULL,
  `esi_calc` varchar(100) DEFAULT NULL,
  `custom_components` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `salary_structures`
--

INSERT INTO `salary_structures` (`id`, `user_id`, `template`, `statutory_component`, `effective_cycle`, `salary_ctc`, `basic_monthly`, `special_allowance_monthly`, `created_at`, `updated_at`, `pf_monthly`, `esi_monthly`, `pf_calc`, `esi_calc`, `custom_components`) VALUES
(1, 11, 'Monthly', 'PF+ESI', 'Sep 2026', 45101.13, 40001.00, 0.00, '2026-08-20 10:08:40', '2026-09-10 06:37:03', 4800.12, 300.01, '12% of Salary', '0.75% of Gross', '[]'),
(5, 26, 'Monthly', '', 'Aug 2026', 0.00, 0.00, 0.00, '2026-08-25 12:12:27', '2026-08-25 12:12:27', 0.00, 0.00, '', '', '[]');

-- --------------------------------------------------------

--
-- Table structure for table `shifts`
--

CREATE TABLE `shifts` (
  `id` int(11) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `shifts`
--

INSERT INTO `shifts` (`id`, `start_time`, `end_time`, `created_at`) VALUES
(5, '09:00:00', '18:00:00', '2026-03-01 10:24:36'),
(6, '09:30:00', '18:30:00', '2026-03-01 10:27:38'),
(7, '10:00:00', '19:00:00', '2026-03-01 10:27:56'),
(8, '10:30:00', '19:30:00', '2026-03-01 10:28:11');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('suparadmin','admin','employee','face_operator','supervisor') NOT NULL DEFAULT 'employee',
  `department` varchar(100) DEFAULT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `company` varchar(100) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `dashboard_role` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `shift_time` varchar(50) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `date_of_joining` date DEFAULT NULL,
  `status` enum('Working','Resign') DEFAULT 'Working',
  `resign_date` date DEFAULT NULL,
  `sex` enum('Male','Female','Other') DEFAULT NULL,
  `week_off` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') DEFAULT NULL,
  `password_set` tinyint(1) DEFAULT 0,
  `face_descriptor` longtext DEFAULT NULL COMMENT 'JSON array of 128 face descriptor values',
  `date_of_exit` date DEFAULT NULL,
  `geo_restricted` tinyint(1) NOT NULL DEFAULT 0,
  `rights` text DEFAULT NULL,
  `aadhar_number` varchar(20) DEFAULT NULL,
  `pan_number` varchar(20) DEFAULT NULL,
  `alternate_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `profile_photo_type` varchar(50) DEFAULT NULL,
  `bank_account_number` varchar(30) DEFAULT NULL,
  `bank_ifsc_code` varchar(15) DEFAULT NULL,
  `family_member_name` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `department`, `employee_id`, `company`, `phone`, `profile_photo`, `dashboard_role`, `created_at`, `updated_at`, `shift_time`, `location`, `date_of_joining`, `status`, `resign_date`, `sex`, `week_off`, `password_set`, `face_descriptor`, `date_of_exit`, `geo_restricted`, `rights`, `aadhar_number`, `pan_number`, `alternate_number`, `address`, `profile_photo_type`, `bank_account_number`, `bank_ifsc_code`, `family_member_name`, `bank_name`) VALUES
(1, 'Super Admin', 'superadmin@company.com', '$2y$10$mV1nzkWS.oijwYmCcoezA.fin3LCSfB0yFuWFWrqNU1c05uFiHApu', 'suparadmin', NULL, NULL, NULL, '9748302601', NULL, NULL, '2026-03-01 10:11:10', '2026-08-21 07:27:08', NULL, NULL, NULL, 'Working', NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'Admin User', 'admin@company.com', '$2y$10$daJBiEkJzwk4x7U73gxcE.MnG1QKksvE1rWLXTFuMgELo5v7078jW', 'admin', 'HR', '00', 'Test', '9830376200', NULL, NULL, '2026-03-01 10:13:47', '2026-08-20 13:20:38', NULL, NULL, NULL, 'Working', NULL, NULL, NULL, 0, NULL, NULL, 0, '[\"manage_employees\",\"manage_departments\",\"view_attendance\",\"manual_attendance\",\"comp_off\",\"export_reports\",\"manage_companies\",\"manage_shifts\",\"manage_locations\",\"od_management\",\"gps_restriction\"]', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(6, 'Operator', 'operator_1772869732@local', '$2y$10$xEiDHax2z6v58pMnWiw/SOepL2mQzkv2oRxErx94Is3wBoqfP3apO', 'face_operator', NULL, NULL, NULL, '9830376203', NULL, NULL, '2026-03-07 07:48:52', '2026-03-07 07:48:52', NULL, NULL, NULL, 'Working', NULL, NULL, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(11, 'Ankita Dutta', '', '$2y$10$lzznD8EdWSIzHJ4TDGEjpeF.EoQghvixMjq2.x2OecSx.cwci6ZIu', 'employee', 'IT', '042', 'IT Service', '8697718086', '?PNG\r\n\Z\n\0\0\0\rIHDR\0\0\0?\0\0\0?\0\0\0?ʷ\0\0\0sRGB\0???\0\0\0gAMA\0\0???a\0\0\0	pHYs\0\0?\0\0??o?d\0\08?IDATx^??i|Lg???d&?$$???\Z;?????E?{	????T(J?U???Z?Tj?????VIk?ƾ?IH??L??b?H&?&3\'C????M??????k??\'g?QH?$!?,t ??($I?*????? 6??p?^?}L+?P?_?R\n????\r?!??9?N?$)?r?:?Q', NULL, '2026-08-20 10:08:40', '2026-09-10 06:37:03', '09:30 AM - 06:30 PM', 'Kolkata', '2026-08-06', 'Working', NULL, 'Female', 'Monday', 1, NULL, '0000-00-00', 0, NULL, '', '', '', '', 'image/png', '', '', '', ''),
(23, 'Prosenjit Biswas', '', '$2y$10$sGIghy6cM597gahVoVQflO1VVSnvgPuxRbqjH7JexJzQgem04paQK', 'supervisor', NULL, '', NULL, '9830376202', NULL, NULL, '2026-08-25 12:02:38', '2026-08-25 12:02:38', NULL, 'Kolkata', NULL, 'Working', NULL, NULL, NULL, 1, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(26, 'Subhabrata Mojumder', 'system@sanmarg.in', '$2y$10$e5IUvtH9/hrbM7TdvJbr/uAt2ur46UA3.DLPEHmAkYMZcQ.ivwyNW', 'employee', 'HR', '047', 'IT Service', '8697718088', NULL, NULL, '2026-08-25 12:12:27', '2026-08-25 12:14:04', '09:00 AM - 06:00 PM', 'Kolkata', '2026-08-25', 'Working', NULL, 'Male', 'Monday', 0, NULL, '0000-00-00', 0, NULL, '', '', '', 'Kolkata', NULL, '', '', '', NULL),
(31, 'Rahul Sharma', 'rahul.sharma@example.com', '$2y$10$qY3Ki28fpSyVhxdzxvpBfON6tLXZl3rGPXxn/QsGkthnqf5XI9.k2', 'employee', 'IT Support', 'EMP0101', 'Sanmarg', '9812345670', NULL, NULL, '2026-09-10 06:42:56', '2026-09-10 06:42:56', '09:30 AM - 06:30 PM', 'Delhi', '2024-04-01', 'Working', NULL, 'Male', 'Sunday', 0, NULL, NULL, 0, NULL, '123456789012', 'ABCPS1234F', '9812345671', '12 Nehru Road, New Delhi', NULL, '1234567890123', 'SBIN0001234', 'Suresh Sharma', NULL),
(32, 'Priya Das', 'priya.das@example.com', '$2y$10$Ae6sUKuFsJjNpJ2Oc6zrXORdeouBTApqWzDcPf39gsHFraeDssIeK', 'employee', 'Field Service', 'EMP0102', 'Sanmarg', '9812345672', NULL, NULL, '2026-09-10 06:42:56', '2026-09-10 06:42:56', '09:00 AM - 06:00 PM', 'Kolkata', '2024-06-15', 'Working', NULL, 'Female', 'Saturday', 0, NULL, NULL, 0, NULL, '123456789013', 'ABCPD5678K', '9812345673', '45 Park Street, Kolkata', NULL, '1234567890124', 'HDFC0000456', 'Anita Das', NULL),
(33, 'Amit Verma', 'amit.verma@example.com', '$2y$10$l26TtKRmq9NJRt/p8mIPkusKjatOw3bYWb8w9e0HvPXN2e7OH4g1q', 'employee', 'IT Support', 'EMP0003', 'Sanmarg', '9812345674', NULL, NULL, '2026-09-10 06:42:56', '2026-09-10 06:42:56', '09:30 AM - 06:30 PM', 'Delhi', '2023-01-10', 'Resign', NULL, 'Male', 'Sunday', 0, NULL, '2025-08-31', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_date` (`date`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `attendance_policy`
--
ALTER TABLE `attendance_policy`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `comp_off_requests`
--
ALTER TABLE `comp_off_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_comp_off` (`user_id`,`comp_off_date`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `employee_leave_balances`
--
ALTER TABLE `employee_leave_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_emp_type_year` (`user_id`,`leave_type`,`year`);

--
-- Indexes for table `employee_tracking_settings`
--
ALTER TABLE `employee_tracking_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `leave_applications`
--
ALTER TABLE `leave_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `leave_policies`
--
ALTER TABLE `leave_policies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_type_year` (`leave_type`,`year`);

--
-- Indexes for table `locations`
--
ALTER TABLE `locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `location_tracking`
--
ALTER TABLE `location_tracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_punch` (`user_id`,`punch_in_id`),
  ADD KEY `idx_timestamp` (`timestamp`);

--
-- Indexes for table `od_records`
--
ALTER TABLE `od_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_od` (`user_id`,`od_date`),
  ADD KEY `marked_by` (`marked_by`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_od_date` (`od_date`);

--
-- Indexes for table `office_settings`
--
ALTER TABLE `office_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `project_holidays`
--
ALTER TABLE `project_holidays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_project_date` (`project`,`holiday_date`),
  ADD KEY `idx_date` (`holiday_date`);

--
-- Indexes for table `route_summary`
--
ALTER TABLE `route_summary`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_date` (`user_id`,`date`);

--
-- Indexes for table `salary_structures`
--
ALTER TABLE `salary_structures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `shifts`
--
ALTER TABLE `shifts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_employee_id` (`employee_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=336;

--
-- AUTO_INCREMENT for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `comp_off_requests`
--
ALTER TABLE `comp_off_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `employee_leave_balances`
--
ALTER TABLE `employee_leave_balances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `employee_tracking_settings`
--
ALTER TABLE `employee_tracking_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `leave_applications`
--
ALTER TABLE `leave_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `leave_policies`
--
ALTER TABLE `leave_policies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `locations`
--
ALTER TABLE `locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `location_tracking`
--
ALTER TABLE `location_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `od_records`
--
ALTER TABLE `od_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `office_settings`
--
ALTER TABLE `office_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `project_holidays`
--
ALTER TABLE `project_holidays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `route_summary`
--
ALTER TABLE `route_summary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salary_structures`
--
ALTER TABLE `salary_structures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `shifts`
--
ALTER TABLE `shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD CONSTRAINT `auth_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employee_leave_balances`
--
ALTER TABLE `employee_leave_balances`
  ADD CONSTRAINT `employee_leave_balances_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employee_tracking_settings`
--
ALTER TABLE `employee_tracking_settings`
  ADD CONSTRAINT `employee_tracking_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_applications`
--
ALTER TABLE `leave_applications`
  ADD CONSTRAINT `leave_applications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `location_tracking`
--
ALTER TABLE `location_tracking`
  ADD CONSTRAINT `location_tracking_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `od_records`
--
ALTER TABLE `od_records`
  ADD CONSTRAINT `od_records_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `od_records_ibfk_2` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `route_summary`
--
ALTER TABLE `route_summary`
  ADD CONSTRAINT `route_summary_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `salary_structures`
--
ALTER TABLE `salary_structures`
  ADD CONSTRAINT `salary_structures_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
