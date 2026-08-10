# Inertia SSR runtime

The normal server deployment builds both Vite bundles with `npm run build:ssr`, installs `oblivion-inertia-ssr` in Supervisor, restarts it so the new bundle is loaded, and refuses to report provisioning success until both Supervisor and `php artisan inertia:check-ssr` confirm the runtime is healthy.

## Operator checks

```bash
sudo supervisorctl status oblivion-inertia-ssr
php artisan inertia:check-ssr
sudo tail -f /var/log/oblivion/inertia-ssr.log
```

The installer defaults to `/etc/supervisor/conf.d`, the active Ubuntu Supervisor configuration, runtime user `www-data`, and `/var/log/oblivion`. Deployment environments with different paths must set the corresponding `INERTIA_SSR_*` variables consumed by `scripts/deploy-server.sh`.

`--skip-inertia-ssr` is an explicit release opt-out only for an environment where another process manager already owns the same built SSR runtime. Record that external process status and a successful `php artisan inertia:check-ssr` in the deployment evidence; the flag does not make an unhealthy or absent SSR service acceptable.
