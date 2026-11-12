-- phpMyAdmin SQL Dump
-- version 4.8.5
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 28, 2025 at 07:59 AM
-- Server version: 10.1.38-MariaDB
-- PHP Version: 7.3.3

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ddu_attendance_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) DEFAULT NULL,
  `details` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `assignment_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `type` enum('assignment','exam','quiz','project') DEFAULT 'assignment',
  `due_date` date NOT NULL,
  `max_marks` decimal(5,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `week_number` int(11) DEFAULT NULL,
  `session_type` enum('lecture','lab','tutorial','exam') DEFAULT 'lecture',
  `status` enum('present','absent','late','excused','holiday') DEFAULT 'absent',
  `check_in_time` time DEFAULT NULL,
  `check_out_time` time DEFAULT NULL,
  `notes` text,
  `marked_by` int(11) DEFAULT NULL,
  `marked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`attendance_id`, `course_id`, `teacher_id`, `student_id`, `attendance_date`, `week_number`, `session_type`, `status`, `check_in_time`, `check_out_time`, `notes`, `marked_by`, `marked_at`) VALUES
(1, 1, 7, 2, '2025-12-24', 1, 'lecture', 'present', '15:09:11', '15:57:42', 'bjhbkjkjnknknb', 7, '2025-12-24 22:00:35'),
(2, 11, 7, 2, '2025-12-01', 2, 'lecture', 'absent', '07:09:56', '07:57:00', 'SFFA', 7, '2025-12-24 22:07:42');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_backup`
--

CREATE TABLE `attendance_backup` (
  `attendance_id` int(11) NOT NULL DEFAULT '0',
  `course_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `week_number` int(11) DEFAULT NULL,
  `session_type` enum('lecture','lab','tutorial','exam') DEFAULT 'lecture',
  `status` enum('present','absent','late','excused','holiday') DEFAULT 'absent',
  `check_in_time` time DEFAULT NULL,
  `check_out_time` time DEFAULT NULL,
  `notes` text,
  `marked_by` int(11) DEFAULT NULL,
  `marked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_details`
--

