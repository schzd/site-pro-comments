#!/usr/bin/env bash
# Brings the whole stack up inside a GitHub Codespace (or any devcontainer).
# The app itself is unchanged; only the public URLs differ, because a Codespace
# serves every forwarded port on its own https domain.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -n "${CODESPACE_NAME:-}" ] && [ -n "${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-}" ]; then
    domain="${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
    cat > .env <<EOF
# Written by .devcontainer/post-start.sh for this Codespace — not committed.
FRONTEND_PUBLIC_URL=https://${CODESPACE_NAME}-20080.${domain}
BACKEND_PUBLIC_URL=https://${CODESPACE_NAME}-21080.${domain}
EOF
    echo "[echo] Codespace URLs:"
    cat .env
else
    echo "[echo] not in a Codespace: using the localhost URLs from docker-compose.yml"
fi

echo "[echo] starting the stack (first start installs composer deps, ~3-5 min)…"
docker compose up -d --wait frontend backend ws

# Wall (20080) and admin (21080) live on different Codespace domains. Avatars
# and cross-links between them only load if both ports are public; private
# ports would require a separate GitHub sign-in per domain.
if [ -n "${CODESPACE_NAME:-}" ]; then
    if gh codespace ports visibility 20080:public 21080:public -c "${CODESPACE_NAME}" >/dev/null 2>&1; then
        echo "[echo] ports 20080 and 21080 set to public"
    else
        echo "[echo] WARNING: could not make ports 20080/21080 public automatically."
        echo "        Ports tab → right-click each port → Port Visibility → Public."
        echo "        (The app works either way; only avatars/links across the two ports need it.)"
    fi
fi

cat <<'EOF'

[echo] Ready.
  Public wall   → Ports tab, port 20080
  Admin panel   → Ports tab, port 21080   (admin / admin12345)
  Run the tests → docker compose --profile test up -d && docker compose run --rm tester all
EOF
