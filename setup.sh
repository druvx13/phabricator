#!/usr/bin/env bash
# setup.sh – Phabricator automated setup for LAMP or LEMP on Ubuntu/Debian/CentOS
#
# Usage:  sudo bash setup.sh
#
# This script:
#   1. Detects the OS (Ubuntu/Debian or CentOS/RHEL).
#   2. Prompts whether to install LAMP (Apache) or LEMP (Nginx).
#   3. Installs PHP 8.1, the chosen web server, MariaDB, Git, and Node.js 18.
#   4. Creates /var/repo and /var/phabricator/files with correct ownership.
#   5. Creates the MySQL 'phabricator' user and runs bin/storage upgrade.
#
# Prerequisites:
#   - Run as root or with sudo.
#   - Internet access to package repositories.

set -euo pipefail

# ── Colour helpers ─────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()    { echo -e "${GREEN}[INFO]${NC}  $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

# ── Ensure running as root ──────────────────────────────────────────────────
[[ "$EUID" -ne 0 ]] && error "Please run this script with sudo or as root."

# ── Detect script directory (Phabricator root) ─────────────────────────────
PHAB_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
info "Phabricator root: ${PHAB_ROOT}"

# ── Detect OS ──────────────────────────────────────────────────────────────
if [[ -f /etc/os-release ]]; then
    . /etc/os-release
    OS_ID="${ID}"
    OS_LIKE="${ID_LIKE:-}"
else
    error "Cannot detect OS. /etc/os-release not found."
fi

if echo "${OS_ID} ${OS_LIKE}" | grep -qiE "debian|ubuntu"; then
    DISTRO="debian"
elif echo "${OS_ID} ${OS_LIKE}" | grep -qiE "rhel|centos|fedora|almalinux|rocky"; then
    DISTRO="rhel"
else
    error "Unsupported OS: ${OS_ID}. Supported: Ubuntu, Debian, CentOS, RHEL, AlmaLinux, Rocky."
fi
info "Detected OS family: ${DISTRO}"

# ── Prompt: LAMP or LEMP ───────────────────────────────────────────────────
echo ""
echo "Choose your web server stack:"
echo "  1) LAMP  (Apache + PHP module)"
echo "  2) LEMP  (Nginx + PHP-FPM)"
read -rp "Enter 1 or 2 [default: 2]: " STACK_CHOICE
STACK_CHOICE="${STACK_CHOICE:-2}"
[[ "$STACK_CHOICE" =~ ^[12]$ ]] || error "Invalid choice. Enter 1 or 2."

# ── Prompt: database password ─────────────────────────────────────────────
echo ""
read -rsp "Enter a password for the new 'phabricator' database user: " DB_PASS
echo ""
[[ -n "$DB_PASS" ]] || error "Database password cannot be empty."

# ── Install packages ───────────────────────────────────────────────────────
PHP_VER="8.1"
PHP_EXTS="php${PHP_VER}-cli php${PHP_VER}-curl php${PHP_VER}-gd php${PHP_VER}-mbstring php${PHP_VER}-mysql php${PHP_VER}-xml php${PHP_VER}-zip php${PHP_VER}-json php${PHP_VER}-opcache"

install_debian() {
    apt-get update -q
    apt-get install -y software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update -q

    if [[ "$STACK_CHOICE" == "1" ]]; then
        info "Installing LAMP stack (Apache)..."
        apt-get install -y apache2 php${PHP_VER} ${PHP_EXTS}
        a2enmod rewrite
        systemctl enable --now apache2
        WEB_USER="www-data"
    else
        info "Installing LEMP stack (Nginx + PHP-FPM)..."
        apt-get install -y nginx php${PHP_VER}-fpm ${PHP_EXTS}
        systemctl enable --now nginx php${PHP_VER}-fpm
        WEB_USER="www-data"
    fi

    apt-get install -y mariadb-server mariadb-client git
    systemctl enable --now mariadb

    # Node.js 18 LTS
    if ! command -v node &>/dev/null; then
        curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
        apt-get install -y nodejs
    fi
}

install_rhel() {
    dnf update -y
    dnf install -y https://rpms.remirepo.net/enterprise/remi-release-8.rpm || true
    dnf module reset php -y
    dnf module enable php:remi-${PHP_VER} -y

    if [[ "$STACK_CHOICE" == "1" ]]; then
        info "Installing LAMP stack (Apache)..."
        dnf install -y httpd php php-cli php-curl php-gd php-mbstring php-mysqlnd php-xml php-zip php-json php-opcache
        systemctl enable --now httpd
        WEB_USER="apache"
    else
        info "Installing LEMP stack (Nginx + PHP-FPM)..."
        dnf install -y nginx php-fpm php-cli php-curl php-gd php-mbstring php-mysqlnd php-xml php-zip php-json php-opcache
        systemctl enable --now nginx php-fpm
        WEB_USER="nginx"
    fi

    dnf install -y mariadb-server mariadb git
    systemctl enable --now mariadb

    # Node.js 18 LTS
    if ! command -v node &>/dev/null; then
        curl -fsSL https://rpm.nodesource.com/setup_18.x | bash -
        dnf install -y nodejs
    fi

    firewall-cmd --permanent --add-service=http --add-service=https 2>/dev/null || true
    firewall-cmd --reload 2>/dev/null || true
}

