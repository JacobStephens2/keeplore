"""Reject an APK whose resolved launcher icon differs from the approved Keeplore K."""

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

DENSITIES = ["ldpi", "mdpi", "hdpi", "xhdpi", "xxhdpi", "xxxhdpi"]
PIXEL_NAMES = ["ic_launcher", "ic_launcher_round", "ic_launcher_foreground", "ic_launcher_background"]
ADAPTIVE_NAMES = ["ic_launcher", "ic_launcher_round"]
# aapt 36.0.0 encoding of the approved 16.7% inset. A different value crops the K.
APPROVED_INSET = "(type 0x6)0x15604230"


def parse_xmltree(text):
    roots = []
    stack = []
    for raw in text.splitlines():
        if not raw.strip():
            continue
        depth = (len(raw) - len(raw.lstrip(" "))) // 2
        line = raw.strip()
        if line.startswith("N:"):
            continue
        if line.startswith("E:"):
            node = {"kind": "element", "name": line.split()[1], "children": [], "depth": depth}
        elif line.startswith("A:"):
            name_match = re.match(r"A: (?:android:)?([A-Za-z0-9_]+)\(", line)
            plain_match = re.match(r"A: ([A-Za-z0-9_:]+)=", line)
            ref_match = re.search(r"=@(0x[0-9a-f]+)\b", line)
            raw_string = re.search(r'\(Raw: "(.*)"\)$', line)
            quoted = re.search(r'="([^"]*)"', line)
            value = line.split("=", 1)[1].split(" (Raw:")[0].strip() if "=" in line else ""
            if name_match:
                attribute_name = name_match.group(1)
            elif plain_match:
                attribute_name = plain_match.group(1).split(":")[-1]
            else:
                attribute_name = None
            node = {
                "kind": "attr",
                "name": attribute_name,
                "ref": ref_match.group(1) if ref_match else None,
                "string": raw_string.group(1) if raw_string else (quoted.group(1) if quoted else None),
                "value": value,
                "children": [],
                "depth": depth,
            }
        else:
            continue
        while stack and stack[-1]["depth"] >= depth:
            stack.pop()
        if stack:
            stack[-1]["children"].append(node)
        else:
            roots.append(node)
        if node["kind"] == "element":
            stack.append(node)
    return roots


def elements_named(node, name):
    found = []
    if node.get("kind") == "element" and node.get("name") == name:
        found.append(node)
    for child in node.get("children", []):
        found.extend(elements_named(child, name))
    return found


def attr_map(node):
    found = {}
    for child in node["children"]:
        if child["kind"] == "attr" and child["name"]:
            found.setdefault(child["name"], []).append(child)
    return found


def require_icon(element, label, icon_id, round_id, required):
    for attribute, expected in (("icon", icon_id), ("roundIcon", round_id)):
        values = attr_map(element).get(attribute, [])
        if not values:
            if required:
                raise ValueError(f"{label} must set android:{attribute} to the approved launcher resource")
            continue
        if len(values) != 1 or values[0]["ref"] != expected:
            raise ValueError(f"{label} android:{attribute} must be the approved launcher resource")


def explicit_ref(element, attribute):
    values = attr_map(element).get(attribute, [])
    if not values:
        return None
    if len(values) != 1 or not values[0]["ref"]:
        raise ValueError(f"android:{attribute} must reference one drawable resource")
    return values[0]["ref"]


def qualify(class_name, package):
    if class_name.startswith(".") and package:
        return package + class_name
    if "." not in class_name and package:
        return f"{package}.{class_name}"
    return class_name


def component_name(element, kind):
    names = [attr["string"] for attr in attr_map(element).get("name", []) if attr["string"]]
    if len(names) != 1:
        raise ValueError(f"{kind} must have one android:name")
    return names[0]


def resolved_launcher_ref(component, application, activities, package, attribute):
    own = explicit_ref(component, attribute)
    if own is not None:
        return own
    if component["name"] == "activity-alias":
        targets = [attr["string"] for attr in attr_map(component).get("targetActivity", []) if attr["string"]]
        if len(targets) != 1:
            raise ValueError("launcher activity-alias must name one target activity")
        target_name = qualify(targets[0], package)
        matches = [
            activity
            for name, activity in activities.items()
            if qualify(name, package) == target_name
        ]
        if len(matches) != 1:
            raise ValueError("launcher activity-alias target activity was not found")
        inherited = explicit_ref(matches[0], attribute)
        if inherited is not None:
            return inherited
    inherited = explicit_ref(application, attribute)
    if inherited is None:
        raise ValueError(f"launcher {component['name']} has no android:{attribute}")
    return inherited


def is_launcher(component):
    for intent in [child for child in component["children"] if child["kind"] == "element" and child["name"] == "intent-filter"]:
        actions = []
        categories = []
        for child in intent["children"]:
            if child["kind"] != "element":
                continue
            strings = [attr["string"] for attr in child["children"] if attr["kind"] == "attr" and attr["name"] == "name"]
            if child["name"] == "action":
                actions.extend(strings)
            elif child["name"] == "category":
                categories.extend(strings)
        if "android.intent.action.MAIN" in actions and "android.intent.category.LAUNCHER" in categories:
            return True
    return False


