#!/usr/bin/env bash
# Vérifie l'intégrité des originaux (SHA-256) et les liens relatifs Markdown de docs/.
set -u
cd "$(dirname "$0")/../.." || exit 1
fail=0
( cd docs/references && shasum -a 256 -c MANIFEST.sha256 >/dev/null 2>&1 ) && echo "OK  SHA-256 des originaux" || { echo "KO  SHA-256"; fail=1; }
python3 -I - <<'PY' || fail=1
import re,os,sys,glob
bad=0
for f in glob.glob("docs/**/*.md",recursive=True)+["CLAUDE.md"]:
    txt=open(f,encoding="utf-8").read()
    txt=re.sub(r"```.*?```","",txt,flags=re.S)
    for m in re.finditer(r"\]\(([^)\s]+)\)",txt):
        t=m.group(1).split("#")[0]
        if not t or re.match(r"[a-z]+:",t):continue
        if not os.path.exists(os.path.normpath(os.path.join(os.path.dirname(f),t))):
            print("KO  lien cassé",f,"->",m.group(1));bad+=1
print("OK  liens relatifs" if not bad else f"KO  {bad} lien(s) cassé(s)")
sys.exit(1 if bad else 0)
PY
exit $fail
