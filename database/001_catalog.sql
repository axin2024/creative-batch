CREATE TABLE IF NOT EXISTS studios (
    tenant VARCHAR(63) PRIMARY KEY,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS access_keys (
    digest CHAR(64) PRIMARY KEY,
    tenant VARCHAR(63) NOT NULL,
    actor VARCHAR(63) NOT NULL,
    role ENUM('admin','designer','operator','reviewer','channel') NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6),
    KEY access_tenant(tenant, actor),
    FOREIGN KEY (tenant) REFERENCES studios(tenant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS images (
    tenant VARCHAR(63) NOT NULL,
    digest CHAR(64) NOT NULL,
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    bytes MEDIUMBLOB NOT NULL,
    PRIMARY KEY (tenant, digest),
    FOREIGN KEY (tenant) REFERENCES studios(tenant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS brands (
    tenant VARCHAR(63) NOT NULL,
    id VARCHAR(63) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    packaging VARCHAR(120) NOT NULL,
    palette JSON NOT NULL,
    approved_by VARCHAR(63) NOT NULL,
    PRIMARY KEY (tenant,id,revision),
    FOREIGN KEY (tenant) REFERENCES studios(tenant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS asset_rights (
    tenant VARCHAR(63) NOT NULL,
    id VARCHAR(63) NOT NULL,
    image_digest CHAR(64) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    grantor VARCHAR(160) NOT NULL,
    scope JSON NOT NULL,
    commercial BOOLEAN NOT NULL,
    valid_from DATETIME(6) NOT NULL,
    valid_until DATETIME(6) NOT NULL,
    revoked_at DATETIME(6),
    PRIMARY KEY (tenant,id),
    KEY rights_expiry(tenant,valid_until),
    FOREIGN KEY (tenant,image_digest) REFERENCES images(tenant,digest)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS variants (
    tenant VARCHAR(63) NOT NULL,
    id VARCHAR(63) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    brand VARCHAR(63) NOT NULL,
    brand_revision INT UNSIGNED NOT NULL,
    sku VARCHAR(63) NOT NULL,
    color VARCHAR(63) NOT NULL,
    rgb CHAR(7) NOT NULL,
    image_digest CHAR(64) NOT NULL,
    right_id VARCHAR(63) NOT NULL,
    PRIMARY KEY (tenant,id,revision),
    UNIQUE KEY variant_color(tenant,brand,sku,color,revision),
    FOREIGN KEY (tenant,brand,brand_revision) REFERENCES brands(tenant,id,revision),
    FOREIGN KEY (tenant,image_digest) REFERENCES images(tenant,digest),
    FOREIGN KEY (tenant,right_id) REFERENCES asset_rights(tenant,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS channels (
    tenant VARCHAR(63) NOT NULL,
    id VARCHAR(63) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    margin TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant,id,revision),
    FOREIGN KEY (tenant) REFERENCES studios(tenant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS templates (
    tenant VARCHAR(63) NOT NULL,
    id VARCHAR(63) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    brand VARCHAR(63) NOT NULL,
    brand_revision INT UNSIGNED NOT NULL,
    channel VARCHAR(63) NOT NULL,
    channel_revision INT UNSIGNED NOT NULL,
    style JSON NOT NULL,
    PRIMARY KEY (tenant,id,revision),
    FOREIGN KEY (tenant,brand,brand_revision) REFERENCES brands(tenant,id,revision),
    FOREIGN KEY (tenant,channel,channel_revision) REFERENCES channels(tenant,id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant VARCHAR(63) NOT NULL,
    actor VARCHAR(63) NOT NULL,
    action VARCHAR(63) NOT NULL,
    object_id VARCHAR(80) NOT NULL,
    detail JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY audit_object(tenant,object_id,id),
    FOREIGN KEY (tenant) REFERENCES studios(tenant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;
