# Atomic production releases

The Hostinger application must not be updated by synchronizing a new source
tree into the live application directory. That approach can remove `vendor`,
compiled Vite assets, or Laravel runtime directories while requests are being
served.

The supported target layout is:

```text
/home/u720282675/domains/qa.mprestoza.com/
  qa-training-platform -> qa-training-platform-releases/<sha>
  qa-training-platform-releases/
    <sha>/                  # complete immutable release
  qa-training-platform-shared/
    .env
    private/                # storage/app/private; persistent uploads
    logs/                   # shared Laravel logs
```

The existing public entrypoint already references the stable
`qa-training-platform` path. After a planned one-time cutover converts that
path to a symlink, future releases can switch it atomically without changing
the public root. The one-time conversion is intentionally not performed by
the deployment script.

## Release sequence

1. Build an exact Git archive and a verified frontend build.
2. Run `scripts/atomic-release.sh prepare` into a new release directory.
3. Link only shared `.env`, `storage/app/private`, and `storage/logs`; create
   fresh per-release framework cache/session/view directories.
4. Ensure Composer dependencies are complete, using the committed lockfile.
5. Build config, route, and view caches inside the new release.
6. Verify Laravel boot, writable runtime paths, the Vite manifest, database
   compatibility, and persistent storage access.
7. Run `scripts/atomic-release.sh activate`; it changes the stable symlink
   with one `mv -T` operation.
8. Check HTTP health, queue status, and the persistent-storage manifest. If
   post-switch health fails, activate the previous complete release.

No migration is applied automatically by the script. Pending migrations block
activation. Schema changes must use an expand/migrate/contract sequence so old
and new releases remain compatible during the switch.

For Windows-side persistent-file verification, use
`scripts/persistent-storage-check.ps1` to capture before/after path, byte-size,
and SHA-256 manifests. The Linux release host must perform the equivalent
manifest check before and after activation; the PowerShell helper is not
required on Hostinger.

The existing Hostinger queue/cron mechanism remains the worker mechanism. A
deployment must reload that existing mechanism after activation; it must not
introduce Supervisor, systemd, or a second scheduler.

## Safe simulation

Run from Git Bash:

```bash
scripts/atomic-release.sh simulate
```

The simulation verifies that release A remains live while B is built, an
incomplete C cannot activate, rollback works, and persistent files remain
unchanged throughout.
