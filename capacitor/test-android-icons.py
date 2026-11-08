"""Regression: matching source artwork must not approve the published A icon."""

import argparse
import hashlib
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("a_icon_apk", type=Path, help="Keeplore's published 2.1.0 APK")
args = parser.parse_args()
apk = args.a_icon_apk.resolve()
assert hashlib.sha256(apk.read_bytes()).hexdigest() == "323040b0b1b5eac0dad513a716146ee1023e74e7f41f17fccfb0dfc86f2f7cc4", "expected the published 2.1.0 A-icon APK"
sdk = os.environ.get("ANDROID_HOME") or os.environ["ANDROID_SDK_ROOT"]
aapt = Path(sdk) / "build-tools/36.0.0/aapt"
dump = subprocess.check_output([str(aapt), "dump", "--values", "resources", str(apk)], text=True)

with tempfile.TemporaryDirectory(prefix="keeplore-a-icon-") as directory:
    root = Path(directory)
    verifier = root / "verify-android-icons.py"
    shutil.copyfile(Path(__file__).with_name(verifier.name), verifier)
    config = name = None
    replaced = 0
    with zipfile.ZipFile(apk) as archive:
        for line in map(str.strip, dump.splitlines()):
            if line.startswith("config "):
                config = line.removeprefix("config ").removesuffix(":")
            if line.startswith("resource "):
                match = re.match(r"resource 0x[0-9a-f]+ app\.keeplore:mipmap/(ic_launcher\w*):", line)
                name = match.group(1) if match else None
            if name and config in ["ldpi", "mdpi", "hdpi", "xhdpi", "xxhdpi", "xxxhdpi"] and line.startswith('(string8) "'):
                target = root / "android/app/src/main/res" / f"mipmap-{config}" / f"{name}.png"
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(archive.read(line.split('"')[1]))
                replaced += 1
    assert replaced == 24, f"expected 24 A-artwork source replacements, got {replaced}"
    result = subprocess.run([sys.executable, str(verifier), str(apk)], text=True, capture_output=True)
    print(result.stdout, end="")
    print(result.stderr, end="")
    assert result.returncode == 1 and "does not match Keeplore K artwork" in result.stderr, (
        "The verifier must reject the published A-icon APK even when source PNGs also contain A artwork"
    )
    print("PASS: A artwork is rejected even when the source PNGs also contain A artwork")
