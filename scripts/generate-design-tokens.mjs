import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const tokens = JSON.parse(fs.readFileSync(path.join(root, 'design-tokens/promerty.json'), 'utf8'));
const resolve = (value) => value.startsWith('{') ? tokens.brand[value.slice(1, -1)] : value;
const cssValue = (value) => value.startsWith('{') ? `var(--brand-${value.slice(1, -1)})` : value;
const declarations = (theme) => Object.entries(tokens[theme]).map(([key, value]) => `    --color-${key}: ${cssValue(value)};`).join('\n');
let css = '/* Generated from design-tokens/promerty.json. Run npm run tokens; do not edit. */\n:root {\n';
css += Object.entries(tokens.brand).map(([key, value]) => `    --brand-${key}: ${value};`).join('\n');
css += `\n    color-scheme: light;\n${declarations('light')}\n}\n`;
css += `@media (prefers-color-scheme: dark) {\n  :root:not([data-theme="light"]):not([data-theme="dark"]) {\n    color-scheme: dark;\n${declarations('dark')}\n  }\n}\n`;
css += `[data-theme="dark"] {\n    color-scheme: dark;\n${declarations('dark')}\n}\n`;
for (const [file, content] of Object.entries({ 'resources/css/promerty/tokens.css': css })) {
  const target = path.join(root, file);
  if (process.argv.includes('--check')) {
    if (!fs.existsSync(target) || fs.readFileSync(target, 'utf8') !== content) throw new Error(`Stale generated file: ${file}`);
  } else {
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, content);
  }
}
// Report meaningful foreground/background contrast; gold alone on white is decorative only.
const luminance = (hex) => hex.slice(1).match(/../g).map(c => parseInt(c, 16) / 255).map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4).reduce((sum, c, i) => sum + c * [.2126, .7152, .0722][i], 0);
for (const mode of ['light', 'dark']) {
  for (const [fg, bg] of [['text', 'bg'], ['text-muted', 'card'], ['on-primary', 'primary'], ['on-error', 'error'], ['error', 'card'], ['success', 'card'], ['warning', 'card'], ['info', 'card']]) {
    const a = luminance(resolve(tokens[mode][fg])), b = luminance(resolve(tokens[mode][bg]));
    const ratio = (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
    if (ratio < 4.5) throw new Error(`${mode} ${fg}/${bg} contrast fails: ${ratio}`);
    console.log(`${mode}: ${fg}/${bg} ${ratio.toFixed(2)}:1`);
  }
}
