import io, json, subprocess, zipfile
from pathlib import Path
with zipfile.ZipFile(io.BytesIO(subprocess.check_output(['git','archive','--worktree-attributes','--format=zip','HEAD']))) as archive:
    names = archive.namelist()
    assert all(n in ('composer.json','LICENSE','README.md') or n.startswith(('src/','config/','contao/','templates/','translations/','public/')) for n in names)
    assert 'src/Provider/StripeProvider.php' in names and 'contao/config/config.php' in names and 'contao/dca/tl_nw_payment_settings.php' in names
# The Contao Manager compares against 5.7.0 for a root constraint of 5.7.*, so the require must admit 5.7.0; the conflict keeps older 5.7 releases out.
manifest = json.loads(Path('composer.json').read_text())
assert manifest['require']['contao/core-bundle'] == '^5.7' and manifest['conflict']['contao/core-bundle'] == '<5.7.12'
print('Payment package export verified.')
