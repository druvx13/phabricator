#!/usr/bin/env bash
# verify.sh – Phabricator environment readiness checker
#
# Usage:  bash verify.sh
#
# Checks:
#   1. PHP version (>= 7.2, recommended 8.1+)
#   2. Required PHP extensions
#   3. Web server (Apache or Nginx) running
#   4. MySQL/MariaDB running and accessible
#   5. Node.js installed (for Aphlict)
#   6. Git installed
#   7. Write permissions on conf/local/, /var/repo, /var/phabricator/files
#
# Exit codes:
#   0 – all checks passed
#   1 – one or more checks failed

set -uo pipefail

# ── Colour helpers ─────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
pass()  { echo -e "  ${GREEN}[PASS]${NC}  $*"; }
fail()  { echo -e "  ${RED}[FAIL]${NC}  $*"; ERRORS=$((ERRORS + 1)); }
warn()  { echo -e "  ${YELLOW}[WARN]${NC}  $*"; }
header(){ echo -e "\n${CYAN}── $* ──${NC}"; }

ERRORS=0
PHAB_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── 1. PHP version ─────────────────────────────────────────────────────────
header "PHP Version"
if command -v php &>/dev/null; then
    PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
    PHP_MAJOR="$(php -r 'echo PHP_MAJOR_VERSION;')"
    PHP_MINOR="$(php -r 'echo PHP_MINOR_VERSION;')"
    if [[ "$PHP_MAJOR" -lt 7 ]] || { [[ "$PHP_MAJOR" -eq 7 ]] && [[ "$PHP_MINOR" -lt 2 ]]; }; then
        fail "PHP ${PHP_VERSION} is below the minimum required version (7.2)."
    elif [[ "$PHP_MAJOR" -lt 8 ]]; then
        warn "PHP ${PHP_VERSION} is supported but PHP 8.1+ is recommended for best compatibility."
    else
        pass "PHP ${PHP_VERSION}"
    fi
else
    fail "PHP is not installed or not in PATH."
fi

# ── 2. Required PHP extensions ─────────────────────────────────────────────
header "PHP Extensions"
REQUIRED_EXTS=(curl gd mbstring pdo_mysql xml zip json opcache iconv)
for ext in "${REQUIRED_EXTS[@]}"; do
    # opcache may appear as "Zend OPcache" in php -m output
    if php -m 2>/dev/null | grep -qi "^${ext}$" || \
       { [[ "$ext" == "opcache" ]] && php -m 2>/dev/null | grep -qi "opcache"; }; then
        pass "${ext}"
    else
        fail "${ext} extension is missing. Install: sudo apt-get install php${PHP_MAJOR}.${PHP_MINOR}-${ext}"
    fi
done

# ── 3. Web server ──────────────────────────────────────────────────────────
header "Web Server"
if command -v apache2 &>/dev/null || command -v httpd &>/dev/null; then
    if systemctl is-active --quiet apache2 2>/dev/null || systemctl is-active --quiet httpd 2>/dev/null; then
        pass "Apache is running."
    else
        warn "Apache is installed but not running. Start with: sudo systemctl start apache2"
    fi
    # Check mod_rewrite
    if apache2ctl -M 2>/dev/null | grep -q rewrite_module || httpd -M 2>/dev/null | grep -q rewrite_module; then
        pass "mod_rewrite is enabled."
    else
        warn "mod_rewrite may not be enabled. Run: sudo a2enmod rewrite"
    fi
fi

if command -v nginx &>/dev/null; then
    if systemctl is-active --quiet nginx 2>/dev/null; then
        pass "Nginx is running."
    else
        warn "Nginx is installed but not running. Start with: sudo systemctl start nginx"
    fi
fi

if ! command -v apache2 &>/dev/null && ! command -v httpd &>/dev/null && ! command -v nginx &>/dev/null; then
    fail "No web server (Apache or Nginx) found in PATH."
fi

# ── 4. MySQL / MariaDB ─────────────────────────────────────────────────────
header "MySQL / MariaDB"
if command -v mysql &>/dev/null; then
    if systemctl is-active --quiet mysql 2>/dev/null || systemctl is-active --quiet mariadb 2>/dev/null; then
        pass "MySQL/MariaDB is running."
    else
        warn "MySQL/MariaDB is installed but not running. Start with: sudo systemctl start mariadb"
    fi

    # Try to connect (will succeed without password if run as root)
    if mysql -u root --connect-timeout=3 -e "SELECT 1;" &>/dev/null; then
        pass "Connected to MySQL/MariaDB as root (no password required from OS root)."
    else
        warn "Could not connect to MySQL as root. Verify credentials or run as root."
    fi
else
    fail "MySQL/MariaDB client (mysql) not found in PATH."
fi

# ── 5. Node.js ─────────────────────────────────────────────────────────────
header "Node.js (Aphlict)"
if command -v node &>/dev/null; then
    NODE_VERSION="$(node --version)"
    NODE_MAJOR="$(node --version | tr -d 'v' | cut -d. -f1)"
    if [[ "$NODE_MAJOR" -lt 10 ]]; then
        fail "Node.js ${NODE_VERSION} is below minimum (10.x). Recommended: 18 LTS."
    elif [[ "$NODE_MAJOR" -lt 18 ]]; then
        warn "Node.js ${NODE_VERSION} is supported. Node.js 18 LTS is recommended."
    else
        pass "Node.js ${NODE_VERSION}"
    fi
else
    warn "Node.js is not installed. Aphlict (notifications) will not work."
fi

# ── 6. Git ─────────────────────────────────────────────────────────────────
header "Git"
if command -v git &>/dev/null; then
    GIT_VERSION="$(git --version | awk '{print $3}')"
    pass "Git ${GIT_VERSION}"
else
    fail "Git is not installed. Required for Diffusion repository hosting."
fi

# ── 7. Write permissions ───────────────────────────────────────────────────
header "Write Permissions"
check_writable() {
    local dir="$1"
    if [[ -d "$dir" ]]; then
        if [[ -w "$dir" ]]; then
            pass "${dir} is writable by $(whoami)."
        else
            fail "${dir} exists but is not writable by $(whoami)."
        fi
    else
        warn "${dir} does not exist yet. It will be created during setup."
    fi
}

check_writable "${PHAB_ROOT}/conf/local"
check_writable "/var/repo"
check_writable "/var/phabricator/files"

# ── Summary ────────────────────────────────────────────────────────────────
echo ""
if [[ "$ERRORS" -eq 0 ]]; then
    echo -e "${GREEN}All checks passed. Your environment looks ready for Phabricator.${NC}"
    exit 0
else
    echo -e "${RED}${ERRORS} check(s) failed. Please resolve the issues above before installing.${NC}"
    echo "  See docs/INSTALL.md and docs/TROUBLESHOOTING.md for guidance."
    exit 1
fi
