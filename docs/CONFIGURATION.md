# Phabricator Configuration Reference

This document covers all major configuration options, environment variables, and Apache/PHP settings for Phabricator.

---

## Table of Contents

1. [Configuration Storage](#1-configuration-storage)
2. [Core Configuration Options](#2-core-configuration-options)
3. [Database Options](#3-database-options)
4. [Email / Mailer Options](#4-email--mailer-options)
5. [Authentication Options](#5-authentication-options)
6. [File Storage Options](#6-file-storage-options)
7. [Repository Options](#7-repository-options)
8. [Apache VirtualHost Settings](#8-apache-virtualhost-settings)
9. [PHP.ini Recommended Settings](#9-phpini-recommended-settings)
10. [Environment Variables (.env)](#10-environment-variables-env)

---

## 1. Configuration Storage

Phabricator stores runtime configuration in `conf/local/local.json`.

Use the CLI to set values:

```bash
./bin/config set <key> <value>
./bin/config get <key>
./bin/config delete <key>
./bin/config list
```

---

## 2. Core Configuration Options

| Key | Default | Description |
|-----|---------|-------------|
| `phabricator.base-uri` | *(none)* | **Required.** Full base URL, e.g. `https://phabricator.example.com/` |
| `phabricator.production-uri` | *(none)* | Public-facing URI if different from base URI |
| `phabricator.developer-mode` | `false` | Enable verbose error messages (disable in production) |
| `phabricator.timezone` | `UTC` | PHP timezone string |
| `darkconsole.enabled` | `false` | Enable dark console for debugging |

```bash
./bin/config set phabricator.base-uri 'https://phabricator.example.com/'
./bin/config set phabricator.timezone 'America/New_York'
```

---

## 3. Database Options

| Key | Default | Description |
|-----|---------|-------------|
| `mysql.host` | `localhost` | MySQL/MariaDB host |
| `mysql.port` | `3306` | MySQL/MariaDB port |
| `mysql.user` | `root` | Database user |
| `mysql.pass` | *(none)* | Database password |
| `storage.default-namespace` | `phabricator` | Database name prefix |
| `storage.mysql-engine.max-size` | `0` | Max MySQL packet size (0 = auto) |

```bash
./bin/config set mysql.host 'localhost'
./bin/config set mysql.user 'phabricator'
./bin/config set mysql.pass 'YOUR_DB_PASSWORD'
```

---

## 4. Email / Mailer Options

| Key | Description |
|-----|-------------|
| `metamta.default-address` | From address for outgoing mail |
| `metamta.domain` | Domain for auto-generated addresses |
| `metamta.mail-adapter` | Adapter class (e.g. `PhabricatorMailImplementationPHPMailerAdapter`) |
| `phpmailer.smtp-host` | SMTP host |
| `phpmailer.smtp-port` | SMTP port |
| `phpmailer.smtp-user` | SMTP username |
| `phpmailer.smtp-password` | SMTP password |
| `phpmailer.smtp-protocol` | `ssl` or `tls` |

```bash
./bin/config set metamta.default-address 'phabricator@example.com'
./bin/config set phpmailer.smtp-host 'smtp.example.com'
./bin/config set phpmailer.smtp-port 587
./bin/config set phpmailer.smtp-protocol 'tls'
```

---

## 5. Authentication Options

| Key | Default | Description |
|-----|---------|-------------|
| `auth.require-approval` | `false` | Require admin approval for new accounts |
| `auth.email-domains` | `[]` | Restrict sign-ups to specific domains |
| `auth.require-email-verification` | `false` | Require email address verification |
| `policy.allow-public` | `false` | Allow unauthenticated access to public objects |

---

## 6. File Storage Options

| Key | Default | Description |
|-----|---------|-------------|
| `storage.engine-selector` | *(auto)* | Storage engine selector class |
| `storage.local-disk.path` | *(none)* | Local disk path for file storage |
| `storage.s3.bucket` | *(none)* | S3 bucket name |
| `amazon-s3.access-key` | *(none)* | AWS access key |
| `amazon-s3.secret-key` | *(none)* | AWS secret key |
| `amazon-s3.endpoint` | *(none)* | Custom S3-compatible endpoint |

---

## 7. Repository Options

| Key | Default | Description |
|-----|---------|-------------|
| `repository.default-local-path` | `/var/repo` | Where repository data is stored |
| `diffusion.allow-http-auth` | `false` | Allow HTTP auth for repository access |
| `diffusion.ssh-host` | *(none)* | SSH host for repository access |
| `diffusion.ssh-port` | `22` | SSH port for repository access |

---

## 8. Web Server Settings

### Apache VirtualHost Settings (LAMP)

See [support/apache/phabricator.conf](../support/apache/phabricator.conf) for a complete example.

Key Apache directives:

```apache
# Required: disable MultiViews
Options -MultiViews

# Required: allow .htaccess overrides
AllowOverride All

# Required: mod_rewrite for clean URLs
RewriteEngine on
RewriteRule ^(.*)$  /index.php?__path__=$1  [B,L,QSA]
```

### Nginx Server Block Settings (LEMP)

See [support/nginx/phabricator.conf](../support/nginx/phabricator.conf) for a complete example.

Key nginx directives:

```nginx
# Rewrite all requests through index.php
location / {
    index index.php;
    rewrite ^/(.*)$  /index.php?__path__=/$1  last;
}

# PHP-FPM handler
location ~ \.php$ {
    fastcgi_pass  unix:/run/php/php8.1-fpm.sock;
    fastcgi_index  index.php;
    fastcgi_param  SCRIPT_FILENAME  $document_root$fastcgi_script_name;
    include        fastcgi_params;
}
```

**Aphlict WebSocket proxy for nginx:** To proxy Aphlict notifications through nginx
instead of exposing port 22280 directly, add to your server block:

```nginx
location /ws/ {
    proxy_pass         http://127.0.0.1:22280/;
    proxy_http_version 1.1;
    proxy_set_header   Upgrade    $http_upgrade;
    proxy_set_header   Connection "upgrade";
    proxy_set_header   Host       $host;
}
```

Then set in Phabricator:

```bash
./bin/config set notification.servers '[{"type":"client","host":"YOUR_DOMAIN","port":443,"protocol":"https","path":"/ws/"}]'
```

---

## 9. PHP.ini Recommended Settings

See [support/php/php.ini.recommended](../support/php/php.ini.recommended) for a complete example.

Key settings:

```ini
memory_limit = 256M
max_execution_time = 120
post_max_size = 32M
upload_max_filesize = 32M
date.timezone = UTC
opcache.enable = 1
opcache.memory_consumption = 128
```

---

## 10. Environment Variables (.env)

While Phabricator uses `conf/local/local.json` for most configuration, you can use environment variables to pass secrets without writing them to disk. This is useful in Docker or CI/CD environments.

Phabricator reads the following environment variables if set:

| Variable | Corresponds To |
|----------|----------------|
| `PHABRICATOR_BASE_URI` | `phabricator.base-uri` |
| `PHABRICATOR_MYSQL_HOST` | `mysql.host` |
| `PHABRICATOR_MYSQL_PORT` | `mysql.port` |
| `PHABRICATOR_MYSQL_USER` | `mysql.user` |
| `PHABRICATOR_MYSQL_PASS` | `mysql.pass` |

> **Note:** Native `.env` file support requires a bootstrap shim. When using Docker, pass these as `environment:` values in `docker-compose.yml`. See [docker-compose.yml](../docker-compose.yml) for examples.

**Never commit passwords or secrets to version control.** Use `conf/local/local.json` (already in `.gitignore`) or environment variables.
