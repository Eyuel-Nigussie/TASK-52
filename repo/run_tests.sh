#!/usr/bin/env bash
set -euo pipefail

# ─── Colour helpers ───────────────────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

info()  { echo -e "${GREEN}[run_tests.sh]${NC} $*"; }
warn()  { echo -e "${YELLOW}[run_tests.sh] WARNING:${NC} $*"; }
error() { echo -e "${RED}[run_tests.sh] ERROR:${NC} $*" >&2; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ─── Build app image if not present ──────────────────────────────────────────
info "Ensuring the 'app' image is built..."
docker compose build app

# ─── Run test suite in a throwaway container (SQLite in-memory) ──────────────
# No PostgreSQL required — phpunit.xml already configures sqlite/:memory: but
# we pass explicit env vars here for maximum portability when running ad-hoc.
info "Launching throwaway test container..."
echo ""

set +e
docker compose run --rm \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=:memory: \
    -e DB_URL="" \
    -e APP_ENV=testing \
    -e APP_KEY="base64:0eOu7/QTYq8WxLGNGqZZ2LG5FVoEH0iz7rhojHreOJM=" \
    -e APP_DEBUG=true \
    -e CACHE_STORE=array \
    -e QUEUE_CONNECTION=sync \
    -e SESSION_DRIVER=array \
    -e BROADCAST_CONNECTION=null \
    -e MAIL_MAILER=array \
    app \
    php artisan test --ansi
TEST_EXIT_CODE=$?
set -e

# ─── Report ───────────────────────────────────────────────────────────────────
echo ""
if [ "$TEST_EXIT_CODE" -eq 0 ]; then
    echo -e "${GREEN}============================================================${NC}"
    echo -e "${GREEN}  All tests passed.${NC}"
    echo -e "${GREEN}============================================================${NC}"
else
    error "One or more tests failed (exit code ${TEST_EXIT_CODE})."
    echo -e "${RED}============================================================${NC}"
    echo -e "${RED}  Test run finished with failures.${NC}"
    echo -e "${RED}============================================================${NC}"
fi

exit "$TEST_EXIT_CODE"
