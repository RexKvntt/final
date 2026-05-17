-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 17, 2026 at 04:54 PM
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
-- Database: `helios_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `authorized_people`
--

CREATE TABLE `authorized_people` (
  `id` varchar(36) NOT NULL,
  `firstname` varchar(100) NOT NULL,
  `lastname` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phonenumber` varchar(20) NOT NULL COMMENT 'Normalized digits e.g. 9171234567',
  `role` enum('student','faculty','admin') NOT NULL,
  `status` enum('pending','activated') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `activated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `authorized_people`
--

INSERT INTO `authorized_people` (`id`, `firstname`, `lastname`, `email`, `phonenumber`, `role`, `status`, `created_at`, `activated_at`) VALUES
('AUTH-174673A3', 'Morgan Clide', 'Calibo', 'clidecalibojr@gmail.com', '9810065270', 'student', 'pending', '2026-05-10 18:36:10', NULL),
('AUTH-32C84052', 'Mackenzie', 'McKinley', 'kentlagundino72@gmail.com', '9301774959', 'student', 'activated', '2026-05-16 14:38:47', '2026-05-16 14:50:00'),
('AUTH-32CB7659', 'Dazai', 'Tang', 'helios.univv@gmail.com', '9810065270', 'student', 'activated', '2026-05-10 19:23:19', '2026-05-10 19:24:22'),
('AUTH-8CB8E95C', 'Janne', 'Guest', 'clideroncesvalles@gmail.com', '9810065270', 'faculty', 'activated', '2026-05-10 22:33:04', '2026-05-10 22:37:47'),
('AUTH-9A9B3BFC', 'Ken Lu Ren', 'Zhaogu', 'kentlawrencelagundino@gmail.com', '9301774959', 'faculty', 'activated', '2026-05-11 21:33:55', '2026-05-11 21:36:15'),
('AUTH-AE451408', 'Dawn', 'Vessel', 'hakienma@gmail.com', '9810065270', 'student', 'activated', '2026-05-10 18:42:08', '2026-05-10 18:54:25'),
('AUTH-F97F1070', 'Domina', 'Columbina', 'drarryevr0@gmail.com', '9301774959', 'faculty', 'activated', '2026-05-16 15:25:51', '2026-05-16 15:26:28');

-- --------------------------------------------------------

--
-- Table structure for table `calendar_events`
--

CREATE TABLE `calendar_events` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `created_by` varchar(10) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `class_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` varchar(20) NOT NULL,
  `name` varchar(200) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`id`, `name`, `subject`, `description`, `status`, `created_at`) VALUES
('C002', 'CLASS1B', 'PHYSICS', NULL, 'archived', '2026-05-10 23:12:46'),
('C003', '2b', '', NULL, 'active', '2026-05-13 16:12:56'),
('C004', 'Sect 2C', '', NULL, 'active', '2026-05-16 15:24:06'),
('C005', 'Class-1A', '', NULL, 'active', '2026-05-17 18:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `class_members`
--

CREATE TABLE `class_members` (
  `class_id` varchar(20) NOT NULL,
  `username` varchar(10) NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `class_members`
--

INSERT INTO `class_members` (`class_id`, `username`, `joined_at`) VALUES
('C002', '2026-0000', '2026-05-11 10:12:33'),
('C002', '2026-0001', '2026-05-11 10:12:17'),
('C002', '2026-0002', '2026-05-11 10:12:27'),
('C003', '2026-0001', '2026-05-13 16:21:22'),
('C003', '2026-0002', '2026-05-16 15:29:10'),
('C003', '2026-0005', '2026-05-16 15:29:16'),
('C004', '2026-0000', '2026-05-16 15:28:32'),
('C004', '2026-0001', '2026-05-16 15:28:26'),
('C004', '2026-0002', '2026-05-16 15:28:38'),
('C004', '2026-0005', '2026-05-16 15:28:19'),
('C005', '2026-0000', '2026-05-17 19:00:55'),
('C005', '2026-0001', '2026-05-17 19:00:47'),
('C005', '2026-0005', '2026-05-17 19:00:36');

-- --------------------------------------------------------

--
-- Table structure for table `comments`
--

CREATE TABLE `comments` (
  `id` varchar(20) NOT NULL,
  `post_id` varchar(20) NOT NULL,
  `author` varchar(10) NOT NULL,
  `role` enum('student','faculty','admin') NOT NULL,
  `body` text NOT NULL,
  `posted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` varchar(20) NOT NULL,
  `type` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `time` datetime NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `type`, `message`, `time`, `is_read`) VALUES
('N27F16C3C', 'register', 'Morgan Clide Calibo submitted an account activation request.', '2026-05-10 18:36:53', 0),
('N29B07371', 'register', 'Mackenzie McKinley submitted an account activation request.', '2026-05-16 14:40:41', 0),
('N7A425793', 'register', 'Ken Lu Ren Zhaogu submitted an account activation request.', '2026-05-11 21:35:43', 0),
('N9DF67885', 'register', 'Domina Columbina submitted an account activation request.', '2026-05-16 15:26:20', 0),
('NAC09A737', 'register', 'Dawn Vessel submitted an account activation request.', '2026-05-10 18:42:52', 0),
('ND392ECF0', 'register', 'Janne Guest submitted an account activation request.', '2026-05-10 22:36:49', 0),
('NEBFC7809', 'register', 'Dazai Tang submitted an account activation request.', '2026-05-10 19:24:03', 0);

-- --------------------------------------------------------

--
-- Table structure for table `posts`
--

CREATE TABLE `posts` (
  `id` varchar(20) NOT NULL,
  `class_id` varchar(20) NOT NULL,
  `type` enum('announcement','assignment','material') NOT NULL DEFAULT 'announcement',
  `title` varchar(255) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `link_url` varchar(2048) DEFAULT NULL,
  `posted_by` varchar(10) NOT NULL,
  `subject` varchar(20) DEFAULT NULL,
  `posted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deadline` datetime DEFAULT NULL,
  `points` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `posts`
--

INSERT INTO `posts` (`id`, `class_id`, `type`, `title`, `body`, `link_url`, `posted_by`, `subject`, `posted_at`, `deadline`, `points`) VALUES
('post_6a013a1532fb9', 'C002', 'assignment', 'Pag ubra bata', 'yes ubra kamo bata now a', NULL, '2026-0003', 'S002', '2026-05-11 10:08:21', '2026-05-12 23:59:00', 100),
('post_6a0433ff16140', 'C003', 'assignment', 'wowow', 'jhuuhuhh', NULL, '2026-0003', 'S004', '2026-05-13 16:19:11', '2026-05-13 03:33:00', 100),
('post_6a0984f44498d', 'C004', 'assignment', 'Finals Presentation', 'Final defense for your system. Attached is a sample of your documentation.', NULL, '2026-0006', 'S006', '2026-05-17 17:05:56', '2026-05-18 11:00:00', 100),
('post_6a09b305ed6c0', 'C005', 'material', 'Study this scheisse', 'Yeah just do what i say, if not then that\'s your choice.', NULL, '2026-0006', 'S008', '2026-05-17 20:22:29', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `post_files`
--

CREATE TABLE `post_files` (
  `id` int(10) UNSIGNED NOT NULL,
  `post_id` varchar(20) NOT NULL,
  `orig_name` varchar(255) NOT NULL,
  `stored_path` varchar(512) NOT NULL,
  `ext` varchar(10) NOT NULL,
  `size` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `post_files`
--

INSERT INTO `post_files` (`id`, `post_id`, `orig_name`, `stored_path`, `ext`, `size`) VALUES
(1, 'post_6a0984f44498d', 'Documentation-updated-but-not-final-gyapon.docx', 'uploads/class_posts/up_6a0984f44420f_Documentation-updated-but-not-final-gyapon.docx', 'docx', 4465222),
(2, 'post_6a09b305ed6c0', 'Research_Platform_Technologies.pdf', 'uploads/class_posts/up_6a09b305eb414_Research_Platform_Technologies.pdf', 'pdf', 490181);

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` varchar(20) NOT NULL,
  `class_id` varchar(20) NOT NULL,
  `name` varchar(200) NOT NULL,
  `faculty` varchar(10) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `class_id`, `name`, `faculty`, `created_at`) VALUES
