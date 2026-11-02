"""Extract the shared PHP helper's literal messages and compile the maintained French PO.
Run after editing I18n::t literals; edit the PO translations before compiling a release.
"""
from pathlib import Path
import json,re,subprocess
root=Path(__file__).resolve().parents[1]
pattern=re.compile(r"I18n::t\(\s*'((?:[^'\\]|\\.)*)'\s*\)")
messages=set()
for file in [root/'woocommerce-prestashop-bridge.php',*root.glob('includes/**/*.php')]:
 for value in pattern.findall(file.read_text()):
  messages.add(value.replace("\\'","'").replace('\\\\','\\'))
header='msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n\n'
(root/'languages/inklura-sync.pot').write_text(header+''.join('msgid '+json.dumps(s,ensure_ascii=False)+'\nmsgstr ""\n\n' for s in sorted(messages)))
subprocess.run(['msgfmt','--check','-o',str(root/'languages/inklura-sync-fr_FR.mo'),str(root/'languages/inklura-sync-fr_FR.po')],check=True)
print('Extracted',len(messages),'messages and compiled French translations')
