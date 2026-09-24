# Inertia SSR runtime

The normal server deployment builds both Vite bundles with `npm run build:ssr`, installs `oblivion-inertia-ssr` in Supervisor, asks the already-running daemon to parse and discover the updated definition with `supervisorctl reread`, restarts it so the new bundle is loaded, and refuses to report provisioning success until both Supervisor and `php artisan inertia:check-ssr` confirm the runtime is healthy. Do not start a second `supervisord` process as a configuration test: it can collide with the active daemon's control socket or HTTP port without proving the running daemon accepted the definition.

## Operator checks

```bash
sudo supervisorctl status oblivion-inertia-ssr
php artisan inertia:check-ssr
sudo tail -f /var/log/oblivion/inertia-ssr.log
```

The installer defaults to `/etc/supervisor/conf.d`, the active Ubuntu Supervisor configuration, runtime user `www-data`, and `/var/log/oblivion`. Deployment environments with different paths must set the corresponding `INERTIA_SSR_*` variables consumed by `scripts/deploy-server.sh`.

`INERTIA_SSR_ENABLED` and `INERTIA_SSR_URL` are application settings in `config/inertia.php`, not deployment variables. They let local development switch SSR off when no SSR runtime is running. Servers leave both unset (SSR on at `http://127.0.0.1:13714`). Setting `INERTIA_SSR_ENABLED=false` on a server does not skip SSR: `inertia:start-ssr` refuses to start, so the release fails its health gates.

`--skip-inertia-ssr` is an explicit release opt-out only for an environment where another process manager already owns the same built SSR runtime and is configured to restart it. After building the new SSR bundle, deployment requires exactly one current-release `artisan inertia:start-ssr --runtime=node` process, invokes the supported `php artisan inertia:stop-ssr` command, then waits for one distinct replacement PID and two consecutive successful health samples. The release fails closed if the manager does not replace the process or the replacement is unhealthy. Record that external-manager restart status and the successful `php artisan inertia:check-ssr` result in deployment evidence; the flag does not make an unhealthy, stale, absent, or manually started SSR service acceptable.
