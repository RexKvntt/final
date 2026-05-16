CREATE TABLE IF NOT EXISTS `calendar_events` (
    `id`          INT          NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(200) NOT NULL,
    `description` TEXT,
    `event_date`  DATE         NOT NULL,
    `class_id`    INT          DEFAULT NULL,
    `created_by`  VARCHAR(100) NOT NULL,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_event_date` (`event_date`),
    KEY `idx_class_id`   (`class_id`),
    KEY `idx_created_by` (`created_by`),
    CONSTRAINT `fk_cal_class`
        FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;