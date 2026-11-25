CREATE TABLE IF NOT EXISTS render_receipts (
    tenant VARCHAR(63) NOT NULL,
    task CHAR(32) NOT NULL,
    image_digest CHAR(64) NOT NULL,
    evidence JSON NOT NULL,
    PRIMARY KEY (tenant,task),
    FOREIGN KEY (tenant,task) REFERENCES generation_tasks(tenant,id),
    FOREIGN KEY (tenant,image_digest) REFERENCES images(tenant,digest)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin;
