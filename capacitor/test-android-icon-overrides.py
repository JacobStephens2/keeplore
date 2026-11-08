"""The packaged-icon gate must reject launcher artwork Android can resolve ahead of the approved K."""

import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
VERIFIER = ROOT / "capacitor/verify-android-icons.py"
SOURCE_RES = ROOT / "capacitor/android/app/src/main/res"
DENSITIES = ["ldpi", "mdpi", "hdpi", "xhdpi", "xxhdpi", "xxxhdpi"]
PIXEL_NAMES = [
    "ic_launcher",
    "ic_launcher_round",
    "ic_launcher_foreground",
    "ic_launcher_background",
]
HONEST_MANIFEST = """<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android" package="app.keeplore">
    <uses-sdk android:minSdkVersion="24" android:targetSdkVersion="36"/>
    <application android:icon="@mipmap/ic_launcher" android:roundIcon="@mipmap/ic_launcher_round" android:label="Keeplore">
        <activity android:name=".MainActivity" android:exported="true">
            <intent-filter>
                <action android:name="android.intent.action.MAIN"/>
                <category android:name="android.intent.category.LAUNCHER"/>
            </intent-filter>
        </activity>
    </application>
</manifest>
"""


def sdk_tool(name):
    sdk = os.environ.get("ANDROID_HOME") or os.environ.get("ANDROID_SDK_ROOT")
    if not sdk:
        sys.exit("ANDROID_HOME or ANDROID_SDK_ROOT is required")
    return Path(sdk) / name


def copy_approved_resources(res):
    for density in DENSITIES:
        bucket = res / f"mipmap-{density}"
        bucket.mkdir(parents=True)
        for name in PIXEL_NAMES:
            shutil.copy(SOURCE_RES / bucket.name / f"{name}.png", bucket / f"{name}.png")
    anydpi = res / "mipmap-anydpi-v26"
    anydpi.mkdir()
    for name in ["ic_launcher", "ic_launcher_round"]:
        shutil.copy(SOURCE_RES / anydpi.name / f"{name}.xml", anydpi / f"{name}.xml")


def link_apk(res, manifest_text, destination):
    aapt2 = sdk_tool("build-tools/36.0.0/aapt2")
    android_jar = sdk_tool("platforms/android-36/android.jar")
    with tempfile.TemporaryDirectory(prefix="keeplore-icon-link-") as directory:
        root = Path(directory)
        (root / "AndroidManifest.xml").write_text(manifest_text)
        compiled = root / "compiled"
        compiled.mkdir()
        subprocess.check_call(
            [str(aapt2), "compile", "--dir", str(res), "-o", str(compiled)],
            stdout=subprocess.DEVNULL,
        )
        subprocess.check_call(
            [
                str(aapt2),
                "link",
                "-o",
                str(destination),
                "--manifest",
                str(root / "AndroidManifest.xml"),
                "-I",
                str(android_jar),
                *[str(item) for item in sorted(compiled.glob("*.flat"))],
            ],
            stdout=subprocess.DEVNULL,
        )


def verify(apk):
    return subprocess.run(
        [sys.executable, str(VERIFIER), str(apk)],
        text=True,
        capture_output=True,
    )


def require_accept(apk, label):
    result = verify(apk)
    if result.returncode != 0:
        sys.exit(f"FAIL: verifier rejected the approved K fixture ({label})\n{result.stderr}")
    print(f"PASS: approved fixture accepted ({label})")


def require_reject(apk, label):
    result = verify(apk)
    if result.returncode == 0:
        sys.exit(
            "FAIL: verifier accepted launcher artwork Android resolves instead of the approved K "
            f"({label})\n{result.stdout}"
        )
    print(f"PASS: rejected {label}")
    print(result.stderr, end="")


