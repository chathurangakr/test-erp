#!/usr/bin/env bash
# =================================================================================================
#  Coconut ERP — set up outgoing email (Gmail) for the weekly update.
#
#    bash ~/coconut-erp/deploy/set-mail.sh
#
#  Asks for the Gmail address and its APP PASSWORD (Google Account → Security → 2-Step
#  Verification → App passwords — the normal Gmail password does not work), saves them in
#  provision.env beside the other settings (readable by you only), applies them by re-running
#  the installer, and can send a test email. Nothing is deleted. See docs/WEEKLY_UPDATE.md.
# =================================================================================================
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="$DIR/provision.env"
[[ -f "$CONFIG_FILE" ]] || { echo "No $CONFIG_FILE — install the ERP first (deploy/coconut-erp.sh)." >&2; exit 1; }

read -rp "Gmail address that sends the email: " address
[[ "$address" == *@*.* ]] || { echo "That is not an email address." >&2; exit 1; }
read -rsp "Its Gmail app password (16 letters; spaces are fine): " password; echo
password="${password// /}"
[[ ${#password} -ge 8 ]] || { echo "That password is too short — use the 16-letter app password." >&2; exit 1; }
[[ "$password" != *'"'* && "$password" != *'\'* ]] || { echo "The password may not contain quotes or backslashes." >&2; exit 1; }

save() {   # save KEY VALUE — replaces the line in provision.env, or appends it
    local line="$1=\"$2\""
    if grep -qE "^#?\s*$1=" "$CONFIG_FILE"; then
        KEY="$1" LINE="$line" perl -i -pe 's/^#?\s*\Q$ENV{KEY}\E=.*$/$ENV{LINE}/' "$CONFIG_FILE"
    else
        printf '%s\n' "$line" >> "$CONFIG_FILE"
    fi
}
save SMTP_USERNAME "$address"
save SMTP_PASSWORD "$password"
chmod 600 "$CONFIG_FILE"
echo "Saved. Applying the settings (the installer runs again; it takes a few minutes)…"

bash "$DIR/coconut-erp.sh"

read -rp "Send a test weekly update now? Type an address, or press Enter to skip: " test_to
if [[ -n "$test_to" ]]; then
    APP_DIR="$(grep -E '^APP_DIR=' "$CONFIG_FILE" | cut -d= -f2- | tr -d '"')"; APP_DIR="${APP_DIR:-/var/www/coconut-erp}"
    APP_USER="$(grep -E '^APP_USER=' "$CONFIG_FILE" | cut -d= -f2- | tr -d '"')"; APP_USER="${APP_USER:-coconuterp}"
    sudo -u "$APP_USER" php "$APP_DIR/artisan" reports:weekly-update --to="$test_to"
fi
