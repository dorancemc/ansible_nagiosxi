# ansible_nagiosxi

Installs Nagios XI on RHEL family hosts using the official installer
(`xi-latest.tar.gz` + `fullinstall -n`). The installer manages its own stack
(apache, php, mariadb, repositories, firewall), so this role does not duplicate
any of that: it downloads, runs the installer once, and ensures the services
stay started and enabled.

The install runs only when `/usr/local/nagiosxi` does not exist, so the role is
safe to run repeatedly. The first run takes 15 to 30 minutes.

## Before The Installer Runs

Nagios Enterprises only supports installing on a clean, fully updated system,
and the installer itself pulls packages from the distribution repositories. On a
stale image that mix breaks things: a CentOS Stream 9 image from 2023 ended up
with `openssl` at 3.5.7 while its `openssh-server` was still built against 3.0,
and `sshd` refused to start with `OpenSSL version mismatch` — the host stayed up
and served the web interface, but nobody could log in over SSH again.

So the role updates every package first, reboots if the update changed anything,
and only then downloads the installer. It also checks that the SSH daemon is
running after the update and again after the installer, and fails loudly if it
is not: an installer that returns 0 while locking you out of the machine should
not be reported as success.

All three steps happen only on a host that does not have Nagios XI yet. Running
the role against an existing installation upgrades nothing.

| Variable | Default | Purpose |
|---|---|---|
| `nagiosxi_update_packages` | `true` | Update every package before installing |
| `nagiosxi_reboot_after_update` | `true` | Reboot when that update changed something |
| `nagiosxi_reboot_timeout` | `600` | Seconds to wait for the host to come back |
| `nagiosxi_url` | `https://{{ inventory_hostname }}/nagiosxi/` | Program URL the web interface builds its redirects from |
| `nagiosxi_verify_wizard` | `true` | Refuse to configure a host whose setup wizard is still pending |

## The Setup Wizard Splits The Deploy In Two

`fullinstall` leaves the product installed but not activated. The rest of the
setup — license key and administrator account — happens in the browser, and
when that wizard finishes it rewrites `ssl.conf` with the self-signed
certificate Nagios XI ships. Any certificate deployed before that point is
gone, which is exactly what happens when Certbot runs in the same pass as the
installer.

So the role never runs the installer and the configuration in one go:

1. **First run** — Nagios XI is not installed. The role installs it, prints the
   URL of the wizard and ends the play for that host with `meta: end_host`.
   Nothing downstream of the role runs, so no certificate is issued yet.
2. **You complete the wizard** in the browser.
3. **Second run, same tags** — the role finds Nagios XI installed, asks the
   database whether the wizard was completed and only then continues. If it is
   still pending the run fails with a clear message instead of burning a
   certificate.

`files/check_setup_complete.php` answers that question. The web interface
cannot: an installation still sitting on its wizard already redirects
`/nagiosxi/` to `login.php`, and `install.php` is compiled with SourceGuardian.
Two rows in `xi_options` do tell the two states apart, and neither exists on a
freshly installed host:

| Row | Written by |
|---|---|
| `install_version` | the web installer, when it finishes |
| `enterprise_key` or `trial_key` | the activation step |

The script reads the database credentials from the installation itself, the
same way `set_program_url.php` does, and exits `0` when the wizard is done and
`3` when it is not.

## SSL Is Handled By Nagios XI's Own Script

Nagios XI owns `ssl.conf`: its **Admin > SSL Config** screen — and the
activation wizard behind it — call `manage_ssl_config.sh` and rewrite
`SSLCertificateFile` and `SSLCertificateKeyFile`. Editing that file with
`lineinfile` puts Ansible and Nagios XI in a fight over the same resource, and
whatever Certbot deployed disappears the moment somebody walks through that
screen.

`tasks/ssl.yml` calls the same script Nagios XI calls, and points Apache
straight at `/etc/letsencrypt/live/`. There are no copies under
`/usr/local/nagiosxi/var/certs/` to drift out of sync — that directory is
empty in 2026R1 anyway — and the renewal only has to reload `httpd`.

Because Apache cannot be pointed at a certificate that does not exist yet,
these tasks are **not** part of `tasks/main.yml`. Run them after whatever
issues the certificate:

```yaml
- hosts: nagiosxi
  roles:
    - role: nagiosxi
    - role: certbot
  post_tasks:
    - name: Point Apache at the Let's Encrypt certificate
      ansible.builtin.include_role:
        name: nagiosxi
        tasks_from: ssl
```

| Variable | Default | Purpose |
|---|---|---|
| `nagiosxi_ssl_cert` | `""` | Certificate Apache should serve. Empty disables the whole step |
| `nagiosxi_ssl_key` | `""` | Its private key |
| `nagiosxi_ssl_conf` | `/etc/httpd/conf.d/ssl.conf` | File read to decide whether the change is already applied |
| `nagiosxi_ssl_script` | `/usr/local/nagiosxi/scripts/manage_ssl_config.sh` | The Nagios XI SSL manager |

`nagiosxi_url` matters more than it looks: `fullinstall -n` runs unattended and
stores `http://localhost/nagiosxi/`, so every browser that reaches the server
gets redirected to its own machine. The role writes the real URL into the
`xi_options` table, reading the database credentials from the installation
itself.

## Requirements

- RHEL family host (RHEL, Alma, Rocky, Oracle Linux). The role refuses any
  other family.
- Outbound HTTPS to `assets.nagios.com`, or point `nagiosxi_src_url` at a
  mirrored tarball. Pin a version there instead of `xi-latest` for
  reproducible installs.

## Usage

```yaml
- hosts: nagiosxi
  roles:
    - role: nagiosxi
```

```bash
ansible-playbook -i inventory/hosts.ini playbooks/main.yml --limit nagiosxi.example.com --tags nagiosxi
```

Configuration of monitoring objects is out of scope: that belongs to the
`nagiosconfig` role, which writes them under `/usr/local/nagios/etc/static`.
