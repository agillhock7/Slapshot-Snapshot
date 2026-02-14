SET @logo_path_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'teams'
    AND COLUMN_NAME = 'logo_path'
);

SET @logo_path_sql := IF(
  @logo_path_exists = 0,
  'ALTER TABLE teams ADD COLUMN logo_path VARCHAR(255) NULL AFTER team_notes',
  'SELECT "teams.logo_path already exists"'
);

PREPARE stmt_logo_path FROM @logo_path_sql;
EXECUTE stmt_logo_path;
DEALLOCATE PREPARE stmt_logo_path;
