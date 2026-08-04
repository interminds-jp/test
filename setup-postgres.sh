#!/usr/bin/env bash
#
# PostgreSQL 18 setup for Ubuntu / Debian.
#
# Idempotent: safe to re-run. It never drops a cluster that holds user data
# unless you explicitly pass PG_FORCE_RECREATE=yes.
#
# Usage:
#   sudo ./setup-postgres.sh
#   sudo PG_ALLOW_CIDR="10.0.0.0/24,192.168.1.0/24" ./setup-postgres.sh
#   sudo PG_DRY_RUN=yes ./setup-postgres.sh      # show what would change
#
# See README.md for the full list of variables.

set -Eeuo pipefail

PG_VERSION="${PG_VERSION:-18}"
PG_CLUSTER="${PG_CLUSTER:-main}"
PG_LISTEN="${PG_LISTEN:-localhost}"
PG_PORT="${PG_PORT:-5432}"
PG_ALLOW_CIDR="${PG_ALLOW_CIDR:-}"
PG_MAX_CONNECTIONS="${PG_MAX_CONNECTIONS:-100}"
PG_SSL="${PG_SSL:-on}"
PG_LOCALE_PROVIDER="${PG_LOCALE_PROVIDER:-builtin}"
PG_LOCALE="${PG_LOCALE:-C.UTF-8}"
PG_ENCODING="${PG_ENCODING:-UTF8}"
PG_RECREATE_CLUSTER="${PG_RECREATE_CLUSTER:-auto}"
PG_FORCE_RECREATE="${PG_FORCE_RECREATE:-no}"
PG_DRY_RUN="${PG_DRY_RUN:-no}"

CONF_DIR="/etc/postgresql/${PG_VERSION}/${PG_CLUSTER}"
LOCAL_CONF="${CONF_DIR}/conf.d/10-local.conf"
HBA_CONF="${CONF_DIR}/pg_hba.conf"
REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
STAMP="$(date +%Y%m%d%H%M%S)"

NEEDS_RESTART=no
NEEDS_RELOAD=no

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m  ok\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m  !!\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

dry() { [[ "${PG_DRY_RUN}" == "yes" ]]; }

run() {
    if dry; then
        printf '     would run: %s\n' "$*"
    else
        "$@"
    fi
}

trap 'die "failed at line ${LINENO}: ${BASH_COMMAND}"' ERR

# --------------------------------------------------------------------------
# preflight
# --------------------------------------------------------------------------

preflight() {
    log "Preflight"

    if [[ "${EUID}" -ne 0 ]] && ! dry; then
        die "run as root (sudo ./setup-postgres.sh)"
    fi

    [[ -r /etc/os-release ]] || die "/etc/os-release not found; unsupported OS"
    # shellcheck disable=SC1091
    . /etc/os-release

    case "${ID:-}${ID_LIKE:-}" in
        *debian*|*ubuntu*) ;;
        *) die "this script targets Debian/Ubuntu, found ID=${ID:-unknown}" ;;
    esac

    [[ -n "${VERSION_CODENAME:-}" ]] || die "VERSION_CODENAME missing from /etc/os-release"

    case "${PG_LOCALE_PROVIDER}" in
        builtin|icu|libc) ;;
        *) die "PG_LOCALE_PROVIDER must be builtin, icu or libc (got '${PG_LOCALE_PROVIDER}')" ;;
    esac

    if [[ "${PG_LOCALE_PROVIDER}" == "builtin" && "${PG_VERSION}" -lt 17 ]]; then
        die "PG_LOCALE_PROVIDER=builtin requires PostgreSQL 17+, got ${PG_VERSION}"
    fi

    ok "${PRETTY_NAME:-unknown} (${VERSION_CODENAME}), target PostgreSQL ${PG_VERSION}"
}

# --------------------------------------------------------------------------
# PGDG apt repository
# --------------------------------------------------------------------------

pgdg_configured() {
    grep -rqs 'apt\.postgresql\.org' /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null
}

