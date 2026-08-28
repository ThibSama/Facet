-- Messages submitted through the public contact form.
--
-- The lifecycle is closed and deliberately small: a message arrives `new`, is
-- `read`, and is eventually `archived`. Nothing is deleted by the application,
-- so the table doubles as the record of what was received.
--
-- Lengths are bounded at the column rather than only in the form handler: the
-- database is the last line of defence against an oversized submission, and a
-- TEXT column with no CHECK accepts 64 KB of anything.

CREATE TABLE contact_messages (
    id          BIGINT UNSIGNED                       NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120)                          NOT NULL,
    email       VARCHAR(254)                          NOT NULL,
    subject     VARCHAR(200)                          NOT NULL,
    message     TEXT                                  NOT NULL,
    status      ENUM('new', 'read', 'archived')       NOT NULL DEFAULT 'new',
    created_at  DATETIME                              NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- The inbox view: unread first, newest first.
    KEY idx_contact_messages_status_created (status, created_at),

    -- Finding every message from one correspondent.
    KEY idx_contact_messages_email (email),

    CONSTRAINT chk_contact_messages_name_present
        CHECK (CHAR_LENGTH(TRIM(name)) > 0),

    -- Same normalisation guarantee as `users`, for the same reason: an address
    -- that is stored inconsistently cannot be matched against reliably. The
    -- binary collation is what stops the comparison from being vacuous under
    -- the table's case-insensitive default.
    CONSTRAINT chk_contact_messages_email_normalised
        CHECK (email COLLATE utf8mb4_bin = LOWER(email) AND email LIKE '%_@_%'),

    CONSTRAINT chk_contact_messages_subject_present
        CHECK (CHAR_LENGTH(TRIM(subject)) > 0),

    CONSTRAINT chk_contact_messages_message_bounded
        CHECK (CHAR_LENGTH(message) BETWEEN 1 AND 5000)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
