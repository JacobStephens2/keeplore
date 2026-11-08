"""Reject an APK whose launcher resources differ from the approved Keeplore K."""

import argparse
import hashlib
import io
import os
from pathlib import Path
import re
import subprocess
import sys
import zipfile

from PIL import Image


# Reviewed K artwork: SHA-256 of "WIDTHxHEIGHT:" followed by decoded RGBA pixels.
# Keep this approval independent of the source PNGs used to build the APK.
# Change fingerprints only after visually reviewing an intentional redesign.
APPROVED_PIXELS = {
    "mipmap-hdpi/ic_launcher.png": "86f18c6d37652b3d8a1bb845b1bae4c41f7d400aa3371fc9ff821f84918db7a0",
    "mipmap-hdpi/ic_launcher_background.png": "9d97fa8ea9fa4340063d414bd831e27fc970776e60617e89b53d8fd9927cbb29",
    "mipmap-hdpi/ic_launcher_foreground.png": "0aeeea1aec8b531dea37ad8967356457b7dc11b6564e55f63bfb2194045bf93c",
    "mipmap-hdpi/ic_launcher_round.png": "5a48d093ddd4bb1829b293784175471ad70cafd1df8a1ac5084fecf8ab039547",
    "mipmap-ldpi/ic_launcher.png": "14ee923308c90ec3b0f8f06d5c9a47ee50f620204d069293341c3b244bac15da",
    "mipmap-ldpi/ic_launcher_background.png": "6b21a426354c73e70d67462fea97fbc492abdc61052602cf19c84e6496cfb1fc",
    "mipmap-ldpi/ic_launcher_foreground.png": "ecc62d6b64db9ac255d699eed2ed6696078dd58df4972d30f88b859cd0ed0549",
    "mipmap-ldpi/ic_launcher_round.png": "c47207bfb1af1a48e08939535196073db1f0249f7936c96ec0e61fbe5ed47626",
    "mipmap-mdpi/ic_launcher.png": "87a2f675b567a0547204f1681ee7b211de61f780ed4ac0ce5a5a388235899aa5",
    "mipmap-mdpi/ic_launcher_background.png": "0fd0033b1e34fa73a16d113718587cb0c1cf8b04b1af059466b95fabcd348350",
    "mipmap-mdpi/ic_launcher_foreground.png": "20ecd6ec6d923f0f8aacb85229fc35ee0868fa87e9d49da341d24d598fd638e9",
    "mipmap-mdpi/ic_launcher_round.png": "51d7a52b77032369911be88b9ff3d46a941a605d9a110e0fecb11f45beaa7425",
    "mipmap-xhdpi/ic_launcher.png": "f3719403ef6a243b22e1f3f467d502809710be786c218e32311df2b1e0edc924",
    "mipmap-xhdpi/ic_launcher_background.png": "1477afb7496aabbc40cf1c0d7050b5a42ae4bedfdb328f8aa42831bc9097224d",
    "mipmap-xhdpi/ic_launcher_foreground.png": "f225ce329c7a7b36cca4fc3df2422a751bf802b52e081a9d01692b3ef1e39513",
    "mipmap-xhdpi/ic_launcher_round.png": "f69f0288820429a8f9353dea5fb9e585777024e7617264512f90071fabc3415e",
    "mipmap-xxhdpi/ic_launcher.png": "3ad446628f3ad06022e107441b3fd38705534e120142fb77b6f75493045e6a8a",
    "mipmap-xxhdpi/ic_launcher_background.png": "0620944eb8c703771dcd27bfd75dcfe88f2a912f44aac31280c1edd73ca8a250",
    "mipmap-xxhdpi/ic_launcher_foreground.png": "14e72034b24649b769e05f58b4cf6db89b42ba84786d179bf7627d642f698574",
    "mipmap-xxhdpi/ic_launcher_round.png": "bb02194911f68c058bba666554a82fe225103c001798685c1876f62a33a7dae6",
    "mipmap-xxxhdpi/ic_launcher.png": "5a81c591e95065a571abc2b10657501b93df679cf7578294799ed0b644f75233",
    "mipmap-xxxhdpi/ic_launcher_background.png": "912b550fb360802a9b1ed01a9103154d04274ccc0e45a5dad17359311a7405cb",
    "mipmap-xxxhdpi/ic_launcher_foreground.png": "7affae91e644e4aca0e4c2e9ce24ae7a046db2f01932b2b0d45a91fc825bacae",
    "mipmap-xxxhdpi/ic_launcher_round.png": "df8e7bdeec20d62aedd3050f3c6ab9c149829bcca86dec8a375bf1bbbddaf2f9",
}


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

    count = 0
    with zipfile.ZipFile(apk) as archive:
        for density in ["ldpi", "mdpi", "hdpi", "xhdpi", "xxhdpi", "xxxhdpi"]:
            for name in ["ic_launcher", "ic_launcher_round", "ic_launcher_foreground", "ic_launcher_background"]:
                label = f"mipmap-{density}/{name}.png"
                packed = resources.get((density, name))
                if packed is None:
                    raise ValueError(f"missing {label}")
                with Image.open(io.BytesIO(archive.read(packed))) as image:
                    pixels = f"{image.width}x{image.height}:".encode() + image.convert("RGBA").tobytes()
                    if hashlib.sha256(pixels).hexdigest() != APPROVED_PIXELS[label]:
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
