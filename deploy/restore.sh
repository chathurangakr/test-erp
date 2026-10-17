#!/usr/bin/env bash
# =====================================================================================
#  Coconut Factory ERP — put a backup back (or move the data from another computer)
# =====================================================================================
#
#  Replaces ALL the ERP's data on this computer with a backup made by the nightly backup
#  (/var/backups/coconut-erp/coconut_erp-YYYY-MM-DD-HHMM.sql.gz) — this computer's own, or one
#  copied from another computer running the ERP.
#
#    1. checks the file really is an ERP backup, from this version or an older one
#    2. backs up the data here first, so nothing is ever lost
#    3. loads the backup, with the security key (APP_KEY) from the env-… file made beside it,
#       so two-factor sign-in keeps working
#    4. brings the tables up to this version (an older backup is upgraded; records unchanged)
#  If any step fails, the data that was here is put back automatically.
#
#  Usage — on the computer the ERP is installed on, in the folder it was installed from:
#    bash deploy/restore.sh /path/to/coconut_erp-2026-10-16-0230.sql.gz
#    bash deploy/restore.sh /path/to/backup.sql.gz /path/to/env-2026-10-16-0230
#
#  Without the second file, the env-… file with the same date beside the backup is used. If
#  there is none, the data is still restored, but two-factor sign-in set up on the other
#  computer must be set up again.
#
#  After it finishes, people sign in with the accounts and passwords FROM THE BACKUP.
# =====================================================================================
set -Eeuo pipefail

BACKUP_COMMAND=/usr/local/sbin/coconut-erp-backup
PHP_VERSION=8.5

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m! %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

SCRIPT_PATH="$(readlink -f "${BASH_SOURCE[0]}")"
SCRIPT_DIR="$(dirname "$SCRIPT_PATH")"
CONFIG_FILE="$(readlink -f "$SCRIPT_DIR/provision.env")"