('S002', 'C002', 'Platform Technology ( 7:00 AM - 9:00 AM )', '2026-0003', '2026-05-11 00:55:23'),
('S003', 'C002', 'Programming 2 ( 8:00 AM - 10:00 AM )', NULL, '2026-05-11 00:57:36'),
('S004', 'C003', 'History', '2026-0003', '2026-05-13 16:13:27'),
('S005', 'C004', 'Life of Caesar', '2026-0003', '2026-05-16 15:24:36'),
('S006', 'C004', 'The Capitalist Society', '2026-0006', '2026-05-16 15:28:07'),
('S007', 'C003', 'Readings in Philippine History', '2026-0006', '2026-05-16 15:29:03'),
('S008', 'C005', 'Information Management', '2026-0006', '2026-05-17 19:00:03'),
('S009', 'C005', 'Integrative Programming', '2026-0003', '2026-05-17 19:00:25');

-- --------------------------------------------------------

--
-- Table structure for table `subject_members`
--

CREATE TABLE `subject_members` (
  `subject_id` varchar(20) NOT NULL,
  `username` varchar(10) NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subject_members`
--

INSERT INTO `subject_members` (`subject_id`, `username`, `joined_at`) VALUES
('S002', '2026-0000', '2026-05-11 10:12:33'),
('S002', '2026-0001', '2026-05-11 10:12:17'),
('S002', '2026-0002', '2026-05-11 10:12:27'),
('S004', '2026-0001', '2026-05-13 16:21:22'),
('S004', '2026-0005', '2026-05-16 15:29:16'),
('S005', '2026-0000', '2026-05-16 15:28:32'),
('S005', '2026-0001', '2026-05-16 15:28:26'),
('S005', '2026-0002', '2026-05-16 15:28:38'),
('S005', '2026-0005', '2026-05-16 15:28:19'),
('S006', '2026-0005', '2026-05-16 15:29:25'),
('S007', '2026-0001', '2026-05-16 15:29:48'),
('S007', '2026-0002', '2026-05-16 15:29:10'),
('S007', '2026-0005', '2026-05-16 15:29:36'),
('S008', '2026-0000', '2026-05-17 19:00:55'),
('S008', '2026-0001', '2026-05-17 19:00:47'),
('S008', '2026-0005', '2026-05-17 19:00:36'),
('S009', '2026-0000', '2026-05-17 19:01:22'),
('S009', '2026-0001', '2026-05-17 19:01:11'),
('S009', '2026-0005', '2026-05-17 19:01:04');

-- --------------------------------------------------------

--
-- Table structure for table `submissions`
--

CREATE TABLE `submissions` (
  `id` int(10) UNSIGNED NOT NULL,
  `post_id` varchar(20) NOT NULL,
  `student_username` varchar(10) NOT NULL,
  `note` text DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `score` decimal(6,2) DEFAULT NULL,
  `score_note` text DEFAULT NULL,
  `scored_at` datetime DEFAULT NULL,
  `scored_by` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `submission_files`
--

CREATE TABLE `submission_files` (
  `id` int(10) UNSIGNED NOT NULL,
  `submission_id` int(10) UNSIGNED NOT NULL,
  `orig_name` varchar(255) NOT NULL,
  `stored_path` varchar(512) NOT NULL,
  `ext` varchar(10) NOT NULL,
  `size` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('allow_reg', '1'),
('enforce_otp', '0'),
('last_updated', '2026-05-17 16:46:46'),
('maintenance', '0'),
('m_duration', '1'),
('m_started_at', '1779029132'),
('m_work', 'Thou shan\'t proceedeth no furthere, for a minore mishap has permeatedeth this mighty contraptioneth!'),
('org_name', 'Helios University'),
('sys_email', 'helios.univv@gmail.com');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `request_id` varchar(20) NOT NULL COMMENT 'Temp registration ID (REQ-XXXXXXXX), kept for audit trail',
  `authorized_person_id` varchar(36) DEFAULT NULL,
  `firstname` varchar(100) NOT NULL,
  `lastname` varchar(100) NOT NULL,
  `fullname` varchar(201) NOT NULL,
  `phonenumber` varchar(255) NOT NULL COMMENT 'Encrypted at application layer',
  `username` varchar(10) NOT NULL COMMENT 'Permanent ID — YY-XXXX format — set once on approval, NEVER updated',
  `email` varchar(255) NOT NULL COMMENT 'Encrypted at application layer',
  `password` varchar(255) DEFAULT NULL COMMENT 'bcrypt hash — NULL until approved',
  `role` enum('student','faculty','admin') NOT NULL,
  `status` enum('pending','active','disabled') NOT NULL DEFAULT 'pending',
  `activation_request` tinyint(1) NOT NULL DEFAULT 1,
  `registered_at` datetime NOT NULL DEFAULT current_timestamp(),
  `activated_at` datetime DEFAULT NULL COMMENT 'Set when admin approves the account',
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `temp_password_expires_at` int(11) DEFAULT NULL,
  `disabled_reason` varchar(100) DEFAULT NULL,
  `disabled_at` datetime DEFAULT NULL,
  `otp` varchar(6) DEFAULT NULL,
  `otp_expiry` int(11) DEFAULT NULL,
  `disabled_by` varchar(100) DEFAULT NULL,
  `enabled_by` varchar(100) DEFAULT NULL,
  `enabled_at` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `request_id`, `authorized_person_id`, `firstname`, `lastname`, `fullname`, `phonenumber`, `username`, `email`, `password`, `role`, `status`, `activation_request`, `registered_at`, `activated_at`, `must_change_password`, `temp_password_expires_at`, `disabled_reason`, `disabled_at`, `otp`, `otp_expiry`, `disabled_by`, `enabled_by`, `enabled_at`, `password_changed_at`) VALUES
(2, 'REQ-26-0001', NULL, 'Admin', 'User', 'Admin User', 'y8CZ8x2UxEs8uWS9QayM6g==', '26-0001', 'Fpdey5rjij+GySaMHyS9XfpmPlNG0KtibXLNmndLn44=', '$2b$10$vICZ9eUweBLKS717y/pePu6TCfc1pVosNu1TaDMFgOLsr3RfDKQKK', 'admin', 'active', 0, '2026-05-10 16:47:23', '2026-05-10 16:47:23', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, '2026-0000', 'AUTH-174673A3', 'Morgan Clide', 'Calibo', 'Morgan Clide Calibo', 'UTa/Lcv4sk8QlPBa39wJUA==', '2026-0000', 'BwuDqpETtkSBWPnhm9A1a0+oBmYcq/0jurTO40bbfVo=', '$2y$10$66Kpcw/3BJmB.1R/GfNr2OmP4mOozOF16YZlu8AbuYDb2gOK77wSe', 'student', 'active', 0, '2026-05-10 18:36:53', '2026-05-10 18:52:13', 1, 1778669533, NULL, '2026-05-10 18:59:47', NULL, NULL, '26-0001', '26-0001', '2026-05-10 18:59:55', NULL),
(4, '2026-0001', 'AUTH-AE451408', 'Dawn', 'Vessel', 'Dawn Vessel', 'UTa/Lcv4sk8QlPBa39wJUA==', '2026-0001', 'vjS48abhAGkXmyKn9oes8Sv0coyMfHfUuLJT0cLqpGo=', '$2y$10$uBegOPnhc4di2m5M18Mm2u2EstiK1JJEe2B3Ehg/TDDNCNuatTB7O', 'student', 'active', 0, '2026-05-10 18:42:52', '2026-05-10 18:54:25', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-10 19:19:50'),
(5, '2026-0002', 'AUTH-32CB7659', 'Dazai', 'Tang', 'Dazai Tang', 'UTa/Lcv4sk8QlPBa39wJUA==', '2026-0002', 'Fpdey5rjij+GySaMHyS9XfpmPlNG0KtibXLNmndLn44=', '$2y$10$0UswXU5VobEM07AEkzIle.8v5OMDXYaRmMxls.e./M3pUGhkquXHG', 'student', 'disabled', 0, '2026-05-10 19:24:03', '2026-05-10 19:24:22', 1, 1778671462, NULL, '2026-05-10 19:25:38', NULL, NULL, '26-0001', NULL, NULL, NULL),
(6, '2026-0003', 'AUTH-8CB8E95C', 'Janne', 'Guest', 'Janne Guest', 'UTa/Lcv4sk8QlPBa39wJUA==', '2026-0003', 'k9NvSOeWxdbFbzBRbB2qTRD+0z0ybVMc0Qxm8z0YbO0=', '$2y$10$PR1TSTbG173zFeyM.bhH2.U0JiafZxWgT4dBC51zs.1Yx0QTAsuie', 'faculty', 'active', 0, '2026-05-10 22:36:49', '2026-05-10 22:37:47', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-13 16:05:06'),
(7, '2026-0004', 'AUTH-9A9B3BFC', 'Ken Lu Ren', 'Zhaogu', 'Ken Lu Ren Zhaogu', 'lTyvofVcArVbyLiZQXd5Hw==', '2026-0004', '2ES5uzZ52PsN6mIZP/gtR6D2Zu5S42OqQWU+eis1kek=', '$2y$10$TDfhN8h4nyml3iDnjpC0q.Fvhxu1Hp1YHV3q0wQyv/87PIZo1chQW', 'admin', 'active', 0, '2026-05-11 21:35:43', '2026-05-11 21:36:15', 0, NULL, NULL, NULL, '344096', 1778507026, NULL, NULL, NULL, '2026-05-11 22:51:39'),
(8, '2026-0005', 'AUTH-32C84052', 'Mackenzie', 'McKinley', 'Mackenzie McKinley', 'lTyvofVcArVbyLiZQXd5Hw==', '2026-0005', 'IW4fQ21nTanajyNy9Jer+q6to7OAwJCipsbkBhmQYYk=', '$2y$10$qXtnrTdu2CK7VBoy/KMAe.Yrb5aa4lEXo0PtcLgbLxDTPzu3JWGi2', 'student', 'active', 0, '2026-05-16 14:40:41', '2026-05-16 14:50:00', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-16 15:46:33'),
(9, '2026-0006', 'AUTH-F97F1070', 'Domina', 'Columbina', 'Domina Columbina', 'lTyvofVcArVbyLiZQXd5Hw==', '2026-0006', '7T6pCyDHhcpr/BcASOp+7hB4Ysjziy0DkqIpN0ixmuE=', '$2y$10$CyxADt1UCwBaD2bWChkQWeF7/URMEeOeOIAKIc/E3JvGMx8TKO1cW', 'faculty', 'active', 0, '2026-05-16 15:26:20', '2026-05-16 15:26:28', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-16 15:27:19');

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `trg_block_username_change` BEFORE UPDATE ON `users` FOR EACH ROW BEGIN
    IF OLD.username REGEXP '^[0-9]{2}-[0-9]{4}$'
       AND NEW.username <> OLD.username
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Username (ID) is permanent and cannot be changed after activation.';
    END IF;
END
$$
DELIMITER ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `authorized_people`
--
ALTER TABLE `authorized_people`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_ap_status` (`status`);

--
-- Indexes for table `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `class_members`
--
ALTER TABLE `class_members`
  ADD PRIMARY KEY (`class_id`,`username`),
  ADD KEY `username` (`username`);

--
-- Indexes for table `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `author` (`author`),
  ADD KEY `idx_comments_post` (`post_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_read` (`is_read`);

--
-- Indexes for table `posts`
--
ALTER TABLE `posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `posted_by` (`posted_by`),
  ADD KEY `idx_posts_class` (`class_id`),
  ADD KEY `idx_posts_type` (`type`),
  ADD KEY `idx_posts_subject` (`subject`);

--
-- Indexes for table `post_files`
--
ALTER TABLE `post_files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `post_id` (`post_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_id` (`class_id`);

--
-- Indexes for table `subject_members`
--
ALTER TABLE `subject_members`
  ADD PRIMARY KEY (`subject_id`,`username`);

--
-- Indexes for table `submissions`
--
ALTER TABLE `submissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_submission` (`post_id`,`student_username`),
  ADD KEY `student_username` (`student_username`),
  ADD KEY `scored_by` (`scored_by`),
  ADD KEY `idx_submissions_post` (`post_id`);

--
-- Indexes for table `submission_files`
--
ALTER TABLE `submission_files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `submission_id` (`submission_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `request_id` (`request_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `authorized_person_id` (`authorized_person_id`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_status` (`status`),
  ADD KEY `idx_users_activated` (`activated_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `calendar_events`
--
ALTER TABLE `calendar_events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `post_files`
--
ALTER TABLE `post_files`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `submissions`
--
ALTER TABLE `submissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `submission_files`
--
ALTER TABLE `submission_files`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD CONSTRAINT `calendar_events_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`username`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `class_members`
--
ALTER TABLE `class_members`
  ADD CONSTRAINT `class_members_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `class_members_ibfk_2` FOREIGN KEY (`username`) REFERENCES `users` (`username`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `comments`
--
ALTER TABLE `comments`
  ADD CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`author`) REFERENCES `users` (`username`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `posts`
--
ALTER TABLE `posts`
  ADD CONSTRAINT `posts_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `posts_ibfk_2` FOREIGN KEY (`posted_by`) REFERENCES `users` (`username`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `post_files`
--
ALTER TABLE `post_files`
  ADD CONSTRAINT `post_files_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subjects`
--
ALTER TABLE `subjects`
  ADD CONSTRAINT `subjects_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subject_members`
--
ALTER TABLE `subject_members`
  ADD CONSTRAINT `subject_members_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `submissions`
--
ALTER TABLE `submissions`
  ADD CONSTRAINT `submissions_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `submissions_ibfk_2` FOREIGN KEY (`student_username`) REFERENCES `users` (`username`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `submissions_ibfk_3` FOREIGN KEY (`scored_by`) REFERENCES `users` (`username`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `submission_files`
--
ALTER TABLE `submission_files`
  ADD CONSTRAINT `submission_files_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`authorized_person_id`) REFERENCES `authorized_people` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
