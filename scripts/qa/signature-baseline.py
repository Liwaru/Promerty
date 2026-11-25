"""Reconstruct the original Customer presentation CSS from the recorded edit boundaries.
Only writes an evidence file. Never overwrites application CSS or historical backups.
"""
from pathlib import Path

root = Path(__file__).resolve().parents[2]
css = (root / 'resources/css/promerty/customer.css').read_text(encoding='utf-8')
start = css.index('    /* Customer signature derivatives.')
end = css.index('    .f-main', start)
css = css[:start] + css[end:]
css = css.replace(' background:var(--customer-surface); box-shadow:inset 0 2px var(--color-primary);', '')
start = css.index('    .f-app #feed-detail')
end = css.index('    @media(max-width:760px)', start)
css = css[:start] + css[end:]
start = css.index('        .f-app .f-player', css.index('    @media(max-width:760px)'))
end = css.index('        .f-post { height:100%; min-height:100%; }', start)
css = css[:start] + css[end:]
css = css.replace('        .f-app .p-button:active { transform:none; }\n', '')
if '--customer-' in css:
    raise RuntimeError('Unexpected residual signature styling')
output = root / 'docs/customer/signature/baseline-customer.css'
output.write_text(css, encoding='utf-8')
print('Original presentation CSS reconstructed as evidence; application files untouched.')