def require_manifest_icons(manifest, icon_id, round_id):
    roots = [node for node in parse_xmltree(manifest) if node["kind"] == "element" and node["name"] == "manifest"]
    if len(roots) != 1:
        raise ValueError("Android manifest must contain one manifest element")
    applications = elements_named(roots[0], "application")
    if len(applications) != 1:
        raise ValueError("Android manifest must contain one application element")
    require_icon(applications[0], "application", icon_id, round_id, required=True)
    package_values = [attr["string"] for attr in attr_map(roots[0]).get("package", []) if attr["string"]]
    package = package_values[0] if len(package_values) == 1 else ""
    activities = {}
    for activity in elements_named(roots[0], "activity"):
        name = component_name(activity, "activity")
        if name in activities:
            raise ValueError(f"duplicate activity name {name}")
        activities[name] = activity
    launchers = [
        component
        for name in ("activity", "activity-alias")
        for component in elements_named(roots[0], name)
        if is_launcher(component)
    ]
    if not launchers:
        raise ValueError("Android manifest must contain a launcher activity")
    for component in launchers:
        for attribute, expected in (("icon", icon_id), ("roundIcon", round_id)):
            resolved = resolved_launcher_ref(component, applications[0], activities, package, attribute)
            if resolved != expected:
                raise ValueError(
                    f"launcher {component['name']} android:{attribute} must be the approved launcher resource"
                )


def require_adaptive(xml, background_id, foreground_id, label):
    roots = [node for node in parse_xmltree(xml) if node["kind"] == "element"]
    if len(roots) != 1 or roots[0]["name"] != "adaptive-icon":
        raise ValueError(f"{label} must be a single adaptive-icon")
    root = roots[0]
    if any(child["kind"] == "attr" for child in root["children"]):
        raise ValueError(f"{label} must not add adaptive-icon attributes")
    layers = [child for child in root["children"] if child["kind"] == "element"]
    if [layer["name"] for layer in layers] != ["background", "foreground"]:
        raise ValueError(f"{label} layers must be the approved background and foreground")
    expected_ref = {"background": background_id, "foreground": foreground_id}
    for layer in layers:
        if any(child["kind"] == "attr" for child in layer["children"]):
            raise ValueError(f"{label} {layer['name']} must not set attributes")
        insets = [child for child in layer["children"] if child["kind"] == "element"]
        if len(insets) != 1 or insets[0]["name"] != "inset":
            raise ValueError(f"{label} {layer['name']} must be one inset")
        inset = insets[0]
        if any(child["kind"] == "element" for child in inset["children"]):
            raise ValueError(f"{label} inset must not wrap another drawable")
        attrs = attr_map(inset)
        if set(attrs) != {"drawable", "inset"} or any(len(values) != 1 for values in attrs.values()):
            raise ValueError(f"{label} inset must only set the approved drawable and inset")
        if attrs["drawable"][0]["ref"] != expected_ref[layer["name"]]:
            raise ValueError(f"{label} must use the approved {layer['name']} artwork")
        if attrs["inset"][0]["value"] != APPROVED_INSET:
            raise ValueError(f"{label} inset must stay at the approved 16.7%")


def verify(apk):
    sdk = os.environ.get("ANDROID_HOME") or os.environ["ANDROID_SDK_ROOT"]
    aapt = str(Path(sdk) / "build-tools/36.0.0/aapt")

    def dump(*args):
        return subprocess.check_output([aapt, "dump", *args], text=True)

    resources = {}
    names = {}
    seen = {}
    config = None
    resource = None
    for line in dump("--values", "resources", str(apk)).splitlines():
        line = line.strip()
        if line.startswith("config "):
            config = line.removeprefix("config ").removesuffix(":")
            resource = None
            continue
        match = re.match(r"resource (0x[0-9a-f]+) app\.keeplore:mipmap/(ic_launcher\w*):", line)
        if line.startswith("resource "):
            resource = match.group(2) if match else None
            if match:
                if config is None:
                    raise ValueError("launcher resource appeared before a configuration")
                names[resource] = match.group(1)
                seen.setdefault(resource, set()).add(config)
            continue
        if resource and line.startswith('(string8) "'):
            key = (config, resource)
            path = line.split('"', 2)[1]
            if key in resources and resources[key] != path:
                raise ValueError(f"multiple files for {resource} at {config}")
            resources[key] = path

    for name in PIXEL_NAMES:
        if name not in names:
            raise ValueError(f"missing mipmap resource {name}")
    require_manifest_icons(dump("xmltree", str(apk), "AndroidManifest.xml"), names["ic_launcher"], names["ic_launcher_round"])

    for name in PIXEL_NAMES:
        expected = set(DENSITIES)
        if name in ADAPTIVE_NAMES:
            expected.add("anydpi-v26")
        present = seen.get(name, set())
        if present != expected:
            extra = sorted(present - expected)
            missing = sorted(expected - present)
            detail = ""
            if extra:
                detail += f"; extra {extra}"
            if missing:
                detail += f"; missing {missing}"
            raise ValueError(f"{name} launcher configurations must be exactly the approved K set{detail}")

    count = 0
    with zipfile.ZipFile(apk) as archive:
        for density in DENSITIES:
            for name in PIXEL_NAMES:
                label = f"mipmap-{density}/{name}.png"
                packed = resources.get((density, name))
                if packed is None:
                    raise ValueError(f"missing {label}")
                with Image.open(io.BytesIO(archive.read(packed))) as image:
                    pixels = f"{image.width}x{image.height}:".encode() + image.convert("RGBA").tobytes()
                    if hashlib.sha256(pixels).hexdigest() != APPROVED_PIXELS[label]:
                        raise ValueError(f"{label} does not match Keeplore K artwork")
                count += 1

        for name in ADAPTIVE_NAMES:
            packed = resources.get(("anydpi-v26", name))
            if packed is None:
                raise ValueError(f"missing adaptive {name}")
            require_adaptive(
                dump("xmltree", str(apk), packed),
                names["ic_launcher_background"],
                names["ic_launcher_foreground"],
                f"adaptive {name}",
            )
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
