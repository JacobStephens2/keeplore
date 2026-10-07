# Android releases

The Android app's Keeplore K launcher icons and splash screens are already in
`capacitor/assets/` and `capacitor/android/app/src/main/res/`. Version 2.1.1
(`versionCode` 10) is prepared in `main`; GitHub's last published Android version
is 2.1.0 (`versionCode` 9).

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