CONFIRMED=no
if [[ "${1:-}" == "--confirmed" ]]; then CONFIRMED=yes; shift; fi
[[ $# -ge 1 ]] || die "Say which backup to restore:  bash $0 /path/to/coconut_erp-….sql.gz"
DUMP="$(readlink -f "$1")"
ENV_BACKUP="${2:+$(readlink -f "$2")}"

[[ -f "$CONFIG_FILE" ]] || die "Settings file not found: $CONFIG_FILE
Run this from the folder the ERP was installed from (the one holding deploy/provision.env)."
[[ -f "$BACKUP_COMMAND" ]] || die "The ERP is not installed on this computer yet. Install it first."
[[ -f "$DUMP" ]] || die "Backup file not found: $1"
[[ -z "$ENV_BACKUP" || -f "$ENV_BACKUP" ]] || die "File not found: $2"

# ------------------------------------------------- ask, then become root (once)
if [[ $EUID -ne 0 ]]; then
    if [[ "$CONFIRMED" == "no" ]]; then
        cat <<ASK

  This REPLACES ALL the ERP data on this computer with:
      $DUMP
  The data here now is backed up first, so it can be put back.
  Nobody should be using the ERP while this runs (a minute or two).

ASK
        read -r -p "  Type RESTORE to continue: " answer
        [[ "$answer" == "RESTORE" ]] || die "Nothing was changed."
    fi

    # shellcheck disable=SC1090
    sudo_password="$(set -a; . "$CONFIG_FILE"; printf '%s' "${SUDO_PASSWORD:-}")"
    if [[ -z "$sudo_password" ]]; then
        exec sudo bash "$SCRIPT_PATH" --confirmed "$DUMP" ${ENV_BACKUP:+"$ENV_BACKUP"}
    fi
    printf '%s\n' "$sudo_password" | sudo -S -p '' true 2>/dev/null \
        || die "The SUDO_PASSWORD in $CONFIG_FILE is not this computer's administrator password."
    status=0
    printf '%s\n' "$sudo_password" | sudo -S -p '' bash "$SCRIPT_PATH" --confirmed "$DUMP" ${ENV_BACKUP:+"$ENV_BACKUP"} || status=$?
    exit "$status"
fi
[[ "$CONFIRMED" == "yes" ]] || die "Run it as your normal user (without sudo): bash $0 $1"

exec < /dev/null

# From here on we are root. Settings are read one by one, never exported: an exported DB_PASSWORD
# (blank in the settings file) would override the ERP's own .env for every artisan command.
setting() {
    # shellcheck disable=SC1090
    (set -a; . "$CONFIG_FILE"; printf '%s' "${!1:-}")
}
APP_DIR="$(setting APP_DIR)"; APP_DIR="${APP_DIR:-/var/www/coconut-erp}"
APP_USER="$(setting APP_USER)"; APP_USER="${APP_USER:-coconuterp}"
DB_NAME="$(setting DB_NAME)"; DB_NAME="${DB_NAME:-coconut_erp}"
BACKUP_DIR="$(setting BACKUP_DIR)"; BACKUP_DIR="${BACKUP_DIR:-/var/backups/coconut-erp}"
APP_HOME="$(getent passwd "$APP_USER" | cut -d: -f6)"
ENV_FILE="$APP_DIR/.env"

as_app() { runuser -u "$APP_USER" -- env HOME="$APP_HOME" "$@"; }
artisan() { as_app php "$APP_DIR/artisan" "$@" --no-interaction; }
dump_cat() {   # a .sql.gz or a plain .sql
    if gzip -t "$1" 2>/dev/null; then gzip -dc "$1"; else cat "$1"; fi
}
load_dump() {   # load_dump FILE — empties the database, then loads FILE into it
    mariadb -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`;
                CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    dump_cat "$1" | mariadb "$DB_NAME"
}
set_app_key() {
    KEY_LINE="$1" perl -i -pe 's/^APP_KEY=.*$/$ENV{KEY_LINE}/' "$ENV_FILE"
}

# ---------------------------------------------------------------- 1. check the file
say "Checking the backup"
[[ "$(dump_cat "$DUMP" | grep -cE 'CREATE TABLE `(migrations|inventory_transaction_lines|users)`' || true)" == "3" ]] \
    || die "$DUMP is not a Coconut Factory ERP backup. Nothing was changed."

# Every migration the backup has been through must exist here; otherwise it comes from a newer
# version than this computer runs.
# mariadb-dump writes the rows one per line after "INSERT INTO `migrations` VALUES".
mapfile -t migrations < <(dump_cat "$DUMP" \
    | awk '/^INSERT INTO `migrations`/ { rows = 1 } rows { print } rows && /;$/ { rows = 0 }' \
    | grep -oE "'[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+'" | tr -d "'" || true)
(( ${#migrations[@]} > 0 )) || die "$DUMP lists no database changes, so it is not a complete ERP backup. Nothing was changed."
newer=()
for migration in "${migrations[@]}"; do
    [[ -f "$APP_DIR/database/migrations/$migration.php" ]] || newer+=("$migration")
done
(( ${#newer[@]} == 0 )) || die "This backup comes from a newer version of the ERP than this computer runs.
Update this computer first (bash deploy/coconut-erp.sh), then restore. Nothing was changed."
ok "It is an ERP backup this version can load"

if [[ -z "$ENV_BACKUP" ]]; then
    stamp="$(basename "$DUMP" | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{4}' || true)"
    [[ -z "$stamp" || ! -f "$(dirname "$DUMP")/env-$stamp" ]] || ENV_BACKUP="$(dirname "$DUMP")/env-$stamp"
fi
new_key=""
if [[ -n "$ENV_BACKUP" ]]; then
    new_key="$(grep -E '^APP_KEY=' "$ENV_BACKUP" | tail -n1 || true)"
    [[ "$new_key" =~ ^APP_KEY=\"?base64: ]] || die "$ENV_BACKUP holds no APP_KEY. Nothing was changed."
    ok "Security key taken from $ENV_BACKUP"
else
    warn "No env-… file beside the backup: two-factor sign-in from that computer must be set up again."
fi
old_key="$(grep -E '^APP_KEY=' "$ENV_FILE" | tail -n1)"

# ---------------------------------------------------------------- 2. safety backup
say "Backing up the data here first"
"$BACKUP_COMMAND" >/dev/null || die "The backup of this computer's data failed, so nothing was changed."
SAFETY="$(ls -t "$BACKUP_DIR/$DB_NAME"-*.sql.gz | head -n1)"
ok "Saved as $SAFETY"

# ---------------------------------------------------------------- 3 and 4. restore
put_back() {
    trap - ERR
    set +e
    printf '\n\033[1;31m%s\033[0m\n' "$1" >&2
    say "Putting back the data that was here"
    load_dump "$SAFETY"
    set_app_key "$old_key"
    artisan optimize:clear >/dev/null
    artisan optimize >/dev/null
    systemctl restart "php${PHP_VERSION}-fpm"
    artisan up >/dev/null
    die "The restore did not complete. Everything is as it was before (from $SAFETY)."
}
trap 'put_back "Step failed at line $LINENO: $BASH_COMMAND"' ERR

say "Loading the backup"
artisan down --retry=60 >/dev/null
load_dump "$DUMP"
[[ -z "$new_key" ]] || set_app_key "$new_key"
ok "Data loaded"

say "Bringing the tables up to this version"
artisan optimize:clear >/dev/null
artisan migrate --force
artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force >/dev/null
artisan cache:clear >/dev/null
artisan optimize >/dev/null
systemctl restart "php${PHP_VERSION}-fpm"
artisan up >/dev/null
trap - ERR
ok "Up to date"

users="$(mariadb -N -B -e "SELECT COUNT(*) FROM \`${DB_NAME}\`.users WHERE is_active = 1")"
cat <<DONE

==================================================================================
  Restored from:   $DUMP
  Active users:    $users  — sign in with the accounts and passwords from that backup.
  The data that was here before is kept in:
                   $SAFETY
==================================================================================
DONE
