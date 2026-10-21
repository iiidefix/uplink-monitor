# uplink-watchdog: primary uplink watchdog

Debian package with the `uplink-watchdog` service. It watches the primary
uplink (`eth0`). When the primary fails, it uses a backup interface
(default `wwan0`) only to tell a webservice and ask it what to do. If
asked, it starts a VPN over the backup. **Normal traffic and DNS are never
rerouted.** The backup can be any ifupdown interface (LTE, Ethernet,
Wi-Fi, …).

## Behaviour

- **Normal:** the backup is down (`ifdown`). The watchdog takes it down on
  every start, so it is also down after boot. The primary is pinged every
  `INTERVAL` (10 s).
- **Failover:** once the primary has been unable to reach the internet
  continuously for `FAILOVER_AFTER` (default **5 min**), the watchdog:
  1. sets `/run/uplink-watchdog/failed`;
  2. brings the backup up, POSTs a `failover` event to `NOTIFY_URL`, then
     brings the backup down again.
- **Polling:** while failed over, every `POLL_INTERVAL` (5 min), it brings
  the backup up, sends `GET POLL_URL?host=<hostname>`, and brings the
  backup down again. If the backup doesn't come up or the request fails,
  it takes the backup down, brings it up and tries once more right away.
  The answer (plain text) decides what happens:

  | Answer | Action |
  |---|---|
  | `vpn` | Keep the backup up and run `VPN_START_CMD`, unless the VPN already runs. While it runs, a later `vpn` reruns `VPN_START_CMD` only if `VPN_CHECK_CMD` fails |
  | `none` | Nothing |
  | `stop` | End the VPN if it runs: `VPN_STOP_CMD`, backup down. Polling continues; a later `vpn` starts it again |
  | anything else, error | Logged, treated as `none` |

  If the failover notification failed, it is resent before each poll.
- **While the VPN runs:** the backup stays up. It is pinged every 30 s and
  brought down and up again after two failures.
- **Failback:** the primary is checked every `FAILED_INTERVAL` (2 s). Once
  it has been healthy for `FAILBACK_AFTER` (default 0, the first good
  check), the watchdog stops polling, runs `VPN_STOP_CMD` if the VPN was
  running, POSTs a `failback` event over the primary (one attempt), and
  brings the backup down.
- **Stop:** stopping the service, including on package removal, does the
  failback steps. A restart while failed over fails back first.
- **Health checks are layer 3 only.** Pings to `TARGETS` go through each
  interface, never using link or carrier state.

### Webservice

| Request | When | Interface |
|---|---|---|
| `POST NOTIFY_URL`, `Content-Type: application/json` | Failover, then before each poll until it succeeds | Backup |
| `GET POLL_URL?host=<hostname>` (`&host=` if the URL already has a query) | Every `POLL_INTERVAL` while failed over | Backup |
| `POST NOTIFY_URL` | Failback | Primary |

```json
{"host":"pi","event":"failover","primary":"eth0","backup":"wwan0","time":"2026-10-20T23:09:24+02:00"}
```

The `event` field is `failover` or `failback`. With `API_TOKEN` set, every
request carries `Authorization: Bearer <token>`. HTTP status 400 and above
counts as a failure.

**No DNS is used for the webservice**, because the primary keeps the
default route and DNS. Requests are bound to the backup with
`curl --interface`. Put an IP address in the URLs, or put the address of
the URL's host name in `SERVICE_IP` (it is passed to `curl --resolve`, so
HTTPS certificates are still checked against the name).

### VPN commands

