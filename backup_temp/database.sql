-- Дамп базы данных knowledge_base
-- Дата: 2026-07-11 19:54:31

DROP TABLE IF EXISTS `access`;
CREATE TABLE `access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `action` enum('read','write','delete') DEFAULT 'read',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `access_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `access_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=128 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT INTO `access` VALUES ('3','1','1','delete');
INSERT INTO `access` VALUES ('89','1','3','write');
INSERT INTO `access` VALUES ('91','1','3','read');
INSERT INTO `access` VALUES ('111','7257','2','read');
INSERT INTO `access` VALUES ('114','7446','2','write');
INSERT INTO `access` VALUES ('115','7446','1','write');
INSERT INTO `access` VALUES ('117','1','1','read');
INSERT INTO `access` VALUES ('122','1','5','write');
INSERT INTO `access` VALUES ('123','1','5','read');
INSERT INTO `access` VALUES ('125','1','1','write');
INSERT INTO `access` VALUES ('126','1','6','write');
INSERT INTO `access` VALUES ('127','1','6','read');

DROP TABLE IF EXISTS `backups`;
CREATE TABLE `backups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `filesize` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;


DROP TABLE IF EXISTS `companies`;
CREATE TABLE `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `deleted` tinyint(1) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT INTO `companies` VALUES ('1','NSCAR','Тестовая компания','1','0',NULL,'/uploads/logos/logo_1.jpg');
INSERT INTO `companies` VALUES ('2','NSCAR','Видеонаблюдение для транспорта','1','1','2026-07-05 21:44:10','/uploads/logos/logo_2.png');
INSERT INTO `companies` VALUES ('3','Риксет','','1','1','2026-07-05 20:27:11',NULL);
INSERT INTO `companies` VALUES ('4','545','','0','1','2026-07-05 20:26:22',NULL);
INSERT INTO `companies` VALUES ('5','жоп','','1','1','2026-07-05 21:45:26',NULL);
INSERT INTO `companies` VALUES ('6','RIXET','','1','0',NULL,NULL);

DROP TABLE IF EXISTS `favorites`;
CREATE TABLE `favorites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_favorite` (`user_id`,`section_id`),
  KEY `section_id` (`section_id`),
  CONSTRAINT `favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `favorites_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;


DROP TABLE IF EXISTS `files`;
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
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `files_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `files_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT INTO `files` VALUES ('1','1','','габариты мониторов.docx','габариты мониторов.docx','/uploads/files/company_1/габариты мониторов.docx','14820','1','2026-07-11 20:26:01','1');
INSERT INTO `files` VALUES ('2','1','','Тех.паспорт сенсорный монитор 4 канала 4G GPS.docx','Тех.паспорт сенсорный монитор 4 канала 4G GPS.docx','/uploads/files/company_1/Тех.паспорт сенсорный монитор 4 канала 4G GPS.docx','1678841','1','2026-07-11 20:27:00','1');
INSERT INTO `files` VALUES ('3','1','','Доп.инфа.docx','Доп.инфа.docx','/uploads/files/company_1/Доп.инфа.docx','12994','1','2026-07-11 20:28:12','1');

DROP TABLE IF EXISTS `logs`;
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
  KEY `user_id` (`user_id`),
  CONSTRAINT `logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT INTO `logs` VALUES ('1','1','delete_user','Удалён пользователь ID 3854','','','127.0.0.1','2026-07-05 18:34:55');
