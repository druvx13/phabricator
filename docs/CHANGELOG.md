# Changelog

All notable changes made during the modernization of this Phabricator fork are documented here.

---

## [Unreleased] – 2026

### Added

#### Documentation
- `README.md` – Replaced minimal stub with a full project overview, feature list, and
  version compatibility matrix covering PHP 7.2–8.3, MySQL 5.5–8.0, MariaDB 10.2–10.6,
  Apache 2.4, Nginx 1.18+, and Node.js 10–18.
- `docs/INSTALL.md` – Comprehensive step-by-step installation guide for both
  **LAMP** (Linux · Apache · MySQL · PHP) and **LEMP** (Linux · Nginx · MySQL · PHP-FPM)
  on Ubuntu/Debian and CentOS/RHEL.
- `docs/CONFIGURATION.md` – Full configuration reference covering all major Phabricator
  settings, Apache VirtualHost directives, Nginx server block directives (including
  WebSocket proxy for Aphlict), and PHP.ini recommendations.
- `docs/USAGE.md` – Basic user guide covering first login, user/project creation,
  Maniphest tasks, Differential code review, Diffusion repositories, and Phriction wiki.
- `docs/TROUBLESHOOTING.md` – Common errors and solutions for both LAMP and LEMP,
  covering web server 404s, database connection errors, file permission issues, PHP 8.x
  fatal errors, daemon startup failures, email, repository access, Aphlict, and
  storage upgrade failures.
- `docs/CHANGELOG.md` – This file.

#### Configuration Files
- `support/apache/phabricator.conf` – Ready-to-use Apache 2.4 VirtualHost configuration
  with HTTP and commented-out HTTPS (Let's Encrypt) blocks.
- `support/nginx/phabricator.conf` – Ready-to-use Nginx server block configuration with
  PHP-FPM integration, sensitive directory protection, WebSocket proxy comments, and
  commented-out HTTPS block.
- `support/mysql/setup.sql` – MySQL/MariaDB setup script that creates the `phabricator`
  database user with the required privileges.
- `support/php/php.ini.recommended` – Recommended PHP.ini settings for both Apache and
  PHP-FPM deployments (memory_limit, upload limits, OPcache, timezone, security).

#### Deployment Scripts
- `setup.sh` – Interactive setup script that automates LAMP/LEMP dependency installation,
  directory creation, permission setting, database user creation, and `bin/storage upgrade`.
- `verify.sh` – Non-destructive environment checker that validates PHP version, required
  extensions, web server, database connectivity, directory permissions, and Node.js.
- `docker-compose.yml` – Docker Compose file providing a full LEMP environment
  (Nginx + PHP 8.1-FPM + MariaDB) for local development and testing, with a persistent
  volume for repository storage.

### Changed

- `.gitignore` – Added exclusions for log files (`*.log`), Nginx/Apache PID files,
  common editor swap files, and OS metadata files (`.DS_Store`, `Thumbs.db`).

---

## Prior History

For changes made to the upstream Phabricator codebase before this fork was created,
see the upstream commit history at:
https://github.com/phacility/phabricator
