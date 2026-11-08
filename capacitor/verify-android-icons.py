"""Reject an APK whose launcher resources differ from the approved Keeplore K."""

import argparse
import io
import os
from pathlib import Path
import re
import subprocess
import sys
import zipfile

from PIL import Image


def verify(apk):
    sdk = os.environ.get("ANDROID_HOME") or os.environ["ANDROID_SDK_ROOT"]
    aapt = str(Path(sdk) / "build-tools/36.0.0/aapt")

    def dump(*args):
        return subprocess.check_output([aapt, "dump", *args], text=True)

    resources = {}
    names = {}
    config = None
    resource = None
    for line in dump("--values", "resources", str(apk)).splitlines():
        line = line.strip()
        if line.startswith("config "):
            config = line.removeprefix("config ").removesuffix(":")
        match = re.match(r"resource (0x[0-9a-f]+) app\.keeplore:mipmap/(ic_launcher\w*):", line)
        if line.startswith("resource "):
            resource = match.group(2) if match else None
        if match:
            names[resource] = match.group(1)
        if resource and line.startswith('(string8) "'):
            resources[config, resource] = line.split('"')[1]

    manifest = dump("xmltree", str(apk), "AndroidManifest.xml")
    for attribute, name in [("icon", "ic_launcher"), ("roundIcon", "ic_launcher_round")]:
        expected = names.get(name)
        if expected is None or not re.search(rf"A: android:{attribute}\([^)]*\)=@{expected}\b", manifest):
            raise ValueError(f"Android manifest must use @mipmap/{name}")

    source = Path(__file__).resolve().parent / "android/app/src/main/res"
    count = 0
    with zipfile.ZipFile(apk) as archive:
        for density in ["ldpi", "mdpi", "hdpi", "xhdpi", "xxhdpi", "xxxhdpi"]:
            for name in ["ic_launcher", "ic_launcher_round", "ic_launcher_foreground", "ic_launcher_background"]:
                label = f"mipmap-{density}/{name}.png"
                packed = resources.get((density, name))
                if packed is None:
                    raise ValueError(f"missing {label}")
                with Image.open(io.BytesIO(archive.read(packed))) as image, Image.open(source / label) as expected:
                    if image.size != expected.size or image.convert("RGBA").tobytes() != expected.convert("RGBA").tobytes():
                        raise ValueError(f"{label} does not match Keeplore K artwork")
                count += 1

        for name in ["ic_launcher", "ic_launcher_round"]:
            packed = resources.get(("anydpi-v26", name))
            if packed is None:
                raise ValueError(f"missing adaptive {name}")
            adaptive = dump("xmltree", str(apk), packed)
            layers = re.findall(r"A: android:drawable\([^)]*\)=@(0x[0-9a-f]+)", adaptive)
            if "E: adaptive-icon " not in adaptive or layers != [names["ic_launcher_background"], names["ic_launcher_foreground"]]:
                raise ValueError(f"adaptive {name} must use the Keeplore K layers")
            count += 1

    print(f"PASS: {count} packaged launcher resources match Keeplore K artwork")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("apk", type=Path)
    args = parser.parse_args()
    try:
        verify(args.apk)
    except (ValueError, KeyError, OSError, zipfile.BadZipFile, subprocess.CalledProcessError) as error:
        print(f"FAIL: {error}", file=sys.stderr)
        sys.exit(1)
