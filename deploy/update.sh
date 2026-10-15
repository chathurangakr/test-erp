#!/usr/bin/env bash
# =====================================================================================
#  Coconut Factory ERP — one-command update
# =====================================================================================
#
#  Brings an installed ERP up to the newest version:
#    1. backs up the database right now (beside the nightly backups)
#    2. gets the newest copy of this project folder, so the installer itself is current
#    3. runs the installer, which updates the code, libraries, database tables and screens
#       and restarts the services. It never deletes records.
#
#  Usage — on the computer the ERP is installed on, in the folder it was installed from:
#    bash deploy/update.sh                                # or: sudo bash deploy/update.sh
#    bash deploy/update.sh /path/to/other-settings.env    # a settings file elsewhere
#
#  Run it outside working hours: the ERP is unavailable for a few minutes.
# =====================================================================================
set -Eeuo pipefail

BACKUP_COMMAND=/usr/local/sbin/coconut-erp-backup

say()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m! %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

# Reads one value from the settings file without keeping the rest of it.
setting() {
    # shellcheck disable=SC1090
    (set -a; . "$2"; printf '%s' "${!1:-}")
}

# The whole update lives in one function, so bash has read all of it before step 2 replaces
# this file with its newest version.
main() {
    local script_dir project_dir config_file
    script_dir="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
    project_dir="$(dirname "$script_dir")"
    config_file="$(readlink -f "${1:-$script_dir/provision.env}")"

    say "Checking this computer"
    [[ -f "$config_file" ]] || die "Settings file not found: $config_file
Run the update from the folder the ERP was installed from (the one holding deploy/provision.env)."
    # The backup program is root-only (mode 700), so a normal user can only see that it exists.
    [[ -f "$BACKUP_COMMAND" ]] || die "The ERP is not installed on this computer yet.
Install it first with: bash deploy/provision.sh"

    local sudo_password git_token backup_dir
    sudo_password="$(setting SUDO_PASSWORD "$config_file")"
    git_token="$(setting GIT_TOKEN "$config_file")"
    backup_dir="$(setting BACKUP_DIR "$config_file")"
    backup_dir="${backup_dir:-/var/backups/coconut-erp}"

    as_root() {
        if [[ $EUID -eq 0 ]]; then
            "$@"
        elif [[ -n "$sudo_password" ]]; then
            printf '%s\n' "$sudo_password" | sudo -S -p '' "$@"
        else
            sudo "$@"
        fi
    }
    if [[ $EUID -ne 0 && -n "$sudo_password" ]]; then
        printf '%s\n' "$sudo_password" | sudo -S -p '' true 2>/dev/null \
            || die "The SUDO_PASSWORD in $config_file is not this computer's administrator password."
    fi
    ok "The ERP is installed here"

    # ---------------------------------------------------------------- 1. backup
    say "Backing up the database before changing anything"
    as_root "$BACKUP_COMMAND" >/dev/null \
        || die "The backup failed, so nothing was changed. Check that MariaDB is running: sudo systemctl status mariadb"
    ok "Backup saved in $backup_dir"

    # ---------------------------------------------------------------- 2. newest installer
    say "Getting the newest version"
    if [[ -d "$project_dir/.git" ]]; then
        # Everything else is installed by the installer in step 3; git is needed before it.
        if ! command -v git >/dev/null 2>&1; then
            as_root apt-get install -y -q git >/dev/null || die "Could not install git. Check the internet connection."
            ok "git installed"
        fi

        local owner
        local -a git_auth=()
        owner="$(stat -c %U "$project_dir")"
        if [[ -n "$git_token" ]]; then
            git_auth=(-c "http.extraHeader=Authorization: Basic $(printf 'x-access-token:%s' "$git_token" | base64 -w0)")
        fi
        # Git refuses a folder owned by someone else, so it runs as the folder's owner.
        project_git() {
            if [[ $EUID -eq 0 && "$owner" != root ]]; then
                runuser -u "$owner" -- git -C "$project_dir" "${git_auth[@]}" "$@"
            else
                git -C "$project_dir" "${git_auth[@]}" "$@"
            fi
        }

        local before after
        before="$(project_git rev-parse HEAD)"
        project_git pull --quiet --ff-only \
            || die "Could not get the newest version. Check the internet connection and try again.
If it mentions local changes, files in $project_dir were edited by hand; ask your developer."
        after="$(project_git rev-parse HEAD)"

        if [[ "$before" == "$after" ]]; then
            ok "This folder already had the newest version: $(project_git log -1 --format='%h %s')"
        else
            ok "Now at: $(project_git log -1 --format='%h %s')"
        fi
    else
        warn "$project_dir is not a git copy, so it was not updated here."
        warn "If you install from a copied folder (SOURCE_DIR), copy the new version over it first."
    fi

    # ---------------------------------------------------------------- 3. installer
    say "Applying the update (this takes a few minutes)"
    exec bash "$script_dir/provision.sh" "$config_file"
}

main "$@"
exit
