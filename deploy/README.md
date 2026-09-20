# Staging

https://staging.keeplore.app/ serves a branch before it merges to `main`.

Same database as production. Separate session cookie (`domain=staging.keeplore.app`). A brown **Staging** bar appears once a commit that includes the banner is promoted.

## How a PR gets there

A same-repo PR against `main` runs `.github/workflows/deploy-keeplore-staging.yml`. GitHub Actions SSHes as `keeplore-deploy` and runs:

```
sudo -n /usr/local/libexec/keeplore/promote-keeplore-staging.sh <head-sha>
```

That fetches the SHA into the root-owned source mirror, lints PHP, and points `/opt/keeplore/staging/current` at it. Fork PRs are ignored.

To promote a SHA by hand:

```
sudo /usr/local/libexec/keeplore/promote-keeplore-staging.sh <40-char-sha>
```

Host copies of the scripts live in `/usr/local/libexec/keeplore/`. This directory is the versioned source; install with `sudo install -m 0755 deploy/*.sh /usr/local/libexec/keeplore/`.
