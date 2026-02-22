# Phabricator

> **Notice:** Effective June 1, 2021, Phabricator is no longer actively maintained by the original authors. This fork maintains the codebase and provides modernized deployment tooling for PHP 8.x and current-generation LAMP stacks.

**Phabricator** is a collection of open-source web applications for software development, including:

- **Differential** – Code review
- **Diffusion** – Repository browser (Git, SVN, Mercurial)
- **Maniphest** – Bug/task tracker
- **Phriction** – Wiki
- **Pholio** – Design review
- **Herald** – Notification rules
- **Harbormaster** – Build/CI system
- **Conpherence** – Team messaging
- **Phabricator** – Admin dashboard and user management

---

## Version Compatibility Matrix

| Component       | Minimum      | Recommended  | Notes                                      |
|-----------------|--------------|--------------|---------------------------------------------|
| PHP             | 7.2          | 8.1 – 8.3    | PHP 8.0+ requires minor compatibility fixes |
| MySQL           | 5.5          | 8.0 / MariaDB 10.6+ | Use `utf8mb4` charset                |
| MariaDB         | 10.2         | 10.6 LTS     | Preferred over MySQL for new installs       |
| Apache          | 2.4          | 2.4.x latest | mod_rewrite required                        |
| nginx           | 1.18         | 1.24+        | Alternative to Apache                       |
| Node.js         | 10.x         | 18 LTS       | Required for Aphlict (notifications)        |
| Git             | 2.6+         | 2.40+        | Required for Diffusion                      |

---

## Quick Start

### Shared Hosting (InfinityFree, cPanel, FTP upload — no SSH needed)

```text
1. Upload this project to your web host's public_html/ folder
2. The root .htaccess routes all traffic to webroot/ automatically — no config needed
3. Copy .env.example → .env and fill in your database credentials
4. Visit  http://yoursite/install.php  — click "Run Database Setup"
5. Delete install.php, then open your site
```

Full details: **[docs/INSTALL.md — Quick Deploy](docs/INSTALL.md#quick-deploy-shared-hosting-ftp-upload)**

### VPS / Dedicated Server

```bash
# Verify your environment
bash verify.sh

# Automated LAMP or LEMP setup (requires root/sudo)
sudo bash setup.sh
```

Full details: **[docs/INSTALL.md](docs/INSTALL.md)**

---

## Documentation

| File | Description |
|------|-------------|
| [docs/INSTALL.md](docs/INSTALL.md) | Step-by-step installation (Ubuntu/Debian/CentOS) |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | All configuration options and environment variables |
| [docs/USAGE.md](docs/USAGE.md) | Basic user guide |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Common errors and solutions |
| [docs/CHANGELOG.md](docs/CHANGELOG.md) | Modernization changelog |

---

## LICENSE

Phabricator is released under the **Apache 2.0 license** except as otherwise noted. See [LICENSE](LICENSE) and [NOTICE](NOTICE) for details.
