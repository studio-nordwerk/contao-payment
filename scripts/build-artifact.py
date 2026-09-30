"""Build a versioned Contao Manager artifact from the last commit."""
import io
import json
from pathlib import Path
import re
import subprocess
import sys
import zipfile

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
        result.writestr(entry, data)
print(f"Built version {version} from HEAD; the working tree was not packaged.")
