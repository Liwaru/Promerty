# Promerty UI conventions

- All new and edited Promerty UI must use the locked palette in `design-tokens/promerty.json`.
- Use `components.layouts.app` (`<x-layouts.app>`) for new Blade pages so the early theme initializer and Vite assets load together.
- Use semantic CSS variables, `p-*` component classes, and Tailwind semantic utilities (`bg-background`, `bg-panel`, `text-foreground`, `text-muted`, `bg-brand`, `border-outline`). Do not introduce page-specific brand colors.
- Before migrating existing colors, inventory their roles and record their mapping. Preserve existing behavior and links. See `docs/design-system/legacy-color-audit.md`.
- Gold text alone on white is decorative, not body text. Use dark foreground on gold for primary buttons and price labels; keep a visible keyboard focus indicator.
- Edit the JSON source and run `npm run tokens` for shared colors. Never hand-edit generated CSS or Dart colors. Read `docs/design-system/README.md` for integration and validation.
- Design/theme changes must not modify Auth API, database configuration, schema, or data.
