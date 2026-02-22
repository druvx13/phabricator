# Phabricator Troubleshooting Guide

This document covers common issues and solutions for both LAMP and LEMP deployments.

---

## Table of Contents

1. [Web Server Returns 404 / Blank Page](#1-web-server-returns-404--blank-page)
2. [Database Connection Errors](#2-database-connection-errors)
3. [Permission Errors](#3-permission-errors)
4. [PHP Fatal Errors / White Screen](#4-php-fatal-errors--white-screen)
5. [Daemons (Phd) Not Starting](#5-daemons-phd-not-starting)
6. [Email Not Being Sent](#6-email-not-being-sent)
7. [Repository Cloning / Push Errors](#7-repository-cloning--push-errors)
8. [Aphlict / Notifications Not Working](#8-aphlict--notifications-not-working)
9. [Storage Upgrade Failures](#9-storage-upgrade-failures)
10. [Performance Issues](#10-performance-issues)

---

## 1. Web Server Returns 404 / Blank Page

**Apache (LAMP)**

- Ensure `mod_rewrite` is enabled: `sudo a2enmod rewrite && sudo systemctl reload apache2`
- Check that `AllowOverride All` is set for the webroot directory in your VirtualHost.
- Verify the `DocumentRoot` points to `webroot/`, not the Phabricator root.

**Nginx (LEMP)**

- Confirm the rewrite rule in your server block is present:
  ```nginx
  rewrite ^/(.*)$  /index.php?__path__=/$1  last;
  ```
- Check that the PHP-FPM socket path in `fastcgi_pass` matches an existing socket:
  ```bash
  ls /run/php/         # Ubuntu/Debian
  ls /run/php-fpm/     # CentOS/RHEL
  ```
- Test the nginx config: `sudo nginx -t`

---

## 2. Database Connection Errors

**`Access denied for user 'phabricator'@'localhost'`**

```bash
# Verify the user exists and has grants
sudo mysql -u root -p -e "SELECT user, host FROM mysql.user WHERE user='phabricator';"
sudo mysql -u root -p -e "SHOW GRANTS FOR 'phabricator'@'localhost';"

# If missing, re-run the setup script (after setting the password)
sudo mysql -u root -p < support/mysql/setup.sql
```

**`Can't connect to local MySQL server through socket`**

```bash
sudo systemctl status mariadb   # or mysql
sudo systemctl start mariadb
```

**Verify Phabricator config matches your database credentials:**

```bash
./bin/config get mysql.host
./bin/config get mysql.user
./bin/config get mysql.pass
```

---

## 3. Permission Errors

**`Failed to open stream: Permission denied`**

The web server user needs write access to the repository and file storage paths:

```bash
# LAMP (Apache)
sudo chown -R www-data:www-data /var/repo /var/phabricator/files  # Ubuntu
sudo chown -R apache:apache     /var/repo /var/phabricator/files  # CentOS

# LEMP (Nginx + PHP-FPM)
sudo chown -R www-data:www-data /var/repo /var/phabricator/files  # Ubuntu
sudo chown -R nginx:nginx       /var/repo /var/phabricator/files  # CentOS
```

For the Phabricator directory itself, the web server user needs read access but **not** write access (except `conf/local/`):

```bash
sudo chown -R root:root /path/to/phabricator
sudo chown -R www-data:www-data /path/to/phabricator/conf/local/
```

---

## 4. PHP Fatal Errors / White Screen

**Enable developer mode to see errors:**

```bash
./bin/config set phabricator.developer-mode true
```

> **Disable developer mode in production** once the issue is resolved.

**Check PHP error logs:**

```bash
# LAMP
sudo tail -f /var/log/apache2/phabricator_error.log
sudo tail -f /var/log/php8.1-fpm.log         # PHP-FPM log if using LEMP on Apache

# LEMP
sudo tail -f /var/log/nginx/phabricator_error.log
sudo tail -f /var/log/php8.1-fpm.log
```

**Common PHP 8.x issues:**

| Symptom | Cause | Fix |
|---------|-------|-----|
| `Deprecated: ...` warnings | PHP 8.x deprecation | Enable `error_reporting = E_ALL & ~E_DEPRECATED` in php.ini |
| `TypeError: ...` | Stricter type enforcement in PHP 8 | Check `phabricator.developer-mode` for stack trace |
| Missing extension error | Extension not installed | `sudo apt-get install php8.1-<extension>` |

---

## 5. Daemons (Phd) Not Starting

```bash
# Check for errors
./bin/phd log

# Restart cleanly
./bin/phd stop
./bin/phd start

# Check that the daemon user has write access to /var/tmp/phd/
sudo mkdir -p /var/tmp/phd
sudo chown -R www-data:www-data /var/tmp/phd   # or the user running phd
```

---

## 6. Email Not Being Sent

```bash
# Test mail delivery
./bin/mail send-test --to your@email.com --subject "Test"

# Check the mail log
./bin/mail list-outbound
./bin/mail show-outbound --id <id>
```

Verify SMTP settings:

```bash
./bin/config get phpmailer.smtp-host
./bin/config get phpmailer.smtp-port
./bin/config get phpmailer.smtp-user
```

---

## 7. Repository Cloning / Push Errors

**`Permission denied (publickey)`**

Verify the SSH configuration:

```bash
./bin/config get diffusion.ssh-host
./bin/config get diffusion.ssh-port
```

Check that `sshd` is running and the SSH user (`git` or `vcs`) is configured in your `/etc/passwd` with the correct shell pointing to `bin/ssh-auth`.

**`Repository not found`**

Ensure the repository is **Activated** in Diffusion and that the daemon has cloned/mirrored it:

```bash
./bin/phd status
./bin/repository update --all
```

---

## 8. Aphlict / Notifications Not Working

```bash
# Check if Aphlict is running
./bin/aphlict status

# Restart Aphlict
./bin/aphlict stop
./bin/aphlict start

# Tail its log
tail -f /var/log/aphlict.log
```

**Nginx proxy for WebSocket (LEMP):**

If you are proxying WebSocket connections through nginx, confirm the upstream block is present in your server block:

```nginx
location /ws/ {
    proxy_pass         http://127.0.0.1:22280/;
    proxy_http_version 1.1;
    proxy_set_header   Upgrade    $http_upgrade;
    proxy_set_header   Connection "upgrade";
}
```

Then verify the Phabricator notification server config matches:

```bash
./bin/config get notification.servers
```

---

## 9. Storage Upgrade Failures

**`You have unresolved setup issues`**

Run the storage upgrade to apply any pending migrations:

```bash
./bin/storage upgrade --user phabricator --password YOUR_DB_PASSWORD
```

If the upgrade fails midway, try:

```bash
./bin/storage upgrade --force --user phabricator --password YOUR_DB_PASSWORD
```

Check for MySQL `max_allowed_packet` issues if large blobs fail:

```sql
-- In MySQL/MariaDB
SET GLOBAL max_allowed_packet = 33554432;
```

---

## 10. Performance Issues

- **Enable OPcache** — see [support/php/php.ini.recommended](../support/php/php.ini.recommended).
- **Increase `memory_limit`** to at least `256M` in `php.ini`.
- **Start Phabricator daemons** (`./bin/phd start`) — many operations are async and require running daemons.
- **Use a local repository path** — serving repositories from a fast local disk greatly improves Diffusion performance.
- **MySQL tuning** — add `innodb_buffer_pool_size = 256M` (or higher) in `/etc/mysql/my.cnf` for installs with large datasets.