def main():
    with tempfile.TemporaryDirectory(prefix="keeplore-icon-override-") as directory:
        root = Path(directory)

        honest = root / "honest"
        copy_approved_resources(honest)
        honest_apk = root / "honest.apk"
        link_apk(honest, HONEST_MANIFEST, honest_apk)
        require_accept(honest_apk, "six densities and the 16.7% adaptive icon")

        overridden = root / "overridden"
        copy_approved_resources(overridden)
        # anydpi beats every density-specific foreground on API 26+ (minSdk is 24; adaptive icons start at 26).
        Image.new("RGBA", (108, 108), (255, 0, 255, 255)).save(
            overridden / "mipmap-anydpi-v26/ic_launcher_foreground.png"
        )
        overridden_apk = root / "overridden.apk"
        link_apk(overridden, HONEST_MANIFEST, overridden_apk)
        require_reject(overridden_apk, "anydpi-v26 foreground ahead of the hashed density PNGs")

        decoy_res = root / "decoy"
        copy_approved_resources(decoy_res)
        Image.new("RGBA", (192, 192), (0, 255, 0, 255)).save(decoy_res / "mipmap-xxxhdpi/ic_not_approved.png")
        Image.new("RGBA", (192, 192), (0, 0, 255, 255)).save(
            decoy_res / "mipmap-xxxhdpi/ic_not_approved_round.png"
        )
        decoy_manifest = """<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android" package="app.keeplore">
    <uses-sdk android:minSdkVersion="24" android:targetSdkVersion="36"/>
    <application android:icon="@mipmap/ic_not_approved" android:roundIcon="@mipmap/ic_not_approved_round" android:label="Keeplore">
        <activity android:name=".MainActivity" android:exported="true">
            <intent-filter>
                <action android:name="android.intent.action.MAIN"/>
                <category android:name="android.intent.category.LAUNCHER"/>
            </intent-filter>
        </activity>
        <service android:name=".Decoy" android:exported="false" android:icon="@mipmap/ic_launcher" android:roundIcon="@mipmap/ic_launcher_round"/>
    </application>
</manifest>
"""
        decoy_apk = root / "decoy.apk"
        link_apk(decoy_res, decoy_manifest, decoy_apk)
        require_reject(decoy_apk, "application icon points elsewhere while a service holds the approved ids")

        cropped = root / "cropped"
        copy_approved_resources(cropped)
        for name in ["ic_launcher.xml", "ic_launcher_round.xml"]:
            path = cropped / "mipmap-anydpi-v26" / name
            path.write_text(path.read_text().replace("16.7%", "49%"))
        cropped_apk = root / "cropped.apk"
        link_apk(cropped, HONEST_MANIFEST, cropped_apk)
        require_reject(cropped_apk, "adaptive inset changed from the approved 16.7%")

        recolored = root / "recolored"
        copy_approved_resources(recolored)
        Image.new("RGBA", (72, 72), (255, 0, 0, 255)).save(recolored / "mipmap-hdpi/ic_launcher.png")
        recolored_apk = root / "recolored.apk"
        link_apk(recolored, HONEST_MANIFEST, recolored_apk)
        require_reject(recolored_apk, "hashed hdpi launcher pixels replaced")

        alias_res = root / "alias"
        copy_approved_resources(alias_res)
        Image.new("RGBA", (192, 192), (0, 255, 0, 255)).save(alias_res / "mipmap-xxxhdpi/ic_not_approved.png")
        Image.new("RGBA", (192, 192), (0, 0, 255, 255)).save(alias_res / "mipmap-xxxhdpi/ic_not_approved_round.png")
        alias_manifest = """<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android" package="app.keeplore">
    <uses-sdk android:minSdkVersion="24" android:targetSdkVersion="36"/>
    <application android:icon="@mipmap/ic_launcher" android:roundIcon="@mipmap/ic_launcher_round" android:label="Keeplore">
        <activity android:name=".MainActivity" android:exported="true" android:icon="@mipmap/ic_not_approved" android:roundIcon="@mipmap/ic_not_approved_round"/>
        <activity-alias android:name=".Launcher" android:targetActivity=".MainActivity" android:exported="true">
            <intent-filter>
                <action android:name="android.intent.action.MAIN"/>
                <category android:name="android.intent.category.LAUNCHER"/>
            </intent-filter>
        </activity-alias>
    </application>
</manifest>
"""
        alias_apk = root / "alias.apk"
        link_apk(alias_res, alias_manifest, alias_apk)
        require_reject(alias_apk, "launcher activity-alias inherits a different target activity icon")

    print("PASS: launcher icon overrides are rejected and the approved K still verifies")


if __name__ == "__main__":
    main()
