#!/usr/bin/env bash
#
# Create an application role + database, plus a read-only role for the same
# database. Idempotent: re-running will not reset an existing password.
#
# Usage:
#   sudo ./scripts/create-app-db.sh myapp
#   sudo APP_PASSWORD='...' ./scripts/create-app-db.sh myapp
#
# With no APP_PASSWORD a random one is generated and written to
# /root/.pgpass-<name> (mode 600). Passwords are passed to psql over stdin,
# so they do not appear in the process list or in shell history.

set -Eeuo pipefail

APP_NAME="${1:-}"
PG_PORT="${PG_PORT:-5432}"
APP_PASSWORD="${APP_PASSWORD:-}"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m  ok\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

[[ -n "${APP_NAME}" ]] || die "usage: $0 <app-name>"
[[ "${APP_NAME}" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] \
    || die "app name must match ^[a-z_][a-z0-9_]{0,62}$ (got '${APP_NAME}')"
[[ "${EUID}" -eq 0 ]] || die "run as root"

# Note the bounded read rather than `tr ... | head -c 32`: head closes the
# pipe as soon as it has enough, tr dies of SIGPIPE, and pipefail turns that
# into a spurious exit 141.
gen_password() {
    local raw
    raw="$(LC_ALL=C tr -dc 'A-Za-z0-9' < <(head -c 1024 /dev/urandom))"
    [[ ${#raw} -ge 32 ]] || die "could not gather enough entropy for a password"
    printf '%s' "${raw:0:32}"
}

if [[ -z "${APP_PASSWORD}" ]]; then
    APP_PASSWORD="$(gen_password)"
    GENERATED=yes
else
    GENERATED=no
fi

psql_as_postgres() { su postgres -c "psql -v ON_ERROR_STOP=1 -X -q -p ${PG_PORT} $*"; }

log "Role and database '${APP_NAME}'"

# DO blocks keep this idempotent: CREATE ROLE/DATABASE both error if the
# object already exists, and CREATE DATABASE cannot run inside a transaction.
psql_as_postgres -d postgres <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${APP_NAME}') THEN
        CREATE ROLE ${APP_NAME} LOGIN;
        RAISE NOTICE 'created role ${APP_NAME}';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${APP_NAME}_readonly') THEN
        CREATE ROLE ${APP_NAME}_readonly NOLOGIN;
        RAISE NOTICE 'created role ${APP_NAME}_readonly';
    END IF;
END
\$\$;
SQL

if ! psql_as_postgres -d postgres -tAc \
        "\"SELECT 1 FROM pg_database WHERE datname = '${APP_NAME}'\"" | grep -q 1; then
    psql_as_postgres -d postgres -c "\"CREATE DATABASE ${APP_NAME} OWNER ${APP_NAME}\""
    ok "database created"
else
    ok "database already exists"
fi

# Set the password only when the role has none, or when one was supplied
# explicitly. This keeps re-runs from rotating a password the app is using.
HAS_PASSWORD="$(psql_as_postgres -d postgres -tAc \
    "\"SELECT rolpassword IS NOT NULL FROM pg_authid WHERE rolname = '${APP_NAME}'\"")"

if [[ "${HAS_PASSWORD}" != "t" || "${GENERATED}" == "no" ]]; then
    printf "ALTER ROLE %s PASSWORD '%s';\n" "${APP_NAME}" "${APP_PASSWORD}" \
        | su postgres -c "psql -v ON_ERROR_STOP=1 -X -q -p ${PG_PORT} -d postgres"
    ok "password set"

    if [[ "${GENERATED}" == "yes" ]]; then
        PGPASS="/root/.pgpass-${APP_NAME}"
        printf 'localhost:%s:%s:%s:%s\n' \
            "${PG_PORT}" "${APP_NAME}" "${APP_NAME}" "${APP_PASSWORD}" > "${PGPASS}"
        chmod 600 "${PGPASS}"
        ok "generated password stored in ${PGPASS}"
    fi
else
    ok "password already set, left unchanged"
fi

log "Privileges"

psql_as_postgres -d postgres <<SQL
REVOKE ALL ON DATABASE ${APP_NAME} FROM PUBLIC;
GRANT CONNECT ON DATABASE ${APP_NAME} TO ${APP_NAME};
GRANT CONNECT ON DATABASE ${APP_NAME} TO ${APP_NAME}_readonly;
SQL

psql_as_postgres -d "${APP_NAME}" <<SQL
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

-- Since PostgreSQL 15 the public schema is not world-writable, so the owner
-- has to be granted explicitly.
GRANT USAGE, CREATE ON SCHEMA public TO ${APP_NAME};
GRANT USAGE ON SCHEMA public TO ${APP_NAME}_readonly;

GRANT SELECT ON ALL TABLES IN SCHEMA public TO ${APP_NAME}_readonly;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO ${APP_NAME}_readonly;

-- Applies to tables created later, but only to those created by ${APP_NAME}.
ALTER DEFAULT PRIVILEGES FOR ROLE ${APP_NAME} IN SCHEMA public
    GRANT SELECT ON TABLES TO ${APP_NAME}_readonly;
ALTER DEFAULT PRIVILEGES FOR ROLE ${APP_NAME} IN SCHEMA public
    GRANT SELECT ON SEQUENCES TO ${APP_NAME}_readonly;
SQL

ok "granted"

cat <<EOF

  Connection string:
    postgresql://${APP_NAME}@localhost:${PG_PORT}/${APP_NAME}

  To hand out read-only access, create a login role and grant the group:
    CREATE ROLE alice LOGIN PASSWORD '...';
    GRANT ${APP_NAME}_readonly TO alice;

EOF
