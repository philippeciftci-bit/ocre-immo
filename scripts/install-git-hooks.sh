#!/bin/bash
# Installe les git hooks d'auto-deploy ocre-immo. À exécuter une fois après clone.
# Les hooks de .git/hooks/ ne sont pas versionnés par git, d'où ce script bootstrap.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOOKS="$REPO_ROOT/.git/hooks"

cat > "$HOOKS/post-merge" <<'EOF'
#!/bin/bash
exec /root/bin/ocre-deploy.sh
EOF

cat > "$HOOKS/post-checkout" <<'EOF'
#!/bin/bash
[ "${3:-0}" = "1" ] && exec /root/bin/ocre-deploy.sh
exit 0
EOF

chmod +x "$HOOKS/post-merge" "$HOOKS/post-checkout"
echo "Hooks installés : post-merge, post-checkout → /root/bin/ocre-deploy.sh"
