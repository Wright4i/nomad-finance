# Svelte on Vite, Slim as the API

The UI is a Svelte 5 app compiled by Vite with Tailwind 4. Slim 4 is a JSON API and, in production, the host for the built files. Dev runs Vite on port 5173 and proxies `/api` to PHP on port 8080. The framework and CSS ship in the build, with fonts bundled, so the app can load offline after the first visit.
