-- 1) Create database
CREATE DATABASE IF NOT EXISTS `attandance_utm`
  CHARACTER SET = utf8mb4
  COLLATE = utf8mb4_general_ci;
USE `attandance_utm`;

-- 2) Groups
CREATE TABLE IF NOT EXISTS `groups` (
  `id`   INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(10) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) Users  (adds `subgroup` to match current DB)
CREATE TABLE IF NOT EXISTS `users` (
  `id`       INT(11) NOT NULL AUTO_INCREMENT,
  `tg_id`    BIGINT(20) NOT NULL,
  `name`     VARCHAR(100) NOT NULL,
  `role`     ENUM('admin','moderator','monitor','student') NOT NULL DEFAULT 'student',
  `group_id` INT(11) NOT NULL,
  `subgroup` TINYINT(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tg_id` (`tg_id`),
  KEY `group_id` (`group_id`),
  CONSTRAINT `users_ibfk_1`
    FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4) Schedule  (adds `week_type`, `subgroup`, `semester` to match current DB)
CREATE TABLE IF NOT EXISTS `schedule` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `group_id`    INT(11) NOT NULL,
  `day_of_week` ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `time_slot`   VARCHAR(11) NOT NULL,
  `subject`     VARCHAR(50) NOT NULL,
  `location`    VARCHAR(20) DEFAULT NULL,
  `type`        ENUM('curs','sem','lab') DEFAULT NULL,
  `week_type`   ENUM('odd','even') DEFAULT NULL,
  `subgroup`    TINYINT(1) DEFAULT NULL,
  `semester`    TINYINT(3) UNSIGNED NOT NULL DEFAULT 2,
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  CONSTRAINT `schedule_ibfk_1`
    FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5) Attendance
CREATE TABLE IF NOT EXISTS `attendance` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11) NOT NULL,
  `schedule_id` INT(11) NOT NULL,
  `date`        DATE NOT NULL,
  `present`     TINYINT(1) DEFAULT 0,
  `motivated`   TINYINT(1) NOT NULL DEFAULT 0,
  `motivation`  TEXT DEFAULT NULL,
  `marked_by`   INT(11) NOT NULL,
  `updated_at`  DATETIME DEFAULT NULL,
  `updated_by`  INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `schedule_id` (`schedule_id`),
  KEY `marked_by` (`marked_by`),
  KEY `attendance_updated_by_fk` (`updated_by`),
  CONSTRAINT `attendance_ibfk_1`
    FOREIGN KEY (`user_id`)     REFERENCES `users`    (`id`),
  CONSTRAINT `attendance_ibfk_2`
    FOREIGN KEY (`schedule_id`) REFERENCES `schedule` (`id`),
  CONSTRAINT `attendance_ibfk_3`
    FOREIGN KEY (`marked_by`)   REFERENCES `users`    (`id`),
  CONSTRAINT `attendance_updated_by_fk`
    FOREIGN KEY (`updated_by`)  REFERENCES `users`    (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6) Attendance log
CREATE TABLE IF NOT EXISTS `attendance_log` (
  `id`             INT(11) NOT NULL AUTO_INCREMENT,
  `attendance_id`  INT(11) NOT NULL,
  `changed_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  `changed_by`     INT(11) NOT NULL,
  `old_present`    TINYINT(1) DEFAULT NULL,
  `new_present`    TINYINT(1) DEFAULT NULL,
  `old_motivated`  TINYINT(1) DEFAULT NULL,
  `new_motivated`  TINYINT(1) DEFAULT NULL,
  `old_motivation` TEXT DEFAULT NULL,
  `new_motivation` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendance_id` (`attendance_id`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `attendance_log_ibfk_1`
    FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`),
  CONSTRAINT `attendance_log_ibfk_2`
    FOREIGN KEY (`changed_by`)    REFERENCES `users`     (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
