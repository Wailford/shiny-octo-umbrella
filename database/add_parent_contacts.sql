-- ============================================================
-- Parent Contacts & Parent-Student Relationship Tables
-- ============================================================

-- Store parent / guardian contact information per school
CREATE TABLE IF NOT EXISTS `parent_contacts` (
    `id`               INT(11)       NOT NULL AUTO_INCREMENT,
    `school_id`        INT(11)       NOT NULL,
    `full_name`        VARCHAR(255)  NOT NULL,
    `relationship`     VARCHAR(50)   NOT NULL DEFAULT 'Parent',
    `phone`            VARCHAR(20)   NOT NULL,
    `whatsapp_number`  VARCHAR(20)   DEFAULT NULL COMMENT 'WhatsApp number (leave blank to use phone)',
    `email`            VARCHAR(255)  DEFAULT NULL,
    `notes`            TEXT          DEFAULT NULL,
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_parent_school` (`school_id`),
    CONSTRAINT `fk_parent_school` FOREIGN KEY (`school_id`) REFERENCES `school_info` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link each parent to one or more students (one parent can have multiple children in the school)
CREATE TABLE IF NOT EXISTS `parent_student_links` (
    `id`          INT(11)   NOT NULL AUTO_INCREMENT,
    `parent_id`   INT(11)   NOT NULL,
    `student_id`  INT(11)   NOT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_parent_student` (`parent_id`, `student_id`),
    KEY `idx_psl_student` (`student_id`),
    CONSTRAINT `fk_psl_parent`  FOREIGN KEY (`parent_id`)  REFERENCES `parent_contacts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_psl_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification settings stored in the existing system_settings table
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
    ('zenoph_api_key',           ''),
    ('zenoph_sender_id',         'SCHOOL'),
    ('zenoph_whatsapp_sender',   ''),
    ('notification_email_from',  ''),
    ('notification_email_name',  'School Notification'),
    ('report_base_url',          '');
