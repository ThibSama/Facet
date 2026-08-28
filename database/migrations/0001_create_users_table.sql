-- Accounts that can sign in to Facet.
--
-- Two roles exist and no more: an `admin` maintains the site, a `client` is
-- given access to their own work. The set is closed at the database level
-- rather than in application code, so a typo or an unmigrated deploy cannot
-- invent a third one.
--
-- `email` is stored already normalised (trimmed and lowercased by
-- Facet\Support\EmailAddress). The CHECK below is what makes that a guarantee
-- rather than a convention: a row that skipped normalisation is rejected, so
-- the UNIQUE index cannot be defeated by differing case.

CREATE TABLE users (
    id             BIGINT UNSIGNED               NOT NULL AUTO_INCREMENT,
    email          VARCHAR(254)                  NOT NULL,
    password_hash  VARCHAR(255)                  NOT NULL,
    role           ENUM('admin', 'client')       NOT NULL DEFAULT 'client',
    status         ENUM('active', 'disabled')    NOT NULL DEFAULT 'active',
    created_at     DATETIME                      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME                      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                 ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- One account per address. 254 is the RFC 5321 maximum for a path.
    UNIQUE KEY uniq_users_email (email),

    -- Supports the only listing this table needs so far: administrators, or
    -- the active members of a role.
    KEY idx_users_role_status (role, status),

    -- `COLLATE utf8mb4_bin` is load-bearing. Under the table's own
    -- case-insensitive collation, `email = LOWER(email)` is true for every
    -- value and the constraint silently enforces nothing; comparing under a
    -- binary collation is what makes it detect stored mixed case.
    CONSTRAINT chk_users_email_normalised
        CHECK (email COLLATE utf8mb4_bin = LOWER(email) AND email LIKE '%_@_%'),

    -- A bcrypt/argon digest is far longer than this; the bound only rules out
    -- an empty column or a plaintext password stored by mistake.
    CONSTRAINT chk_users_password_hash_present
        CHECK (CHAR_LENGTH(password_hash) >= 20)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