CREATE TABLE `attendance_details` (
  `id` int(11) NOT NULL,
  `attendance_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `status` enum('present','absent','leave','late') DEFAULT 'absent',
  `marked_by` int(11) DEFAULT NULL,
  `marked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_records`
--

CREATE TABLE `attendance_records` (
  `id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `class_date` date NOT NULL,
  `total_present` int(11) DEFAULT '0',
  `total_absent` int(11) DEFAULT '0',
  `total_leave` int(11) DEFAULT '0',
  `notes` text,
  `status` enum('pending','completed','cancelled') DEFAULT 'completed',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_sessions`
--

CREATE TABLE `attendance_sessions` (
  `session_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `session_date` date NOT NULL,
  `session_type` enum('lecture','lab','tutorial','exam') DEFAULT 'lecture',
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `topic_covered` varchar(255) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_summary`
--

CREATE TABLE `attendance_summary` (
  `summary_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `academic_year` year(4) DEFAULT NULL,
  `semester` int(11) DEFAULT NULL,
  `total_sessions` int(11) DEFAULT '0',
  `present_count` int(11) DEFAULT '0',
  `absent_count` int(11) DEFAULT '0',
  `late_count` int(11) DEFAULT '0',
  `excused_count` int(11) DEFAULT '0',
  `attendance_percentage` decimal(5,2) DEFAULT '0.00',
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `class_schedule`
--

CREATE TABLE `class_schedule` (
  `schedule_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `room_number` varchar(50) DEFAULT NULL,
  `building` varchar(100) DEFAULT NULL,
  `session_type` enum('lecture','lab','tutorial') DEFAULT 'lecture',
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `class_schedule`
--

INSERT INTO `class_schedule` (`schedule_id`, `course_id`, `teacher_id`, `day_of_week`, `start_time`, `end_time`, `room_number`, `building`, `session_type`, `is_active`, `created_by`, `created_at`) VALUES
(1, 11, 7, 'Monday', '02:00:00', '04:00:00', '328', '1', 'lecture', 1, 1, '2025-12-24 22:28:12');

-- --------------------------------------------------------

--
-- Table structure for table `class_schedules`
--

CREATE TABLE `class_schedules` (
  `id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `room_number` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `course_id` int(11) NOT NULL,
  `course_code` varchar(20) NOT NULL,
  `course_name` varchar(100) NOT NULL,
  `credit_hours` int(11) DEFAULT '3',
  `department_id` int(11) DEFAULT NULL,
  `semester` int(11) DEFAULT NULL,
  `year_of_study` int(11) DEFAULT NULL,
  `description` text,
  `created_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`course_id`, `course_code`, `course_name`, `credit_hours`, `department_id`, `semester`, `year_of_study`, `description`, `created_by`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'CS101', 'Introduction to Programming', 3, 1, 1, 1, NULL, NULL, 1, '2025-12-18 13:36:23', '2025-12-24 12:59:49'),
(2, 'CS102', 'Data Structures', 3, 1, 2, 1, NULL, NULL, 1, '2025-12-18 13:36:23', '2025-12-24 12:59:49'),
(3, 'CS201', 'Object-Oriented Programming', 3, 1, 1, 2, NULL, NULL, 1, '2025-12-18 13:36:23', '2025-12-24 12:59:49'),
(4, 'CS202', 'Database Systems', 3, 1, 2, 2, NULL, NULL, 1, '2025-12-18 13:36:23', '2025-12-24 12:59:49'),
(5, 'CS301', 'Software Engineering', 3, 1, 1, 3, NULL, NULL, 1, '2025-12-18 13:36:23', '2025-12-24 12:59:49'),
(6, 'CS302', 'Computer Networks', 3, 1, 2, 3, NULL, NULL, 1, '2025-12-18 13:36:23', '2025-12-24 12:59:49'),
(7, 'CS401', 'Artificial Intelligence', 3, 1, 1, 4, NULL, NULL, 1, '2025-12-18 13:36:23', '2025-12-24 12:59:49'),
(8, 'CS402', 'Project Management', 3, 1, 2, 4, NULL, NULL, 1, '2025-12-18 13:36:23', '2025-12-24 12:59:49'),
(9, 'MATH101', 'Calculus I', 3, 1, 1, 1, NULL, NULL, 1, '2025-12-18 13:36:23', '2025-12-24 12:59:49'),
(11, 'ITEC 3034', 'Computer Maintenance and Support', 3, 106, 1, 1, NULL, NULL, 1, '2025-12-18 13:42:35', '2025-12-24 12:59:49'),
(12, 'ITEC 2111', 'Fundamentals of Databases System', 3, 3, 1, 2, NULL, NULL, 1, '2025-12-23 07:20:19', '2025-12-24 12:59:49'),
(13, 'ITEC 3033', 'System and Network Administrator', 3, 3, 1, 3, '0', 1, 1, '2025-12-24 13:20:07', '2025-12-24 13:20:07');

-- --------------------------------------------------------

--
-- Table structure for table `course_assignments`
--

CREATE TABLE `course_assignments` (
  `assignment_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `academic_year` year(4) DEFAULT NULL,
  `semester` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `assigned_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `course_assignments`
--

INSERT INTO `course_assignments` (`assignment_id`, `course_id`, `teacher_id`, `academic_year`, `semester`, `is_active`, `assigned_date`) VALUES
(1, 1, 2, 2024, 1, 1, '2025-12-18');

-- --------------------------------------------------------

--
-- Table structure for table `course_students`
--

CREATE TABLE `course_students` (
  `id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `enrolled_by` int(11) DEFAULT NULL,
  `enrollment_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('active','dropped','completed') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `is_class_rep` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `course_students`
--

INSERT INTO `course_students` (`id`, `course_id`, `student_id`, `enrolled_by`, `enrollment_date`, `status`, `is_class_rep`) VALUES
(1, 1, 8, 1, '2025-12-24 16:58:26', 'active', 0),
(2, 2, 8, 1, '2025-12-24 16:58:26', 'active', 0),
(3, 2, 2, 1, '2025-12-24 18:18:04', 'active', 0),
(4, 2, 1, 1, '2025-12-24 18:18:04', 'active', 0),
(5, 11, 2, 1, '2025-12-24 18:26:53', 'active', 0),
(6, 11, 1, 1, '2025-12-24 18:26:53', 'active', 0),
(8, 13, 2, 1, '2025-12-24 18:29:03', 'active', 0),
(9, 13, 1, 1, '2025-12-24 18:29:03', 'active', 0),
(12, 13, 5, 1, '2025-12-24 18:44:16', 'active', 0),
(16, 12, 5, 1, '2025-12-24 18:47:14', 'active', 0),
(19, 12, 2, 1, '2025-12-24 18:47:14', 'active', 0),
(20, 12, 1, 1, '2025-12-24 18:47:14', 'active', 0),
(22, 6, 5, 1, '2025-12-24 19:22:51', 'active', 0),
(25, 6, 2, 1, '2025-12-24 19:22:51', 'active', 0),
(26, 6, 1, 1, '2025-12-24 19:22:51', 'active', 0),
(28, 1, 5, 1, '2025-12-24 20:33:30', 'active', 0),
(31, 1, 2, 1, '2025-12-24 20:33:31', 'active', 0),
(32, 1, 7, 1, '2025-12-24 20:33:31', 'active', 0),
(33, 1, 1, 1, '2025-12-24 20:33:31', 'active', 0);

-- --------------------------------------------------------

--
-- Table structure for table `course_students_backup`
--

CREATE TABLE `course_students_backup` (
  `id` int(11) NOT NULL DEFAULT '0',
  `course_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `enrolled_date` date DEFAULT NULL,
  `status` enum('active','dropped','completed') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `course_teachers`
--

CREATE TABLE `course_teachers` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `role` varchar(50) DEFAULT 'primary',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `course_teachers`
--

INSERT INTO `course_teachers` (`id`, `course_id`, `teacher_id`, `role`, `start_date`, `end_date`, `assigned_by`, `assigned_date`) VALUES
(1, 13, 2, 'primary', '2025-12-05', '2025-12-24', 1, '2025-12-24 16:24:37'),
(5, 12, 2, 'assistant', '2025-01-01', '2025-12-24', 1, '2025-12-24 16:39:19'),
(6, 11, 7, 'primary', '2025-02-06', '2025-12-18', 1, '2025-12-24 16:40:17'),
(7, 1, 9, 'primary', '2025-06-06', '2025-12-13', 1, '2025-12-24 16:40:42'),
(8, 2, 7, 'primary', '2025-12-05', '2025-12-20', 1, '2025-12-24 16:59:15'),
(10, 6, 7, 'guest', '2025-12-01', '2025-12-20', 1, '2025-12-24 19:22:22');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_code` varchar(20) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `faculty` varchar(100) DEFAULT NULL,
  `description` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `department_head_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_code`, `department_name`, `faculty`, `description`, `created_at`, `department_head_id`) VALUES
(1, 'CS', 'Computer Science', 'Computing and Informatics', NULL, '2025-12-18 08:59:00', 9),
(2, 'SWE', 'Software Engineering', 'Computing and Informatics', NULL, '2025-12-18 08:59:00', 10),
(3, 'IT', 'Information Technology', 'Computing and Informatics', NULL, '2025-12-18 08:59:00', 7),
(4, 'EEE', 'Electrical Engineering', 'Engineering', NULL, '2025-12-18 08:59:00', NULL),
(5, 'MBA', 'Business Administration', 'Business and Economics', NULL, '2025-12-18 08:59:00', NULL),
(106, 'IS', 'Information System', 'Computing and Informatics', NULL, '2025-12-18 13:35:55', NULL),
(107, 'ECE', 'Electrical & Computer Engineering', 'Engineering', NULL, '2025-12-18 13:35:55', NULL),
(108, 'CE', 'Civil Engineering', 'Engineering', NULL, '2025-12-18 13:35:55', NULL),
(109, 'ME', 'Mechanical Engineering', 'Engineering', NULL, '2025-12-18 13:35:55', NULL),
(110, 'ACC', 'Accounting', 'Business & Economics', NULL, '2025-12-18 13:35:55', NULL),
(111, 'ECO', 'Economics', 'Business & Economics', NULL, '2025-12-18 13:35:55', NULL),
(112, 'LAW', 'Law', 'Law', NULL, '2025-12-18 13:35:55', NULL),
(113, 'ENG', 'English', 'Social Sciences', NULL, '2025-12-18 13:35:55', NULL),
(114, 'PSY', 'Psychology', 'Social Sciences', NULL, '2025-12-18 13:35:55', NULL),
(116, 'PHY', 'Physics', 'Natural Social', NULL, '2025-12-24 11:36:18', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `enrollment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `enrollment_date` datetime NOT NULL,
  `academic_year` varchar(9) NOT NULL,
  `semester` tinyint(4) NOT NULL,
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `enrollments`
--

INSERT INTO `enrollments` (`enrollment_id`, `student_id`, `course_id`, `enrollment_date`, `academic_year`, `semester`, `is_active`) VALUES
(1, 7, 4, '2025-12-24 23:32:03', '2025-2026', 1, 1),
(2, 7, 5, '2025-12-24 23:32:03', '2025-2026', 1, 1),
(3, 7, 6, '2025-12-24 23:32:03', '2025-2026', 1, 1),
(4, 4, 7, '2025-12-24 23:46:20', '2025-2026', 2, 1),
(5, 4, 8, '2025-12-24 23:46:20', '2025-2026', 2, 1),
(6, 8, 1, '2025-12-25 10:16:47', '2025-2026', 1, 1),
(7, 8, 2, '2025-12-25 10:16:47', '2025-2026', 1, 1),
(8, 8, 11, '2025-12-25 10:16:47', '2025-2026', 1, 1),
(9, 11, 5, '2025-12-25 11:39:19', '2025-2026', 2, 1),
(10, 11, 6, '2025-12-25 11:39:19', '2025-2026', 2, 1),
(11, 11, 7, '2025-12-25 11:39:19', '2025-2026', 2, 1),
(12, 11, 8, '2025-12-25 11:39:19', '2025-2026', 2, 1);

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `leave_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `leave_type` enum('sick','personal','family','emergency','other') DEFAULT 'personal',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text NOT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `review_notes` text,
  `requested_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `login_logs`
--

CREATE TABLE `login_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `login_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `success` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `notices`
--

CREATE TABLE `notices` (
  `notice_id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `is_active` tinyint(1) DEFAULT '1',
  `posted_by` int(11) NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('attendance','leave','system','alert') DEFAULT 'system',
  `is_read` tinyint(1) DEFAULT '0',
  `related_id` int(11) DEFAULT NULL,
  `related_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `schedule_conflicts`
--

CREATE TABLE `schedule_conflicts` (
  `conflict_id` int(11) NOT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `conflicting_schedule_id` int(11) DEFAULT NULL,
  `conflict_type` enum('teacher','room','time') DEFAULT NULL,
  `description` text,
  `resolved` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `schedule_issues`
--

CREATE TABLE `schedule_issues` (
  `issue_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `issue_type` enum('time','room','other') DEFAULT 'other',
  `description` text NOT NULL,
  `status` enum('pending','reviewed','resolved') DEFAULT 'pending',
  `resolved_by` int(11) DEFAULT NULL,
  `resolution` text,
  `reported_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `schedule_notifications`
--

CREATE TABLE `schedule_notifications` (
  `notification_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `department_head_id` int(11) DEFAULT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `message` text,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `key` varchar(191) NOT NULL,
  `value` text,
  `category` varchar(50) DEFAULT NULL,
  `description` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `key`, `value`, `category`, `description`, `created_at`, `updated_at`) VALUES
(1, 'system_name', 'DaDU Attendance System', 'general', 'Name of the system', '2025-12-23 06:17:26', '2025-12-23 06:19:39'),
(2, 'institute_name', 'Dambi Dollo University', 'general', 'Name of the institute', '2025-12-23 06:17:26', '2025-12-23 06:19:39'),
(3, 'institute_address', 'Dambi Dollo, Oromia, Ethiopia', 'general', 'Institute address', '2025-12-23 06:17:26', '2025-12-23 06:19:39'),
(4, 'contact_email', 'info@dadu.edu.et', 'general', 'Contact email address', '2025-12-23 06:17:26', '2025-12-23 06:19:39'),
(5, 'contact_phone', '+251-57 555 2391', 'general', 'Contact phone number', '2025-12-23 06:17:26', '2025-12-23 06:19:39'),
(6, 'timezone', 'Asia/Kolkata', 'general', 'System timezone', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(7, 'date_format', 'Y-m-d', 'general', 'Date format', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(8, 'min_attendance_percentage', '75', 'attendance', 'Minimum required attendance percentage', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(9, 'default_attendance_status', 'absent', 'attendance', 'Default attendance status', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(10, 'late_attendance_minutes', '15', 'attendance', 'Minutes allowed for late attendance', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(11, 'auto_absent_hours', '24', 'attendance', 'Hours after which auto-mark absent', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(12, 'enable_qr_attendance', '1', 'attendance', 'Enable QR code attendance', '2025-12-23 06:17:26', '2025-12-23 06:20:03'),
(13, 'enable_biometric', '1', 'attendance', 'Enable biometric attendance', '2025-12-23 06:17:26', '2025-12-23 06:20:03'),
(14, 'smtp_host', 'smtp.gmail.com', 'email', 'SMTP server host', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(15, 'smtp_port', '587', 'email', 'SMTP server port', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(16, 'smtp_username', '', 'email', 'SMTP username', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(17, 'smtp_password', '', 'email', 'SMTP password', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(18, 'from_email', 'noreply@ddu.ac.in', 'email', 'Sender email address', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(19, 'from_name', 'DDU Attendance System', 'email', 'Sender name', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(20, 'smtp_ssl', '1', 'email', 'Use SSL/TLS for SMTP', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(21, 'session_timeout', '30', 'security', 'Session timeout in minutes', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(22, 'max_login_attempts', '5', 'security', 'Maximum login attempts', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(23, 'lockout_duration', '15', 'security', 'Account lockout duration in minutes', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(24, 'password_expiry_days', '90', 'security', 'Password expiry in days', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(25, 'force_password_change', '0', 'security', 'Force password change on first login', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(26, 'enable_2fa', '0', 'security', 'Enable two-factor authentication', '2025-12-23 06:17:26', '2025-12-23 06:17:26'),
(27, 'enable_ip_whitelist', '0', 'security', 'Enable IP whitelist for admin', '2025-12-23 06:17:26', '2025-12-23 06:17:26');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `student_id` int(11) NOT NULL,
  `student_id_no` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `program` varchar(100) DEFAULT NULL,
  `year_of_study` int(11) DEFAULT NULL,
  `semester` int(11) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`student_id`, `student_id_no`, `full_name`, `email`, `phone`, `gender`, `date_of_birth`, `department_id`, `program`, `year_of_study`, `semester`, `profile_image`, `is_active`, `created_at`, `user_id`) VALUES
(1, 'DDU/CS/001/14', 'Test Student', 'student@ddu.edu.et', '0919171717', NULL, NULL, 1, 'CS', 3, 1, NULL, 1, '2025-12-18 13:47:18', NULL),
(2, 'DaDu/IT/002/25', 'Kumbi Kitesa', 'kumbikitesa@gmail.com', '0919191919', NULL, NULL, 106, 'IT', 3, 1, NULL, 1, '2025-12-22 06:16:18', NULL),
(3, 'DaDu/SE/003/25', 'Gelana Geleta Gadisa', 'g3elanaeletaadisa@gmail.com', '0919191910', NULL, NULL, 2, 'SE', 2, 1, NULL, 1, '2025-12-23 06:10:42', NULL),
(4, 'DaDu/IT/004/25', 'Abdi Kitesa', 'abdikitesa@gmail.com', '0916831848', NULL, NULL, 106, 'System', 3, 1, NULL, 1, '2025-12-24 18:28:43', NULL),
(5, 'DaDu/IT/006/25', 'Amanuel Tola', 'amanueltolaa@gmail.com', '0917171710', 'Male', '2022-08-01', 1, 'CS', 2, 1, NULL, 1, '2025-12-24 18:43:19', NULL),
(6, 'DaDu/IT/007/25', 'Girma Dhuguma', 'girmadhuguma@gmail.com', '0921123113', NULL, NULL, 107, 'ECE', 2, 1, NULL, 1, '2025-12-24 18:46:46', NULL),
(7, 'DaDu/IT/008/25', 'Milkiyasa Tolesa', 'milkiyaastolasaa@gmail.com', '0911011217', NULL, NULL, 3, 'IT', 4, 1, NULL, 1, '2025-12-24 20:07:29', NULL),
(8, 'DaDu/IT/009/25', 'John Dolton', 'john.dolton.dadu/it/009/25@ddu.ac.in', '0910141513', NULL, NULL, 3, 'Bsc', 3, 1, NULL, 1, '2025-12-25 07:15:55', NULL),
(11, 'STU25GG001', 'Kitesa Karkaro', 'kitesa.karkaro.dadu@ddu.ac.in', '0919191910', NULL, NULL, 107, 'Bsc', 2, 2, NULL, 1, '2025-12-25 08:38:47', NULL),
(12, 'STU25GG002', 'Abdisa Deresa', 'abdisa.deresa.stu25gg002@ddu.ac.in', '0919191910', NULL, NULL, 3, 'Bsc', 4, 2, NULL, 1, '2025-12-25 12:46:32', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `student_attendance`
--

CREATE TABLE `student_attendance` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `attendance_status` enum('present','absent','late','leave') DEFAULT 'present',
  `remarks` text,
  `marked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `student_details`
--

CREATE TABLE `student_details` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `semester` int(11) DEFAULT '1',
  `academic_year` varchar(20) DEFAULT NULL,
  `enrollment_date` date DEFAULT NULL,
  `graduation_date` date DEFAULT NULL,
  `current_cgpa` decimal(3,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `student_enrollments`
--

CREATE TABLE `student_enrollments` (
  `enrollment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `academic_year` year(4) DEFAULT NULL,
  `semester` int(11) DEFAULT NULL,
  `enrollment_date` date DEFAULT NULL,
  `enrollment_status` enum('active','dropped','completed') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `student_enrollments`
--

INSERT INTO `student_enrollments` (`enrollment_id`, `student_id`, `course_id`, `academic_year`, `semester`, `enrollment_date`, `enrollment_status`) VALUES
(1, 1, 1, 2024, 1, '2025-12-18', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `setting_type` varchar(50) DEFAULT NULL,
  `description` text,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `setting_type`, `description`, `updated_at`) VALUES
(1, 'attendance_threshold', '75', 'percentage', 'Minimum attendance percentage required', '2025-12-18 08:59:00'),
(2, 'late_threshold_minutes', '15', 'minutes', 'Minutes after which student is marked late', '2025-12-18 08:59:00'),
(3, 'academic_year', '2024', 'year', 'Current academic year', '2025-12-18 08:59:00'),
(4, 'current_semester', '1', 'number', 'Current semester', '2025-12-18 08:59:00'),
(5, 'university_name', 'Dambi Dollo University', 'text', 'University name', '2025-12-18 08:59:00'),
(6, 'max_absent_days', '10', 'days', 'Maximum allowed absent days', '2025-12-18 08:59:00');

-- --------------------------------------------------------

--
-- Table structure for table `teacher_courses`
--

CREATE TABLE `teacher_courses` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_details`
--

CREATE TABLE `teacher_details` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `qualification` varchar(200) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `date_of_joining` date DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `address` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('admin','teacher','student','dean','registrar') DEFAULT 'teacher',
  `department_id` int(11) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_department_head` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `full_name`, `email`, `phone`, `role`, `department_id`, `profile_image`, `is_active`, `created_at`, `updated_at`, `is_department_head`) VALUES
(1, 'admin', '$2y$10$vte3syOK81QK1GgptV0KbuP8YUsa0OgsxPNK1Bq5K8WJkYH30FcdG', 'System Administrator', 'admin@ddu.edu.et', '0911011211', 'admin', NULL, NULL, 1, '2025-12-18 08:59:00', '2025-12-23 05:57:19', 0),
(2, 'teacher1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Test Teacher', 'teacher@ddu.edu.et', '0911011219', 'teacher', 2, NULL, 1, '2025-12-18 13:44:52', '2025-12-23 07:05:14', 0),
(5, 'teacher2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Test Teacher', 'teacher2@ddu.edu.et', '0919191919', 'teacher', 1, NULL, 1, '2025-12-18 13:47:18', '2025-12-24 12:59:49', 0),
(7, 'hambaa', '$2y$10$lvENCHUT7WqXjFCk6C9GSu.IJrj2inkXIip5v4I9TE6agRlyS975C', 'Hamba Ababe Shere', 'hambaaababe@gmail.com', '0917171716', 'teacher', 3, NULL, 1, '2025-12-22 06:47:34', '2025-12-24 20:44:10', 1),
(8, 'yaadoo', '$2y$10$80RT1RpQb0gEPcMiHxcgDO7hOacb2Vc995atDYUhlBgpw6t0oi6/K', 'Yadeta Dinbashawu', 'yaadoo@gmail.com', '0919171718', 'teacher', NULL, NULL, 1, '2025-12-23 05:46:04', '2025-12-27 09:21:27', 0),
(9, 'obsinaan', '$2y$10$LFeq0eKb2dtEduhheJx6cuQwdUia.YXplB8Lpwk/VHuWhXNaq7UcW', 'Kibiru Tesfa', 'obsinaan@gmail.com', '0919191914', 'teacher', 1, 'profile_9_1766576900.jpg', 1, '2025-12-23 07:06:11', '2025-12-24 20:43:44', 1),
(10, 'jaaloo', '$2y$10$iIVa.Y3rgGKbY7Va.vW.2eCcoOaqKccpGCQaHwEfStH/HO.sBKsr2', 'Geleta Tilahun', 'julietsmithwillian@gmail.com', '0919520019', 'teacher', 2, NULL, 1, '2025-12-24 20:44:57', '2025-12-24 20:45:16', 1),
(11, 'stu_ddu/cs/001/14', '$2y$10$iIVa.Y3rgGKbY7Va.vW.2eCcoOaqKccpGCQaHwEfStH/HO.sBKsr2', 'Test Student', 'student@ddu.edu.et', '0919171717', 'student', NULL, NULL, 1, '2025-12-25 08:09:02', '2025-12-25 15:21:32', 0),
(12, 'stu_dadu/it/002/25', '$2y$10$', 'Kumbi Kitesa', 'kumbikitesa@gmail.com', '0919191919', 'student', NULL, NULL, 1, '2025-12-25 08:09:02', '2025-12-25 15:21:12', 0),
(13, 'stu_dadu/se/003/25', '$2y$10$', 'Gelana Geleta Gadisa', 'g3elanaeletaadisa@gmail.com', '0919191910', 'student', NULL, NULL, 1, '2025-12-25 08:09:02', '2025-12-25 15:21:07', 0),
(14, 'stu_dadu/it/004/25', '$2y$10$', 'Abdi Kitesa', 'abdikitesa@gmail.com', '0916831848', 'student', NULL, NULL, 1, '2025-12-25 08:09:02', '2025-12-25 15:21:01', 0),
(15, 'stu_dadu/it/006/25', '$2y$10$', 'Amanuel Tola', 'amanueltolaa@gmail.com', '0917171710', 'student', NULL, NULL, 1, '2025-12-25 08:09:02', '2025-12-25 15:20:56', 0),
(16, 'stu_dadu/it/007/25', '$2y$10$', 'Girma Dhuguma', 'girmadhuguma@gmail.com', '0921123113', 'student', 111, NULL, 1, '2025-12-25 08:09:02', '2025-12-25 15:22:20', 0),
(17, 'stu_dadu/it/008/25', '$2y$10$', 'Milkiyasa Tolesa', 'milkiyaastolasaa@gmail.com', '0911011217', 'student', 1, NULL, 1, '2025-12-25 08:09:02', '2025-12-25 15:22:13', 0),
(18, 'stu_dadu/it/009/25', '$2y$10$', 'John Dolton', 'john.dolton.dadu/it/009/25@ddu.ac.in', '0910141513', 'teacher', 4, NULL, 1, '2025-12-25 08:09:02', '2025-12-25 15:22:05', 0),
(26, 'galma.galgalo', '$2y$10$B.UWujeOARhX6KlkSdCa/eTMwmQR3vo77HKe5tcrkL8L2txW/Wh8y', 'Galma Galgalo', 'galma.galgalo@ddu.ac.in', '0919181811', 'student', 3, NULL, 1, '2025-12-25 08:29:51', '2025-12-27 13:42:21', 0),
(27, 'abdisa.deresa', '$2y$10$HcYuty2b2MuQpIJvKbQV8uq2RTrT3dT4i4euHfDx0RjLtG7./84ia', 'Abdisa Deresa', 'abdisa.deresa@ddu.ac.in', '0910041513', 'student', 3, NULL, 1, '2025-12-25 12:44:05', '2025-12-25 15:18:18', 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `permissions` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD UNIQUE KEY `unique_attendance` (`course_id`,`student_id`,`attendance_date`,`session_type`),
  ADD UNIQUE KEY `unique_attendance_entry` (`student_id`,`course_id`,`attendance_date`,`session_type`),
  ADD KEY `fk_attendance_marked_by` (`marked_by`),
  ADD KEY `idx_student_course` (`student_id`,`course_id`),
  ADD KEY `idx_attendance_date` (`attendance_date`),
  ADD KEY `idx_teacher_date` (`teacher_id`,`attendance_date`);

--
-- Indexes for table `attendance_details`
--
ALTER TABLE `attendance_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `attendance_id` (`attendance_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `marked_by` (`marked_by`);

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `attendance_summary`
--
ALTER TABLE `attendance_summary`
  ADD PRIMARY KEY (`summary_id`),
  ADD UNIQUE KEY `unique_summary` (`student_id`,`course_id`,`academic_year`,`semester`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `class_schedule`
--
ALTER TABLE `class_schedule`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `class_schedules`
--
ALTER TABLE `class_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`course_id`),
  ADD UNIQUE KEY `course_code` (`course_code`);

--
-- Indexes for table `course_assignments`
--
ALTER TABLE `course_assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD UNIQUE KEY `unique_assignment` (`course_id`,`teacher_id`,`academic_year`,`semester`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `course_students`
--
ALTER TABLE `course_students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_enrollment` (`course_id`,`student_id`),
  ADD KEY `fk_course_students_student` (`student_id`) USING BTREE,
  ADD KEY `fk_course_students_enrolled_by` (`enrolled_by`) USING BTREE;

--
-- Indexes for table `course_teachers`
--
ALTER TABLE `course_teachers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_assignment` (`course_id`,`teacher_id`),
  ADD KEY `fk_course_teachers_teacher` (`teacher_id`),
  ADD KEY `fk_course_teachers_assigned_by` (`assigned_by`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD UNIQUE KEY `department_code` (`department_code`),
  ADD KEY `department_head_id` (`department_head_id`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`enrollment_id`),
  ADD UNIQUE KEY `unique_enrollment` (`student_id`,`course_id`,`academic_year`,`semester`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`leave_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `login_logs`
--
ALTER TABLE `login_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notices`
--
ALTER TABLE `notices`
  ADD PRIMARY KEY (`notice_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `posted_by` (`posted_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `schedule_conflicts`
--
ALTER TABLE `schedule_conflicts`
  ADD PRIMARY KEY (`conflict_id`),
  ADD KEY `schedule_id` (`schedule_id`),
  ADD KEY `conflicting_schedule_id` (`conflicting_schedule_id`);

--
-- Indexes for table `schedule_issues`
--
ALTER TABLE `schedule_issues`
  ADD PRIMARY KEY (`issue_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `schedule_id` (`schedule_id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `resolved_by` (`resolved_by`);

--
-- Indexes for table `schedule_notifications`
--
ALTER TABLE `schedule_notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `department_head_id` (`department_head_id`),
  ADD KEY `schedule_id` (`schedule_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `student_id_no` (`student_id_no`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `student_attendance`
--
ALTER TABLE `student_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_student_attendance` (`student_id`,`course_id`,`attendance_date`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `student_details`
--
ALTER TABLE `student_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `student_enrollments`
--
ALTER TABLE `student_enrollments`
  ADD PRIMARY KEY (`enrollment_id`),
  ADD UNIQUE KEY `unique_enrollment` (`student_id`,`course_id`,`academic_year`,`semester`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `teacher_courses`
--
ALTER TABLE `teacher_courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `teacher_id` (`teacher_id`,`course_id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `teacher_details`
--
ALTER TABLE `teacher_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `attendance_details`
--
ALTER TABLE `attendance_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_records`
--
ALTER TABLE `attendance_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_summary`
--
ALTER TABLE `attendance_summary`
  MODIFY `summary_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_schedule`
--
ALTER TABLE `class_schedule`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `class_schedules`
--
ALTER TABLE `class_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `course_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `course_assignments`
--
ALTER TABLE `course_assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `course_students`
--
ALTER TABLE `course_students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `course_teachers`
--
ALTER TABLE `course_teachers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=117;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `enrollment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `leave_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_logs`
--
ALTER TABLE `login_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notices`
--
ALTER TABLE `notices`
  MODIFY `notice_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schedule_conflicts`
--
ALTER TABLE `schedule_conflicts`
  MODIFY `conflict_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schedule_issues`
--
ALTER TABLE `schedule_issues`
  MODIFY `issue_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schedule_notifications`
--
ALTER TABLE `schedule_notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `student_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `student_attendance`
--
ALTER TABLE `student_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_details`
--
ALTER TABLE `student_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_enrollments`
--
ALTER TABLE `student_enrollments`
  MODIFY `enrollment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `teacher_courses`
--
ALTER TABLE `teacher_courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teacher_details`
--
ALTER TABLE `teacher_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `attendance_ibfk_4` FOREIGN KEY (`marked_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_attendance_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `fk_attendance_marked_by` FOREIGN KEY (`marked_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `fk_attendance_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `attendance_details`
--
ALTER TABLE `attendance_details`
  ADD CONSTRAINT `attendance_details_ibfk_1` FOREIGN KEY (`attendance_id`) REFERENCES `attendance_records` (`id`),
  ADD CONSTRAINT `attendance_details_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `attendance_details_ibfk_3` FOREIGN KEY (`marked_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD CONSTRAINT `attendance_records_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `attendance_records_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD CONSTRAINT `attendance_sessions_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `attendance_sessions_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `attendance_summary`
--
ALTER TABLE `attendance_summary`
  ADD CONSTRAINT `attendance_summary_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `attendance_summary_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`);

--
-- Constraints for table `class_schedule`
--
ALTER TABLE `class_schedule`
  ADD CONSTRAINT `class_schedule_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `class_schedule_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `class_schedule_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `class_schedules`
--
ALTER TABLE `class_schedules`
  ADD CONSTRAINT `class_schedules_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`);

--
-- Constraints for table `course_assignments`
--
ALTER TABLE `course_assignments`
  ADD CONSTRAINT `course_assignments_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `course_assignments_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `course_students`
--
ALTER TABLE `course_students`
  ADD CONSTRAINT `fk_course_students_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_course_students_enrolled_by` FOREIGN KEY (`enrolled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_course_students_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `course_teachers`
--
ALTER TABLE `course_teachers`
  ADD CONSTRAINT `course_teachers_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `course_teachers_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `course_teachers_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_course_teachers_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_course_teachers_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_course_teachers_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`department_head_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `leave_requests_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `leave_requests_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `leave_requests_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `login_logs`
--
ALTER TABLE `login_logs`
  ADD CONSTRAINT `login_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `notices`
--
ALTER TABLE `notices`
  ADD CONSTRAINT `notices_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `notices_ibfk_2` FOREIGN KEY (`posted_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`);

--
-- Constraints for table `schedule_conflicts`
--
ALTER TABLE `schedule_conflicts`
  ADD CONSTRAINT `schedule_conflicts_ibfk_1` FOREIGN KEY (`schedule_id`) REFERENCES `class_schedule` (`schedule_id`),
  ADD CONSTRAINT `schedule_conflicts_ibfk_2` FOREIGN KEY (`conflicting_schedule_id`) REFERENCES `class_schedule` (`schedule_id`);

--
-- Constraints for table `schedule_issues`
--
ALTER TABLE `schedule_issues`
  ADD CONSTRAINT `schedule_issues_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `schedule_issues_ibfk_2` FOREIGN KEY (`schedule_id`) REFERENCES `class_schedule` (`schedule_id`),
  ADD CONSTRAINT `schedule_issues_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `schedule_issues_ibfk_4` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `schedule_notifications`
--
ALTER TABLE `schedule_notifications`
  ADD CONSTRAINT `schedule_notifications_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `schedule_notifications_ibfk_2` FOREIGN KEY (`department_head_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `schedule_notifications_ibfk_3` FOREIGN KEY (`schedule_id`) REFERENCES `class_schedule` (`schedule_id`);

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `student_attendance`
--
ALTER TABLE `student_attendance`
  ADD CONSTRAINT `student_attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `student_attendance_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`),
  ADD CONSTRAINT `student_attendance_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `student_details`
--
ALTER TABLE `student_details`
  ADD CONSTRAINT `student_details_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `student_enrollments`
--
ALTER TABLE `student_enrollments`
  ADD CONSTRAINT `student_enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `student_enrollments_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`);

--
-- Constraints for table `teacher_courses`
--
ALTER TABLE `teacher_courses`
  ADD CONSTRAINT `teacher_courses_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `teacher_courses_ibfk_2` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE;

--
-- Constraints for table `teacher_details`
--
ALTER TABLE `teacher_details`
  ADD CONSTRAINT `teacher_details_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL;

--
-- Constraints for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
