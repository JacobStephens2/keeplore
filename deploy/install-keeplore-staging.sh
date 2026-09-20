#!/usr/bin/env bash
#
# Build an immutable Keeplore staging Release from any pushed commit.
# Unlike production install, the commit need not be on origin/main.
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

repository=JacobStephens2/keeplore
source_mirror=/var/lib/keeplore-source/repository.git
release_root=/opt/keeplore/staging/releases
shared_root=/opt/keeplore/staging/shared
source_remote="https://github.com/${repository}.git"

if [[ ! -d $source_mirror ]]; then
  printf 'Keeplore source mirror is unavailable.\n' >&2
  exit 1
fi

git_repository=(git -c protocol.allow=never -c protocol.https.allow=always \
  --git-dir="$source_mirror")
GIT_TERMINAL_PROMPT=0 "${git_repository[@]}" fetch --force "$source_remote" \
  "$commit" >/dev/null
resolved=$("${git_repository[@]}" rev-parse --verify "${commit}^{commit}")
if [[ $resolved != "$commit" ]]; then
  printf 'Commit %s did not resolve.\n' "$commit" >&2
  exit 1
fi

for shared_entry in private/environment_variables.php private/vendor logs cache; do
  if [[ ! -e "$shared_root/$shared_entry" ]]; then
    printf 'Shared staging state %s is missing from %s.\n' \
      "$shared_entry" "$shared_root" >&2
    exit 1
  fi
done

getent group keeplore-builder >/dev/null || groupadd --system keeplore-builder
id -u keeplore-builder >/dev/null 2>&1 || useradd \
  --system --gid keeplore-builder --home-dir /var/lib/keeplore-builder \
  --shell /usr/sbin/nologin keeplore-builder
install -d -o root -g root -m 0755 "$release_root"
install -d -o keeplore-builder -g keeplore-builder -m 0750 /var/lib/keeplore-builder

release="$release_root/$commit"
if [[ -d $release ]]; then
  printf 'Staging Release %s already exists.\n' "$commit"
  exit 0
fi

staging=$(mktemp -d "$release_root/.release.XXXXXX")
cleanup() {
  if [[ -n ${staging:-} && -d $staging ]]; then rm -rf -- "$staging"; fi
}
trap cleanup EXIT
"${git_repository[@]}" archive "$commit" | tar -x -C "$staging"
chown -R keeplore-builder:keeplore-builder "$staging"
runuser --user keeplore-builder -- env -i \
  HOME=/var/lib/keeplore-builder \
  PATH=/usr/local/bin:/usr/bin:/bin \
  /bin/bash -c 'cd "$1" && find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -r -n1 php -l' \
  keeplore-builder-build "$staging"

for shared_entry in private/environment_variables.php private/vendor logs cache; do
  rm -rf -- "${staging:?}/$shared_entry"
  install -d -o root -g root -m 0755 "$(dirname "$staging/$shared_entry")"
  ln -sfn "$shared_root/$shared_entry" "$staging/$shared_entry"
done
printf '%s\n' "$commit" > "$staging/.keeplore-staging-release"
chown -R --no-dereference root:root "$staging"
chmod -R a-w "$staging"
chmod -R a+rX "$staging"
mv "$staging" "$release"
staging=""
trap - EXIT

printf 'Installed Keeplore staging Release %s.\n' "$commit"
