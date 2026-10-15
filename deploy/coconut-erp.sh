#!/usr/bin/env bash
# =====================================================================================
#  Coconut Factory ERP — install AND update with one file
# =====================================================================================
#
#  Run it as your normal Ubuntu user (not with sudo):
#
#      bash coconut-erp.sh
#
#  FIRST run  — asks for this computer's sudo password (nothing else; on a cloud server where
#               sudo needs no password, not even that), saves it, downloads the
#               ERP with git into ~/coconut-erp and installs everything. The end of the run
#               shows the address, the administrator's sign-in email and its password.
#  LATER runs — asks nothing: backs up the database, gets the newest version and updates.
#
#  The password is saved in ~/coconut-erp/deploy/provision.env (readable by you only), beside
#  the other settings (address, ports, backups…) — see deploy/provision.env.example.
#
#  This file can live anywhere (Desktop, USB drive). Run from inside a downloaded copy of
#  the project, it uses that copy instead of ~/coconut-erp.
# =====================================================================================
set -Eeuo pipefail

REPO_URL="${COCONUT_ERP_REPO:-https://github.com/chathurangakr/test-erp.git}"
REPO_BRANCH="${COCONUT_ERP_BRANCH:-main}"
BACKUP_COMMAND=/usr/local/sbin/coconut-erp-backup
SECRETS_FILE=/etc/coconut-erp/secrets.env

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

# ask_password VAR — one hidden typed answer into VAR.
ask_password() {
    local reply
    IFS= read -r -s -p "    This computer's password (the one you use for sudo): " reply \
        || die "No password was typed. Nothing was changed."
    echo
    printf -v "$1" '%s' "$reply"
}

password_works() {
    [[ -n "$1" ]] && printf '%s\n' "$1" | sudo -S -k -p '' true 2>/dev/null
}

# Writes KEY='value' into the settings file, quoted so any character is safe.
set_setting() {
    local file="$1" key="$2" value="$3"
    local quoted="'${value//\'/\'\\\'\'}'"
    NEW_LINE="$key=$quoted" awk -v key="$key" '
        BEGIN { line = ENVIRON["NEW_LINE"] }
        index($0, key "=") == 1 { print line; done = 1; next }
        { print }
        END { if (!done) print line }
    ' "$file" > "$file.new"
    chmod 600 "$file.new"
    mv "$file.new" "$file"
}

# Everything lives in one function, so bash has read all of it before git replaces this file.
main() {
    [[ $EUID -ne 0 ]] || die "Run this as your normal user, without sudo:  bash $0
It asks for the sudo password itself the first time."

    # ---------------------------------------------------------------- where the ERP lives
    local here project_dir
    here="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
    if [[ -n "${COCONUT_ERP_DIR:-}" ]]; then
        project_dir="$COCONUT_ERP_DIR"
    elif [[ -f "$here/provision.sh" && -d "$here/../.git" ]]; then
        project_dir="$(dirname "$here")"
    else
        project_dir="$HOME/coconut-erp"
    fi
    local config_file="$project_dir/deploy/provision.env"

    # ---------------------------------------------------------------- first run: questions
    local sudo_password=""
    if [[ -f "$config_file" ]]; then
        sudo_password="$(set -a; . "$config_file"; printf '%s' "${SUDO_PASSWORD:-}")"
    fi

    # Cloud servers (Oracle, AWS, Google) usually let the default user run sudo with no password
    # at all, and that user has none to type. Then nothing is asked and nothing is saved.
    if sudo -k -n true 2>/dev/null; then
        sudo_password=""
    elif ! password_works "$sudo_password"; then
        if [[ -z "$sudo_password" ]]; then
            say "First-time setup"
        else
            say "The saved password no longer works (was it changed?)"
        fi
        local attempt
        for attempt in 1 2 3; do
            ask_password sudo_password
            password_works "$sudo_password" && break
            sudo_password=""
            echo "    That password was not accepted. Try again."
        done
        [[ -n "$sudo_password" ]] || die "The password was not accepted three times. Nothing was changed."
        ok "Password accepted (it is saved, so you are not asked again)"
    fi
    as_root() {
        if [[ -z "$sudo_password" ]]; then
            sudo "$@"
        else
            printf '%s\n' "$sudo_password" | sudo -S -p '' "$@"
        fi
    }

    # ---------------------------------------------------------------- get the code
    if ! command -v git >/dev/null 2>&1; then
        say "Installing git"
        as_root apt-get update -q >/dev/null
        as_root apt-get install -y -q git >/dev/null
        ok "git installed"
    fi

    if [[ ! -d "$project_dir/.git" ]]; then
        [[ ! -e "$project_dir" || -z "$(ls -A "$project_dir")" ]] \
            || die "$project_dir already exists but is not a git copy of the ERP. Move it aside and run this again."
        say "Downloading the ERP into $project_dir"
        git clone --quiet --branch "$REPO_BRANCH" "$REPO_URL" "$project_dir" \
            || die "Could not download the ERP from $REPO_URL. Check the internet connection."
        ok "Downloaded $(git -C "$project_dir" log -1 --format='%h %s')"
    fi

    # ---------------------------------------------------------------- the settings file
    if [[ ! -f "$config_file" ]]; then
        cp "$project_dir/deploy/provision.env.example" "$config_file"
        chmod 600 "$config_file"
        set_setting "$config_file" REPO_URL "$REPO_URL"
        set_setting "$config_file" REPO_BRANCH "$REPO_BRANCH"
    fi
    set_setting "$config_file" SUDO_PASSWORD "$sudo_password"

    # The administrator: the one this computer was installed with, if any; otherwise the
    # settings file's (admin@example.com by default), shown with its password at the end.
    local installed_admin
    installed_admin="$(as_root sh -c "grep -E '^ADMIN_EMAIL=' '$SECRETS_FILE' 2>/dev/null | tail -n1 | cut -d= -f2-" || true)"
    if [[ -n "$installed_admin" ]]; then
        set_setting "$config_file" ADMIN_EMAIL "$installed_admin"
    fi
    ok "Settings saved in $config_file"

    # ---------------------------------------------------------------- install or update
    if [[ -f "$BACKUP_COMMAND" ]]; then
        exec bash "$project_dir/deploy/update.sh" "$config_file"
    else
        exec bash "$project_dir/deploy/provision.sh" "$config_file"
    fi
}

main "$@"
exit