add_pgdg_repo() {
    log "PGDG apt repository"

    if pgdg_configured; then
        ok "already configured"
        return
    fi

    if dry; then
        printf '     would add apt.postgresql.org for %s-pgdg\n' "${VERSION_CODENAME}"
        return
    fi

    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq postgresql-common curl ca-certificates gnupg

    # Preferred path: the helper shipped by postgresql-common, which owns the
    # keyring and sources file so later package updates stay consistent.
    local helper=/usr/share/postgresql-common/pgdg/apt.postgresql.org.sh
    if [[ -x "${helper}" ]] && "${helper}" -y; then
        ok "added via postgresql-common helper"
    else
        warn "helper unavailable or failed; falling back to manual repo setup"
        install -d /usr/share/postgresql-common/pgdg
        curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
            -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
        printf 'deb [signed-by=%s] https://apt.postgresql.org/pub/repos/apt %s-pgdg main\n' \
            /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
            "${VERSION_CODENAME}" > /etc/apt/sources.list.d/pgdg.list
        ok "added manually"
    fi

    apt-get update -qq
}

# --------------------------------------------------------------------------
# packages
# --------------------------------------------------------------------------

install_packages() {
    log "Packages"

    local pkgs=(
        "postgresql-${PG_VERSION}"
        "postgresql-client-${PG_VERSION}"
        "postgresql-contrib-${PG_VERSION}"
    )

    local missing=()
    local p
    for p in "${pkgs[@]}"; do
        dpkg-query -W -f='${Status}' "${p}" 2>/dev/null | grep -q '^install ok installed$' \
            || missing+=("${p}")
    done

    if [[ ${#missing[@]} -eq 0 ]]; then
        ok "already installed: ${pkgs[*]}"
        return
    fi

    if dry; then
        printf '     would install: %s\n' "${missing[*]}"
        return
    fi

    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${missing[@]}"
    ok "installed: ${missing[*]}"
}

# --------------------------------------------------------------------------
# cluster (encoding + locale are immutable, so get them right up front)
# --------------------------------------------------------------------------

cluster_exists() {
    pg_lsclusters -h 2>/dev/null | awk -v v="${PG_VERSION}" -v c="${PG_CLUSTER}" \
        '$1 == v && $2 == c { found = 1 } END { exit !found }'
}

cluster_query() {
    su postgres -c "psql -tAX -p ${PG_PORT} -c \"$1\"" 2>/dev/null
}

cluster_reachable() {
    su postgres -c "psql -tAX -p ${PG_PORT} -c 'SELECT 1'" >/dev/null 2>&1
}

# Callers must confirm cluster_reachable first. Treating an unreachable server
# as "not empty" would report a data-loss risk that does not exist, and
# treating it as empty would be far worse.
cluster_is_empty() {
    local count
    count="$(cluster_query "SELECT count(*) FROM pg_database \
        WHERE datname NOT IN ('postgres','template0','template1')")"
    [[ "${count}" == "0" ]]
}

# lc_collate and lc_ctype were removed as settings in PostgreSQL 16, so
# `SHOW lc_collate` errors out there -- reading it would make this function
# report a mismatch on every run and drop a perfectly good cluster. Read the
# catalog instead. The locale column is datlocale on 17+ and daticulocale on
# 16, hence the jsonb lookup rather than naming it directly.
cluster_locale_matches() {
    local row enc provider locale expected

    # datlocprovider is of type "char", which has no unambiguous || operator.
    row="$(cluster_query "SELECT pg_encoding_to_char(encoding) || '|' || datlocprovider::text || '|' \
        || coalesce(to_jsonb(d)->>'datlocale', to_jsonb(d)->>'daticulocale', datcollate) \
        FROM pg_database d WHERE datname = 'template1'")"
    [[ -n "${row}" ]] || return 1

    IFS='|' read -r enc provider locale <<< "${row}"

    case "${PG_LOCALE_PROVIDER}" in
        builtin) expected=b ;;
        icu)     expected=i ;;
        libc)    expected=c ;;
    esac

    [[ "${enc}" == "${PG_ENCODING}" \
        && "${provider}" == "${expected}" \
        && "${locale}" == "${PG_LOCALE}" ]]
}

initdb_opts() {
    if [[ "${PG_LOCALE_PROVIDER}" == "builtin" ]]; then
        printf -- '--encoding=%s --locale-provider=builtin --builtin-locale=%s' \
            "${PG_ENCODING}" "${PG_LOCALE}"
    else
        printf -- '--encoding=%s --locale=%s' "${PG_ENCODING}" "${PG_LOCALE}"
    fi
}

