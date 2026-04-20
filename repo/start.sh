#!/usr/bin/env bash
set -euo pipefail

# ─── Colour helpers ───────────────────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Colour

info()    { echo -e "${GREEN}[start.sh]${NC} $*"; }
warn()    { echo -e "${YELLOW}[start.sh] WARNING:${NC} $*"; }
error()   { echo -e "${RED}[start.sh] ERROR:${NC} $*" >&2; exit 1; }

# ─── .env bootstrap ───────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if [ ! -f .env ]; then
    warn ".env not found — copying .env.example. Edit DB credentials before production use."
    cp .env.example .env
fi

# ─── Sanity-check DB driver ───────────────────────────────────────────────────
DB_CONNECTION_VAL="$(grep -E '^DB_CONNECTION=' .env | cut -d= -f2 | tr -d '[:space:]"' || true)"
if [ "${DB_CONNECTION_VAL}" = "sqlite" ]; then
    warn "DB_CONNECTION is set to 'sqlite' in .env. Docker Compose expects MySQL."
    warn "Update DB_CONNECTION=mysql (and related DB_* vars) for full functionality."
fi

# ─── Clean up any previous state ──────────────────────────────────────────────
# Bringing the stack down first makes repeated `./start.sh` runs idempotent and
# avoids "port already allocated" errors from half-started earlier attempts.
info "Bringing down any previous VetOps stack (if running)..."
docker compose down --remove-orphans >/dev/null 2>&1 || true

# ─── Pre-flight: is port 8000 free? ───────────────────────────────────────────
HOST_PORT="${VETOPS_HOST_PORT:-8000}"
if command -v lsof >/dev/null 2>&1 && lsof -Pi :"${HOST_PORT}" -sTCP:LISTEN -t >/dev/null 2>&1; then
    HOLDER="$(lsof -Pi :"${HOST_PORT}" -sTCP:LISTEN -F c -F p 2>/dev/null | awk '/^c/{sub(/^c/,""); print}' | head -1)"
    warn "Port ${HOST_PORT} is already in use on the host${HOLDER:+ by '${HOLDER}'}."
    warn "Either stop that process, or run with a different port:  VETOPS_HOST_PORT=8001 ./start.sh"
    error "Cannot bind to port ${HOST_PORT}. Aborting."
fi

# ─── Build & start ────────────────────────────────────────────────────────────
info "Building Docker images (--pull ensures base images are up to date)..."
docker compose build --pull

info "Starting services in detached mode..."
docker compose up -d

# ─── Wait for the app to be reachable ─────────────────────────────────────────
info "Waiting for VetOps Portal to become healthy..."
MAX_WAIT=60
ELAPSED=0
INTERVAL=3
until curl -sf "http://localhost:${HOST_PORT}/up" >/dev/null 2>&1; do
    if [ "$ELAPSED" -ge "$MAX_WAIT" ]; then
        warn "App did not respond within ${MAX_WAIT}s. Check logs: docker compose logs app"
        break
    fi
    sleep "$INTERVAL"
    ELAPSED=$((ELAPSED + INTERVAL))
done

# ─── Done ─────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}============================================================${NC}"
echo -e "${GREEN}  VetOps Portal is running at http://localhost:${HOST_PORT}${NC}"
echo -e "${GREEN}============================================================${NC}"
echo ""
echo "  Run tests : ./run_tests.sh"
echo "  View logs : docker compose logs -f app"
echo "  Stop      : docker compose down"
echo ""
