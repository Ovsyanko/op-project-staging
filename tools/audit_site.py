from pathlib import Path
import re, sys

ROOT = Path(__file__).resolve().parents[1]
BASE = '/op-project-staging'
ROUTES = [
    '/', '/home/', '/referencelist/', '/contacts/', '/drying/', '/charcoal/',
    '/composite/', '/poultry/', '/tech-project/', '/tech-audit/', '/optimisation/',
    '/plant/', '/agro/', '/page-derevo/', '/policy/', '/callmeback/', '/presswood/',
    '/offers/', '/revision/', '/pellets/', '/insulation/', '/supply/', '/installation/',
    '/grinding/', '/combustion/', '/briquetting/', '/heat-recovery/', '/food/', '/energy/'
]

errors=[]
warnings=[]

def route_file(route):
    if route == '/': return ROOT/'index.html'
    return ROOT/route.strip('/')/'index.html'

for route in ROUTES:
    p=route_file(route)
    if not p.exists():
        errors.append(f'missing route file: {route} -> {p.relative_to(ROOT)}')
        continue
    text=p.read_text(encoding='utf-8', errors='replace')
    if 'noindex' not in text.lower():
        warnings.append(f'no noindex marker: {route}')
    for bad in ('s3.reg.solutions','op-project.ru/img/','reg.ru/img/'):
        if bad in text:
            errors.append(f'external source image reference in {route}: {bad}')
    refs=set(re.findall(r"(?:src=|url\()['\"]?(/op-project-staging/assets/[^'\"\) >]+)", text))
    for ref in refs:
        rel=ref[len(BASE)+1:]
        target=ROOT/rel
        if not target.exists():
            errors.append(f'missing asset in {route}: {ref}')

# Audit shared JS/CSS that can inject active page content.
for p in list((ROOT/'assets').glob('*.js')) + list((ROOT/'assets').glob('*.css')):
    text=p.read_text(encoding='utf-8', errors='replace')
    for bad in ('s3.reg.solutions','op-project.ru/img/','reg.ru/img/'):
        if bad in text:
            errors.append(f'external source image reference in {p.relative_to(ROOT)}: {bad}')

print(f'Audited {len(ROUTES)} routes')
for w in warnings:
    print('WARNING:', w)
for e in errors:
    print('ERROR:', e)
if errors:
    sys.exit(1)
print('PASS: active staging routes have local assets and no REG.RU/S3 image dependencies')