ensure_cluster() {
    log "Cluster ${PG_VERSION}/${PG_CLUSTER}"

    if dry && ! cluster_exists; then
        printf '     would create cluster with: %s\n' "$(initdb_opts)"
        return
    fi

    if ! cluster_exists; then
        # shellcheck disable=SC2046
        run pg_createcluster "${PG_VERSION}" "${PG_CLUSTER}" --port "${PG_PORT}" --start -- $(initdb_opts)
        ok "created"
        return
    fi

    run pg_ctlcluster "${PG_VERSION}" "${PG_CLUSTER}" start --skip-systemctl-redirect 2>/dev/null || true

    # Every check below reads from the running server. Without this guard a
    # connection failure looks identical to "locale differs, cluster has data",
    # which is an alarming and completely wrong thing to tell the operator.
    if ! cluster_reachable; then
        if dry; then
            warn "cluster is not accepting connections on port ${PG_PORT};"
            warn "cannot verify encoding/locale in dry-run mode"
            return
        fi
        die "cluster ${PG_VERSION}/${PG_CLUSTER} exists but is not accepting connections
     on port ${PG_PORT}. Check: pg_lsclusters and
     /var/log/postgresql/postgresql-${PG_VERSION}-${PG_CLUSTER}.log"
    fi

    if cluster_locale_matches; then
        ok "exists with encoding=${PG_ENCODING} locale=${PG_LOCALE}"
        return
    fi

    warn "cluster locale/encoding differs from the requested ${PG_ENCODING}/${PG_LOCALE}"
    warn "these cannot be changed in place; the cluster must be recreated"

    if [[ "${PG_RECREATE_CLUSTER}" == "no" ]]; then
        warn "PG_RECREATE_CLUSTER=no, leaving it alone"
        return
    fi

    if ! cluster_is_empty && [[ "${PG_FORCE_RECREATE}" != "yes" ]]; then
        die "cluster holds user databases; refusing to drop it.
     Back up first (pg_dumpall), then re-run with PG_FORCE_RECREATE=yes,
     or set PG_RECREATE_CLUSTER=no to keep the current locale."
    fi

    run pg_dropcluster "${PG_VERSION}" "${PG_CLUSTER}" --stop
    # shellcheck disable=SC2046
    run pg_createcluster "${PG_VERSION}" "${PG_CLUSTER}" --port "${PG_PORT}" --start -- $(initdb_opts)
    ok "recreated"
}

# --------------------------------------------------------------------------
# tuning, written to conf.d so package upgrades never clobber it
# --------------------------------------------------------------------------

clamp() {
    local value="$1" lo="$2" hi="$3"
    (( value < lo )) && value="${lo}"
    (( value > hi )) && value="${hi}"
    printf '%s' "${value}"
}

render_local_conf() {
    local total_mb shared_mb cache_mb maint_mb work_mb
    total_mb=$(( $(awk '/^MemTotal:/ {print $2}' /proc/meminfo) / 1024 ))

    shared_mb=$(clamp $(( total_mb / 4 )) 128 65536)
    cache_mb=$(clamp $(( total_mb * 3 / 5 )) 256 262144)
    maint_mb=$(clamp $(( total_mb / 20 )) 256 2048)
    work_mb=$(clamp $(( total_mb / 4 / PG_MAX_CONNECTIONS )) 4 64)

    cat <<EOF
# Managed by setup-postgres.sh -- edits here are overwritten on re-run.
# Put hand-tuned overrides in a file that sorts later, e.g. 20-manual.conf.
# Generated for a host with ${total_mb} MB RAM.

listen_addresses = '${PG_LISTEN}'
port = ${PG_PORT}
max_connections = ${PG_MAX_CONNECTIONS}

# Authentication. scram-sha-256 only; md5 is broken and must not be used.
password_encryption = scram-sha-256
ssl = ${PG_SSL}

# Memory
shared_buffers = ${shared_mb}MB
effective_cache_size = ${cache_mb}MB
maintenance_work_mem = ${maint_mb}MB
work_mem = ${work_mb}MB

# Storage assumptions: SSD-backed. Raise random_page_cost for spinning disks.
random_page_cost = 1.1
effective_io_concurrency = 200

# WAL / checkpoints
wal_level = replica
max_wal_size = 4GB
min_wal_size = 1GB
checkpoint_completion_target = 0.9
archive_mode = on
archive_command = '/bin/true'   # replaced by pgBackRest; see README

# Observability. pg_stat_statements needs a restart to load.
shared_preload_libraries = 'pg_stat_statements'
pg_stat_statements.max = 10000
pg_stat_statements.track = all

log_min_duration_statement = 1000
log_checkpoints = on
log_connections = on
log_disconnections = on
log_lock_waits = on
log_temp_files = 0
log_autovacuum_min_duration = 250ms
EOF
}

