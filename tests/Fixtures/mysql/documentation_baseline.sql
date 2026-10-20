-- Controlled pre-migration schema for the isolated MySQL 8.4.3 documentation validation database.
-- This is a fixture, not an application migration. It intentionally omits section_key,
-- pending-review functional indexes, and draft tables, which are introduced by the migration under test.
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_name (name),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY ix_users_role_id (role_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT NULL,
    repository_url VARCHAR(255) NULL,
    status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
    owner_user_id BIGINT UNSIGNED NOT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_projects_slug (slug),
    KEY ix_projects_owner_user_id (owner_user_id),
    CONSTRAINT fk_projects_owner FOREIGN KEY (owner_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_project_slug (project_id, slug),
    KEY ix_categories_project_id (project_id),
    CONSTRAINT fk_categories_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(200) NOT NULL,
    current_version_id BIGINT UNSIGNED NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documents_project_slug (project_id, slug),
    KEY ix_documents_current_pair (current_version_id, id),
    KEY ix_documents_category_id (category_id),
    CONSTRAINT fk_documents_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_documents_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE document_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    content_markdown MEDIUMTEXT NOT NULL,
    changelog TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_document_versions_number (document_id, version_number),
    UNIQUE KEY uq_document_versions_pair (id, document_id),
    KEY ix_document_versions_created_by (created_by_user_id),
    CONSTRAINT fk_document_versions_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
    CONSTRAINT fk_document_versions_user FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE documents
    ADD CONSTRAINT fk_documents_current_version_pair
    FOREIGN KEY (current_version_id, id) REFERENCES document_versions (id, document_id);

CREATE TABLE document_change_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    proposed_version_number INT UNSIGNED NOT NULL,
    proposed_title VARCHAR(200) NOT NULL,
    proposed_content MEDIUMTEXT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    author_user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY ix_dcr_document_status (document_id, status),
    KEY ix_dcr_author_user (author_user_id),
    CONSTRAINT fk_dcr_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
    CONSTRAINT fk_dcr_author FOREIGN KEY (author_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE document_change_request_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    change_request_id BIGINT UNSIGNED NOT NULL,
    decided_by_user_id BIGINT UNSIGNED NOT NULL,
    decision ENUM('approved','rejected') NOT NULL,
    comments TEXT NOT NULL,
    decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_dcrd_change_request (change_request_id),
    KEY ix_dcrd_decided_by (decided_by_user_id),
    CONSTRAINT fk_dcrd_request FOREIGN KEY (change_request_id) REFERENCES document_change_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_dcrd_user FOREIGN KEY (decided_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE technical_contexts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL,
    description TEXT NULL,
    current_version_id BIGINT UNSIGNED NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_technical_contexts_project_slug (project_id, slug),
    KEY ix_technical_contexts_current_pair (current_version_id, id),
    CONSTRAINT fk_technical_contexts_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE technical_context_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    technical_context_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    architecture_summary MEDIUMTEXT NOT NULL,
    tech_stack_json JSON NULL,
    conventions_markdown MEDIUMTEXT NULL,
    changelog TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_context_versions_number (technical_context_id, version_number),
    UNIQUE KEY uq_context_versions_pair (id, technical_context_id),
    KEY ix_context_versions_created_by (created_by_user_id),
    CONSTRAINT fk_context_versions_context FOREIGN KEY (technical_context_id) REFERENCES technical_contexts (id) ON DELETE CASCADE,
    CONSTRAINT fk_context_versions_user FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE technical_contexts
    ADD CONSTRAINT fk_technical_contexts_current_version_pair
    FOREIGN KEY (current_version_id, id) REFERENCES technical_context_versions (id, technical_context_id);

CREATE TABLE technical_context_change_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    technical_context_id BIGINT UNSIGNED NOT NULL,
    proposed_version_number INT UNSIGNED NOT NULL,
    proposed_architecture_summary MEDIUMTEXT NOT NULL,
    proposed_tech_stack_json JSON NULL,
    proposed_conventions_markdown MEDIUMTEXT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    author_user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY ix_tccr_context_status (technical_context_id, status),
    KEY ix_tccr_author_user (author_user_id),
    CONSTRAINT fk_tccr_context FOREIGN KEY (technical_context_id) REFERENCES technical_contexts (id) ON DELETE CASCADE,
    CONSTRAINT fk_tccr_author FOREIGN KEY (author_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE technical_context_change_request_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    change_request_id BIGINT UNSIGNED NOT NULL,
    decided_by_user_id BIGINT UNSIGNED NOT NULL,
    decision ENUM('approved','rejected') NOT NULL,
    comments TEXT NOT NULL,
    decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_tccrd_change_request (change_request_id),
    KEY ix_tccrd_decided_by (decided_by_user_id),
    CONSTRAINT fk_tccrd_request FOREIGN KEY (change_request_id) REFERENCES technical_context_change_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_tccrd_user FOREIGN KEY (decided_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
