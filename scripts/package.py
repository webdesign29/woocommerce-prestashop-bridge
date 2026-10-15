from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED, ZipInfo
import argparse
import hashlib
import re
root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--channel', choices=['direct', 'woo-marketplace'], default='direct')
parser.add_argument('--companion', type=Path)
args = parser.parse_args()
marketplace = args.channel == 'woo-marketplace'
slug = 'inklura-sync' if marketplace else 'woocommerce-prestashop-bridge'
files = [root / 'includes' / 'admin-design.css', root / 'includes' / 'admin-design.js', root / 'includes' / 'product-links.js', root / 'includes' / 'record-panel.js'] + [root / name for name in ['woocommerce-prestashop-bridge.php', 'README.md', 'CHANGELOG.md', 'LICENSE', 'OPERATIONS.md', 'ACCEPTANCE.md', 'SUPPLIERS.md', 'CUSTOM-FIELDS.md', 'GALLERY.md']]
for directory in ['includes']:
    files.extend(sorted((root / directory).rglob('*.php')))
main = (root / 'woocommerce-prestashop-bridge.php').read_text()
version = re.search(r"Version: ([0-9.]+)|define\('WD29_WOOBRIDGE_VERSION', '([0-9.]+)'\)", main)
version = version.group(1) or version.group(2)
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
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    def add(relative, data):
        info = ZipInfo(str(Path(slug) / relative), (2026, 10, 13, 0, 0, 0))
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
            data = data.replace('Description: Synchronisation WooCommerce ↔ PrestaShop : catalogue, stocks, commandes et clients.'.encode(), b'Description: Direct synchronization of products, stock, orders and customers between WooCommerce and PrestaShop.')
        if marketplace and relative.name == 'readme.txt':
            data = data.replace(b'Requires at least: 6.5', b'Requires at least: 7.0')
        add(relative, data)
    if companion_data is not None:
        add(Path('companion/wd29woobridge.zip'), companion_data)
digest = hashlib.sha256(output.read_bytes()).hexdigest()
output.with_suffix('.zip.sha256').write_text(digest + '  ' + output.name + '\n')
print(output)
print(digest)
