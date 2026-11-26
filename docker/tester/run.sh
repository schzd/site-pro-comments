#!/usr/bin/env bash
# Test runner entrypoint for the `tester` compose service.
#
#   docker compose run --rm tester unit        # pure + ORM unit tests (~8 s)
#   docker compose run --rm tester smoke       # black-box HTTP + WebSocket gate (~10 s, needs test stack)
#   docker compose run --rm tester regression  # in-process functional suites (~15 s)
#   docker compose run --rm tester e2e         # real Chromium via Selenium (~40 s, needs test stack)
#   docker compose run --rm tester all         # everything, in gate order
#   docker compose run --rm tester qa <cmd>    # test intelligence: matrix | plan | gaps | scaffold | shards | history
#
# Extra arguments go to codecept:   docker compose run --rm tester regression -g step-S4 -v
# Env: QA_WORKER=w2           -> unit/regression use their own schema (parallel workers)
#      QA_INCLUDE_QUARANTINE=1 -> also run tests quarantined as flaky (default: skipped, reported)
set -euo pipefail
cd /app

if [ "${1:-help}" = "qa" ]; then
    shift
    exec php qa/qa.php "$@"
fi

# Actor classes (*Tester.php) are generated, not committed.
for p in common frontend backend blackbox; do vendor/bin/codecept build -c "$p" >/dev/null; done

suite="${1:-help}"
shift || true

db_dsn() { echo "mysql:host=mysql;port=3306;dbname=$1;charset=utf8mb4"; }

prepare_db() {
    local db="$1"
    if [ -n "${QA_WORKER:-}" ]; then
        db="${db}_${QA_WORKER}"
        php -r '$p = new PDO("mysql:host=mysql;port=3306", getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
                $p->exec("CREATE DATABASE IF NOT EXISTS `" . $argv[1] . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");' "$db"
    fi
    export DB_DSN
    DB_DSN="$(db_dsn "$db")"
    php yii migrate --interactive=0 >/dev/null
    echo "[tester] database: $db"
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

# codecept with JUnit output (for qa learning) and the quarantine policy.
codecept() {
    local project="$1" name="$2" xml="$3"; shift 3
    local extra=(--xml "$xml")
    if [ -z "${QA_INCLUDE_QUARANTINE:-}" ] && [ -s "qa/quarantine/${project}.txt" ]; then
        echo "[tester] quarantined (non-blocking, see qa/quarantine/${project}.txt):"
        sed 's/^/           /' "qa/quarantine/${project}.txt"
        extra+=(--skip-group quarantine)
    fi
    vendor/bin/codecept run -c "$project" "$name" "${extra[@]}" "$@"
}

run_suite() {
    local s="$1"; shift
    case "$s" in
        unit)
            prepare_db yii2advanced_unit
            codecept common Unit qa-unit.xml "$@"
            ;;
        regression)
            prepare_db yii2advanced_regression
            codecept frontend Regression qa-regression.xml "$@" && codecept backend Regression qa-regression.xml "$@"
            ;;
        smoke|e2e)
            # Black-box suites talk to frontend-test/backend-test/ws-test, which use
            # the e2e schema. They only add uniquely-marked rows, never truncate,
            # so any number of black-box workers can share it.
            prepare_db yii2advanced_e2e
            php yii seed/admin >/dev/null
            require_http frontend-test "${E2E_FRONTEND_URL}/"
            require_http backend-test "${E2E_BACKEND_URL}/login"
            if [ "$s" = "e2e" ]; then
                require_http selenium "http://${SELENIUM_HOST}:4444/status"
                codecept blackbox E2E qa-e2e.xml "$@"
            else
                codecept blackbox Smoke qa-smoke.xml "$@"
            fi
            ;;
        *)
            echo "Unknown suite '$s'. Use: unit | smoke | regression | e2e | all | qa" >&2
            exit 64
            ;;
    esac
}

# Learn from every run, pass or fail (durations, failures, flakiness).
learn() { php qa/qa.php record || true; }

if [ "$suite" = "help" ] || [ "$suite" = "-h" ] || [ "$suite" = "--help" ]; then
    sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'
    exit 0
fi

rm -f ./*/tests/Support/output/qa-*.xml

status=0
if [ "$suite" = "all" ]; then
    # Gate order: cheapest and most diagnostic first; stop at the first red suite.
    for s in unit smoke regression e2e; do
        echo "================================================================ $s"
        run_suite "$s" "$@" || { status=$?; break; }
    done
else
    run_suite "$suite" "$@" || status=$?
fi
learn
exit "$status"
