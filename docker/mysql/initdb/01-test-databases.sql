-- Runs once, on the first start of an empty MySQL volume.
-- Each test suite gets its own schema so suites can run in parallel
-- without truncating each other's fixtures.
CREATE DATABASE IF NOT EXISTS `yii2advanced_unit`       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `yii2advanced_regression` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `yii2advanced_e2e`        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `yii2advanced\_%`.* TO 'yii2advanced'@'%';
FLUSH PRIVILEGES;
