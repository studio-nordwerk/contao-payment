"""Build a versioned Contao Manager artifact from the last commit."""
import io
import json
from pathlib import Path
import re
import subprocess
import sys
import zipfile


def unix_mode(info):
    """Readable for every PHP user: files 0644, directories 0755 (zipfile writes 0600 by default)."""
    return (0o40755 << 16 | 0x10) if info.is_dir() else 0o100644 << 16

if len(sys.argv) != 3 or not re.fullmatch(r"\d+\.\d+\.\d+(?:-dev|-(?:alpha|beta|RC|rc)(?:[.-]?\d+)?)?", sys.argv[1]):
    raise SystemExit("Usage: build-artifact.py VERSION OUTPUT.zip (e.g. 1.0.0)")
version, output = sys.argv[1:]
archive = subprocess.check_output(["git", "archive", "--worktree-attributes", "--format=zip", "HEAD"])
target = Path(output)
target.parent.mkdir(parents=True, exist_ok=True)
with zipfile.ZipFile(io.BytesIO(archive)) as source, zipfile.ZipFile(target, "x", compression=zipfile.ZIP_DEFLATED) as result:
    for entry in source.infolist():
        data = source.read(entry.filename)
        if entry.filename == "composer.json":
            manifest = json.loads(data)
            manifest["version"] = version
            data = (json.dumps(manifest, ensure_ascii=False, indent=2) + "\n").encode()
        entry.create_system, entry.external_attr = 3, unix_mode(entry)
        result.writestr(entry, data)
print(f"Built version {version} from HEAD; the working tree was not packaged.")
