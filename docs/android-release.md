# Android releases

The Android app's Keeplore K launcher icons and splash screens are already in
`capacitor/assets/` and `capacitor/android/app/src/main/res/`. Version 2.1.1
(`versionCode` 10) is prepared in `main`; GitHub's last published Android version
is 2.1.0 (`versionCode` 9).

Issue #291's A icon is still present in the published 2.1.0 APK, including its
adaptive foreground. Updating the website does not replace an installed native
app's icon. Install the signed 2.1.1 APK over 2.1.0 once it is published; do not
uninstall the app or use a debug build to replace a release installation.

As of 2026-11-08, the release workflow has no runs and the repository is missing
`KEEPLORE_ANDROID_KEYSTORE_B64` and `KEEPLORE_ANDROID_KEYSTORE_PASSWORD`. The
original keystore is required to publish an update; a newly generated key cannot
upgrade the existing installation. The K artwork is ready, but the release is
still pending the signing setup below.

The publication requested in [#296](https://github.com/JacobStephens2/keeplore/issues/296)
therefore remains unfinished. The existing
[`needs-triage` issue #293](https://github.com/JacobStephens2/keeplore/issues/293)
tracks recovering the original signing key and configuring those secrets. The
key is also absent from the `capacitor/keystore.jks` and `twa/keystore.jks` paths
in both local checkouts checked for #296. Someone with custody of the original
key must complete that handoff; a passing debug build is not a published update.

## Verify the packaged icon

The **Verify Keeplore Android icons** workflow builds and checks a debug APK on
Android changes without release credentials. Both it and the release workflow
run `capacitor/verify-android-icons.py` on the built APK. The check compares the
decoded pixels and dimensions of all six densities of regular, round, foreground
and background launcher resources with independent approved K fingerprints in
the verifier. It rejects any other configuration of those resources, so a
closer qualifier cannot replace the K at install time. The application icon and round icon must reference those approved resources.
A launcher activity or activity-alias must resolve to those same resources,
including an alias that inherits its target activity's icon. The adaptive-icon
XML must stay the approved background and foreground insets of 16.7%.
Mentioning the approved ids on some other component is not enough. It rejects the published A-icon APK even if the source PNGs have
also regressed to A.
PNG compression differences do not affect the check. Update the fingerprints
only after visually reviewing an intentional artwork change.

After building locally, install Pillow (for example, `sudo apt-get install
python3-pil` on Debian/Ubuntu) and run from the repository root, with
`ANDROID_HOME` pointing to the SDK:

```bash
python3 capacitor/verify-android-icons.py capacitor/android/app/build/outputs/apk/debug/app-debug.apk
```

Use the release APK path instead to verify the signed download before publishing.

The CI regression test uses the published 2.1.0 APK as a known A-artwork fixture,
checks its SHA-256, and verifies that matching source PNGs cannot approve it:

```bash
gh release download 2.1.0 --pattern keeplore-v2.1.0.apk --dir /tmp/keeplore-icon-test
python3 capacitor/test-android-icons.py /tmp/keeplore-icon-test/keeplore-v2.1.0.apk
```

## Publish 2.1.1

The **Release Keeplore Android** workflow builds and checks the APK and AAB, then
publishes them with `SHA256SUMS` under a version tag at the selected commit.

Before running it, configure these repository Actions secrets:

- `KEEPLORE_ANDROID_KEYSTORE_B64`: base64 of the existing `keystore.jks`, originally
  `twa/keystore.jks`, with the `artifact-manager` alias.
- `KEEPLORE_ANDROID_KEYSTORE_PASSWORD`: the keystore password.
- `KEEPLORE_ANDROID_KEY_PASSWORD`: only needed if the key password differs from
  the keystore password.

Use the original signing key: Android can only upgrade an installed 2.1.0 app
with the same release identity. The workflow checks the certificate against
`ui/.well-known/assetlinks.json` before building.

Once this workflow is on `main`, publish the prepared version with:

```bash
gh workflow run android-release.yml --ref main
gh run list --workflow android-release.yml --limit 1
gh run watch <run-id> --exit-status
gh release view 2.1.1
```

Then verify the files GitHub actually serves, using a fresh download directory
and the Android SDK and Pillow setup described below and above:

```bash
release_dir=$(mktemp -d /tmp/keeplore-2.1.1.XXXXXX)
gh release download 2.1.1 --repo JacobStephens2/keeplore \
  --pattern keeplore-v2.1.1.apk --pattern keeplore-v2.1.1.aab \
  --pattern SHA256SUMS --dir "$release_dir"
(cd "$release_dir" && sha256sum --check SHA256SUMS)
"$ANDROID_HOME/build-tools/36.0.0/apksigner" verify --verbose --print-certs \
  "$release_dir/keeplore-v2.1.1.apk"
python3 capacitor/verify-android-icons.py "$release_dir/keeplore-v2.1.1.apk"
```

Compare the printed signer certificate SHA-256 digest with the `app.keeplore`
fingerprint in `ui/.well-known/assetlinks.json` (ignoring colons and letter case).
Finally, install that APK over an existing 2.1.0 installation and check that the
app retains its data and shows the K launcher icon. Leave #293 open until this
signed update is published and the upgrade is verified. As of the check above,
`gh release view 2.1.1 --repo JacobStephens2/keeplore` returns `release not found`;
none of these publication or device checks has passed yet.

For later releases, increase `versionCode` in `capacitor/android/app/build.gradle`
and update `versionName`, `capacitor/package.json` and `capacitor/package-lock.json`
together. The workflow refuses mismatched version names and existing version
tags rather than replacing downloads.

## Build locally

Install JDK 21, Node 24 and the Android SDK (platform 36 and build tools 36.0.0).
Set `JAVA_HOME` and `ANDROID_HOME` for those installations. The release build
accepts `KEEPLORE_ANDROID_KEYSTORE` as the path to the original keystore and the
same password environment variables listed above. Without those overrides, it
uses `capacitor/keystore.jks` and the existing local signing configuration.

```bash
cd capacitor
npm ci
npx cap sync android
cd android
./gradlew --no-daemon test lint assembleRelease bundleRelease
```

The signed files are `app/build/outputs/apk/release/app-release.apk` and
`app/build/outputs/bundle/release/app-release.aab`. Without the release key,
`./gradlew --no-daemon assembleDebug` can still verify the K icon in a debug APK.
Debug builds cannot upgrade a release installation.
