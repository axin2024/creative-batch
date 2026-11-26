SET @creative_operations_ddl = IF(EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='batches' AND index_name='batches_recency'
), 'DO 0', 'ALTER TABLE batches ADD KEY batches_recency(tenant,created_at,id), ADD KEY batches_phase_recency(tenant,phase,created_at,id)');
PREPARE creative_operations_ddl FROM @creative_operations_ddl;
EXECUTE creative_operations_ddl;
DEALLOCATE PREPARE creative_operations_ddl;
