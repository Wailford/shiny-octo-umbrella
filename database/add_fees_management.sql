-- ============================================================
-- Fees Management — fee_structures + fee_payments tables
-- Run once against school_management_system database
-- ============================================================

CREATE TABLE IF NOT EXISTS `fee_structures` (
  `id`            int(11)        NOT NULL AUTO_INCREMENT,
  `school_id`     int(11)        NOT NULL,
  `class_id`      int(11)        DEFAULT NULL COMMENT 'NULL = applies to all classes',
  `fee_name`      varchar(120)   NOT NULL,
  `fee_type`      enum('tuition','pta','sports','books','exam','uniform','other') NOT NULL DEFAULT 'tuition',
  `amount`        decimal(10,2)  NOT NULL,
  `term`          enum('1','2','3','all') NOT NULL DEFAULT '1',
  `academic_year` varchar(20)    NOT NULL,
  `is_active`     tinyint(1)     NOT NULL DEFAULT 1,
  `notes`         text           DEFAULT NULL,
  `created_at`    timestamp      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `school_term_year` (`school_id`, `term`, `academic_year`),
  KEY `class_id`         (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fee_payments` (
  `id`               int(11)       NOT NULL AUTO_INCREMENT,
  `school_id`        int(11)       NOT NULL,
  `student_id`       int(11)       NOT NULL,
  `fee_structure_id` int(11)       NOT NULL,
  `amount_paid`      decimal(10,2) NOT NULL,
  `payment_date`     date          NOT NULL,
  `payment_method`   enum('cash','momo','bank_transfer','cheque','other') NOT NULL DEFAULT 'cash',
  `receipt_number`   varchar(60)   NOT NULL,
  `notes`            text          DEFAULT NULL,
  `recorded_by`      int(11)       DEFAULT NULL,
  `sms_sent`         tinyint(1)    NOT NULL DEFAULT 0,
  `sms_status`       varchar(255)  DEFAULT NULL,
  `created_at`       timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `student_fee`     (`student_id`, `fee_structure_id`),
  KEY `school_id`       (`school_id`),
  KEY `receipt_number`  (`receipt_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
