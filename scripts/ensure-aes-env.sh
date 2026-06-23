#!/bin/sh
# Sync AES login vars from .env.production.example into live .env on every deploy.
ENV_FILE="${1:-.env}"
EXAMPLE="${2:-.env.production.example}"

[ -f "$ENV_FILE" ] || exit 0
[ -f "$EXAMPLE" ] || exit 0

TMP="${ENV_FILE}.aes.$$"
/bin/grep -vE '^AES_(AUTH_KEY|REF_HOST)=' "$ENV_FILE" > "$TMP" 2>/dev/null || /bin/cp "$ENV_FILE" "$TMP"

{
  /bin/cat "$TMP"
  echo ""
  echo "# AES institute login (synced on deploy)"
  /bin/grep -E '^AES_(AUTH_KEY|REF_HOST)=' "$EXAMPLE"
} > "$ENV_FILE"

/bin/rm -f "$TMP"