write_local_conf() {
    log "Tuning (${LOCAL_CONF})"

    local tmp
    tmp="$(mktemp)"
    render_local_conf > "${tmp}"

    if [[ -f "${LOCAL_CONF}" ]] && cmp -s "${tmp}" "${LOCAL_CONF}"; then
        rm -f "${tmp}"
        ok "unchanged"
        return
    fi

    if dry; then
        printf '     would write %s:\n' "${LOCAL_CONF}"
        sed 's/^/       /' "${tmp}"
        rm -f "${tmp}"
        return
    fi

    install -d -o postgres -g postgres -m 755 "${CONF_DIR}/conf.d"
    [[ -f "${LOCAL_CONF}" ]] && cp -a "${LOCAL_CONF}" "${LOCAL_CONF}.bak.${STAMP}"
    install -o postgres -g postgres -m 644 "${tmp}" "${LOCAL_CONF}"
    rm -f "${tmp}"

    # listen_addresses, shared_buffers and shared_preload_libraries are all
    # postmaster-level, so a reload will not pick them up.
    NEEDS_RESTART=yes
    ok "written (restart required)"
}

# --------------------------------------------------------------------------
# pg_hba.conf
# --------------------------------------------------------------------------

render_hba() {
    local hosts=""
    if [[ -n "${PG_ALLOW_CIDR}" ]]; then
        local cidr
        while IFS= read -r cidr; do
            [[ -n "${cidr}" ]] || continue
            hosts+="$(printf 'hostssl all             all             %-18s scram-sha-256\n' "${cidr}")"$'\n'
        done < <(tr ',' '\n' <<< "${PG_ALLOW_CIDR}" | sed 's/[[:space:]]//g')
    else
        hosts="# (no PG_ALLOW_CIDR set -- local connections only)"$'\n'
    fi

    local tmpl="${REPO_ROOT}/templates/pg_hba.conf.tmpl"
    [[ -r "${tmpl}" ]] || die "template not found: ${tmpl}"

    awk -v hosts="${hosts}" '
        /__ALLOWED_HOSTS__/ { printf "%s", hosts; next }
        { print }
    ' "${tmpl}"
}

write_hba() {
    log "Host-based auth (${HBA_CONF})"

    local tmp
    tmp="$(mktemp)"
    render_hba > "${tmp}"

    if [[ -f "${HBA_CONF}" ]] && cmp -s "${tmp}" "${HBA_CONF}"; then
        rm -f "${tmp}"
        ok "unchanged"
        return
    fi

    if dry; then
        printf '     would write %s:\n' "${HBA_CONF}"
        sed 's/^/       /' "${tmp}"
        rm -f "${tmp}"
        return
    fi

    [[ -f "${HBA_CONF}" ]] && cp -a "${HBA_CONF}" "${HBA_CONF}.bak.${STAMP}"
    install -o postgres -g postgres -m 640 "${tmp}" "${HBA_CONF}"
    rm -f "${tmp}"

    NEEDS_RELOAD=yes
    ok "written (backup: ${HBA_CONF}.bak.${STAMP})"
}

# --------------------------------------------------------------------------
# apply
# --------------------------------------------------------------------------

apply_changes() {
    log "Applying"

    if [[ "${NEEDS_RESTART}" == "yes" ]]; then
        run pg_ctlcluster "${PG_VERSION}" "${PG_CLUSTER}" restart
        ok "restarted"
    elif [[ "${NEEDS_RELOAD}" == "yes" ]]; then
        run pg_ctlcluster "${PG_VERSION}" "${PG_CLUSTER}" reload
        ok "reloaded"
    else
        ok "no changes to apply"
    fi

    run systemctl enable postgresql >/dev/null 2>&1 || true
}

summary() {
    log "Done"
    cat <<EOF

  Next steps:

    1. Create the application role and database:
         sudo ./scripts/create-app-db.sh myapp

    2. Check the result:
         sudo ./scripts/verify.sh

    3. Set up backups before this cluster holds anything you care about.
       See the "Backups" section of README.md -- archive_command is a
       placeholder until pgBackRest is configured.

EOF
}

main() {
    dry && warn "PG_DRY_RUN=yes -- no changes will be made"
    preflight
    add_pgdg_repo
    install_packages
    ensure_cluster
    write_local_conf
    write_hba
    apply_changes
    summary
}

main "$@"