INSERT INTO `logs` VALUES ('2','1','edit_profile','Обновлён профиль администратора','','','127.0.0.1','2026-07-05 18:36:31');
INSERT INTO `logs` VALUES ('3','1','edit_profile','Обновлён профиль пользователя','','','127.0.0.1','2026-07-05 19:28:13');
INSERT INTO `logs` VALUES ('4','1','edit_user','Отредактирован пользователь 1231231123 (ID: 7446)','','','127.0.0.1','2026-07-05 19:45:13');
INSERT INTO `logs` VALUES ('5','1','edit_user','Отредактирован пользователь 1231231123 (ID: 7446)','','','127.0.0.1','2026-07-05 19:46:08');
INSERT INTO `logs` VALUES ('6','1','add_user','Создан пользователь 131231 (ID: 7257)','','','127.0.0.1','2026-07-05 19:54:34');
INSERT INTO `logs` VALUES ('7','1','edit_user','Отредактирован пользователь 1231231123 (ID: 7446)','','','127.0.0.1','2026-07-05 19:54:46');
INSERT INTO `logs` VALUES ('8','1','add_company','Создана компания: Риксет (ID: 3)','','','127.0.0.1','2026-07-05 19:56:46');
INSERT INTO `logs` VALUES ('9','1','edit_user','Отредактирован пользователь 131231 (ID: 7257)','','','127.0.0.1','2026-07-05 20:10:54');
INSERT INTO `logs` VALUES ('10','1','add_company','Создана компания: 545 (ID: 4)','','','127.0.0.1','2026-07-05 20:18:26');
INSERT INTO `logs` VALUES ('11','1','edit_user','Отредактирован пользователь 131231 (ID: 7257)','','','127.0.0.1','2026-07-05 20:34:59');
INSERT INTO `logs` VALUES ('12','1','edit_user','Отредактирован пользователь 131231 (ID: 7257)','','','127.0.0.1','2026-07-05 21:04:50');
INSERT INTO `logs` VALUES ('13','1','edit_user','Отредактирован пользователь 1231231123 (ID: 7446)','','','127.0.0.1','2026-07-05 21:05:06');
INSERT INTO `logs` VALUES ('14','1','mass_delete_user','Массовое удаление пользователя ID 7257','','','127.0.0.1','2026-07-05 21:05:25');
INSERT INTO `logs` VALUES ('15','1','edit_user','Отредактирован пользователь 1231231123 (ID: 7446)','','','127.0.0.1','2026-07-05 21:05:55');
INSERT INTO `logs` VALUES ('16','1','edit_profile','Обновлён профиль пользователя','','','127.0.0.1','2026-07-05 21:10:04');
INSERT INTO `logs` VALUES ('17','1','add_company','Создана компания: жоп (ID: 5)','','','127.0.0.1','2026-07-05 21:43:32');
INSERT INTO `logs` VALUES ('18','1','add_company','Создана компания: 1231 (ID: 6)','','','127.0.0.1','2026-07-05 21:44:43');
INSERT INTO `logs` VALUES ('19','1','upload_file','Загружен файл габариты мониторов.docx в компанию ID 1','','','127.0.0.1','2026-07-11 20:26:01');
INSERT INTO `logs` VALUES ('20','1','upload_file','Загружен файл Тех.паспорт сенсорный монитор 4 канала 4G GPS.docx в компанию ID 1','','','127.0.0.1','2026-07-11 20:27:00');
INSERT INTO `logs` VALUES ('21','1','upload_file','Загружен файл Доп.инфа.docx в компанию ID 1','','','127.0.0.1','2026-07-11 20:28:12');

DROP TABLE IF EXISTS `section_access`;
CREATE TABLE `section_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `role` enum('editor','employee','guest') NOT NULL,
  `access_type` enum('allow','deny','except') DEFAULT 'allow',
  `except_users` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_access` (`section_id`,`role`),
  CONSTRAINT `section_access_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT INTO `section_access` VALUES ('55','1','editor','deny',NULL);
INSERT INTO `section_access` VALUES ('56','1','employee','deny',NULL);
INSERT INTO `section_access` VALUES ('57','1','guest','allow',NULL);
INSERT INTO `section_access` VALUES ('91','3','editor','allow',NULL);
INSERT INTO `section_access` VALUES ('92','3','employee','allow',NULL);
INSERT INTO `section_access` VALUES ('93','3','guest','allow',NULL);

DROP TABLE IF EXISTS `sections`;
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
  CONSTRAINT `sections_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sections_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT INTO `sections` VALUES ('1','1',NULL,'Добро пожаловать в базу знаний NSCAR','\r\n                <p>Содержимое отсутствует.</p>                <div class=\"view-counter\" id=\"view-counter\">Просмотров: 0</div>\r\n            ','1','0','1','3');
