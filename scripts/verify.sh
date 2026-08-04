#!/usr/bin/env bash
#
# Post-setup check. Reports settings that matter for a shared/production
# cluster and flags the configuration mistakes that cause real incidents.
#
# Exits non-zero if any FAIL is reported, so it can be used in CI.
#
# Usage: sudo ./scripts/verify.sh

set -Eeuo pipefail

PG_VERSION="${PG_VERSION:-18}"
PG_CLUSTER="${PG_CLUSTER:-main}"
PG_PORT="${PG_PORT:-5432}"
HBA_CONF="/etc/postgresql/${PG_VERSION}/${PG_CLUSTER}/pg_hba.conf"

FAILURES=0

log()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
pass() { printf '\033[1;32m  PASS\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31m  FAIL\033[0m %s\n' "$*"; FAILURES=$((FAILURES + 1)); }
info() { printf '       %s\n' "$*"; }

q() { su postgres -c "psql -tAX -p ${PG_PORT} -c \"$1\"" 2>/dev/null; }

log "Service"
if pg_lsclusters -h 2>/dev/null | awk -v v="${PG_VERSION}" -v c="${PG_CLUSTER}" \
        '$1 == v && $2 == c && $4 == "online" { found = 1 } END { exit !found }'; then
    pass "cluster ${PG_VERSION}/${PG_CLUSTER} is online"
else
    fail "cluster ${PG_VERSION}/${PG_CLUSTER} is not online"
    pg_lsclusters 2>/dev/null || true
    exit 1
fi

if systemctl is-enabled postgresql >/dev/null 2>&1; then
    pass "postgresql enabled at boot"
else
    fail "postgresql not enabled at boot (systemctl enable postgresql)"
fi

log "Version and storage"
info "$(q 'SELECT version()')"

CHECKSUMS="$(q 'SHOW data_checksums')"
if [[ "${CHECKSUMS}" == "on" ]]; then
    pass "data checksums on"
else
    fail "data checksums off -- silent corruption will go undetected.
       This cannot be enabled on a running cluster without pg_checksums
       (requires downtime) or a dump/restore into a new cluster."
fi

log "Encoding and locale (immutable -- confirm before loading data)"
# lc_collate/lc_ctype are no longer settings as of PostgreSQL 16; read the
# catalog. datlocale is named daticulocale on 16, so look it up via jsonb.
info "server_encoding = $(q 'SHOW server_encoding')"
info "collate         = $(q "SELECT datcollate FROM pg_database WHERE datname = 'template1'")"
info "ctype           = $(q "SELECT datctype FROM pg_database WHERE datname = 'template1'")"
info "locale          = $(q "SELECT coalesce(to_jsonb(d)->>'datlocale', to_jsonb(d)->>'daticulocale', '(n/a)')
                             FROM pg_database d WHERE datname = 'template1'")"
PROVIDER="$(q "SELECT datlocprovider FROM pg_database WHERE datname = 'template1'")"
case "${PROVIDER}" in
    b) pass "locale provider: builtin (immune to glibc collation drift)" ;;
    i) pass "locale provider: ICU" ;;
    c) info "locale provider: libc -- a glibc upgrade can change sort order and
       corrupt text indexes. Plan to REINDEX after any glibc major upgrade." ;;
    *) info "locale provider: ${PROVIDER}" ;;
esac

log "Authentication"
ENCRYPTION="$(q 'SHOW password_encryption')"
if [[ "${ENCRYPTION}" == "scram-sha-256" ]]; then
    pass "password_encryption = scram-sha-256"
else
    fail "password_encryption = ${ENCRYPTION} (must be scram-sha-256)"
fi

MD5_ROLES="$(q "SELECT count(*) FROM pg_authid WHERE rolpassword LIKE 'md5%'")"
if [[ "${MD5_ROLES}" == "0" ]]; then
    pass "no roles still holding md5 password hashes"
else
    fail "${MD5_ROLES} role(s) still have md5 hashes -- these keep working until
       each password is re-set. Fix with: ALTER ROLE <name> PASSWORD '<new>';"
