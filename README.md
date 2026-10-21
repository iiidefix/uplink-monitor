# uplink-monitor

Failover reporting for a site whose primary uplink (`eth0`) can fail and
whose backup is an on-demand connection such as LTE.

| Directory | What it is |
|---|---|
| [uplink-watchdog](uplink-watchdog/README.md) | Debian package with the `uplink-watchdog` service on the site's router. It watches the primary uplink; when that fails, it uses the backup interface only to tell the webservice and ask what to do. Normal traffic and DNS are never rerouted. |
| [uplink-webservice](uplink-webservice/README.md) | PHP webservice and Telegram bot (plain directory, not a package). It keeps each host's state in SQLite, reports failover and failback in Telegram, and answers the watchdog's poll with the action you chose: `vpn`, `stop` or `none`. |

```
uplink-watchdog ──(over backup)──▶ uplink-webservice ──▶ Telegram
                ◀──── vpn | stop | none ────┘
```

## Build and deploy

```
make -C uplink-watchdog          # → uplink-watchdog/dist/uplink-watchdog_<version>_all.deb
dpkg -i uplink-watchdog/dist/uplink-watchdog_*.deb
```

The webservice is deployed by copying the directory to a PHP host; see its
[README](uplink-webservice/README.md) for `config.php` and the Telegram
setup.

## License

Copyright (C) 2026 iiidefix

uplink-monitor is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. See [LICENSE](LICENSE).

If you would like to use uplink-monitor under a different license, contact the author.
