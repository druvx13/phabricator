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

> **Important — shared hosting database restriction:**
> Providers like InfinityFree, 000webhost, and most cPanel hosts **do not allow
> `CREATE DATABASE` from PHP**. You must create all 57 required databases
> manually in your hosting control panel and list them in your `.env` file.
> The installer walks you through this.

### Step 1 — Create a MySQL user in cPanel

In your hosting control panel (cPanel / DirectAdmin → **MySQL Databases**):

1. Create a **MySQL user** with a strong password (e.g. `epiz_12345_phab`).
2. Note down: **hostname** (usually `localhost`), **username**, and **password**.

> ℹ️ You do **not** need to create any databases yet — the installer will show
> you the exact list to create in Step 3b.

### Step 2 — Upload the project

Upload the **entire project directory** to your hosting `public_html` folder (or a subdirectory) via FTP, cPanel File Manager, or Git.

The root `.htaccess` in the project automatically forwards all web traffic into `webroot/`, so you do **not** need to move any files or change your document root setting.

```
public_html/          ← upload everything here
public_html/.htaccess ← routes traffic into webroot/ automatically
public_html/.env      ← you will create this in Step 3
public_html/webroot/  ← Phabricator's web root (handled automatically)
```

> **Tip:** Make sure your FTP client shows hidden files (dotfiles). `.htaccess` and `.env` start with a dot and are hidden by default in most FTP clients. In FileZilla: *Server → Force showing hidden files*.

### Step 3 — Configure `.env`

In the project root (one level above `webroot/`):

1. Copy `.env.example` to `.env`.
2. Edit `.env` and fill in the required values:

```ini
PHABRICATOR_BASE_URI=http://yoursite.example.com/
PHABRICATOR_MYSQL_HOST=localhost
PHABRICATOR_MYSQL_USER=epiz_12345_phab
PHABRICATOR_MYSQL_PASS=YOUR_DB_PASSWORD

# Set this to your cPanel account username (the mandatory database prefix)
PHABRICATOR_NAMESPACE=epiz_12345
```

> ⚠️ **`PHABRICATOR_NAMESPACE` must equal your cPanel/InfinityFree username** (the
> prefix all databases on your account must start with). Using the default
> `phabricator` will cause error 1044 because the host won't grant access to a
> database not prefixed with your username.

### Step 3b — Pre-create all 57 databases and add them to `.env`

Shared hosting providers require every database to be created manually.

1. Open `http://yoursite.example.com/install.php` in your browser.
2. Scroll to **③ Required Databases** — the installer shows all 57 database names
   based on your `PHABRICATOR_NAMESPACE`.
3. Click **"📋 Copy PHABRICATOR_DB_LIST for .env"** — this copies a ready-to-paste
   line like:
   ```
   PHABRICATOR_DB_LIST=epiz_12345_almanac,epiz_12345_application,...
   ```
4. Paste that line into your `.env` file.
5. In cPanel → **MySQL Databases**, create **each database** in the list one by one.
6. After creating each database, add your MySQL user to it with **All Privileges**.

> ℹ️ This is a one-time process. The installer page stays safe to visit until
> you click "Run Database Setup" — it doesn't modify anything on GET requests.

### Step 4 — Run the web installer

Once all databases are created and `PHABRICATOR_DB_LIST` is set in `.env`:

1. Visit `http://yoursite.example.com/install.php`.
2. Confirm all checks in ① and ② are green, and ② shows
   `PHABRICATOR_DB_LIST is set — N databases explicitly defined`.
3. Click **Run Database Setup**.
4. When it reports success, click **→ Open Phabricator**.

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