fi

NOPASS="$(q "SELECT string_agg(rolname, ', ') FROM pg_authid
             WHERE rolcanlogin AND rolpassword IS NULL AND rolname <> 'postgres'")"
if [[ -z "${NOPASS}" ]]; then
    pass "all login roles have a password"
else
    fail "login roles without a password: ${NOPASS}"
fi

log "Network exposure"
LISTEN="$(q 'SHOW listen_addresses')"
info "listen_addresses = ${LISTEN}"
if [[ "${LISTEN}" == "*" || "${LISTEN}" == "0.0.0.0" ]]; then
    info "listening on all interfaces -- make sure a firewall restricts port ${PG_PORT}"
fi

if [[ -r "${HBA_CONF}" ]]; then
    if grep -vE '^\s*(#|$)' "${HBA_CONF}" | grep -qE '\btrust\b'; then
        fail "pg_hba.conf contains a 'trust' rule -- this accepts any client with
       no authentication at all. Remove it."
    else
        pass "no 'trust' rules in pg_hba.conf"
    fi

    if grep -vE '^\s*(#|$)' "${HBA_CONF}" | grep -qE '(0\.0\.0\.0/0|::/0)'; then
        fail "pg_hba.conf allows 0.0.0.0/0 or ::/0 -- narrow this to known networks"
    else
        pass "no world-open address ranges in pg_hba.conf"
    fi
else
    fail "cannot read ${HBA_CONF}"
fi

SSL="$(q 'SHOW ssl')"
if [[ "${SSL}" == "on" ]]; then
    pass "ssl = on"
else
    fail "ssl = off -- remote connections would be sent in the clear"
fi

log "Resources"
for setting in shared_buffers effective_cache_size work_mem maintenance_work_mem max_connections; do
    info "$(printf '%-22s = %s' "${setting}" "$(q "SHOW ${setting}")")"
done

log "Extensions"
if [[ "$(q "SELECT count(*) FROM pg_settings
            WHERE name = 'shared_preload_libraries'
              AND setting LIKE '%pg_stat_statements%'")" == "1" ]]; then
    pass "pg_stat_statements preloaded"
else
    fail "pg_stat_statements not preloaded -- you will have no query-level
       visibility when something gets slow. Needs a restart to take effect."
fi

log "Backups"
ARCHIVE_MODE="$(q 'SHOW archive_mode')"
ARCHIVE_CMD="$(q 'SHOW archive_command')"
info "archive_mode    = ${ARCHIVE_MODE}"
info "archive_command = ${ARCHIVE_CMD}"
# `SHOW archive_command` reports the literal string "(disabled)" when
# archive_mode is off, which is not a command and must not read as configured.
if [[ "${ARCHIVE_MODE}" != "on" && "${ARCHIVE_MODE}" != "always" ]]; then
    fail "archive_mode = ${ARCHIVE_MODE} -- WAL is not archived, so point-in-time
       recovery is NOT possible. Only the last base backup could be restored."
elif [[ -z "${ARCHIVE_CMD}" || "${ARCHIVE_CMD}" == "(disabled)" || "${ARCHIVE_CMD}" == "/bin/true" ]]; then
    fail "archive_command is a placeholder (${ARCHIVE_CMD:-empty}) -- WAL segments are
       discarded, so point-in-time recovery is NOT possible. Configure
       pgBackRest before this cluster holds production data."
else
    pass "WAL archiving active: ${ARCHIVE_CMD}"
fi

if command -v pgbackrest >/dev/null 2>&1; then
    pass "pgbackrest installed"
    info "last backup: $(pgbackrest info --output=text 2>/dev/null | grep -m1 'full backup' || echo 'none found')"
else
    fail "pgbackrest not installed"
fi

printf '\n'
if [[ "${FAILURES}" -eq 0 ]]; then
    printf '\033[1;32mAll checks passed.\033[0m\n'
else
    printf '\033[1;31m%d check(s) failed.\033[0m\n' "${FAILURES}"
fi
exit $(( FAILURES > 0 ? 1 : 0 ))
