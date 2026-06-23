#!/bin/sh
# Ensure AES login vars exist in live .env (append or replace empty values from example).
ENV_FILE="${1:-.env}"
EXAMPLE="${2:-.env.production.example}"

[ -f "$ENV_FILE" ] || exit 0
[ -f "$EXAMPLE" ] || exit 0

KEY=$(
  /bin/grep -E '^AES_AUTH_KEY=' "$ENV_FILE" 2>/dev/null \
    | /usr/bin/head -n 1 \
    | /usr/bin/cut -d= -f2- \
    | /usr/bin/tr -d ' "\r'
)

if [ -n "$KEY" ]; then
  exit 0
fi

TMP="${ENV_FILE}.aes.$$"
/bin/grep -vE '^AES_(AUTH_KEY|REF_HOST)=' "$ENV_FILE" > "$TMP" 2>/dev/null || /bin/cp "$ENV_FILE" "$TMP"

{
  /bin/cat "$TMP"
  echo ""
  echo "# AES institute login (auto-added on deploy)"
  /bin/grep -E '^AES_(AUTH_KEY|REF_HOST)=' "$EXAMPLE"
} > "$ENV_FILE"

/bin/rm -f "$TMP"
