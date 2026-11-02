from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED, ZipInfo
import argparse
import hashlib
import re
import os
import subprocess
from datetime import datetime, timezone
root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--channel', choices=['direct', 'woo-marketplace'], default='direct')
parser.add_argument('--companion', type=Path)
args = parser.parse_args()
marketplace = args.channel == 'woo-marketplace'
slug = 'inklura-sync' if marketplace else 'woocommerce-prestashop-bridge'
files = [root / 'includes' / 'admin-design.css', root / 'includes' / 'admin-design.js', root / 'includes' / 'product-links.js', root / 'includes' / 'record-panel.js'] + [root / name for name in ['woocommerce-prestashop-bridge.php', 'README.md', 'CHANGELOG.md', 'LICENSE', 'OPERATIONS.md', 'ACCEPTANCE.md', 'SUPPLIERS.md', 'CUSTOM-FIELDS.md', 'GALLERY.md', 'docs/KERAWEN-COMMERCE.md', 'docs/SHOPIFY.md', 'docs/MARKETPLACE-EDITION.md']]
files += sorted((root / 'languages').glob('*'))
files.append(root / 'includes' / 'i18n.js')
for directory in ['includes']:
    files.extend(sorted((root / directory).rglob('*.php')))
main = (root / 'woocommerce-prestashop-bridge.php').read_text()
version = re.search(r"Version: ([0-9.]+)|define\('WD29_WOOBRIDGE_VERSION', '([0-9.]+)'\)", main)
version = version.group(1) or version.group(2)
# Woo validates the first dated entry in changelog.txt, not WordPress readme headings.
changelog = (root / 'changelog.txt').read_text()
changelog_version = re.search(r'^\d{4}-\d{2}-\d{2} - version ([0-9.]+)\s*$', changelog, re.M)
if not changelog.startswith('*** Inklura Sync Changelog ***') or not changelog_version or changelog_version.group(1) != version:
    parser.error('changelog.txt must start with the Woo changelog header and a dated entry matching the plugin version')
readme = (root / 'readme.txt').read_text()
stable = re.search(r'^Stable tag: ([0-9.]+)\s*$', readme, re.M)
readme_changelog = readme.split('== Changelog ==', 1)[-1]
readme_version = re.search(r'^= ([0-9.]+) =\s*$', readme_changelog, re.M)
if not stable or stable.group(1) != version or not readme_version or readme_version.group(1) != version:
    parser.error('readme.txt stable tag and latest changelog must match the plugin version')
companion_data = None
if marketplace:
    if args.companion is None:
        parser.error('--companion is required for a Marketplace build')
    with ZipFile(args.companion) as companion:
        if any(not n.startswith('wd29woobridge/') or '..' in Path(n).parts for n in companion.namelist()):
            parser.error('The companion must use the wd29woobridge root directory')
        marker = companion.read('wd29woobridge/includes/Distribution.php')
        companion_main = companion.read('wd29woobridge/wd29woobridge.php').decode()
        if b"const CHANNEL = 'woo-marketplace';" not in marker:
            parser.error('The companion must use woo-marketplace billing, not direct billing')
        companion_version = re.search(r"define\('WD29_WOOBRIDGE_VERSION', '([0-9.]+)'\)", companion_main).group(1)
        if companion_version != version:
            parser.error('Woo and PrestaShop Marketplace versions must match')
    companion_data = args.companion.read_bytes()
elif args.companion is not None:
    parser.error('--companion is only valid for a Marketplace build')
files += [root / name for name in ['readme.txt', 'changelog.txt'] if (root / name).is_file()]
output = root / 'dist' / (args.channel if marketplace else '') / (slug + '.zip' if marketplace else slug + '-' + version + '.zip')
output.parent.mkdir(parents=True, exist_ok=True)
# A fixed timestamp shared by different releases can leave PHP OPcache serving
# the previous included files after a native module import. Releases keep a
# reproducible commit timestamp; source-only builds may set SOURCE_DATE_EPOCH.
release_epoch = os.environ.get('SOURCE_DATE_EPOCH')
if release_epoch is None:
    try:
        release_epoch = subprocess.check_output(['git', 'log', '-1', '--format=%ct'], cwd=root, stderr=subprocess.DEVNULL).decode().strip()
    except (OSError, subprocess.CalledProcessError):
        release_epoch = str(int(max(file.stat().st_mtime for file in files)))
release_time = datetime.fromtimestamp(int(release_epoch), timezone.utc).timetuple()[:6]
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    def add(relative, data):
        info = ZipInfo(str(Path(slug) / relative), release_time)
        info.compress_type = ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, data)
    for file in files:
        relative = file.relative_to(root)
        data = file.read_bytes()
        if str(relative) == 'includes/Distribution.php':
            assert data.count(b"const CHANNEL = 'direct';") == 1
            data = data.replace(b"const CHANNEL = 'direct';", ("const CHANNEL = '" + args.channel + "';").encode())
        if marketplace and relative.name == 'woocommerce-prestashop-bridge.php':
            relative = relative.with_name('inklura-sync.php')
            data = data.replace(b'Text Domain: wd29-bridge', b'Text Domain: inklura-sync')
            data = data.replace(b' * Requires at least: 6.5', b' * Requires at least: 7.0\n * WC requires at least: 11.2.1')
            data = data.replace(b'Plugin URI: https://plugins.inklura.fr/bridge-woocommerce-prestashop', b'Plugin URI: https://woocommerce.com/products/inklura-sync/')
            data = re.sub(rb'^ \* Description: .*$', b' * Description: Connect WooCommerce and PrestaShop, with an optional Shopify connection for products, stock, customers and order consultation.', data, count=1, flags=re.M)
        if marketplace and relative.name == 'readme.txt':
            data = data.replace(b'Requires at least: 6.5', b'Requires at least: 7.0')
        add(relative, data)
    if companion_data is not None:
        add(Path('companion/wd29woobridge.zip'), companion_data)
digest = hashlib.sha256(output.read_bytes()).hexdigest()
output.with_suffix('.zip.sha256').write_text(digest + '  ' + output.name + '\n')
print(output)
print(digest)
