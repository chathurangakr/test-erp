#!/usr/bin/env bash
# =====================================================================================
#  Coconut Factory ERP — zero-touch installer for Ubuntu (22.04, 24.04 and later)
# =====================================================================================
#
#  Installs and configures EVERYTHING on a fresh Ubuntu computer:
#    PHP 8.5 + PHP-FPM, Nginx (HTTPS), MariaDB (root password, database, app user),
#    Composer, Node.js 22, the ERP code, its settings (.env), database tables and master
#    data, the first administrator, the firewall, the scheduler and nightly backups.
#
#  Usage:
#    cp deploy/provision.env.example deploy/provision.env   # then fill it in
#    bash deploy/provision.sh                               # or: sudo bash deploy/provision.sh
#    bash deploy/provision.sh /path/to/other-settings.env   # a settings file elsewhere
#
#  Safe to run again at any time: it updates the code, applies new migrations and restarts
#  the services. It never drops a database or deletes records. To update an installed ERP,
#  use deploy/update.sh — it takes a backup first and then runs this installer.
#
#  A full log is written to /var/log/coconut-erp-provision.log (passwords are never logged).
# =====================================================================================
set -Eeuo pipefail

SCRIPT_PATH="$(readlink -f "${BASH_SOURCE[0]}")"
SCRIPT_DIR="$(dirname "$SCRIPT_PATH")"
CONFIG_FILE="$(readlink -f "${1:-$SCRIPT_DIR/provision.env}")"

PHP_VERSION=8.5
NODE_MAJOR=22
SECRETS_DIR=/etc/coconut-erp
SECRETS_FILE=$SECRETS_DIR/secrets.env
LOG_FILE=/var/log/coconut-erp-provision.log

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m! %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

trap 'die "Step failed at line $LINENO: $BASH_COMMAND  (full log: $LOG_FILE)"' ERR

# -------------------------------------------------------------------- settings file
[[ -f "$CONFIG_FILE" ]] || die "Settings file not found: $CONFIG_FILE
Copy deploy/provision.env.example to deploy/provision.env and fill it in."

# ------------------------------------------------- become root (once, with the password)
if [[ $EUID -ne 0 ]]; then
    chmod 600 "$CONFIG_FILE" 2>/dev/null || true
    # shellcheck disable=SC1090
    SUDO_PASSWORD="$(set -a; . "$CONFIG_FILE"; printf '%s' "${SUDO_PASSWORD:-}")"

    if [[ -z "$SUDO_PASSWORD" ]]; then
        exec sudo bash "$SCRIPT_PATH" "$CONFIG_FILE"
    fi

    printf '%s\n' "$SUDO_PASSWORD" | sudo -S -p '' true 2>/dev/null \
        || die "The SUDO_PASSWORD in $CONFIG_FILE is not this computer's administrator password."

    trap - ERR
    status=0
    printf '%s\n' "$SUDO_PASSWORD" | sudo -S -p '' bash "$SCRIPT_PATH" "$CONFIG_FILE" || status=$?
    exit "$status"
fi

# From here on we are root. Nothing reads the keyboard (or a leftover password), the
# terminal stays on fd 3 for the final summary, and everything else also goes to the log.
exec < /dev/null
exec 3>&1
mkdir -p "$(dirname "$LOG_FILE")"
touch "$LOG_FILE" && chmod 600 "$LOG_FILE"
exec > >(tee -a "$LOG_FILE") 2>&1
echo "---- provisioning started $(date -Is) ----"

chmod 600 "$CONFIG_FILE"
set -a
# shellcheck disable=SC1090
. "$CONFIG_FILE"
set +a
unset SUDO_PASSWORD

: "${REPO_URL:=https://github.com/chathurangakr/test-erp.git}"
: "${REPO_BRANCH:=main}"
: "${GIT_TOKEN:=}"
: "${SOURCE_DIR:=}"
: "${APP_DIR:=/var/www/coconut-erp}"
: "${APP_USER:=coconuterp}"
: "${SERVER_NAME:=}"
: "${HTTP_PORT:=80}"
: "${HTTPS_PORT:=443}"
: "${TLS_CERT_FILE:=}"
: "${TLS_KEY_FILE:=}"
: "${LETSENCRYPT:=yes}"
: "${SELF_REGISTRATION:=no}"
: "${ENABLE_FIREWALL:=yes}"
: "${MARIADB_ROOT_PASSWORD:=}"
: "${DB_NAME:=coconut_erp}"
: "${DB_USER:=coconut_erp}"
: "${DB_PASSWORD:=}"
: "${ADMIN_NAME:=Administrator}"
: "${ADMIN_EMAIL:=}"
: "${ADMIN_PASSWORD:=}"
: "${APP_NAME:=Coconut ERP}"
: "${TIMEZONE:=Asia/Colombo}"
: "${BACKUP_DIR:=/var/backups/coconut-erp}"
: "${BACKUP_TIME:=02:30}"
: "${BACKUP_KEEP_DAYS:=30}"
: "${DRIVE_KEEP_DAYS:=90}"
: "${SMTP_USERNAME:=}"
: "${SMTP_PASSWORD:=}"
: "${SMTP_HOST:=smtp.gmail.com}"
: "${SMTP_PORT:=587}"
: "${MAIL_FROM_ADDRESS:=}"

export DEBIAN_FRONTEND=noninteractive
APP_HOME="/var/lib/$APP_USER"
FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm-coconut-erp.sock"

# ------------------------------------------------------------------- checks
say "Checking the computer and the settings"

. /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || die "This installer is for Ubuntu (found: ${PRETTY_NAME:-unknown})."
ok "$PRETTY_NAME"