INSERT INTO `sections` VALUES ('2','1',NULL,'123',NULL,'1','0','1','2');
INSERT INTO `sections` VALUES ('3','1','2','1234','<p><font size=\"4\">Технический паспорт и<font color=\"#ff0000\"> руководство пользования на р1ег --&nbsp;&nbsp;</font></font><a href=\"view.php?company=1&amp;section=1\" target=\"_blank\" style=\"font-family: inherit;\"><span style=\"font-size: 72px;\"><span style=\"font-size: 36px;\"><span style=\"font-size: 48px;\">жопа</span></span></span></a></p><p><br></p><p><a href=\"https://lk.nscar.online/808gps/login.html\" target=\"_blank\">https://lk.nscar.online/808gps/login.html</a></p><p><br></p><p><a href=\"https://lk.nscar.online/808gps/login.html\" target=\"_blank\">жопа2</a></p><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><table class=\"editable-table\" style=\"width: 100%; border: 1px solid rgb(204, 204, 204); position: relative; left: 28.6364px; top: 229.787px; cursor: grab;\"><tbody><tr><td contenteditable=\"false\"><span contenteditable=\"true\">прпвпв</span><div class=\"resize-handle\" style=\"width: 8px; height: 8px; cursor: se-resize;\"></div></td><td contenteditable=\"false\"><span contenteditable=\"true\">пвапва</span><div class=\"resize-handle\" style=\"width: 8px; height: 8px; cursor: se-resize;\"></div></td><td contenteditable=\"false\"><span contenteditable=\"true\">пвапва</span><div class=\"resize-handle\" style=\"width: 8px; height: 8px; cursor: se-resize;\"></div></td></tr><tr><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 8px; height: 8px; cursor: se-resize;\"></div></td><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 8px; height: 8px; cursor: se-resize;\"></div></td><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 8px; height: 8px; cursor: se-resize;\"></div></td></tr><tr><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 8px; height: 8px; cursor: se-resize;\"></div></td><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 8px; height: 8px; cursor: se-resize;\"></div></td><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 8px; height: 8px; cursor: se-resize;\"></div></td></tr></tbody></table><p><br></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><p><font size=\"4\"><br></font></p><table class=\"editable-table\" style=\"border: 1px solid rgb(204, 204, 204); position: relative; cursor: grab; left: 0px; top: 0px;\"><tbody><tr><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 10px; height: 10px; position: absolute; bottom: 0px; right: 0px; cursor: nwse-resize; background: rgba(0, 0, 0, 0.05);\"></div><div class=\"drag-handle\"></div></td><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 10px; height: 10px; position: absolute; bottom: 0px; right: 0px; cursor: nwse-resize; background: rgba(0, 0, 0, 0.05);\"></div><div class=\"drag-handle\"></div></td><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 10px; height: 10px; position: absolute; bottom: 0px; right: 0px; cursor: nwse-resize; background: rgba(0, 0, 0, 0.05);\"></div><div class=\"drag-handle\"></div></td></tr><tr><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 10px; height: 10px; position: absolute; bottom: 0px; right: 0px; cursor: nwse-resize; background: rgba(0, 0, 0, 0.05);\"></div><div class=\"drag-handle\"></div></td><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 10px; height: 10px; position: absolute; bottom: 0px; right: 0px; cursor: nwse-resize; background: rgba(0, 0, 0, 0.05);\"></div><div class=\"drag-handle\"></div></td><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 10px; height: 10px; position: absolute; bottom: 0px; right: 0px; cursor: nwse-resize; background: rgba(0, 0, 0, 0.05);\"></div><div class=\"drag-handle\"></div></td></tr><tr><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 10px; height: 10px; position: absolute; bottom: 0px; right: 0px; cursor: nwse-resize; background: rgba(0, 0, 0, 0.05);\"></div><div class=\"drag-handle\"></div></td><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 10px; height: 10px; position: absolute; bottom: 0px; right: 0px; cursor: nwse-resize; background: rgba(0, 0, 0, 0.05);\"></div><div class=\"drag-handle\"></div></td><td contenteditable=\"false\"><span contenteditable=\"true\"> </span><div class=\"resize-handle\" style=\"width: 10px; height: 10px; position: absolute; bottom: 0px; right: 0px; cursor: nwse-resize; background: rgba(0, 0, 0, 0.05);\"></div><div class=\"drag-handle\"></div></td></tr></tbody></table><div class=\"view-counter\">Просмотров: 0</div><div class=\"view-counter\">Просмотров: 0</div>','1','0','1','13');

DROP TABLE IF EXISTS `user_section_access`;
CREATE TABLE `user_section_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_access` (`user_id`,`section_id`),
  KEY `section_id` (`section_id`),
  CONSTRAINT `user_section_access_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_section_access_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;


DROP TABLE IF EXISTS `user_tokens`;
CREATE TABLE `user_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;


DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT INTO `users` VALUES ('1','admin','$2y$10$yqLaPIY1mpjVZ3yp7FOSruUtCn6iVZwxFpxeS/K0ZriHgRMf3mzry','admin','Раменский К.С','2026-07-11 20:54:29','ADMIN','/uploads/avatars/avatar_1783275001_3364.png','0',NULL,'tioliws@gmail.com','','9229286087','0');
INSERT INTO `users` VALUES ('3314','31231231','$2y$10$KO12LsKCOaeM94HxxV4G1us677EYmnlwhrbgtclVNfVZBnrB58ZZa','employee','',NULL,'','','1','2026-07-05 15:53:18',NULL,NULL,NULL,'0');
INSERT INTO `users` VALUES ('3854','31231231467','$2y$10$iNdSA26bMoRxXHjGfmk4z.moB/TYHNG3yv5JkDYX1xvaHsZOWpWX2','editor','Раменский Константин Сергеевич',NULL,'Чертила','','1','2026-07-05 18:34:55',NULL,NULL,NULL,'0');
INSERT INTO `users` VALUES ('5077','312312314','$2y$10$gI16FCu0tqQKSc9jLlfKrusKh8cgWiYUtX7dlFSvgpha8z7LqSpfO','editor','3333333333333333333',NULL,'','','1','2026-07-05 01:29:48',NULL,NULL,NULL,'0');
INSERT INTO `users` VALUES ('5278','gdfgdgdg3','$2y$10$GsKiMBZdvsIXM8iO5rPrjueVSIJy6QejQ5s4hLtjLC9X6dCMJp.WC','employee','',NULL,'','','1','2026-07-05 01:36:16',NULL,NULL,NULL,'0');
INSERT INTO `users` VALUES ('7257','131231','$2y$10$V.oUJ7QnRu0KtMj.kkbsXuInDiI39HpBhLWRFPCycETPWEnq72pyK','employee','',NULL,'','','1','2026-07-05 21:05:25','','','','0');
INSERT INTO `users` VALUES ('7446','1231231123','$2y$10$66B6W/C147DF0V7cVKDD.uE143Yv2qt3y1DTQ2u9c9WzqKXnlr1YW','editor','Раменский Константин Сергеевич',NULL,'Чертила','','0',NULL,'','','','0');
INSERT INTO `users` VALUES ('7547','123141246','$2y$10$2VMJc6PT0UqXuGXSW6./d.R2A7GuokPhzP1iBuEv0tezGg8c/ati.','employee','Федук Константин',NULL,'','','1','2026-07-05 01:55:53',NULL,NULL,NULL,'0');
INSERT INTO `users` VALUES ('8650','12314124','$2y$10$e1IWrDwxKqtTTNvazNJ9MONPyOjaiWzT/rU4hCtCUucrFq2PObtQ.','employee','',NULL,'','','1','2026-07-05 01:49:43',NULL,NULL,NULL,'0');

DROP TABLE IF EXISTS `verification_codes`;
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
  KEY `user_id` (`user_id`),
  CONSTRAINT `verification_codes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;


DROP TABLE IF EXISTS `view_log`;
CREATE TABLE `view_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `session_id` varchar(255) NOT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `section_id` (`section_id`,`session_id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT INTO `view_log` VALUES ('1','1','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 21:58:26');
INSERT INTO `view_log` VALUES ('2','3','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 21:58:28');
INSERT INTO `view_log` VALUES ('3','9','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:19:37');
INSERT INTO `view_log` VALUES ('4','19','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:19:38');
INSERT INTO `view_log` VALUES ('5','12','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:19:38');
INSERT INTO `view_log` VALUES ('6','19','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:30:14');
INSERT INTO `view_log` VALUES ('7','19','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:33:15');
INSERT INTO `view_log` VALUES ('8','1','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:39:53');
INSERT INTO `view_log` VALUES ('9','3','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:39:54');
INSERT INTO `view_log` VALUES ('10','9','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:40:01');
INSERT INTO `view_log` VALUES ('11','19','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:40:16');
INSERT INTO `view_log` VALUES ('12','13','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:41:34');
INSERT INTO `view_log` VALUES ('13','4','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:43:19');
INSERT INTO `view_log` VALUES ('14','3','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:45:14');
INSERT INTO `view_log` VALUES ('15','3','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 22:48:00');
INSERT INTO `view_log` VALUES ('16','3','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 23:14:25');
INSERT INTO `view_log` VALUES ('17','1','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 23:14:43');
INSERT INTO `view_log` VALUES ('18','3','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 23:27:50');
INSERT INTO `view_log` VALUES ('19','3','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 23:33:24');
INSERT INTO `view_log` VALUES ('20','3','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 23:36:49');
INSERT INTO `view_log` VALUES ('21','3','qnkjsls1hhdctlgdutk21n1ugk','2026-07-06 23:37:40');
INSERT INTO `view_log` VALUES ('22','3','tfidga804g2qbousmc7k58m4a3','2026-07-11 18:09:48');
INSERT INTO `view_log` VALUES ('23','2','tfidga804g2qbousmc7k58m4a3','2026-07-11 18:10:50');
INSERT INTO `view_log` VALUES ('24','3','tfidga804g2qbousmc7k58m4a3','2026-07-11 18:10:51');
INSERT INTO `view_log` VALUES ('25','3','tfidga804g2qbousmc7k58m4a3','2026-07-11 20:06:31');
INSERT INTO `view_log` VALUES ('26','2','tfidga804g2qbousmc7k58m4a3','2026-07-11 20:26:20');
INSERT INTO `view_log` VALUES ('27','3','tfidga804g2qbousmc7k58m4a3','2026-07-11 20:26:21');

DROP TABLE IF EXISTS `visitors`;
CREATE TABLE `visitors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `visitor_ip` varchar(45) NOT NULL,
  `visit_date` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_visitor` (`visitor_ip`,`visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;


