# ansible_nagiosxi

Installs Nagios XI on RHEL family hosts using the official installer
(`xi-latest.tar.gz` + `fullinstall -n`). The installer manages its own stack
(apache, php, mariadb, repositories, firewall); this role downloads it, runs it
once, and keeps the services enabled.

The install runs only when `/usr/local/nagiosxi` is missing, so the role is safe
to re-run. First run takes 15 to 30 minutes.

## How it works

- **Clean system first.** The role updates all packages, reboots if anything
  changed, then downloads the installer. It checks `sshd` is alive after both
  the update and the installer, failing loudly if not.
- **Deploy splits in two.** `fullinstall` installs but does not activate. The
  first run installs, prints the wizard URL, and ends the play (`meta: end_host`).
  You complete the wizard in the browser. The second run verifies the wizard
  completed (via `files/check_setup_complete.php`) before continuing.
- **SSL via Nagios XI's own script.** `tasks/ssl.yml` calls
  `manage_ssl_config.sh` and points Apache at `/etc/letsencrypt/live/`. These
  tasks are not in `main.yml`; run them after the certificate is issued.

## Requirements

- RHEL family host (RHEL, Alma, Rocky, Oracle Linux, CentOS). Other families are refused.
- Outbound HTTPS to `assets.nagios.com`, or set `nagiosxi_src_url` to a mirror.

## Usage

```yaml
- hosts: nagiosxi
  roles:
    - role: nagiosxi
```

```bash
ansible-playbook -i inventory/hosts.ini playbooks/main.yml --limit nagiosxi.example.com --tags nagiosxi
```

Applying SSL after a certificate is issued:

```yaml
- hosts: nagiosxi
  roles:
    - role: nagiosxi
    - role: certbot
  post_tasks:
    - ansible.builtin.include_role:
        name: nagiosxi
        tasks_from: ssl
```

## Variables

Defaults live in `defaults/main.yml`. Key items: `nagiosxi_update_packages`,
`nagiosxi_reboot_after_update`, `nagiosxi_url`, `nagiosxi_verify_wizard`,
`nagiosxi_ssl_cert`, `nagiosxi_ssl_key`, `nagiosxi_src_url`.

Monitoring object configuration is out of scope; that belongs to the
`nagiosconfig` role.
