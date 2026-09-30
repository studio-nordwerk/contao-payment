import io, json, subprocess, zipfile
from pathlib import Path
with zipfile.ZipFile(io.BytesIO(subprocess.check_output(['git','archive','--worktree-attributes','--format=zip','HEAD']))) as archive:
    names = archive.namelist()
    assert all(n in ('composer.json','LICENSE','README.md') or n.startswith(('src/','config/','Resources/','templates/','translations/','public/')) for n in names)
    assert 'src/Provider/StripeProvider.php' in names
assert json.loads(Path('composer.json').read_text())['require']['contao/core-bundle'] == '^5.7.12'
print('Payment package export verified.')
