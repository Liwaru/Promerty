# Promerty Development Requirements

## Web platform

- Promerty is a Laravel property marketing website. Build and maintain it as a web application, not a native mobile app.
- Do not create Android/APK or iOS apps, use Flutter or React Native, add native mobile dependencies, or maintain a separate mobile application.
- Prioritize desktop browsers, then laptop browsers, responsive tablet browsers, and responsive mobile browsers.
- Mobile viewport checks are responsive web browser checks. They do not imply native mobile application support.
- Preserve responsive web design and accessibility. Use Playwright MCP and Chrome DevTools MCP for browser verification at relevant viewport sizes.

## Product scope

- Preserve the Laravel architecture and all working Customer features. Target roles are customer, agent, branch_manager, admin, and owner; retain existing super_admin records non-destructively as legacy data. Admin and Owner have distinct privileges.
- Keep the premium luxury visual direction: Dark Black + Gold, Light White + Gold, and the existing System theme option.
- Phase 5 and work on other roles remain deferred until the user explicitly requests them.
