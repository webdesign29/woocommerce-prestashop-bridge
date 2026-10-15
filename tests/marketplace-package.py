"""Packaging fixture only; real companion runtime checks belong to its own repository."""
from pathlib import Path
from tempfile import TemporaryDirectory
from zipfile import ZipFile
import hashlib
import re
import subprocess
import shutil

root = Path(__file__).resolve().parents[1]
version = re.search(r'Version: ([0-9.]+)', (root / 'woocommerce-prestashop-bridge.php').read_text()).group(1)
def run(*args, success=True):
    result = subprocess.run(['python3', 'scripts/package.py', *map(str, args)], cwd=root, capture_output=True, text=True)
    if (result.returncode == 0) != success:
        raise RuntimeError(result.stdout + result.stderr)
    return result
with TemporaryDirectory() as temporary:
    temp = Path(temporary)
    def companion(channel, release=version):
        output = temp / (channel + '-' + release + '.zip')
        with ZipFile(output, 'w') as archive:
            archive.writestr('wd29woobridge/includes/Distribution.php', "<?php const CHANNEL = '" + channel + "';")
            archive.writestr('wd29woobridge/wd29woobridge.php', "<?php define('WD29_WOOBRIDGE_VERSION', '" + release + "');")
        return output
    run('--channel', 'woo-marketplace', success=False)
    run('--channel', 'woo-marketplace', '--companion', companion('direct'), success=False)
    run('--channel', 'woo-marketplace', '--companion', companion('woo-marketplace', '0.0.1'), success=False)
    pairing = companion('woo-marketplace')
    run('--channel', 'woo-marketplace', '--companion', pairing)
    package = root / 'dist/woo-marketplace/inklura-sync.zip'
    first_hash = hashlib.sha256(package.read_bytes()).hexdigest()
    run('--channel', 'woo-marketplace', '--companion', pairing)
    assert first_hash == hashlib.sha256(package.read_bytes()).hexdigest(), 'Non-deterministic package'
    with ZipFile(package) as archive:
        assert all(n.startswith('inklura-sync/') for n in archive.namelist()), 'Wrong top-level folder'
        assert archive.read('inklura-sync/companion/wd29woobridge.zip') == pairing.read_bytes(), 'Companion changed during assembly'
        assert b'Text Domain: inklura-sync' in archive.read('inklura-sync/inklura-sync.php')
        changelog = archive.read('inklura-sync/changelog.txt').decode()
        assert changelog.startswith('*** Inklura Sync Changelog ***'), 'Wrong Woo changelog header'
        assert re.search(r'^\d{4}-\d{2}-\d{2} - version ([0-9.]+)\s*$', changelog, re.M).group(1) == version, 'Woo changelog version mismatch'
        readme = archive.read('inklura-sync/readme.txt').decode()
        assert re.search(r'^Stable tag: ([0-9.]+)\s*$', readme, re.M).group(1) == version
        assert re.search(r'^= ([0-9.]+) =\s*$', readme.split('== Changelog ==', 1)[1], re.M).group(1) == version
        archive.extractall(temp / 'extracted')
    extracted = temp / 'extracted/inklura-sync'
    for test, argument in [('marketplace-distribution', extracted), ('marketplace-admin', extracted), ('marketplace-bootstrap', extracted / 'inklura-sync.php')]:
        subprocess.run(['php', str(root / ('tests/' + test + '.php')), str(argument)], check=True)
    # Reproduce the external validator rejection before an upload is possible.
    damaged = temp / 'damaged-source'
    shutil.copytree(root, damaged, ignore=shutil.ignore_patterns('.git', 'dist', '__pycache__'))
    for name, mutate in [
        ('changelog.txt', lambda text: text.replace(' - version ' + version, ' - version 0.0.1', 1)),
        ('changelog.txt', lambda text: text.replace('*** Inklura Sync Changelog ***', '= ' + version + ' =', 1)),
        ('readme.txt', lambda text: text.replace('Stable tag: ' + version, 'Stable tag: 0.0.1', 1)),
        ('readme.txt', lambda text: text.replace('= ' + version + ' =', '= 0.0.1 =', 1)),
    ]:
        target = damaged / name
        original = target.read_text()
        target.write_text(mutate(original))
        refused = subprocess.run(['python3', 'scripts/package.py'], cwd=damaged, capture_output=True, text=True)
        assert refused.returncode != 0 and name in refused.stderr, 'Invalid release metadata passed packaging: ' + name
        target.write_text(original)
    run()
    with ZipFile(root / ('dist/woocommerce-prestashop-bridge-' + version + '.zip')) as archive:
        assert b"const CHANNEL = 'direct';" in archive.read('woocommerce-prestashop-bridge/includes/Distribution.php')
        assert not any('/companion/' in n for n in archive.namelist())
    assert "const CHANNEL = 'direct';" in (root / 'includes/Distribution.php').read_text()
print('PASS: wrong-channel/mismatched companions rejected; stable Marketplace root/main/readme/changelog; direct source and archive preserved')