[[ "$DISTRO" == "debian" ]] && install_debian || install_rhel

# ── Apply recommended PHP.ini settings ────────────────────────────────────
INI_SRC="${PHAB_ROOT}/support/php/php.ini.recommended"
if [[ -f "$INI_SRC" ]]; then
    if [[ "$STACK_CHOICE" == "1" && "$DISTRO" == "debian" ]]; then
        INI_DEST="/etc/php/${PHP_VER}/apache2/conf.d/99-phabricator.ini"
    elif [[ "$STACK_CHOICE" == "2" && "$DISTRO" == "debian" ]]; then
        INI_DEST="/etc/php/${PHP_VER}/fpm/conf.d/99-phabricator.ini"
    elif [[ "$STACK_CHOICE" == "1" ]]; then
        INI_DEST="/etc/php.d/99-phabricator.ini"
    else
        INI_DEST="/etc/php-fpm.d/99-phabricator.ini"
    fi
    cp "$INI_SRC" "$INI_DEST"
    info "PHP.ini settings applied to ${INI_DEST}"
fi

# ── Create storage directories ─────────────────────────────────────────────
mkdir -p /var/repo /var/phabricator/files
chown -R "${WEB_USER}:${WEB_USER}" /var/repo /var/phabricator/files
info "Created /var/repo and /var/phabricator/files (owner: ${WEB_USER})"

# ── Create MySQL user ──────────────────────────────────────────────────────
info "Creating MySQL 'phabricator' user..."
# Write password to a temp file to avoid shell-escaping issues with special chars.
# The temp file is readable only by root and is deleted immediately after use.
_SQL_TMP="$(mktemp /tmp/phab_setup_XXXXXX.sql)"
chmod 600 "${_SQL_TMP}"
cat > "${_SQL_TMP}" <<ENDSQL
CREATE USER IF NOT EXISTS 'phabricator'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`phabricator_%\`.* TO 'phabricator'@'localhost';
FLUSH PRIVILEGES;
ENDSQL
mysql -u root < "${_SQL_TMP}"
rm -f "${_SQL_TMP}"
info "MySQL user 'phabricator'@'localhost' created."

# ── Write phabricator config ──────────────────────────────────────────────
cd "${PHAB_ROOT}"
./bin/config set mysql.user 'phabricator'
./bin/config set mysql.pass "${DB_PASS}"

# ── Run storage upgrade ───────────────────────────────────────────────────
info "Running bin/storage upgrade (this may take a minute)..."
./bin/storage upgrade --force --user phabricator --password "${DB_PASS}"

# ── Copy web server config ────────────────────────────────────────────────
if [[ "$STACK_CHOICE" == "1" ]]; then
    CONF_SRC="${PHAB_ROOT}/support/apache/phabricator.conf"
    if [[ "$DISTRO" == "debian" ]]; then
        cp "$CONF_SRC" /etc/apache2/sites-available/phabricator.conf
        a2ensite phabricator
        a2dissite 000-default 2>/dev/null || true
        systemctl reload apache2
    else
        cp "$CONF_SRC" /etc/httpd/conf.d/phabricator.conf
        systemctl reload httpd
    fi
    warn "Edit /etc/apache2/sites-available/phabricator.conf (or /etc/httpd/conf.d/) to set YOUR_DOMAIN and document root."
else
    CONF_SRC="${PHAB_ROOT}/support/nginx/phabricator.conf"
    if [[ "$DISTRO" == "debian" ]]; then
        cp "$CONF_SRC" /etc/nginx/sites-available/phabricator
        ln -sf /etc/nginx/sites-available/phabricator /etc/nginx/sites-enabled/phabricator
        rm -f /etc/nginx/sites-enabled/default
        nginx -t && systemctl reload nginx
    else
        cp "$CONF_SRC" /etc/nginx/conf.d/phabricator.conf
        nginx -t && systemctl reload nginx
    fi
    warn "Edit the nginx config to set YOUR_DOMAIN, document root, and PHP-FPM socket path."
fi

echo ""
info "Setup complete!"
info "Next steps:"
info "  1. Edit the web server config to set YOUR_DOMAIN and /path/to/phabricator."
info "  2. Run:  ./bin/config set phabricator.base-uri 'http://YOUR_DOMAIN/'"
info "  3. Start daemons: ./bin/phd start"
info "  4. Open http://YOUR_DOMAIN/ in your browser to finish setup."
