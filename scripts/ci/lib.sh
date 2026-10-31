# Shared CI helpers (TASK-0005 #2, TASK-0006 #2, #5). Sourced by scripts/ci/*.sh, not executed.
# Annotations on this public repository are readable by anyone, so nothing secret may reach them or the logs.
# All CI credentials are generated into .env and .secrets/*.env by init-env.sh; GITHUB_TOKEN is masked by the
# runner itself.

# Values of credential-like entries in the given env files, one per line (6+ characters): any key containing
# PASS, SECRET, KEY, TOKEN, SALT, CREDENTIAL or AUTH (any case), and every entry of a .secrets/ file.
secret_values() {
  local f
  for f in "$@"; do
    [ -r "$f" ] || continue
    if [[ $f == *.secrets/* ]]; then grep -E '^[A-Za-z0-9_]+=.' "$f"
    else grep -iE '^[A-Za-z0-9_]*(PASS|SECRET|KEY|TOKEN|SALT|CREDENTIAL|AUTH)[A-Za-z0-9_]*=.' "$f"; fi
  done | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/' | awk 'length >= 6' | sort -u || true
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
    -e 's/((pass(word|wd)?|pwd|secret|token|api[_-]?key|access[_-]?key)["'\'' ]*[=:] *)[^ ,;"'\'']+/\1***/Ig'
}

# Annotation body for a failed step: error lines and the tail of the step's log, redacted, escaped for a single
# workflow-command line. Usage: annotation_body LOG ENV_FILE...
annotation_body() {
  local log=$1; shift
  { sed 's/\x1b\[[0-9;]*m//g' "$log" | grep -iE 'exception|error|denied|cannot|failed|⨯' | grep -v ' at ' | head -6
    sed 's/\x1b\[[0-9;]*m//g' "$log" | grep -v '^\s*$' | grep -v ' at \|^phar:' | tail -6; } \
    | redact "$@" | sed ':a;N;$!ba;s/%/%25/g;s/\r/%0D/g;s/\n/%0A/g'
}
