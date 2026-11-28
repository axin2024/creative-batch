CREATE TABLE IF NOT EXISTS campaign_workspaces (
    tenant VARCHAR(63) NOT NULL,
    id CHAR(32) NOT NULL,
    name VARCHAR(160) NOT NULL,
    master_revision INT UNSIGNED NOT NULL DEFAULT 0,
    owner VARCHAR(63) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,id),
    FOREIGN KEY (tenant) REFERENCES studios(tenant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS campaign_masters (
    tenant VARCHAR(63) NOT NULL,
    campaign CHAR(32) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    snapshot JSON NOT NULL,
    digest CHAR(64) NOT NULL,
    actor VARCHAR(63) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,campaign,revision),
    FOREIGN KEY (tenant,campaign) REFERENCES campaign_workspaces(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS campaign_fonts (
    tenant VARCHAR(63) NOT NULL,
    digest CHAR(64) NOT NULL,
    family VARCHAR(160) NOT NULL,
    license VARCHAR(120) NOT NULL,
    evidence VARCHAR(600) NOT NULL,
    bytes MEDIUMBLOB NOT NULL,
    actor VARCHAR(63) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,digest),
    FOREIGN KEY (tenant) REFERENCES studios(tenant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS campaign_terms (
    tenant VARCHAR(63) NOT NULL,
    campaign CHAR(32) NOT NULL,
    locale VARCHAR(24) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    snapshot JSON NOT NULL,
    actor VARCHAR(63) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,campaign,locale,revision),
    FOREIGN KEY (tenant,campaign) REFERENCES campaign_workspaces(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS campaign_localizations (
    tenant VARCHAR(63) NOT NULL,
    campaign CHAR(32) NOT NULL,
    territory CHAR(2) NOT NULL,
    locale VARCHAR(24) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    master_revision INT UNSIGNED NOT NULL,
    term_revision INT UNSIGNED NOT NULL,
    snapshot JSON NOT NULL,
    provenance JSON NOT NULL,
    actor VARCHAR(63) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,campaign,territory,locale,revision),
    FOREIGN KEY (tenant,campaign,master_revision) REFERENCES campaign_masters(tenant,campaign,revision),
    FOREIGN KEY (tenant,campaign,locale,term_revision) REFERENCES campaign_terms(tenant,campaign,locale,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS campaign_renders (
    tenant VARCHAR(63) NOT NULL,
    id CHAR(32) NOT NULL,
    campaign CHAR(32) NOT NULL,
    territory CHAR(2) NOT NULL,
    locale VARCHAR(24) NOT NULL,
    localization_revision INT UNSIGNED NOT NULL,
    channel VARCHAR(63) NOT NULL,
    input_digest CHAR(64) NOT NULL,
    image_digest CHAR(64) NOT NULL,
    evidence JSON NOT NULL,
    actor VARCHAR(63) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,id),
    UNIQUE KEY campaign_render_input (tenant,campaign,input_digest),
    KEY campaign_renders_region (tenant,campaign,territory,locale,channel,created_at),
    FOREIGN KEY (tenant,campaign,territory,locale,localization_revision)
        REFERENCES campaign_localizations(tenant,campaign,territory,locale,revision),
    FOREIGN KEY (tenant,image_digest) REFERENCES images(tenant,digest)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS campaign_grants (
    tenant VARCHAR(63) NOT NULL,
    id VARCHAR(63) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    asset_type ENUM('image','font') NOT NULL,
    source_digest CHAR(64) NOT NULL,
    snapshot JSON NOT NULL,
    valid_from DATETIME(6) NOT NULL,
    valid_until DATETIME(6) NOT NULL,
    actor VARCHAR(63) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    revoked_at DATETIME(6),
    PRIMARY KEY (tenant,id,revision),
    KEY campaign_grants_source(tenant,asset_type,source_digest,valid_until),
    FOREIGN KEY (tenant) REFERENCES studios(tenant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS campaign_approvals (
    tenant VARCHAR(63) NOT NULL,
    render CHAR(32) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    decision ENUM('approved','rejected') NOT NULL,
    rights_rev CHAR(64),
    snapshot JSON NOT NULL,
    actor VARCHAR(63) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,render,revision),
    FOREIGN KEY (tenant,render) REFERENCES campaign_renders(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS campaign_placements (
    tenant VARCHAR(63) NOT NULL,
    id CHAR(32) NOT NULL,
    campaign CHAR(32) NOT NULL,
    master_revision INT UNSIGNED NOT NULL,
    territory CHAR(2) NOT NULL,
    locale VARCHAR(24) NOT NULL,
    channel VARCHAR(63) NOT NULL,
    asset_rev CHAR(32) NOT NULL,
    approval_revision INT UNSIGNED NOT NULL,
    rights_rev CHAR(64) NOT NULL,
    request_key VARCHAR(96) NOT NULL,
    input_digest CHAR(64) NOT NULL,
    manifest_digest CHAR(64) NOT NULL,
    manifest JSON NOT NULL,
    delivery_state ENUM('pending','publishing','uncertain','failed','online','withdrawing','withdrawn','cancelled') NOT NULL,
    active_slot CHAR(64),
    replaces CHAR(32),
    remote_revision VARCHAR(160),
    starts_at DATETIME(6) NOT NULL,
    ends_at DATETIME(6) NOT NULL,
    actor VARCHAR(63) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,id),
    UNIQUE KEY campaign_placement_idempotency(tenant,campaign,request_key),
    UNIQUE KEY campaign_current_slot(tenant,campaign,active_slot),
    KEY campaign_placements_listing(tenant,campaign,created_at,id),
    KEY campaign_placements_expiry(tenant,delivery_state,ends_at,id),
    FOREIGN KEY (tenant,campaign,master_revision) REFERENCES campaign_masters(tenant,campaign,revision),
    FOREIGN KEY (tenant,asset_rev,approval_revision) REFERENCES campaign_approvals(tenant,render,revision),
    FOREIGN KEY (tenant,replaces) REFERENCES campaign_placements(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS campaign_placement_sources (
    tenant VARCHAR(63) NOT NULL,
    placement CHAR(32) NOT NULL,
    asset_type ENUM('image','font') NOT NULL,
    source_digest CHAR(64) NOT NULL,
    grant_id VARCHAR(63) NOT NULL,
    grant_revision INT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant,placement,asset_type,source_digest),
    KEY campaign_placement_source(tenant,asset_type,source_digest,placement),
    KEY campaign_placement_grant(tenant,grant_id,grant_revision,placement),
    FOREIGN KEY (tenant,placement) REFERENCES campaign_placements(tenant,id),
    FOREIGN KEY (tenant,grant_id,grant_revision) REFERENCES campaign_grants(tenant,id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS campaign_dispatch (
    tenant VARCHAR(63) NOT NULL,
    placement CHAR(32) NOT NULL,
    operation ENUM('publish','withdraw') NOT NULL,
    phase ENUM('pending','leased','uncertain','failed','done','cancelled') NOT NULL DEFAULT 'pending',
    available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    lease_id CHAR(32),
    lease_until DATETIME(6),
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    owner VARCHAR(63) NOT NULL,
    last_failure VARCHAR(240),
    remote_receipt JSON,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,placement,operation),
    KEY campaign_dispatch_queue(tenant,phase,available_at,placement),
    FOREIGN KEY (tenant,placement) REFERENCES campaign_placements(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS campaign_recalls (
    tenant VARCHAR(63) NOT NULL,
    id CHAR(32) NOT NULL,
    asset_type ENUM('image','font') NOT NULL,
    source_digest CHAR(64) NOT NULL,
    reason VARCHAR(600) NOT NULL,
    owner VARCHAR(63) NOT NULL,
    scope_digest CHAR(64) NOT NULL,
    grant_id VARCHAR(63),
    grant_revision INT UNSIGNED,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,id),
    UNIQUE KEY campaign_recall_identity(tenant,scope_digest),
    KEY campaign_recall_source(tenant,asset_type,source_digest,grant_id),
    FOREIGN KEY (tenant) REFERENCES studios(tenant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS campaign_recall_items (
    tenant VARCHAR(63) NOT NULL,
    recall CHAR(32) NOT NULL,
    placement CHAR(32) NOT NULL,
    PRIMARY KEY (tenant,recall,placement),
    FOREIGN KEY (tenant,recall) REFERENCES campaign_recalls(tenant,id),
    FOREIGN KEY (tenant,placement) REFERENCES campaign_placements(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;
