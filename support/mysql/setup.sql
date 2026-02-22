-- MySQL / MariaDB setup script for Phabricator
--
-- Instructions:
--   1. Replace 'YOUR_DB_PASSWORD' with a strong password BEFORE running this script.
--   2. Run as root:  sudo mysql -u root -p < support/mysql/setup.sql
--   3. After running this script, import the schema:
--        cd /path/to/phabricator
--        ./bin/storage upgrade --user phabricator --password YOUR_DB_PASSWORD

-- Create a dedicated Phabricator database user.
-- The '%' host allows connections from any host; restrict to '127.0.0.1' or
-- 'localhost' for added security on single-server installs.
CREATE USER IF NOT EXISTS 'phabricator'@'localhost' IDENTIFIED BY 'YOUR_DB_PASSWORD';

-- Grant privileges.
-- Phabricator manages its own databases (one per application) under the
-- 'phabricator_' namespace.  The user needs CREATE, ALTER, DROP, and full DML.
GRANT ALL PRIVILEGES ON `phabricator\_%`.* TO 'phabricator'@'localhost';

-- Recommended: set a high maximum packet size to support large diffs/files.
-- You can also set this in /etc/mysql/my.cnf (see support/php/php.ini.recommended).
-- SET GLOBAL max_allowed_packet = 33554432;  -- 32 MB

FLUSH PRIVILEGES;

-- Verify the user was created.
-- Run:  SELECT user, host FROM mysql.user WHERE user = 'phabricator';
