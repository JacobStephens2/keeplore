"""The GitHub download check must reject an AAB signed by some other certificate.

SHA256SUMS is published in the same release as the bundle, so a checksum match
does not say which key signed it. The runbook has to compare the bundle signer
with the app.keeplore fingerprint in ui/.well-known/assetlinks.json and stop.
"""

import argparse
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile


def sdk_root():
    candidates = []
    for name in ("ANDROID_HOME", "ANDROID_SDK_ROOT"):
        if os.environ.get(name):
            candidates.append(Path(os.environ[name]))
    candidates.append(Path.home() / "Android/Sdk")
    for candidate in candidates:
        apksigner = candidate / "build-tools/36.0.0/apksigner"
        platform = candidate / "platforms/android-36/android.jar"
        if apksigner.is_file() and platform.is_file():
            return candidate
    raise SystemExit("Android SDK build-tools 36.0.0 and platform 36 are required")


def download_block(repo):
    text = (repo / "docs/android-release.md").read_text()
    blocks = re.findall(r"```bash\n(.*?)```", text, re.S)
    for block in blocks:
        if "sha256sum --check" in block and "keeplore-v2.1.1.aab" in block:
            return block
    return None


def write_executable(path, text):
    path.write_text(text)
    path.chmod(path.stat().st_mode | stat.S_IEXEC)


def signed_release(directory, sdk):
    """Harmless APK and AAB, each signed by a throwaway key, plus matching sums."""
    keytool = ["keytool", "-genkeypair", "-keyalg", "RSA", "-keysize", "2048", "-validity", "1"]
    distinguished_name = "CN=Local Test, O=Keeplore Release Check, C=US"
    subprocess.run(
        [
            *keytool,
            "-keystore",
            str(directory / "apk.jks"),
            "-storepass",
            "local-apk",
            "-keypass",
            "local-apk",
            "-alias",
            "apk",
            "-dname",
            distinguished_name,
        ],
        check=True,
        capture_output=True,
        text=True,
    )
    subprocess.run(
        [
            *keytool,
            "-keystore",
            str(directory / "aab.jks"),
            "-storepass",
            "local-aab",
            "-keypass",
            "local-aab",
            "-alias",
            "aab",
            "-dname",
            distinguished_name,
        ],
        check=True,
        capture_output=True,
        text=True,
    )
    manifest = directory / "AndroidManifest.xml"
    manifest.write_text(
        '<manifest xmlns:android="http://schemas.android.com/apk/res/android" '
        'package="app.localtest" android:versionCode="1" android:versionName="0">'
        "<application/></manifest>\n"
    )
    unsigned_apk = directory / "unsigned.apk"
    subprocess.run(
        [
            str(sdk / "build-tools/36.0.0/aapt"),
            "package",
            "-f",
            "-M",
            str(manifest),
            "-I",
            str(sdk / "platforms/android-36/android.jar"),
            "-F",
            str(unsigned_apk),
        ],
        check=True,
        capture_output=True,
        text=True,
    )
    apk = directory / "keeplore-v2.1.1.apk"
    subprocess.run(
        [
            str(sdk / "build-tools/36.0.0/apksigner"),
            "sign",
            "--ks",
            str(directory / "apk.jks"),
            "--ks-pass",
            "pass:local-apk",
            "--key-pass",
            "pass:local-apk",
            "--ks-key-alias",
            "apk",
            "--out",
            str(apk),
            str(unsigned_apk),
        ],
        check=True,
        capture_output=True,
        text=True,
    )
    (directory / "payload.txt").write_text("harmless local payload\n")
    aab = directory / "keeplore-v2.1.1.aab"
    subprocess.run(
        ["jar", "cf", str(aab), "-C", str(directory), "payload.txt"],
        check=True,
        capture_output=True,
        text=True,
    )
    subprocess.run(
        [
            "jarsigner",
            "-keystore",
            str(directory / "aab.jks"),
            "-storepass",
            "local-aab",
            str(aab),
            "aab",
        ],
        check=True,
        capture_output=True,
        text=True,
    )
    sums = subprocess.run(
        ["sha256sum", apk.name, aab.name],
        cwd=directory,
        check=True,
        capture_output=True,
        text=True,
    )
    (directory / "SHA256SUMS").write_text(sums.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", type=Path, default=Path(__file__).resolve().parents[1])
    args = parser.parse_args()
    repo = args.repo.resolve()
    block = download_block(repo)
    if block is None:
        print("FAIL: docs/android-release.md is missing the 2.1.1 download check", file=sys.stderr)
        return 1

    sdk = sdk_root()
    with tempfile.TemporaryDirectory(prefix="keeplore-release-check-") as temporary:
        root = Path(temporary)
        fixture = root / "fixture"
        fixture.mkdir()
        signed_release(fixture, sdk)
        bin_dir = root / "bin"
        bin_dir.mkdir()
        marker = root / "gh-invoked"
        write_executable(
            bin_dir / "gh",
            f"""#!/bin/sh
set -eu
dest=""
while [ "$#" -gt 0 ]; do
  if [ "$1" = "--dir" ]; then
    dest="$2"
    shift 2
  else
    shift
  fi
done
test -n "$dest"
mkdir -p "$dest"
cp {fixture}/keeplore-v2.1.1.apk {fixture}/keeplore-v2.1.1.aab {fixture}/SHA256SUMS "$dest/"
touch {marker}
""",
        )
        write_executable(
            bin_dir / "python3",
            """#!/bin/sh
if [ "${1:-}" = "capacitor/verify-android-icons.py" ]; then
  exit 0
fi
exec /usr/bin/python3 "$@"
""",
        )
        env = os.environ.copy()
        env["PATH"] = str(bin_dir) + os.pathsep + env.get("PATH", "")
        env["ANDROID_HOME"] = str(sdk)
        env.pop("GH_TOKEN", None)
        env.pop("GITHUB_TOKEN", None)
        result = subprocess.run(
            ["bash", "-c", block],
            cwd=repo,
            env=env,
            text=True,
            capture_output=True,
        )
        if not marker.exists():
            print(result.stdout, end="")
            print(result.stderr, end="", file=sys.stderr)
            print("FAIL: the download check never invoked gh", file=sys.stderr)
            return 1
        rejected = result.returncode != 0 and (
            "does not match the app.keeplore certificate" in result.stderr
        )
        if not rejected:
            print(result.stdout, end="")
            print(result.stderr, end="", file=sys.stderr)
            print(
                "FAIL: the download check accepted an AAB signed by a different certificate "
                f"(exit {result.returncode})",
                file=sys.stderr,
            )
            return 1
    print("PASS: download check rejected an AAB signed by a different certificate")
    return 0


if __name__ == "__main__":
    sys.exit(main())
