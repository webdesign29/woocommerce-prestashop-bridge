from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import hashlib
import re
root = Path(__file__).resolve().parents[1]
slug = 'woocommerce-prestashop-bridge'
files = [root / 'includes' / 'admin-design.css', root / 'includes' / 'admin-design.js', root / 'includes' / 'product-links.js', root / 'includes' / 'record-panel.js'] + [root / name for name in ['woocommerce-prestashop-bridge.php', 'README.md', 'CHANGELOG.md', 'LICENSE', 'OPERATIONS.md', 'ACCEPTANCE.md', 'SUPPLIERS.md', 'CUSTOM-FIELDS.md', 'GALLERY.md']]
for directory in ['includes']:
    files.extend(sorted((root / directory).rglob('*.php')))
main = (root / (slug + '.php')).read_text()
version = re.search(r"Version: ([0-9.]+)|define\('WD29_WOOBRIDGE_VERSION', '([0-9.]+)'\)", main)
version = version.group(1) or version.group(2)
output = root / 'dist' / (slug + '-' + version + '.zip')
output.parent.mkdir(exist_ok=True)
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for file in files:
        archive.write(file, str(Path(slug) / file.relative_to(root)))
digest = hashlib.sha256(output.read_bytes()).hexdigest()
output.with_suffix('.zip.sha256').write_text(digest + '  ' + output.name + '\n')
print(output)
print(digest)
