#!/usr/bin/env bash
set -euo pipefail
trap 'status=$?; printf "Runtime update stopped at line %s (exit %s). Review the error above before retrying.\n" "$LINENO" "$status" >&2' ERR

repo_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
mode="${1:---check}"
if [[ "$mode" == --check ]]; then
    failed=0
    php "$repo_dir/scripts/check-php-version.php" || failed=1
    node "$repo_dir/scripts/check-node-version.mjs" || failed=1
    exit "$failed"
fi
if [[ "$mode" != --apply || $# -ne 1 ]]; then
    echo "Usage: $0 [--check|--apply]" >&2
    exit 2
fi
if [[ $EUID -ne 0 ]]; then
    echo "Run this update with sudo. It updates installed PHP 8.4 and Node.js packages only." >&2
    exit 1
fi
command -v apt-get >/dev/null
command -v dpkg-query >/dev/null
apt-get update
# Consume all output: exiting awk early can break apt-cache's pipe and abort
# this script under pipefail before any packages are upgraded.
php_candidate="$(apt-cache policy php8.4-cli | awk '/Candidate:/ && !found {print $2; found=1}')"
node_candidate="$(apt-cache policy nodejs | awk '/Candidate:/ && !found {print $2; found=1}')"
php_release="${php_candidate#*:}"
node_release="${node_candidate#*:}"
if [[ "$php_release" != 8.4.* ]] || ! dpkg --compare-versions "$php_release" ge 8.4.27; then
    echo "The configured repositories do not offer patched PHP 8.4.27 or newer. No runtime packages were changed. Use the pinned Docker release or obtain patched packages from your OS vendor." >&2
    exit 1
fi
if [[ "$node_release" != 24.* ]] || ! dpkg --compare-versions "$node_release" ge 24.22.1; then
    echo "The configured repositories do not offer patched Node.js 24.22.1 or newer. No runtime packages were changed. Use the pinned Docker build or obtain patched packages from the Node.js distributor." >&2
    exit 1
fi
mapfile -t php_packages < <(dpkg-query -W -f='${binary:Package} ${db:Status-Status}\n' 'php8.4*' 2>/dev/null | awk '$2 == "installed" {print $1}')
if [[ ${#php_packages[@]} -eq 0 ]]; then
    echo "No installed PHP 8.4 packages found. No runtime packages were changed." >&2
    exit 1
fi
dpkg-query -W -f='${db:Status-Status}' nodejs | grep -qx installed
runtime_packages=("${php_packages[@]}" nodejs)
apt-get --simulate install --only-upgrade --no-remove "${runtime_packages[@]}"
umask 077
backup_dir="/var/backups/supportdesk-runtimes/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p -- "$backup_dir"
dpkg-query -W "${runtime_packages[@]}" > "$backup_dir/packages-before.txt"
if [[ -d /etc/php/8.4 ]]; then cp -a -- /etc/php/8.4 "$backup_dir/php8.4"; fi
DEBIAN_FRONTEND=noninteractive apt-get install --only-upgrade --no-remove -o Dpkg::Options::=--force-confold "${runtime_packages[@]}"
php "$repo_dir/scripts/check-php-version.php"
node "$repo_dir/scripts/check-node-version.mjs"
dpkg-query -W "${runtime_packages[@]}" > "$backup_dir/packages-after.txt"
echo "Runtime packages updated. Package/configuration backup: $backup_dir"
echo "Restart the application's PHP service and queue workers during your maintenance window, then verify the web runtime and health endpoint. No application database, .env file or application key was changed by this script."
