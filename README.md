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
