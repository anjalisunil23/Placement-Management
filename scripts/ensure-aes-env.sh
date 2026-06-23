#!/bin/sh
# Append AES login vars from .env.production.example when missing from live .env
ENV_FILE="${1:-.env}"
EXAMPLE="${2:-.env.production.example}"

[ -f "$ENV_FILE" ] || exit 0
/bin/grep -qE '^AES_AUTH_KEY=.+' "$ENV_FILE" 2>/dev/null && exit 0
[ -f "$EXAMPLE" ] || exit 0

{
  echo ""
  echo "# AES institute login (auto-added on deploy)"
  /bin/grep -E '^AES_(AUTH_KEY|REF_HOST)=' "$EXAMPLE"
} >> "$ENV_FILE"