`VPN_START_CMD`, `VPN_CHECK_CMD` and `VPN_STOP_CMD` (and the
[state commands](#state-commands)) run with `sh -c`, as root,
with a 60 s timeout. Their output goes to the journal. Their environment
has `PRIMARY`, `BACKUP` and `BACKUP_GW` (the backup's IPv4 gateway, from
the backup's default route in any routing table). The
primary keeps the default route, so **`VPN_START_CMD` has to route the VPN over
the backup itself**, for example with a host route to the VPN server:

```sh
VPN_START_CMD='ip route replace 198.51.100.7 via $BACKUP_GW dev $BACKUP && systemctl start wg-quick@backup'
VPN_CHECK_CMD='systemctl is-active --quiet wg-quick@backup'
VPN_STOP_CMD='systemctl stop wg-quick@backup; ip route del 198.51.100.7 || true'
```

### State commands

Optional commands for up and down of either interface, run like the VPN
commands:

| Option | Runs |
|---|---|
| `PRIMARY_UP_CMD` | When the primary's health check passes after failing, and for the first check after a start if it passes |
| `PRIMARY_DOWN_CMD` | When the primary's health check fails after passing, and for the first check after a start if it fails |
| `BACKUP_UP_CMD` | After the watchdog brought the backup up (`ifup` succeeded) |
| `BACKUP_DOWN_CMD` | After the watchdog took the backup down, if it was up |
| `FAILOVER_CMD` | On failing over, before the backup is brought up for the failover notification |
| `FAILBACK_CMD` | After failing back: VPN stopped, failback notification tried, backup down. Also on the failback when the service stops or restarts while failed over |
| `FAILBACK_STABLE_CMD` | Once per failback, when the primary has passed its checks for `FAILBACK_STABLE_AFTER` (300 s) since. A failed check restarts the wait, a new failover cancels it |

The primary commands follow each check at once; they are not delayed by
`FAILOVER_AFTER` or `FAILBACK_AFTER`. A restart while failed over fails
back, so `FAILBACK_STABLE_CMD` follows that too. The backup goes up and down for each
poll, so the backup commands run every `POLL_INTERVAL` while failed over.
They only see changes made by the watchdog itself, not an `ifup`/`ifdown`
by hand. The commands block the watchdog while they run.

| Installed file | Purpose |
|---|---|
| `/usr/sbin/uplink-watchdog` | The watchdog |
| `/usr/lib/systemd/system/uplink-watchdog.service` | Systemd service (`Restart=always`) |
| `/etc/default/uplink-watchdog` | Options (mode 0600, can hold `API_TOKEN`) |

## Configuration

`/etc/default/uplink-watchdog` (conffile, kept on upgrades). Apply changes
with `systemctl restart uplink-watchdog`.

| Option | Default | Meaning |
|---|---|---|
| `PRIMARY` / `BACKUP` | `eth0` / `wwan0` | Interfaces; `BACKUP` must be an ifupdown interface |
| `FAILOVER_AFTER` | `300` | Seconds the primary must be down **continuously** before failing over |
| `FAILBACK_AFTER` | `0` | Seconds the primary must be healthy before failing back (0 = first good check) |
| `INTERVAL` | `10` | Seconds between primary checks normally |
| `FAILED_INTERVAL` | `2` | Seconds between primary checks while failed over |
| `TARGETS` | `1.1.1.1 8.8.8.8 9.9.9.9` | Ping targets; any one answering = healthy |
| `NOTIFY_URL` | empty | Webservice for events; empty = no webservice (failover then only logs) |
| `POLL_URL` | `NOTIFY_URL` | Webservice asked what to do |
| `POLL_INTERVAL` | `300` | Seconds between polls |
| `SERVICE_IP` | empty | Address of the URLs' host name |
| `API_TOKEN` | empty | Bearer token |
| `VPN_START_CMD` / `VPN_CHECK_CMD` / `VPN_STOP_CMD` | empty | See [VPN commands](#vpn-commands) |
| `PRIMARY_UP_CMD` / `PRIMARY_DOWN_CMD` / `BACKUP_UP_CMD` / `BACKUP_DOWN_CMD` / `FAILOVER_CMD` / `FAILBACK_CMD` / `FAILBACK_STABLE_CMD` | empty | See [State commands](#state-commands) |
| `FAILBACK_STABLE_AFTER` | `300` | Seconds the primary must stay healthy after a failback before `FAILBACK_STABLE_CMD` runs |

Also settable, but not in the shipped file: `HTTP_TIMEOUT` (15 s per
request), `CMD_TIMEOUT` (60 s per command), `BACKUP_CHECK` (30 s).

## Build and install

```sh
make                    # → dist/uplink-watchdog_1.0.0_all.deb
make VERSION=1.0.1      # another version
make clean
dpkg -i dist/uplink-watchdog_*.deb
```

Depends on: curl, iputils-ping, iproute2, ifupdown, systemd. The postinst
enables and restarts the service.

## Operating it

```sh
systemctl status uplink-watchdog     # running?
journalctl -u uplink-watchdog -f     # failover, polls, answers, VPN, failback
ls /run/uplink-watchdog/             # failed = failed over; vpn = VPN_START_CMD ran
```

## Limitations

- **IPv4 only.**
- **No internet for normal traffic while failed over.** That is
  intentional: only the webservice requests and the VPN use the backup.
- **Polls block the checks.** A poll can take up to two bring-ups plus two
  `HTTP_TIMEOUT`s, and the primary isn't checked meanwhile. So failback
  can be delayed by that much.
- With `FAILBACK_AFTER=0`, a flapping primary fails back at once, then
  needs another full `FAILOVER_AFTER` before failing over again.
- Health probes go to public anycast IPs. If all of them are blocked
  upstream, the Pi fails over without need; adjust `TARGETS` then.
