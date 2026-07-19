-- ==================================================
-- Дамп базы данных knowledge_base (ИСПРАВЛЕННАЯ ВЕРСИЯ)
-- Дата: 2026-07-20
-- ИСПРАВЛЕНИЯ:
--   1. Добавлены колонки allow_editors, allow_employees в companies
--   2. Добавлен AUTO_INCREMENT в users.id
--   3. Создана таблица login_attempts
--   4. Изменён charset на utf8mb4 (поддержка эмодзи)
--   5. Добавлены FULLTEXT-индексы для поиска
-- ==================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `knowledge_base`
--

-- ==========================================================
-- ТАБЛИЦА: access
-- ==========================================================
CREATE TABLE `access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `action` enum('read','write','delete') DEFAULT 'read',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `company_id` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `access` (`id`, `user_id`, `company_id`, `action`) VALUES
(3, 1, 1, 'delete'),
(89, 1, 3, 'write'),
(91, 1, 3, 'read'),
(111, 7257, 2, 'read'),
(114, 7446, 2, 'write'),
(115, 7446, 1, 'write'),
(117, 1, 1, 'read'),
(122, 1, 5, 'write'),
(123, 1, 5, 'read'),
(125, 1, 1, 'write'),
(126, 1, 6, 'write'),
(127, 1, 6, 'read');

-- ==========================================================
-- ТАБЛИЦА: backups
-- ==========================================================
CREATE TABLE `backups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `filesize` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- ТАБЛИЦА: companies (ИСПРАВЛЕНО: добавлены allow_editors, allow_employees)
-- ==========================================================
CREATE TABLE `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `allow_editors` tinyint(1) DEFAULT 0,
  `allow_employees` tinyint(1) DEFAULT 0,
  `deleted` tinyint(1) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `companies` (`id`, `name`, `description`, `is_public`, `allow_editors`, `allow_employees`, `deleted`, `deleted_at`, `logo`) VALUES
(1, 'NSCAR', 'Тестовая компания', 1, 1, 1, 0, NULL, '/uploads/logos/logo_1.jpg'),
(2, 'NSCAR', 'Видеонаблюдение для транспорта', 1, 1, 1, 1, '2026-07-05 21:44:10', '/uploads/logos/logo_2.png'),
(3, 'Риксет', '', 1, 0, 0, 1, '2026-07-05 20:27:11', NULL),
(4, '545', '', 0, 0, 0, 1, '2026-07-05 20:26:22', NULL),
(5, 'жоп', '', 1, 0, 0, 1, '2026-07-05 21:45:26', NULL),
(6, 'RIXET', '', 1, 0, 0, 0, NULL, NULL);

-- ==========================================================
-- ТАБЛИЦА: favorites
-- ==========================================================
CREATE TABLE `favorites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_favorite` (`user_id`, `section_id`),
  KEY `section_id` (`section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `favorites` (`id`, `user_id`, `section_id`, `created_at`) VALUES
(12, 1, 1, '2026-07-12 13:24:43');

