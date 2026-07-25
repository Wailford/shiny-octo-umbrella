-- Migration: add_notification_log.sql
-- Tracks per-student SMS/notification sends so that a retried batch
-- skips students who already received their notification in the same term.

CREATE TABLE IF NOT EXISTS notification_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id    INT NOT NULL,
    student_id   INT NOT NULL,
    class_id     INT NOT NULL,
    term         VARCHAR(50)  NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    channel      VARCHAR(20)  NOT NULL DEFAULT 'sms',
    sent_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Prevent duplicate sends for the same student/term/year/channel
    UNIQUE KEY uq_student_term_channel (school_id, student_id, term, academic_year, channel),
    INDEX idx_school_class (school_id, class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
