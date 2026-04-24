#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_NAME="$(basename "$0")"
LOCK_FILE="${LOCK_FILE:-/tmp/${SCRIPT_NAME}.lock}"
TMP_ROOT="${TMP_ROOT:-/tmp/${SCRIPT_NAME%.sh}}"
NPM_CACHE="${NPM_CACHE:-/tmp/npm-cache-hetao-h5}"
INSTALL_TIMEOUT="${INSTALL_TIMEOUT:-20m}"
BUILD_TIMEOUT="${BUILD_TIMEOUT:-20m}"
NODE_MAX_OLD_SPACE_SIZE="${NODE_MAX_OLD_SPACE_SIZE:-768}"
NICE_LEVEL="${NICE_LEVEL:-10}"
KEEP_WORKSPACE="${KEEP_WORKSPACE:-0}"

DEFAULT_PROJECTS=("photo-timeline-uniapp" "photo-timeline-admin")
SELECTED_PROJECTS=()

usage() {
  cat <<'EOF'
Usage:
  build-photo-timeline-h5.sh
  build-photo-timeline-h5.sh all
  build-photo-timeline-h5.sh uniapp
  build-photo-timeline-h5.sh admin
  build-photo-timeline-h5.sh photo-timeline-uniapp photo-timeline-admin

Behavior:
  - Builds H5 on local disk under /tmp instead of the mounted /dxy path.
  - Syncs build output back to dist/build/h5 in the source project.
  - Defaults to building both H5 projects.

Environment overrides:
  TMP_ROOT
  NPM_CACHE
  INSTALL_TIMEOUT
  BUILD_TIMEOUT
  NODE_MAX_OLD_SPACE_SIZE
  NICE_LEVEL
  KEEP_WORKSPACE=1
EOF
}

log() {
  printf '[%s] %s\n' "$(date '+%F %T')" "$*"
}

die() {
  log "ERROR: $*"
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

parse_args() {
  if [[ $# -eq 0 ]]; then
    SELECTED_PROJECTS=("${DEFAULT_PROJECTS[@]}")
    return 0
  fi

  for arg in "$@"; do
    case "$arg" in
      all)
        SELECTED_PROJECTS=("${DEFAULT_PROJECTS[@]}")
        return 0
        ;;
      uniapp|photo-timeline-uniapp)
        SELECTED_PROJECTS+=("photo-timeline-uniapp")
        ;;
      admin|photo-timeline-admin)
        SELECTED_PROJECTS+=("photo-timeline-admin")
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      *)
        die "Unsupported target: $arg"
        ;;
    esac
  done
}

setup_lock() {
  exec 9>"$LOCK_FILE"
  flock -n 9 || die "Another build is already running: $LOCK_FILE"
}

cleanup() {
  local exit_code=$?

  if [[ "$KEEP_WORKSPACE" != "1" && -n "$TMP_ROOT" && "$TMP_ROOT" != "/" && -d "$TMP_ROOT" ]]; then
    rm -rf "$TMP_ROOT"
  fi

  if [[ $exit_code -eq 0 ]]; then
    log "Finished."
  else
    log "Failed with exit code: $exit_code"
  fi
}

run_guarded() {
  local timeout_value="$1"
  shift

  if command -v timeout >/dev/null 2>&1; then
    if command -v nice >/dev/null 2>&1; then
      nice -n "$NICE_LEVEL" timeout "$timeout_value" "$@"
    else
      timeout "$timeout_value" "$@"
    fi
    return $?
  fi

  if command -v nice >/dev/null 2>&1; then
    nice -n "$NICE_LEVEL" "$@"
  else
    "$@"
  fi
}

copy_project_to_workspace() {
  local source_dir="$1"
  local workspace_dir="$2"

  rm -rf "$workspace_dir"
  mkdir -p "$workspace_dir"

  tar -C "$source_dir" \
    --exclude=node_modules \
    --exclude=node_modules.codex-old \
    --exclude=dist \
    -cf - . | tar --no-same-owner -C "$workspace_dir" -xf -
}

same_lockfile() {
  local left_project="$1"
  local right_project="$2"

  cmp -s \
    "$ROOT_DIR/$left_project/package-lock.json" \
    "$ROOT_DIR/$right_project/package-lock.json"
}

install_dependencies() {
  local workspace_dir="$1"

  log "Installing dependencies in $workspace_dir"
  (
    cd "$workspace_dir"
    run_guarded "$INSTALL_TIMEOUT" \
      npm ci --cache "$NPM_CACHE" --no-audit --no-fund
  )
}

build_project_h5() {
  local workspace_dir="$1"

  log "Building H5 in $workspace_dir"
  (
    cd "$workspace_dir"
    run_guarded "$BUILD_TIMEOUT" \
      env CI=1 NODE_OPTIONS="--max-old-space-size=$NODE_MAX_OLD_SPACE_SIZE" \
      npm --cache "$NPM_CACHE" run build:h5
  )
}

sync_dist_back() {
  local workspace_dir="$1"
  local source_dir="$2"
  local dist_source="$workspace_dir/dist/build/h5"
  local dist_target="$source_dir/dist/build/h5"

  [[ -f "$dist_source/index.html" ]] || die "Missing build output: $dist_source/index.html"

  mkdir -p "$source_dir/dist/build"
  rm -rf "$dist_target"
  mkdir -p "$dist_target"

  tar -C "$dist_source" -cf - . | tar --no-same-owner -C "$dist_target" -xf -
}

report_output() {
  local project="$1"
  local output_dir="$ROOT_DIR/$project/dist/build/h5"
  local output_size
  local output_time

  output_size="$(du -sh "$output_dir" | awk '{print $1}')"
  output_time="$(find "$output_dir" -maxdepth 1 -name 'index.html' -printf '%TY-%Tm-%Td %TT\n')"

  log "Built $project -> $output_dir"
  log "Output size: $output_size"
  log "index.html time: $output_time"
}

build_selected_projects() {
  local previous_project=""
  local previous_workspace=""

  for project in "${SELECTED_PROJECTS[@]}"; do
    local source_dir="$ROOT_DIR/$project"
    local workspace_dir="$TMP_ROOT/$project"

    [[ -d "$source_dir" ]] || die "Project directory not found: $source_dir"
    [[ -f "$source_dir/package-lock.json" ]] || die "Missing package-lock.json: $source_dir/package-lock.json"

    log "Preparing workspace for $project"
    copy_project_to_workspace "$source_dir" "$workspace_dir"

    if [[ -n "$previous_project" && -d "$previous_workspace/node_modules" ]] && same_lockfile "$previous_project" "$project"; then
      log "Reusing node_modules from $previous_project"
      ln -s "$previous_workspace/node_modules" "$workspace_dir/node_modules"
    else
      install_dependencies "$workspace_dir"
    fi

    build_project_h5 "$workspace_dir"
    sync_dist_back "$workspace_dir" "$source_dir"
    report_output "$project"

    previous_project="$project"
    previous_workspace="$workspace_dir"
  done
}

main() {
  parse_args "$@"

  require_command bash
  require_command cmp
  require_command find
  require_command flock
  require_command node
  require_command npm
  require_command tar
  require_command awk
  require_command du

  mkdir -p "$TMP_ROOT" "$NPM_CACHE"
  trap cleanup EXIT
  setup_lock

  log "Projects: ${SELECTED_PROJECTS[*]}"
  log "Temporary workspace: $TMP_ROOT"
  log "NPM cache: $NPM_CACHE"

  build_selected_projects
}

main "$@"