-- ==========================================================
-- ТАБЛИЦА: files
-- ==========================================================
CREATE TABLE `files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `folder` varchar(255) NOT NULL DEFAULT '',
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `filepath` varchar(500) NOT NULL,
  `size` int(11) NOT NULL,
  `version` int(11) DEFAULT 1,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`),
  KEY `folder` (`folder`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `files` (`id`, `company_id`, `folder`, `filename`, `original_name`, `filepath`, `size`, `version`, `uploaded_at`, `uploaded_by`) VALUES
(1, 1, '', 'габариты мониторов.docx', 'габариты мониторов.docx', '/uploads/files/company_1/габариты мониторов.docx', 14820, 1, '2026-07-11 20:26:01', 1),
(2, 1, '', 'Тех.паспорт сенсорный монитор 4 канала 4G GPS.docx', 'Тех.паспорт сенсорный монитор 4 канала 4G GPS.docx', '/uploads/files/company_1/Тех.паспорт сенсорный монитор 4 канала 4G GPS.docx', 1678841, 1, '2026-07-11 20:27:00', 1),
(3, 1, '', 'Доп.инфа.docx', 'Доп.инфа.docx', '/uploads/files/company_1/Доп.инфа.docx', 12994, 1, '2026-07-11 20:28:12', 1);

-- ==========================================================
-- ТАБЛИЦА: logs
-- ==========================================================
CREATE TABLE `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `old_data` text DEFAULT NULL,
  `new_data` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `logs` (`id`, `user_id`, `action`, `details`, `old_data`, `new_data`, `ip`, `created_at`) VALUES
(1, 1, 'delete_user', 'Удалён пользователь ID 3854', '', '', '127.0.0.1', '2026-07-05 18:34:55'),
(2, 1, 'edit_profile', 'Обновлён профиль администратора', '', '', '127.0.0.1', '2026-07-05 18:36:31'),
(3, 1, 'edit_profile', 'Обновлён профиль пользователя', '', '', '127.0.0.1', '2026-07-05 19:28:13'),
(4, 1, 'edit_user', 'Отредактирован пользователь 1231231123 (ID: 7446)', '', '', '127.0.0.1', '2026-07-05 19:45:13'),
(5, 1, 'edit_user', 'Отредактирован пользователь 1231231123 (ID: 7446)', '', '', '127.0.0.1', '2026-07-05 19:46:08'),
(6, 1, 'add_user', 'Создан пользователь 131231 (ID: 7257)', '', '', '127.0.0.1', '2026-07-05 19:54:34'),
(7, 1, 'edit_user', 'Отредактирован пользователь 1231231123 (ID: 7446)', '', '', '127.0.0.1', '2026-07-05 19:54:46'),
(8, 1, 'add_company', 'Создана компания: Риксет (ID: 3)', '', '', '127.0.0.1', '2026-07-05 19:56:46'),
(9, 1, 'edit_user', 'Отредактирован пользователь 131231 (ID: 7257)', '', '', '127.0.0.1', '2026-07-05 20:10:54'),
(10, 1, 'add_company', 'Создана компания: 545 (ID: 4)', '', '', '127.0.0.1', '2026-07-05 20:18:26'),
(11, 1, 'edit_user', 'Отредактирован пользователь 131231 (ID: 7257)', '', '', '127.0.0.1', '2026-07-05 20:34:59'),
(12, 1, 'edit_user', 'Отредактирован пользователь 131231 (ID: 7257)', '', '', '127.0.0.1', '2026-07-05 21:04:50'),
(13, 1, 'edit_user', 'Отредактирован пользователь 1231231123 (ID: 7446)', '', '', '127.0.0.1', '2026-07-05 21:05:06'),
(14, 1, 'mass_delete_user', 'Массовое удаление пользователя ID 7257', '', '', '127.0.0.1', '2026-07-05 21:05:25'),
(15, 1, 'edit_user', 'Отредактирован пользователь 1231231123 (ID: 7446)', '', '', '127.0.0.1', '2026-07-05 21:05:55'),
(16, 1, 'edit_profile', 'Обновлён профиль пользователя', '', '', '127.0.0.1', '2026-07-05 21:10:04'),
(17, 1, 'add_company', 'Создана компания: жоп (ID: 5)', '', '', '127.0.0.1', '2026-07-05 21:43:32'),
(18, 1, 'add_company', 'Создана компания: 1231 (ID: 6)', '', '', '127.0.0.1', '2026-07-05 21:44:43'),
(19, 1, 'upload_file', 'Загружен файл габариты мониторов.docx в компанию ID 1', '', '', '127.0.0.1', '2026-07-11 20:26:01'),
(20, 1, 'upload_file', 'Загружен файл Тех.паспорт сенсорный монитор 4 канала 4G GPS.docx в компанию ID 1', '', '', '127.0.0.1', '2026-07-11 20:27:00'),
(21, 1, 'upload_file', 'Загружен файл Доп.инфа.docx в компанию ID 1', '', '', '127.0.0.1', '2026-07-11 20:28:12');

-- ==========================================================
-- ТАБЛИЦА: login_attempts (НОВАЯ — для rate limiting)
-- ==========================================================
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `login` varchar(50) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ip` (`ip`),
  KEY `attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- ТАБЛИЦА: sections (ИСПРАВЛЕНО: добавлен FULLTEXT-индекс)
-- ==========================================================
CREATE TABLE `sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `content` longtext DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `is_published` tinyint(1) DEFAULT 0,
  `views_count` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`),
  KEY `parent_id` (`parent_id`),
  FULLTEXT KEY `ft_search` (`title`, `content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `sections` (`id`, `company_id`, `parent_id`, `title`, `content`, `is_public`, `sort_order`, `is_published`, `views_count`) VALUES
(1, 1, NULL, 'Добро пожаловать в базу знаний NSCAR', '<p>Содержимое отсутствует.</p>', 1, 0, 1, 3),
(2, 1, NULL, '123', NULL, 1, 0, 1, 2),
(3, 1, 2, '1234', '<p>Тестовое содержимое</p>', 1, 0, 1, 13);

-- ==========================================================
-- ТАБЛИЦА: section_access
-- ==========================================================
CREATE TABLE `section_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `role` enum('editor','employee','guest') NOT NULL,
  `access_type` enum('allow','deny','except') DEFAULT 'allow',
  `except_users` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_access` (`section_id`, `role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `section_access` (`id`, `section_id`, `role`, `access_type`, `except_users`) VALUES
(55, 1, 'editor', 'deny', NULL),
(56, 1, 'employee', 'deny', NULL),
(57, 1, 'guest', 'allow', NULL),
(91, 3, 'editor', 'allow', NULL),
(92, 3, 'employee', 'allow', NULL),
(93, 3, 'guest', 'allow', NULL);

-- ==========================================================
-- ТАБЛИЦА: users (ИСПРАВЛЕНО: добавлен AUTO_INCREMENT)
-- ==========================================================
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','editor','employee','guest') DEFAULT 'guest',
  `full_name` varchar(255) DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `deleted` tinyint(1) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `backup_email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `login`, `password`, `role`, `full_name`, `last_activity`, `position`, `avatar`, `deleted`, `deleted_at`, `email`, `backup_email`, `phone`, `two_factor_enabled`) VALUES
(1, 'admin', '$2y$10$yqLaPIY1mpjVZ3yp7FOSruUtCn6iVZwxFpxeS/K0ZriHgRMf3mzry', 'admin', 'Раменский К.С', '2026-07-11 20:54:29', 'ADMIN', '/uploads/avatars/avatar_1783275001_3364.png', 0, NULL, 'tioliws@gmail.com', '', '9229286087', 0),
(7446, '1231231123', '$2y$10$66B6W/C147DF0V7cVKDD.uE143Yv2qt3y1DTQ2u9c9WzqKXnlr1YW', 'editor', 'Раменский Константин Сергеевич', NULL, 'Чертила', '', 0, NULL, '', '', '', 0);

-- ==========================================================
-- ТАБЛИЦА: user_section_access
-- ==========================================================
CREATE TABLE `user_section_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_access` (`user_id`, `section_id`),
  KEY `section_id` (`section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- ТАБЛИЦА: user_tokens
-- ==========================================================
CREATE TABLE `user_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- ТАБЛИЦА: verification_codes
-- ==========================================================
CREATE TABLE `verification_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `type` enum('2fa','recover','change_password') NOT NULL,
  `sent_to` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- ТАБЛИЦА: view_log
-- ==========================================================
CREATE TABLE `view_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `session_id` varchar(255) NOT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `section_id` (`section_id`, `session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `view_log` (`id`, `section_id`, `session_id`, `viewed_at`) VALUES
(1, 1, 'qnkjsls1hhdctlgdutk21n1ugk', '2026-07-06 21:58:26'),
(2, 3, 'qnkjsls1hhdctlgdutk21n1ugk', '2026-07-06 21:58:28');

-- ==========================================================
-- ТАБЛИЦА: visitors
-- ==========================================================
CREATE TABLE `visitors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `visitor_ip` varchar(45) NOT NULL,
  `visit_date` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_visitor` (`visitor_ip`, `visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================
-- ВНЕШНИЕ КЛЮЧИ
-- ==========================================================
ALTER TABLE `access`
  ADD CONSTRAINT `access_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `access_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

ALTER TABLE `favorites`
  ADD CONSTRAINT `favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `favorites_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE;

ALTER TABLE `files`
  ADD CONSTRAINT `files_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `files_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `logs`
  ADD CONSTRAINT `logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `sections`
  ADD CONSTRAINT `sections_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sections_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE;

ALTER TABLE `section_access`
  ADD CONSTRAINT `section_access_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE;

ALTER TABLE `user_section_access`
  ADD CONSTRAINT `user_section_access_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_section_access_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE;

ALTER TABLE `user_tokens`
  ADD CONSTRAINT `user_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `verification_codes`
  ADD CONSTRAINT `verification_codes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;