"""Packaging fixture only; real companion runtime checks belong to its own repository."""
from pathlib import Path
from tempfile import TemporaryDirectory
from zipfile import ZipFile
import hashlib
import re
import subprocess

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
        archive.extractall(temp / 'extracted')
    extracted = temp / 'extracted/inklura-sync'
    for test, argument in [('marketplace-distribution', extracted), ('marketplace-admin', extracted), ('marketplace-bootstrap', extracted / 'inklura-sync.php')]:
        subprocess.run(['php', str(root / ('tests/' + test + '.php')), str(argument)], check=True)
    run()
    with ZipFile(root / ('dist/woocommerce-prestashop-bridge-' + version + '.zip')) as archive:
        assert b"const CHANNEL = 'direct';" in archive.read('woocommerce-prestashop-bridge/includes/Distribution.php')
        assert not any('/companion/' in n for n in archive.namelist())
    assert "const CHANNEL = 'direct';" in (root / 'includes/Distribution.php').read_text()
print('PASS: wrong-channel/mismatched companions rejected; stable Marketplace root/main/readme/changelog; direct source and archive preserved')