[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "DB_NAME may contain only letters, digits and _."
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "DB_USER may contain only letters, digits and _."
[[ "$APP_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] || die "APP_USER is not a valid account name."
[[ "$HTTP_PORT" =~ ^[0-9]+$ && "$HTTPS_PORT" =~ ^[0-9]+$ ]] || die "HTTP_PORT and HTTPS_PORT must be numbers."
[[ "$HTTP_PORT" != "$HTTPS_PORT" ]] || die "HTTP_PORT and HTTPS_PORT must be different."
[[ "$BACKUP_TIME" =~ ^([01][0-9]|2[0-3]):[0-5][0-9]$ ]] || die "BACKUP_TIME must look like 02:30."
[[ "$LETSENCRYPT" == "yes" || "$LETSENCRYPT" == "no" ]] || die "LETSENCRYPT must be yes or no."
[[ "$SELF_REGISTRATION" == "yes" || "$SELF_REGISTRATION" == "no" ]] || die "SELF_REGISTRATION must be yes or no."
[[ -n "$ADMIN_EMAIL" ]] || die "ADMIN_EMAIL is required (the first administrator's sign-in)."
if [[ -n "$TLS_CERT_FILE" || -n "$TLS_KEY_FILE" ]]; then
    [[ -f "$TLS_CERT_FILE" && -f "$TLS_KEY_FILE" ]] || die "TLS_CERT_FILE and TLS_KEY_FILE must both point at existing files."
fi
SMTP_PASSWORD="${SMTP_PASSWORD// /}"   # Gmail shows app passwords in groups of four
if [[ -n "$SMTP_USERNAME" ]]; then
    [[ -n "$SMTP_PASSWORD" ]] || die "SMTP_PASSWORD is required when SMTP_USERNAME is set (a Gmail app password)."
    [[ "$SMTP_PORT" =~ ^[0-9]+$ ]] || die "SMTP_PORT must be a number."
    [[ "$SMTP_PASSWORD" != *'"'* && "$SMTP_PASSWORD" != *'\'* ]] || die "SMTP_PASSWORD may not contain quotes or backslashes."
fi
for value in "$MARIADB_ROOT_PASSWORD" "$DB_PASSWORD"; do
    [[ "$value" != *"'"* && "$value" != *'\'* && "$value" != *'"'* ]] \
        || die "Database passwords may not contain quotes or backslashes."
done
ok "Settings look complete"

# ------------------------------------------------------------- secrets (generated once)
say "Preparing passwords"
install -d -m 700 "$SECRETS_DIR"
touch "$SECRETS_FILE" && chmod 600 "$SECRETS_FILE"

# A finite read (no endless pipe for strict mode to trip over): ~250 usable characters.
random_secret() { head -c 1024 /dev/urandom | tr -dc 'A-Za-z0-9' | cut -c "1-${1:-32}"; }
stored_secret() { grep -E "^$1=" "$SECRETS_FILE" | tail -n1 | cut -d= -f2- || true; }
remember_secret() {
    local key="$1" value="$2"
    sed -i "/^${key}=/d" "$SECRETS_FILE"
    printf '%s=%s\n' "$key" "$value" >> "$SECRETS_FILE"
}
resolve_secret() {   # the setting, else the one already generated, else a new one
    local key="$1" given="$2" length="${3:-32}" value
    value="$given"
    [[ -n "$value" ]] || value="$(stored_secret "$key")"
    [[ -n "$value" ]] || value="$(random_secret "$length")"
    remember_secret "$key" "$value"
    printf '%s' "$value"
}

MARIADB_ROOT_PASSWORD="$(resolve_secret MARIADB_ROOT_PASSWORD "$MARIADB_ROOT_PASSWORD")"
DB_PASSWORD="$(resolve_secret DB_PASSWORD "$DB_PASSWORD")"

# The ERP's production password rules: 12+ characters, upper and lower case, a number and a
# symbol (and not on a public leak list, which the ERP itself checks).
strong_password() {
    local p="$1"
    (( ${#p} >= 12 )) && [[ "$p" =~ [a-z] && "$p" =~ [A-Z] && "$p" =~ [0-9] && "$p" =~ [^A-Za-z0-9] ]]
}
generated_admin_password() {   # e.g. "Xk3m-Qa9Tz-7Lp2-Ab7"
    printf '%s-%s-%s-Ab7' "$(random_secret 4)" "$(random_secret 5)" "$(random_secret 4)"
}
if [[ -n "$ADMIN_PASSWORD" ]]; then
    strong_password "$ADMIN_PASSWORD" || die "ADMIN_PASSWORD must have at least 12 characters, with upper and lower case letters, a number and a symbol (or leave it blank to have one generated)."
else
    ADMIN_PASSWORD="$(stored_secret ADMIN_PASSWORD)"
    strong_password "$ADMIN_PASSWORD" || ADMIN_PASSWORD="$(generated_admin_password)"
fi
remember_secret ADMIN_PASSWORD "$ADMIN_PASSWORD"
ok "Passwords ready (kept in $SECRETS_FILE, root only)"

# ------------------------------------------------------------------- clock
say "Setting the time zone to $TIMEZONE"
timedatectl set-timezone "$TIMEZONE" 2>/dev/null || ln -sf "/usr/share/zoneinfo/$TIMEZONE" /etc/localtime
ok "$(date)"

# ----------------------------------------------------------------- packages
# Oracle Cloud's Ubuntu images keep their own iptables rules (saved by iptables-persistent),
# which reject everything but SSH. ufw would uninstall that package, so there ufw is not
# installed and the web ports are opened in Oracle's rules instead (see "firewall" below).
CLOUD_IPTABLES=no
if [[ -f /etc/iptables/rules.v4 ]] && grep -q 'icmp-host-prohibited' /etc/iptables/rules.v4; then
    CLOUD_IPTABLES=yes
fi
firewall_package=ufw
[[ "$CLOUD_IPTABLES" == "no" ]] || firewall_package=""

say "Installing system packages (this takes a few minutes)"
apt-get update -q
apt-get install -y -q ca-certificates curl gnupg git unzip rsync openssl acl cron rclone \
    software-properties-common nginx mariadb-server mariadb-client $firewall_package \
    fail2ban nftables unattended-upgrades
ok "Base packages, Nginx and MariaDB installed"

if ! apt-cache show "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -q
fi
apt-get install -y -q "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" \
    "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-gd"
update-alternatives --set php "/usr/bin/php${PHP_VERSION}" >/dev/null 2>&1 || true
ok "$(php -v | head -n1)"

if ! command -v node >/dev/null 2>&1 || (( $(node -p 'process.versions.node.split(".")[0]') < NODE_MAJOR )); then
    install -d -m 755 /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list
    apt-get update -q
    apt-get install -y -q nodejs
fi
ok "Node.js $(node -v)"

if ! command -v composer >/dev/null 2>&1; then
    tmp="$(mktemp -d)"
    curl -fsSL https://getcomposer.org/installer -o "$tmp/composer-setup.php"
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    actual="$(php -r "echo hash_file('sha384', '$tmp/composer-setup.php');")"
    [[ "$expected" == "$actual" ]] || die "The Composer installer failed its checksum."
    php "$tmp/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
    rm -rf "$tmp"
fi
ok "$(composer --version 2>/dev/null | head -n1)"

# ------------------------------------------------------------------- MariaDB
say "Configuring MariaDB"
systemctl enable mariadb >/dev/null
# An installation interrupted (e.g. a power cut) can leave MariaDB without its own system
# tables. Only in that case — no `mysql` system database at all — initialise them. Existing
# data is never touched.
#
# It runs AS the mysql account: Ubuntu's AppArmor profile for mariadbd does not allow the
# server to switch user, so the usual `--user=mysql` from root fails with "Permission denied"
# (which is also why the package's own first-time setup can leave the tables missing).
if [[ ! -d /var/lib/mysql/mysql ]]; then
    systemctl stop mariadb >/dev/null 2>&1 || true
    install -d -o mysql -g mysql -m 750 /var/lib/mysql
    chown -R mysql:mysql /var/lib/mysql
    runuser -u mysql -- mariadb-install-db --datadir=/var/lib/mysql \
        --auth-root-authentication-method=socket --auth-root-socket-user=root --skip-test-db >/dev/null
    warn "MariaDB's system tables were missing and have been initialised"
fi
systemctl start mariadb
# Wait until it accepts connections (it can take a few seconds after starting).
for _ in $(seq 1 60); do
    mariadb -e 'SELECT 1' >/dev/null 2>&1 && break
    sleep 1
done
mariadb -e 'SELECT 1' >/dev/null 2>&1 || {
    systemctl status mariadb --no-pager || true
    journalctl -u mariadb --no-pager -n 30 || true
    die "MariaDB did not start; see the messages above."
}

SQL_FILE="$(mktemp)"; chmod 600 "$SQL_FILE"
cat > "$SQL_FILE" <<SQL
-- root: Ubuntu's root keeps password-less socket access; the password is for tools.
ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket OR mysql_native_password USING PASSWORD('${MARIADB_ROOT_PASSWORD}');
-- what mariadb-secure-installation would do
DELETE FROM mysql.global_priv WHERE User = '';
DELETE FROM mysql.global_priv WHERE User = 'root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db = 'test' OR Db = 'test\\_%';
-- the ERP's own database and account (never root)
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
mariadb < "$SQL_FILE"
rm -f "$SQL_FILE"

# Only this computer may connect to the database.
if ! grep -rqsE '^\s*bind-address\s*=\s*127\.0\.0\.1' /etc/mysql/; then
    printf '[mysqld]\nbind-address = 127.0.0.1\n' > /etc/mysql/mariadb.conf.d/99-coconut-erp.cnf
    systemctl restart mariadb
fi
ok "Database '${DB_NAME}' and account '${DB_USER}' ready; MariaDB listens on this computer only"

# Memory for the database's working cache: about a third of this computer's memory, in place of
# MariaDB's 128 MB default. Balances, statements and stock are summed from the ledgers, so keeping
# the ledgers in memory is what keeps them fast as years of history build up. Recalculated on every
# install and update; MariaDB is restarted once when its running cache is smaller. Queries
# slower than 2 seconds are logged to /var/log/mysql/coconut-erp-slow.log.
say "Sizing the database cache"
ram_mb="$(awk '/MemTotal/ {print int($2 / 1024)}' /proc/meminfo)"
pool_mb=$(( ram_mb * 35 / 100 / 128 * 128 ))
if (( pool_mb < 256 )); then pool_mb=256; fi
perf_cnf=/etc/mysql/mariadb.conf.d/98-coconut-erp-performance.cnf
perf_new="$(mktemp)"
cat > "$perf_new" <<CNF
[mysqld]
# Written by the Coconut ERP installer from this computer's memory (${ram_mb} MB); rewritten on updates.
innodb_buffer_pool_size = ${pool_mb}M
innodb_log_file_size = 512M
slow_query_log = 1
slow_query_log_file = /var/log/mysql/coconut-erp-slow.log
long_query_time = 2
# Security: the ERP never loads files into the database, so nothing may (LOAD DATA LOCAL).
local_infile = 0
CNF
if ! cmp -s "$perf_new" "$perf_cnf"; then
    install -m 644 "$perf_new" "$perf_cnf"
    install -d -o mysql -g adm -m 2750 /var/log/mysql
    mariadb -e "SET GLOBAL innodb_log_file_size = 512 * 1024 * 1024" >/dev/null 2>&1 || true
    mariadb -e "SET GLOBAL local_infile = 0" >/dev/null 2>&1 || true
    mariadb -e "SET GLOBAL slow_query_log_file = '/var/log/mysql/coconut-erp-slow.log'; SET GLOBAL long_query_time = 2; SET GLOBAL slow_query_log = 1" \
        || warn "Slow-query logging starts when MariaDB next restarts"
fi
# Newer MariaDB cannot grow its cache beyond its start-up size while running; when the running
# cache is smaller than the file says, restart it once (a few seconds) so the file takes effect.
running_mb="$(mariadb -N -B -e 'SELECT @@innodb_buffer_pool_size DIV 1048576' 2>/dev/null || echo 0)"
if (( running_mb + 128 < pool_mb )); then
    systemctl restart mariadb
    for _ in $(seq 1 30); do mariadb -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
    mariadb -e 'SELECT 1' >/dev/null 2>&1 || die "MariaDB did not come back after resizing its cache."
fi
rm -f "$perf_new"
ok "Database cache: ${pool_mb} MB of ${ram_mb} MB memory; slow queries are logged"

# ------------------------------------------------------------------- app account
say "Preparing the ERP's system account '$APP_USER'"
if ! id "$APP_USER" >/dev/null 2>&1; then
    useradd --system --create-home --home-dir "$APP_HOME" --shell /usr/sbin/nologin "$APP_USER"
fi
APP_HOME="$(getent passwd "$APP_USER" | cut -d: -f6)"
ok "Account ready (home $APP_HOME)"

as_app() { runuser -u "$APP_USER" -- env HOME="$APP_HOME" COMPOSER_HOME="$APP_HOME/.composer" "$@"; }

# ------------------------------------------------------------------- the code
say "Getting the ERP code"
install -d -o "$APP_USER" -g "$APP_USER" -m 755 "$APP_DIR"

git_auth=()
if [[ -n "$GIT_TOKEN" ]]; then
    git_auth=(-c "http.extraHeader=Authorization: Basic $(printf 'x-access-token:%s' "$GIT_TOKEN" | base64 -w0)")
fi

if [[ -n "$SOURCE_DIR" ]]; then
    [[ -f "$SOURCE_DIR/artisan" ]] || die "SOURCE_DIR does not contain the ERP (no artisan file): $SOURCE_DIR"
    rsync -a --delete \
        --exclude '.git/' --exclude '.env' --exclude 'vendor/' --exclude 'node_modules/' \
        --exclude 'public/build/' --exclude 'public/hot' --exclude 'storage/' --exclude 'bootstrap/cache/*.php' \
        --exclude 'deploy/provision.env' \
        "$SOURCE_DIR"/ "$APP_DIR"/
    install -d "$APP_DIR/storage"
    rsync -a --ignore-existing --exclude 'logs/*.log' --exclude 'app/backups/' --exclude 'framework/sessions/*' \
        --exclude 'framework/cache/data/*' --exclude 'framework/views/*.php' --exclude 'app/i18n-work/' \
        "$SOURCE_DIR/storage/" "$APP_DIR/storage/"
    chown -R "$APP_USER:$APP_USER" "$APP_DIR"
    ok "Copied from $SOURCE_DIR"
elif [[ -d "$APP_DIR/.git" ]]; then
    as_app git -C "$APP_DIR" "${git_auth[@]}" fetch --quiet origin "$REPO_BRANCH"
    as_app git -C "$APP_DIR" checkout --quiet "$REPO_BRANCH"
    as_app git -C "$APP_DIR" "${git_auth[@]}" pull --quiet --ff-only origin "$REPO_BRANCH"
    ok "Updated to $(as_app git -C "$APP_DIR" log -1 --format='%h %s')"
else
    [[ -z "$(ls -A "$APP_DIR")" ]] || die "$APP_DIR is not empty and is not a git checkout; move it aside first."
    as_app git "${git_auth[@]}" clone --quiet --branch "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
    ok "Downloaded $(as_app git -C "$APP_DIR" log -1 --format='%h %s')"
fi

# ------------------------------------------------------------------- PHP packages
say "Installing PHP libraries"
as_app bash -c "cd '$APP_DIR' && composer install --no-dev --optimize-autoloader --no-interaction --no-progress"
ok "Composer libraries installed"

# ------------------------------------------------------------------- settings (.env)
say "Writing the ERP settings (.env)"
if [[ -z "$SERVER_NAME" ]]; then
    # A cloud server only knows its private address; people reach it by the public one. A
    # cloud is recognised by its metadata service, which answers on 169.254.169.254.
    if [[ "$(curl -s -o /dev/null -m 2 -w '%{http_code}' http://169.254.169.254/ || true)" != "000" ]]; then
        SERVER_NAME="$(curl -fs -m 10 https://checkip.amazonaws.com | tr -d '[:space:]' || true)"
        [[ "$SERVER_NAME" =~ ^[0-9]+(\.[0-9]+){3}$ ]] || SERVER_NAME=""
        [[ -z "$SERVER_NAME" ]] || ok "Cloud server: people will open it at its public address $SERVER_NAME"
    fi
    [[ -n "$SERVER_NAME" ]] || SERVER_NAME="$(hostname -I | awk '{print $1}')"
fi
[[ -n "$SERVER_NAME" ]] || SERVER_NAME="localhost"
APP_URL="https://${SERVER_NAME}"
[[ "$HTTPS_PORT" == "443" ]] || APP_URL="${APP_URL}:${HTTPS_PORT}"

ENV_FILE="$APP_DIR/.env"
if [[ ! -f "$ENV_FILE" ]]; then
    install -o "$APP_USER" -g "$APP_USER" -m 640 "$APP_DIR/.env.example" "$ENV_FILE"
fi
chown "$APP_USER:$APP_USER" "$ENV_FILE"; chmod 640 "$ENV_FILE"

set_env() {   # set_env KEY VALUE — replaces the line, or appends it
    local key="$1" value="$2" line
    line="${key}=\"${value//\"/\\\"}\""
    if grep -qE "^#?\s*${key}=" "$ENV_FILE"; then
        KEY="$key" LINE="$line" perl -i -pe 's/^#?\s*\Q$ENV{KEY}\E=.*$/$ENV{LINE}/' "$ENV_FILE"
    else
        printf '%s\n' "$line" >> "$ENV_FILE"
    fi
}
set_env APP_NAME "$APP_NAME"
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "$APP_URL"
set_env LOG_LEVEL warning
set_env DB_CONNECTION mariadb
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASSWORD"
set_env SESSION_SECURE_COOKIE true
if [[ "$SELF_REGISTRATION" == "yes" ]]; then set_env AUTH_SELF_REGISTRATION true; else set_env AUTH_SELF_REGISTRATION false; fi
# Outgoing email (the weekly update). Without SMTP_USERNAME mail is only written to the log.
if [[ -n "$SMTP_USERNAME" ]]; then
    set_env MAIL_MAILER smtp
    set_env MAIL_SCHEME smtp
    set_env MAIL_HOST "$SMTP_HOST"
    set_env MAIL_PORT "$SMTP_PORT"
    set_env MAIL_USERNAME "$SMTP_USERNAME"
    set_env MAIL_PASSWORD "$SMTP_PASSWORD"
    set_env MAIL_FROM_ADDRESS "${MAIL_FROM_ADDRESS:-$SMTP_USERNAME}"
    set_env MAIL_FROM_NAME "$APP_NAME"
fi

if ! grep -qE '^APP_KEY="?base64:' "$ENV_FILE"; then
    as_app php "$APP_DIR/artisan" key:generate --force --no-interaction
fi
ok "Settings written for $APP_URL"

install -d -o "$APP_USER" -g "$APP_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chown -R "$APP_USER:$APP_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

# ------------------------------------------------------------------- database
say "Creating and updating the database tables"
# A previous run's caches must not hide the new version (no database needed): cached settings
# would hide the .env just written, and cached routes would hide new pages from the screens'
# build, which reads the route list (an update adding a page then failed to build).
as_app php "$APP_DIR/artisan" config:clear --no-interaction >/dev/null
as_app php "$APP_DIR/artisan" route:clear --no-interaction >/dev/null
as_app php "$APP_DIR/artisan" event:clear --no-interaction >/dev/null
as_app php "$APP_DIR/artisan" migrate --force --no-interaction

product_count="$(mariadb -N -B -e "SELECT COUNT(*) FROM \`${DB_NAME}\`.products" 2>/dev/null || echo 0)"
if [[ "$product_count" == "0" ]]; then
    # A fresh installation: units, products, the MAIN warehouse, production recipes, the chart
    # of accounts, product account mappings, cash/bank accounts, roles and permissions.
    # No business transactions and no users.
    as_app php "$APP_DIR/artisan" db:seed --force --no-interaction
    ok "Master data loaded"
else
    # An update: keep everyone's edits to master data; bring permissions up to date and add
    # only the accounts, products and recipes a newer version introduced (never changing any).
    as_app php "$APP_DIR/artisan" db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force --no-interaction
    as_app php "$APP_DIR/artisan" masters:add-new --no-interaction
    ok "Existing data kept; roles, permissions and new master data brought up to date"
fi

# ------------------------------------------------------------------- screens
say "Building the screens (this takes a minute)"
as_app bash -c "cd '$APP_DIR' && npm ci --no-audit --no-fund --loglevel=error && npm run build"
ok "Screens built"

say "Creating the administrator account"
# An administrator may have switched the first account off once they had their own login. An
# update leaves it switched off — it never re-activates or re-promotes it.
admin_active="$(mariadb -N -B -e "SELECT is_active FROM \`${DB_NAME}\`.users WHERE email = '${ADMIN_EMAIL//\'/\'\'}'" 2>/dev/null || true)"
if [[ "$admin_active" == "0" ]]; then
    ok "The account $ADMIN_EMAIL is switched off on this system; left as it is"
else
    runuser -u "$APP_USER" -- env HOME="$APP_HOME" COCONUT_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
        php "$APP_DIR/artisan" authorization:create-admin "$ADMIN_EMAIL" --name="$ADMIN_NAME" --no-interaction
fi

as_app php "$APP_DIR/artisan" optimize --no-interaction >/dev/null
ok "Caches warmed"

# ------------------------------------------------------------------- PHP-FPM
say "Configuring PHP-FPM"
cat > "/etc/php/${PHP_VERSION}/fpm/pool.d/coconut-erp.conf" <<FPM
; Coconut Factory ERP — runs as its own account so it can write storage/ and nothing else.
[coconut-erp]
user = ${APP_USER}
group = ${APP_USER}
listen = ${FPM_SOCKET}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 12
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
pm.max_requests = 500
php_admin_value[upload_max_filesize] = 16M
php_admin_value[post_max_size] = 16M
php_admin_value[memory_limit] = 256M
php_admin_flag[expose_php] = off
FPM
systemctl enable "php${PHP_VERSION}-fpm" >/dev/null
systemctl restart "php${PHP_VERSION}-fpm"
ok "PHP-FPM pool 'coconut-erp' running as $APP_USER"

# ------------------------------------------------------------------- HTTPS certificate
say "Preparing the HTTPS certificate"
# A free trusted certificate (Let's Encrypt) needs a real domain name that points at this
# computer and port 80 open to the internet — e.g. a cloud server with a DuckDNS name. An IP
# address, a factory-network name or another HTTP port keeps the self-signed certificate.
ACME_ROOT=/var/www/letsencrypt
LE_LIVE="/etc/letsencrypt/live/$SERVER_NAME"
WANT_LETSENCRYPT=no
if [[ "$LETSENCRYPT" == "yes" && -z "$TLS_CERT_FILE" && "$HTTP_PORT" == "80" \
      && "$SERVER_NAME" =~ ^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$ && ! "$SERVER_NAME" =~ ^[0-9.]+$ \
      && ! "$SERVER_NAME" =~ \.(local|lan|home|internal|localhost)$ ]]; then
    WANT_LETSENCRYPT=yes
fi
TRUSTED_CERT=no
if [[ -n "$TLS_CERT_FILE" ]]; then
    CERT="$TLS_CERT_FILE"; KEY="$TLS_KEY_FILE"; TRUSTED_CERT=yes
    ok "Using your certificate $CERT"
elif [[ "$WANT_LETSENCRYPT" == "yes" && -f "$LE_LIVE/fullchain.pem" ]]; then
    CERT="$LE_LIVE/fullchain.pem"; KEY="$LE_LIVE/privkey.pem"; TRUSTED_CERT=yes
    ok "Using the Let's Encrypt certificate for $SERVER_NAME (it renews itself)"
else
    CERT=/etc/ssl/coconut-erp/coconut-erp.crt; KEY=/etc/ssl/coconut-erp/coconut-erp.key
    install -d -m 755 /etc/ssl/coconut-erp
    if [[ ! -f "$CERT" ]] \
        || ! openssl x509 -checkend 2592000 -noout -in "$CERT" >/dev/null \
        || ! openssl x509 -noout -ext subjectAltName -in "$CERT" 2>/dev/null | grep -qF "$SERVER_NAME"; then
        san="DNS:localhost,DNS:$(hostname),IP:127.0.0.1"
        for ip in $(hostname -I); do [[ "$ip" == *:* ]] || san="$san,IP:$ip"; done
        if [[ "$SERVER_NAME" =~ ^[0-9.]+$ ]]; then
            [[ "$san" == *"IP:$SERVER_NAME"* ]] || san="$san,IP:$SERVER_NAME"
        else
            san="$san,DNS:$SERVER_NAME"
        fi
        openssl req -x509 -nodes -newkey rsa:2048 -days 3650 -sha256 \
            -subj "/CN=${SERVER_NAME}/O=Coconut Factory ERP" -addext "subjectAltName=${san}" \
            -keyout "$KEY" -out "$CERT" 2>/dev/null
        chmod 600 "$KEY"; chmod 644 "$CERT"
        ok "Self-signed certificate created for: ${san//,/ }"
    else
        ok "Existing certificate still valid"
    fi
fi

# ------------------------------------------------------------------- Nginx
say "Configuring Nginx"
redirect_port=""; [[ "$HTTPS_PORT" == "443" ]] || redirect_port=":${HTTPS_PORT}"
install -d -m 755 "$ACME_ROOT"
write_nginx() {   # uses $CERT, $KEY and $TRUSTED_CERT
# HSTS tells browsers to use HTTPS only for a year. Only with a TRUSTED certificate: with a
# self-signed one it would stop a browser from ever accepting the certificate warning again.
local hsts=""
if [[ "$TRUSTED_CERT" == "yes" ]]; then hsts='add_header Strict-Transport-Security "max-age=31536000" always;'; fi
# Sent with every reply. A location with an add_header of its own drops the server's ones, so
# /build/ repeats them. (The Content-Security-Policy comes from the ERP, which knows each page's
# nonce — app/Http/Middleware/ContentSecurityPolicy.php.)
local security_headers="add_header X-Frame-Options \"SAMEORIGIN\" always;
    add_header X-Content-Type-Options \"nosniff\" always;
    add_header Referrer-Policy \"strict-origin-when-cross-origin\" always;
    add_header Permissions-Policy \"camera=(), microphone=(), geolocation=(), payment=(), usb=()\" always;
    ${hsts}"
cat > /etc/nginx/sites-available/coconut-erp <<NGINX
# Coconut Factory ERP — written by deploy/provision.sh (re-running the installer replaces it)
server {
    listen ${HTTP_PORT};
    listen [::]:${HTTP_PORT};
    server_name _;

    # Let's Encrypt proves the domain by fetching a file from here over plain HTTP.
    location ^~ /.well-known/acme-challenge/ {
        root ${ACME_ROOT};
        default_type text/plain;
        try_files \$uri =404;
    }

    # The API is refused over plain HTTP, never redirected: a redirect would let a wrongly set
    # up app keep working while its bearer token crossed the network unencrypted every time.
    # (The ERP itself refuses it too — app/Http/Middleware/RequireHttpsForApi.php.)
    location ^~ /api/ {
        default_type application/json;
        return 403 '{"message":"The API is only available over HTTPS."}';
    }

    location / {
        return 301 https://\$host${redirect_port}\$request_uri;
    }
}

server {
    listen ${HTTPS_PORT} ssl;
    listen [::]:${HTTPS_PORT} ssl;
    server_name _;

    ssl_certificate     ${CERT};
    ssl_certificate_key ${KEY};
    ssl_protocols       TLSv1.2 TLSv1.3;
    # Only modern, forward-secret ciphers for TLS 1.2 (Mozilla's "intermediate" list).
    ssl_ciphers         ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305;
    ssl_prefer_server_ciphers off;
    ssl_session_cache   shared:coconut_erp:10m;
    ssl_session_tickets off;

    root ${APP_DIR}/public;
    index index.php;
    charset utf-8;
    client_max_body_size 16m;
    server_tokens off;

    ${security_headers}

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location /build/ {
        expires 1y;
        access_log off;
        add_header Cache-Control "public, immutable";
        ${security_headers}
        try_files \$uri =404;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|\$) {
        fastcgi_pass unix:${FPM_SOCKET};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 120s;
        # The reply headers list the screens' files (Link preload) with the full address, so a
        # longer domain name outgrows nginx's 4 KB default and every page answered 502.
        fastcgi_buffer_size 32k;
        fastcgi_buffers 16 32k;
        fastcgi_busy_buffers_size 64k;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
}
write_nginx
ln -sf /etc/nginx/sites-available/coconut-erp /etc/nginx/sites-enabled/coconut-erp
# Ubuntu's nginx.conf still offers TLS 1.0 / 1.1 by default; nothing here should.
sed -i -E 's/^(\s*)ssl_protocols .*/\1ssl_protocols TLSv1.2 TLSv1.3;/' /etc/nginx/nginx.conf
# The stock "Welcome to nginx" site would answer instead of the ERP on port 80.
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable nginx >/dev/null
systemctl reload nginx || systemctl restart nginx
ok "Nginx serves the ERP on ports $HTTP_PORT (redirect) and $HTTPS_PORT (HTTPS)"

# ------------------------------------------------------------------- trusted certificate
if [[ "$WANT_LETSENCRYPT" == "yes" && "$TRUSTED_CERT" == "no" ]]; then
    say "Getting a free trusted certificate for $SERVER_NAME (Let's Encrypt)"
    apt-get install -y -q certbot
    # Renewal runs by itself (certbot's timer) and reloads Nginx through the deploy hook.
    if certbot certonly --webroot -w "$ACME_ROOT" -d "$SERVER_NAME" --non-interactive --agree-tos \
            --register-unsafely-without-email --keep-until-expiring \
            --deploy-hook "systemctl reload nginx"; then
        CERT="$LE_LIVE/fullchain.pem"; KEY="$LE_LIVE/privkey.pem"; TRUSTED_CERT=yes
        write_nginx
        nginx -t
        systemctl reload nginx
        ok "Trusted certificate installed; browsers no longer warn, and it renews itself"
    else
        warn "Could not get a certificate for $SERVER_NAME. Check that the name points at this"
        warn "server's public address and that port 80 is open; then run the installer again."
        warn "The self-signed certificate stays in use meanwhile."
    fi
fi

# ------------------------------------------------------------------- app menu icon
# An icon in Ubuntu's "Show Apps" menu that opens the ERP in the default browser. It points at
# localhost, so it keeps working on this computer even if its network address changes.
say "Adding the ERP to the Ubuntu app menu"
LOCAL_URL="https://localhost"
[[ "$HTTPS_PORT" == "443" ]] || LOCAL_URL="${LOCAL_URL}:${HTTPS_PORT}"
install -D -m 644 "$APP_DIR/public/favicon.svg" /usr/share/icons/hicolor/scalable/apps/coconut-erp.svg
# Missing on an Ubuntu without a desktop; the icon is then simply unused.
install -d -m 755 /usr/share/applications
cat > /usr/share/applications/coconut-erp.desktop <<DESKTOP
[Desktop Entry]
Type=Application
Name=${APP_NAME}
GenericName=Factory ERP
Comment=Open the Coconut Factory ERP in the web browser
Keywords=coconut;erp;factory;stock;
Exec=xdg-open ${LOCAL_URL}
Icon=coconut-erp
Terminal=false
Categories=Office;
DESKTOP
chmod 644 /usr/share/applications/coconut-erp.desktop
gtk-update-icon-cache -q -t /usr/share/icons/hicolor >/dev/null 2>&1 || true
update-desktop-database -q /usr/share/applications >/dev/null 2>&1 || true
ok "\"${APP_NAME}\" added to Show Apps; it opens ${LOCAL_URL}"

# ------------------------------------------------------------------- firewall
if [[ "$CLOUD_IPTABLES" == "yes" ]]; then
    say "Opening the web ports in the cloud server's firewall"
    for port in "$HTTP_PORT" "$HTTPS_PORT"; do
        if ! iptables -C INPUT -p tcp -m state --state NEW --dport "$port" -j ACCEPT 2>/dev/null; then
            # Before Oracle's final "reject everything else" rule, or it would never be reached.
            reject_line="$(iptables -L INPUT --line-numbers -n | awk '/REJECT/ && /icmp-host-prohibited/ {print $1; exit}')"
            iptables -I INPUT "${reject_line:-1}" -p tcp -m state --state NEW --dport "$port" -j ACCEPT
        fi
    done
    netfilter-persistent save >/dev/null 2>&1 || iptables-save > /etc/iptables/rules.v4
    ok "Ports ${HTTP_PORT} and ${HTTPS_PORT} open here — also allow them in the cloud's own security list"
elif [[ "$ENABLE_FIREWALL" == "yes" ]]; then
    say "Configuring the firewall"
    ufw allow OpenSSH >/dev/null 2>&1 || ufw allow 22/tcp >/dev/null
    ufw allow "${HTTP_PORT}/tcp" >/dev/null
    ufw allow "${HTTPS_PORT}/tcp" >/dev/null
    ufw --force enable >/dev/null
    ok "Firewall on: SSH, ${HTTP_PORT} and ${HTTPS_PORT} open; the database stays closed"
fi

# ------------------------------------------------------------------- server hardening
say "Hardening the server"
# fail2ban: an address that fails to sign in over SSH 5 times in 10 minutes is shut out for an
# hour, longer each time it comes back. Its bans live in its own nftables table, so saving the
# cloud firewall's rules (above) never saves them.
cat > /etc/fail2ban/jail.d/coconut-erp.conf <<'JAIL'
# Written by the Coconut ERP installer (deploy/provision.sh); rewritten on updates.
[DEFAULT]
banaction = nftables-multiport
banaction_allports = nftables-allports
bantime = 1h
bantime.increment = true
bantime.maxtime = 1w
findtime = 10m
maxretry = 5

[sshd]
enabled = true
# "aggressive" also counts a bot that offers keys and is turned away ("Connection closed by
# authenticating user … [preauth]") — with password sign-in off that is all most bots ever do.
mode = aggressive
JAIL
systemctl enable fail2ban >/dev/null 2>&1 || true
systemctl restart fail2ban || warn "fail2ban did not start — check: systemctl status fail2ban"

# SSH: no root sign-in and no X11 forwarding. Read before the other files there, so it wins.
# Password sign-in is left exactly as this computer had it.
if command -v sshd >/dev/null 2>&1 && [[ -d /etc/ssh/sshd_config.d ]]; then
    sshd_conf=/etc/ssh/sshd_config.d/10-coconut-erp.conf
    cat > "$sshd_conf" <<'SSHD'
# Written by the Coconut ERP installer (deploy/provision.sh); rewritten on updates.
PermitRootLogin no
X11Forwarding no
LoginGraceTime 30
SSHD
    install -d -m 755 /run/sshd
    if sshd -t 2>/dev/null; then
        systemctl try-reload-or-restart ssh >/dev/null 2>&1 || systemctl try-reload-or-restart sshd >/dev/null 2>&1 || true
    else
        rm -f "$sshd_conf"
        warn "SSH refused the hardening settings; left as it was"
    fi
fi

# rpcbind serves NFS shares, which the ERP never uses (Oracle's image runs it anyway).
if ! grep -qE '[[:space:]]nfs4?[[:space:]]' /proc/mounts; then
    systemctl disable --now rpcbind.socket rpcbind.service >/dev/null 2>&1 || true
fi

# Security updates install themselves every day (Ubuntu's unattended-upgrades).
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'APT'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT
ok "SSH guarded by fail2ban, root SSH sign-in off, security updates automatic"

# ------------------------------------------------------------------- scheduler + backups
say "Setting up the scheduler and nightly backups"
install -d -m 700 "$BACKUP_DIR"
cat > /usr/local/sbin/coconut-erp-backup <<BACKUP
#!/usr/bin/env bash
# Nightly backup of the Coconut Factory ERP: the database and the .env file (its APP_KEY is
# needed to read encrypted data such as two-factor secrets). Written by deploy/provision.sh.
set -euo pipefail
umask 077
stamp="\$(date +%Y-%m-%d-%H%M)"
mariadb-dump --single-transaction --routines --triggers "${DB_NAME}" | gzip > "${BACKUP_DIR}/${DB_NAME}-\${stamp}.sql.gz"
cp "${ENV_FILE}" "${BACKUP_DIR}/env-\${stamp}"
find "${BACKUP_DIR}" -type f -mtime +${BACKUP_KEEP_DAYS} -delete
echo "\$(date -Is) backup \${stamp} written"

# Off-site copy to Google Drive, once a Drive connection named coconut-erp-drive exists for root
# (docs/CLOUD_SERVER.md). A failed upload never fails the local backup; it is logged and the
# next night's run copies whatever is missing.
if command -v rclone >/dev/null && rclone listremotes 2>/dev/null | grep -qx 'coconut-erp-drive:'; then
    if rclone copy "${BACKUP_DIR}" coconut-erp-drive:Coconut-ERP-backups --max-age 7d; then
        rclone delete coconut-erp-drive:Coconut-ERP-backups --min-age ${DRIVE_KEEP_DAYS}d || true
        echo "\$(date -Is) backup \${stamp} copied to Google Drive"
    else
        echo "\$(date -Is) WARNING: copying to Google Drive failed; the local backup is fine"
    fi
fi
BACKUP
chmod 700 /usr/local/sbin/coconut-erp-backup

backup_hour="${BACKUP_TIME%%:*}"; backup_minute="${BACKUP_TIME##*:}"
cat > /etc/cron.d/coconut-erp <<CRON
# Coconut Factory ERP — written by deploy/provision.sh
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * ${APP_USER} cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1
$((10#$backup_minute)) $((10#$backup_hour)) * * * root /usr/local/sbin/coconut-erp-backup >> /var/log/coconut-erp-backup.log 2>&1
CRON
chmod 644 /etc/cron.d/coconut-erp
systemctl enable --now cron >/dev/null
ok "Scheduler every minute; backups daily at $BACKUP_TIME into $BACKUP_DIR (kept $BACKUP_KEEP_DAYS days)"
if rclone listremotes 2>/dev/null | grep -qx 'coconut-erp-drive:'; then
    ok "Each backup is also copied to Google Drive (Coconut-ERP-backups, kept $DRIVE_KEEP_DAYS days)"
fi

# ------------------------------------------------------------------- final check
say "Checking the ERP answers"
status="$(curl -sk -o /dev/null -w '%{http_code}' "https://127.0.0.1:${HTTPS_PORT}/login" || true)"
[[ "$status" == "200" ]] || die "The sign-in page answered $status instead of 200. See $APP_DIR/storage/logs/laravel.log"
ok "Sign-in page answers 200"

{
    echo "# Coconut Factory ERP credentials — root only. Generated or confirmed $(date -Is)."
    echo "ERP_URL=$APP_URL"
    echo "ADMIN_EMAIL=$ADMIN_EMAIL"
    grep -vE '^(#|ERP_URL=|ADMIN_EMAIL=)' "$SECRETS_FILE" || true
} > "$SECRETS_FILE.new" && mv "$SECRETS_FILE.new" "$SECRETS_FILE" && chmod 600 "$SECRETS_FILE"

# The summary (with the administrator password) goes to the screen only, never the log.
cat >&3 <<DONE

==================================================================================
  The Coconut Factory ERP is installed (version $(tr -d '[:space:]' 2>/dev/null < "$APP_DIR/VERSION" || echo unknown)).

  Open:            $APP_URL
  Sign in as:      $ADMIN_EMAIL
  Password:        $ADMIN_PASSWORD

  All passwords (MariaDB root, database, administrator) are kept in:
                   $SECRETS_FILE   (view with: sudo cat $SECRETS_FILE)

  Next steps:
$( [[ "$TRUSTED_CERT" == "yes" ]] && echo "   - The certificate is trusted: browsers open the address without a warning." \
    || echo "   - Self-signed certificate: each browser shows a warning once — accept it." )
   - Change the administrator password after signing in (Settings → Password).
   - SUDO_PASSWORD in $CONFIG_FILE lets updates run unattended; delete it to be asked instead.
   - Copy the nightly backups in $BACKUP_DIR to another computer or drive regularly.
   - To install a newer version later: bash deploy/update.sh
   - Your accountant sets the accounting start date in Finance → Accounting setup.

  Log of this run: $LOG_FILE
==================================================================================
DONE
echo "Installed: $APP_URL (administrator $ADMIN_EMAIL; passwords in $SECRETS_FILE)"
echo "---- provisioning finished $(date -Is) ----"
