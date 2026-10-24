#!/usr/bin/env bash
# Only this application's CI-published images are updated; no host/package/schema updates.
set -euo pipefail
mode=${1:---check}
[[ $# -le 1 && ( "$mode" == --check || "$mode" == --apply ) ]] || { echo 'Usage: auto-update-containers.sh [--check|--apply]' >&2; exit 2; }
for setting in SUPPORTDESK_PROJECT_DIR SUPPORTDESK_COMPOSE_FILE SUPPORTDESK_COMPOSE_ENV_FILE SUPPORTDESK_IMAGE_OVERRIDE SUPPORTDESK_APP_IMAGE SUPPORTDESK_WEB_IMAGE SUPPORTDESK_ORIGIN; do
    [[ -n ${!setting:-} ]] || { echo "Missing setting: $setting" >&2; exit 1; }
done
for setting in SUPPORTDESK_PROJECT_DIR SUPPORTDESK_COMPOSE_FILE SUPPORTDESK_COMPOSE_ENV_FILE SUPPORTDESK_IMAGE_OVERRIDE; do
    [[ ${!setting} == /* ]] || { echo "Use an absolute path for $setting" >&2; exit 1; }
done
[[ -d $SUPPORTDESK_PROJECT_DIR && -f $SUPPORTDESK_COMPOSE_FILE && -f $SUPPORTDESK_COMPOSE_ENV_FILE && -f $SUPPORTDESK_IMAGE_OVERRIDE ]] || { echo 'Deployment paths are unavailable.' >&2; exit 1; }
[[ $SUPPORTDESK_APP_IMAGE =~ ^ghcr\.io/[a-z0-9._/-]+/app:tested$ && $SUPPORTDESK_WEB_IMAGE =~ ^ghcr\.io/[a-z0-9._/-]+/web:tested$ ]] || { echo 'Use the tested application/frontend tags from GHCR.' >&2; exit 1; }
[[ ${SUPPORTDESK_APP_IMAGE%/app:tested} == "${SUPPORTDESK_WEB_IMAGE%/web:tested}" ]] || { echo 'Both images must belong to the same repository.' >&2; exit 1; }
[[ $SUPPORTDESK_ORIGIN =~ ^https://[a-zA-Z0-9.-]+(:[0-9]+)?$ ]] || { echo 'Use an HTTPS origin without credentials, paths or query strings.' >&2; exit 1; }
command -v docker >/dev/null
command -v curl >/dev/null
command -v flock >/dev/null
export SUPPORTDESK_APP_IMAGE SUPPORTDESK_WEB_IMAGE
cd -- "$SUPPORTDESK_PROJECT_DIR"
compose=(docker compose --project-directory "$SUPPORTDESK_PROJECT_DIR" --env-file "$SUPPORTDESK_COMPOSE_ENV_FILE" -f "$SUPPORTDESK_COMPOSE_FILE" -f "$SUPPORTDESK_IMAGE_OVERRIDE")
"${compose[@]}" config --quiet
if [[ $mode == --check ]]; then
    echo 'Configuration valid. Apply would pull the tested image pair, validate release/schema labels, recreate only app/worker/scheduler/web, and verify readiness. No images were pulled or services changed.'
    exit 0
fi
state_dir=${SUPPORTDESK_UPDATE_STATE_DIR:-/var/lib/supportdesk-updates}
[[ $state_dir == /* ]] || { echo 'State directory must be absolute.' >&2; exit 1; }
umask 077
mkdir -p -- "$state_dir"
exec 9>"$state_dir/update.lock"
flock -n 9 || { echo 'Another update is running.'; exit 0; }
services=(app worker scheduler web)
old_images=()
for service in "${services[@]}"; do
    container=$("${compose[@]}" ps --all --quiet "$service")
    [[ -n $container && $container != *$'\n'* ]] || { echo "Expected one existing $service container. Install a reviewed baseline before enabling automatic updates." >&2; exit 1; }
    old_images+=("$(docker inspect --format '{{.Image}}' "$container")")
done
label() { docker image inspect --format "{{index .Config.Labels \"$2\"}}" "$1"; }
old_schema=$(label "${old_images[0]}" org.supportdesk.schema)
old_release=$(label "${old_images[0]}" org.supportdesk.release)
old_application=$(label "${old_images[0]}" org.supportdesk.application)
[[ $old_application =~ ^[a-f0-9]{64}$ ]] || { echo 'Application baseline label missing. Review and deploy a labelled baseline first.' >&2; exit 1; }
[[ $old_release =~ ^[0-9]+-[0-9]+$ ]] || { echo 'Existing release label missing. Install a reviewed baseline first.' >&2; exit 1; }
[[ $old_schema =~ ^[a-f0-9]{64}$ ]] || { echo 'Current app image has no verified schema label. Deploy a reviewed labelled baseline before enabling automatic updates.' >&2; exit 1; }
for image in "${old_images[@]}"; do
    [[ $(label "$image" org.supportdesk.schema) == "$old_schema" && $(label "$image" org.supportdesk.release) == "$old_release" && $(label "$image" org.supportdesk.application) == "$old_application" ]] || { echo 'Existing services do not share the verified schema baseline. Review the deployment first.' >&2; exit 1; }
done
docker pull "$SUPPORTDESK_APP_IMAGE"
docker pull "$SUPPORTDESK_WEB_IMAGE"
# Pin locally to immutable IDs, so a concurrent registry publish cannot change this deployment.
new_app=$(docker image inspect --format '{{.Id}}' "$SUPPORTDESK_APP_IMAGE")
new_web=$(docker image inspect --format '{{.Id}}' "$SUPPORTDESK_WEB_IMAGE")
release=$(label "$new_app" org.supportdesk.release)
[[ $release =~ ^[0-9]+-[0-9]+$ && $(label "$new_web" org.supportdesk.release) == "$release" ]] || { echo 'Registry image pair is incomplete or mismatched; leaving running services unchanged.' >&2; exit 1; }
revision=$(label "$new_app" org.opencontainers.image.revision)
[[ $revision =~ ^[a-f0-9]{40}$ && $(label "$new_web" org.opencontainers.image.revision) == "$revision" ]] || { echo 'Release revisions do not match.' >&2; exit 1; }
for image in "$new_app" "$new_web"; do
    [[ $(label "$image" org.supportdesk.validation) == passed && $(label "$image" org.supportdesk.schema) == "$old_schema" && $(label "$image" org.supportdesk.application) == "$old_application" ]] || { echo 'Validation missing or application/schema changed. A reviewed manual deployment is required; services unchanged.' >&2; exit 1; }
done
if [[ -f $state_dir/rejected-release && $(cat "$state_dir/rejected-release") == "$release" ]]; then
    echo 'This release previously failed health verification. Investigate before retrying it.' >&2; exit 1
fi
if [[ ${old_images[0]} == "$new_app" && ${old_images[1]} == "$new_app" && ${old_images[2]} == "$new_app" && ${old_images[3]} == "$new_web" ]]; then
    echo 'Already running this tested image pair.'; exit 0
fi
[[ $release != "$old_release" && $(printf '%s\n' "$old_release" "$release" | sort -V | tail -1) == "$release" ]] || { echo 'Refusing an older or changed same-ID release.' >&2; exit 1; }
rollback=$(mktemp "$state_dir/rollback.XXXXXX.yaml")
candidate=$(mktemp "$state_dir/candidate.XXXXXX.yaml")
write_override() {
    local destination=$1; shift
    printf 'services:\n' > "$destination"
    for service in "${services[@]}"; do
        [[ $1 =~ ^sha256:[a-f0-9]{64}$ ]] || { echo 'Invalid image ID.' >&2; exit 1; }
        printf '  %s:\n    image: %s\n' "$service" "$1" >> "$destination"; shift
    done
}
write_override "$rollback" "${old_images[@]}"
write_override "$candidate" "$new_app" "$new_app" "$new_app" "$new_web"
changed=false
finish() {
    result=$?
    trap - EXIT INT TERM
    if [[ $changed == true && $result -ne 0 ]]; then
        printf '%s\n' "$release" > "$state_dir/rejected-release"
        echo 'Update failed; attempting rollback to the saved image IDs.' >&2
        if ! "${compose[@]}" -f "$rollback" up -d --no-build --pull never --no-deps --wait --wait-timeout 180 "${services[@]}"; then
            echo "Rollback failed. Operator action required; previous image map retained at $rollback" >&2
        fi
    fi
    # Retain small image maps for operator diagnosis; never prune images or data volumes here.
    exit "$result"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
changed=true
"${compose[@]}" -f "$candidate" up -d --no-build --pull never --no-deps --wait --wait-timeout 180 "${services[@]}"
ready=$(curl --fail --silent --show-error --connect-timeout 5 --max-time 15 "${SUPPORTDESK_ORIGIN%/}/api/v2/health/ready")
[[ $ready =~ \"status\"[[:space:]]*:[[:space:]]*\"ready\" ]] || { echo 'Application readiness failed.' >&2; exit 1; }
printf '%s %s\n' "$release" "$revision" > "$state_dir/last-success"
changed=false
echo "Applied tested SupportDesk release $release. No migrations or other applications were changed."
