# Shared CI helpers (TASK-0005 #2). Sourced by scripts/ci/*.sh, not executed.
# Annotations on this public repository are readable by anyone, so nothing secret may reach them or the logs.

# Values of password, secret, key and token entries in the given env files, one per line (6+ characters).
secret_values() {
  cat -- "$@" 2>/dev/null \
    | grep -E '^[A-Z0-9_]*(PASSWORD|SECRET|KEY|TOKEN)[A-Z0-9_]*=.' \
    | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/' | awk 'length >= 6' | sort -u || true
}

# In GitHub Actions, register those values as masks, so the runner hides them in logs and annotations.
mask_secrets() {
  [ -n "${GITHUB_ACTIONS:-}" ] || return 0
  local v
  while IFS= read -r v; do echo "::add-mask::$v"; done < <(secret_values "$@")
}

# stdin -> stdout with the env files' secret values and credential-shaped text replaced by ***.
redact() {
  local text v
  text=$(cat)
  while IFS= read -r v; do
    [ -n "$v" ] && text=${text//"$v"/***}
  done < <(secret_values "$@")
  printf '%s\n' "$text" | sed -E \
    -e 's#(://[^:/@ ]+:)[^@ ]+@#\1***@#g' \
    -e 's/((password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key)["'\'' ]*[=:] *)[^ ,;"'\'']+/\1***/Ig'
}
