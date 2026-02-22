# Phabricator Installation Guide

Choose the path that matches your environment:

| Deployment type | Go to section |
|----------------|---------------|
| **Shared hosting** (InfinityFree, 000webhost, cPanel hosts — upload via FTP) | [Quick Deploy](#quick-deploy-shared-hosting-ftp-upload) |
| **VPS / Dedicated server — Apache (LAMP)** | [LAMP VPS](#lamp-vps-installation) |
| **VPS / Dedicated server — Nginx (LEMP)** | [LEMP VPS](#lemp-vps-installation) |
| **Docker (local development)** | [Docker](#docker-local-development) |

---

## Quick Deploy (Shared Hosting / FTP Upload)

This method requires **no SSH, no CLI, and no server configuration changes**.
Everything is driven by a single `.env` file and a browser-based installer.

> **Important:** Phabricator creates one database per application (e.g.
> `phabricator_user`, `phabricator_repo`, …). Your MySQL user must have
> `CREATE DATABASE` privileges on `yourprefix_%`. On most cPanel hosts,
> a user granted **All Privileges** on a wildcard like `epiz_12345_%` qualifies.

### Step 1 — Create a MySQL database and user

In your hosting control panel (cPanel / DirectAdmin):

1. Go to **MySQL Databases**.
2. Create a new database, e.g. `epiz_12345_phabricator` (note the full prefix).
3. Create a database user with a strong password.
4. Grant the user **All Privileges** on `epiz_12345_%` (wildcard).
5. Note down: **hostname** (usually `localhost`), **username**, **password**, and the **prefix** (e.g. `epiz_12345`).

### Step 2 — Upload the project

Upload the **entire project directory** to your hosting `public_html` folder (or a subdirectory) via FTP, cPanel File Manager, or Git.

The `webroot/` folder is the document root. If your host only lets you serve from `public_html/`, move the contents of `webroot/` to `public_html/` and adjust paths, **or** point your domain's document root at the `webroot/` subfolder in cPanel.

### Step 3 — Configure `.env`

In the project root (one level above `webroot/`):

1. Copy `.env.example` to `.env`.
2. Edit `.env` and fill in at minimum:

```ini
PHABRICATOR_BASE_URI=http://yoursite.example.com/
PHABRICATOR_MYSQL_HOST=localhost
PHABRICATOR_MYSQL_USER=epiz_12345_user
PHABRICATOR_MYSQL_PASS=YOUR_DB_PASSWORD
PHABRICATOR_NAMESPACE=epiz_12345
```

> Set `PHABRICATOR_NAMESPACE` to your database **prefix** (the part before `_phabricator`).
> Phabricator will create databases named `epiz_12345_user`, `epiz_12345_repo`, etc.

### Step 4 — Run the web installer

Open your browser and visit:

```
http://yoursite.example.com/install.php
```

- The installer checks prerequisites and your `.env` values.
- Click **Run Database Setup** — this imports the full schema without any CLI access.
- When it reports success, click **→ Open Phabricator**.

### Step 5 — Delete `install.php`

**Delete `webroot/install.php`** via FTP or File Manager after setup is complete.
Leaving it accessible allows anyone to reset your database.

### Step 6 — Complete setup in the browser

Phabricator will guide you through the remaining setup (admin account, email, etc.)
at `http://yoursite.example.com/`.

---

## LAMP VPS Installation

Full installation on a VPS or dedicated server using **Apache + mod_php**.

### Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| PHP       | 7.2     | 8.1 – 8.3   |
| MySQL / MariaDB | 5.5 | 8.0 / MariaDB 10.6 |
| Apache    | 2.4     | 2.4.x latest |
| Git       | 2.6     | 2.40+       |
| Node.js   | 10.x    | 18 LTS      |

### Ubuntu / Debian

```bash
sudo apt-get update && sudo apt-get upgrade -y

# Apache + PHP 8.1
sudo apt-get install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y && sudo apt-get update
sudo apt-get install -y apache2 \
  php8.1 php8.1-cli php8.1-curl php8.1-gd php8.1-mbstring \
  php8.1-mysql php8.1-xml php8.1-zip php8.1-json php8.1-opcache

# MariaDB, Git, Node.js 18
sudo apt-get install -y mariadb-server git
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs

sudo a2enmod rewrite && sudo systemctl enable --now apache2 mariadb
```

### CentOS / RHEL

```bash
sudo dnf update -y
sudo dnf install -y httpd
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-8.rpm
sudo dnf module reset php -y && sudo dnf module enable php:remi-8.1 -y
sudo dnf install -y php php-cli php-curl php-gd php-mbstring php-mysqlnd \
  php-xml php-zip php-json php-opcache mariadb-server git
curl -fsSL https://rpm.nodesource.com/setup_18.x | sudo bash -
sudo dnf install -y nodejs
sudo systemctl enable --now httpd mariadb
sudo firewall-cmd --permanent --add-service=http --add-service=https && sudo firewall-cmd --reload
```

### Configure Apache VirtualHost

```bash
sudo cp support/apache/phabricator.conf /etc/apache2/sites-available/phabricator.conf
# Edit: replace YOUR_DOMAIN and /path/to/phabricator
sudo nano /etc/apache2/sites-available/phabricator.conf
sudo a2ensite phabricator && sudo a2dissite 000-default && sudo systemctl reload apache2
```

### Deploy and configure

```bash
# Create DB user
sudo mysql -u root -p < support/mysql/setup.sql   # edit password in file first

# Deploy config from .env (or use bin/config)
cp .env.example .env && nano .env

# Run storage upgrade
./bin/storage upgrade --user phabricator --password YOUR_DB_PASSWORD

# Start daemons
./bin/phd start
```

---

## LEMP VPS Installation

Full installation on a VPS or dedicated server using **Nginx + PHP-FPM**.

### Ubuntu / Debian

```bash
sudo apt-get update && sudo apt-get upgrade -y

# Nginx + PHP 8.1 FPM
sudo apt-get install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y && sudo apt-get update
sudo apt-get install -y nginx \
  php8.1-fpm php8.1-cli php8.1-curl php8.1-gd php8.1-mbstring \
  php8.1-mysql php8.1-xml php8.1-zip php8.1-json php8.1-opcache

# MariaDB, Git, Node.js 18
sudo apt-get install -y mariadb-server git
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs

sudo systemctl enable --now nginx php8.1-fpm mariadb
```

### CentOS / RHEL

```bash
sudo dnf update -y
sudo dnf install -y nginx
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-8.rpm
sudo dnf module reset php -y && sudo dnf module enable php:remi-8.1 -y
sudo dnf install -y php-fpm php-cli php-curl php-gd php-mbstring php-mysqlnd \
  php-xml php-zip php-json php-opcache mariadb-server git
curl -fsSL https://rpm.nodesource.com/setup_18.x | sudo bash -
sudo dnf install -y nodejs
sudo systemctl enable --now nginx php-fpm mariadb
sudo firewall-cmd --permanent --add-service=http --add-service=https && sudo firewall-cmd --reload
```

### Configure Nginx Server Block

```bash
# Ubuntu/Debian
sudo cp support/nginx/phabricator.conf /etc/nginx/sites-available/phabricator
# Edit: replace YOUR_DOMAIN, /path/to/phabricator, and the PHP-FPM socket path
# (check: ls /run/php/ for the correct socket filename)
sudo nano /etc/nginx/sites-available/phabricator
sudo ln -s /etc/nginx/sites-available/phabricator /etc/nginx/sites-enabled/phabricator
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

# CentOS/RHEL
sudo cp support/nginx/phabricator.conf /etc/nginx/conf.d/phabricator.conf
sudo nano /etc/nginx/conf.d/phabricator.conf
sudo nginx -t && sudo systemctl reload nginx
```

### Deploy and configure

```bash
# Create DB user
sudo mysql -u root -p < support/mysql/setup.sql   # edit password in file first

cp .env.example .env && nano .env

./bin/storage upgrade --user phabricator --password YOUR_DB_PASSWORD
./bin/phd start
```

---

## Docker (Local Development)

Spin up the full LEMP stack (Nginx + PHP 8.1-FPM + MariaDB 10.6) with one command:

```bash
cp .env.example .env
# Edit .env: set PHABRICATOR_BASE_URI=http://localhost/

docker compose up -d

# Run the database setup (first time only)
docker compose exec php ./bin/storage upgrade --force \
  --user phabricator --password "$(grep PHABRICATOR_MYSQL_PASS .env | cut -d= -f2)"
```

Then open `http://localhost/` in your browser.

---

## Final Verification

```bash
bash verify.sh
```

See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) if any checks fail.
