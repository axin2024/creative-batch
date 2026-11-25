CREATE TABLE IF NOT EXISTS batches (
    tenant VARCHAR(63) NOT NULL,
    id CHAR(32) NOT NULL,
    campaign VARCHAR(63) NOT NULL,
    input_hash CHAR(64) NOT NULL,
    frozen JSON NOT NULL,
    phase ENUM('draft','queued','review','published') NOT NULL DEFAULT 'draft',
    provider VARCHAR(32) NOT NULL,
    ceiling_micros BIGINT UNSIGNED NOT NULL,
    ceiling_per_task BIGINT UNSIGNED NOT NULL,
    rights_from DATETIME(6) NOT NULL,
    rights_until DATETIME(6) NOT NULL,
    created_by VARCHAR(63) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,id),
    UNIQUE KEY one_campaign(tenant,campaign),
    FOREIGN KEY (tenant) REFERENCES studios(tenant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS generation_tasks (
    tenant VARCHAR(63) NOT NULL,
    id CHAR(32) NOT NULL,
    batch CHAR(32) NOT NULL,
    unit_key VARCHAR(129) NOT NULL,
    ordinal SMALLINT UNSIGNED NOT NULL,
    seed INT UNSIGNED NOT NULL,
    source_task CHAR(32),
    phase ENUM('queued','running','unknown','completed','failed') NOT NULL DEFAULT 'queued',
    provider_ref VARCHAR(150),
    lease CHAR(32),
    lease_until DATETIME(6),
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    error_code VARCHAR(63),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,id),
    UNIQUE KEY task_slot(tenant,batch,unit_key,ordinal),
    UNIQUE KEY provider_once(tenant,provider_ref),
    KEY claim_ready(tenant,phase,updated_at,id),
    FOREIGN KEY (tenant,batch) REFERENCES batches(tenant,id),
    FOREIGN KEY (tenant,source_task) REFERENCES generation_tasks(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS candidates (
    tenant VARCHAR(63) NOT NULL,
    id CHAR(32) NOT NULL,
    batch CHAR(32) NOT NULL,
    unit_key VARCHAR(129) NOT NULL,
    image_digest CHAR(64) NOT NULL,
    evidence JSON NOT NULL,
    quality JSON NOT NULL,
    eligible BOOLEAN NOT NULL,
    verdict ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    review_revision INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant,id),
    KEY candidate_unit(tenant,batch,unit_key,id),
    FOREIGN KEY (tenant,id) REFERENCES generation_tasks(tenant,id),
    FOREIGN KEY (tenant,batch) REFERENCES batches(tenant,id),
    FOREIGN KEY (tenant,image_digest) REFERENCES images(tenant,digest)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS human_reviews (
    tenant VARCHAR(63) NOT NULL,
    candidate CHAR(32) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    actor VARCHAR(63) NOT NULL,
    verdict ENUM('approved','rejected') NOT NULL,
    checks JSON NOT NULL,
    reason VARCHAR(600) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,candidate,revision),
    FOREIGN KEY (tenant,candidate) REFERENCES candidates(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS selections (
    tenant VARCHAR(63) NOT NULL,
    batch CHAR(32) NOT NULL,
    unit_key VARCHAR(129) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    candidate CHAR(32) NOT NULL,
    reason VARCHAR(600) NOT NULL,
    actor VARCHAR(63) NOT NULL,
    changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,batch,unit_key,revision),
    FOREIGN KEY (tenant,batch) REFERENCES batches(tenant,id),
    FOREIGN KEY (tenant,candidate) REFERENCES candidates(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS bundles (
    tenant VARCHAR(63) NOT NULL,
    id CHAR(32) NOT NULL,
    batch CHAR(32) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    digest CHAR(64) NOT NULL,
    manifest JSON NOT NULL,
    actor VARCHAR(63) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant,id),
    UNIQUE KEY bundle_revision(tenant,batch,revision),
    UNIQUE KEY bundle_identity(tenant,batch,digest),
    FOREIGN KEY (tenant,batch) REFERENCES batches(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS bundle_rights (
    tenant VARCHAR(63) NOT NULL,
    right_id VARCHAR(63) NOT NULL,
    bundle CHAR(32) NOT NULL,
    right_revision INT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant,right_id,bundle),
    FOREIGN KEY (tenant,bundle) REFERENCES bundles(tenant,id),
    FOREIGN KEY (tenant,right_id) REFERENCES asset_rights(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS bundle_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant VARCHAR(63) NOT NULL,
    bundle CHAR(32) NOT NULL,
    actor VARCHAR(63) NOT NULL,
    issued_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY receipt_bundle(tenant,bundle,id),
    FOREIGN KEY (tenant,bundle) REFERENCES bundles(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS generation_meter (
    tenant VARCHAR(63) NOT NULL,
    task CHAR(32) NOT NULL,
    source ENUM('live','replay') NOT NULL,
    cost_micros BIGINT UNSIGNED NOT NULL,
    prompt_tokens INT UNSIGNED,
    output_tokens INT UNSIGNED,
    elapsed_ms INT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant,task),
    FOREIGN KEY (tenant,task) REFERENCES generation_tasks(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;
