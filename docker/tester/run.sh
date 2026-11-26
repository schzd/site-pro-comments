#!/usr/bin/env bash
# Test runner entrypoint for the `tester` compose service.
#
#   docker compose run --rm tester unit        # pure + ORM unit tests
#   docker compose run --rm tester regression  # in-process functional suites
#   docker compose run --rm tester smoke       # black-box HTTP gate (needs test stack)
#   docker compose run --rm tester e2e         # real browser via Selenium (needs test stack)
#   docker compose run --rm tester all         # everything, in gate order
#
# Extra arguments are passed to codecept, e.g.
#   docker compose run --rm tester regression --group comments -v
#
# Every suite gets its own database (see docker/mysql/initdb), selected here
# through DB_DSN, so suites can run concurrently in separate containers.
set -euo pipefail
cd /app

# Actor classes (*Tester.php) are generated, not committed.
for p in common frontend backend blackbox; do vendor/bin/codecept build -c "$p" >/dev/null; done

suite="${1:-all}"
shift || true

db_dsn() { echo "mysql:host=mysql;port=3306;dbname=$1;charset=utf8mb4"; }

prepare_db() {
    local db="$1"
    export DB_DSN
    DB_DSN="$(db_dsn "$db")"
    php yii migrate --interactive=0 >/dev/null
}

require_http() {
    local name="$1" url="$2"
    for _ in $(seq 1 60); do
        if curl -s -o /dev/null "$url"; then return 0; fi
        sleep 1
    done
    echo "ERROR: $name is not reachable at $url" >&2
    echo "       Start the isolated test stack first:  docker compose --profile test up -d" >&2
    exit 2
}

run_suite() {
    local s="$1"; shift
    case "$s" in
        unit)
            prepare_db yii2advanced_unit
            vendor/bin/codecept run -c common Unit "$@"
            ;;
        regression)
            prepare_db yii2advanced_regression
            vendor/bin/codecept run -c frontend Regression "$@" && vendor/bin/codecept run -c backend Regression "$@"
            ;;
        smoke|e2e)
            # The black-box suites talk to frontend-test/backend-test/ws-test,
            # which use the e2e schema. Make sure it is migrated and seeded.
            prepare_db yii2advanced_e2e
            php yii seed/admin >/dev/null
            require_http frontend-test "${E2E_FRONTEND_URL}/"
            require_http backend-test "${E2E_BACKEND_URL}/login"
            if [ "$s" = "e2e" ]; then
                require_http selenium "http://${SELENIUM_HOST}:4444/status"
                vendor/bin/codecept run -c blackbox E2E "$@"
            else
                vendor/bin/codecept run -c blackbox Smoke "$@"
            fi
            ;;
        *)
            echo "Unknown suite '$s'. Use: unit | smoke | regression | e2e | all" >&2
            exit 64
            ;;
    esac
}

if [ "$suite" = "help" ] || [ "$suite" = "-h" ] || [ "$suite" = "--help" ]; then
    sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'
    exit 0
fi

if [ "$suite" = "all" ]; then
    # Gate order: cheapest and most diagnostic first.
    for s in unit smoke regression e2e; do
        echo "================================================================ $s"
        run_suite "$s" "$@"
    done
else
    run_suite "$suite" "$@"
fi
