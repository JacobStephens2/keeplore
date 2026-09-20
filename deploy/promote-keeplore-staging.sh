#!/usr/bin/env bash
#
# Point staging.keeplore.app at a pushed commit. Safe to rerun.
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run as root.\n' >&2
  exit 1
fi

commit=${1:-}
if [[ ! $commit =~ ^[0-9a-f]{40}$ ]]; then
  printf 'A 40-character commit SHA is required.\n' >&2
  exit 1
fi

script_directory=$(cd "$(dirname "$0")" && pwd)
release_root=/opt/keeplore/staging/releases
current_link=/opt/keeplore/staging/current
retain=3

exec 9>/run/keeplore-staging-promotion.lock
flock -x 9

"$script_directory/install-keeplore-staging.sh" "$commit"
release="$release_root/$commit"
if [[ ! -f $release/.keeplore-staging-release ]]; then
  printf 'Staging Release %s is missing.\n' "$commit" >&2
  exit 1
fi

ln -sfn "$release" "$current_link"
/usr/sbin/apache2ctl configtest >/dev/null
/usr/sbin/apache2ctl graceful

health_ok=0
for url in \
  https://staging.keeplore.app/login.php \
  http://staging.keeplore.app/login.php
do
  code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$url" || true)
  if [[ $code =~ ^(200|302)$ ]]; then
    health_ok=1
    break
  fi
done
if [[ $health_ok -ne 1 ]]; then
  printf 'Staging health check failed after promoting %s.\n' "$commit" >&2
  exit 1
fi

# Keep the newest staging Releases; never delete the one current points at.
mapfile -t old < <(ls -1dt "$release_root"/[0-9a-f]* 2>/dev/null | tail -n +$((retain + 1)) || true)
active=$(readlink -f "$current_link")
for dir in "${old[@]}"; do
  [[ -d $dir ]] || continue
  if [[ $(readlink -f "$dir") == "$active" ]]; then
    continue
  fi
  rm -rf -- "$dir"
done

printf 'Staging now serves %s at https://staging.keeplore.app/\n' "$commit"
