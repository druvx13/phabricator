# Phabricator Usage Guide

This guide covers the basics of using Phabricator after installation.

---

## Table of Contents

1. [First Login & Admin Setup](#1-first-login--admin-setup)
2. [Creating Users](#2-creating-users)
3. [Creating a Project](#3-creating-a-project)
4. [Creating and Reviewing Tasks (Maniphest)](#4-creating-and-reviewing-tasks-maniphest)
5. [Code Review (Differential)](#5-code-review-differential)
6. [Connecting a Repository (Diffusion)](#6-connecting-a-repository-diffusion)
7. [Writing a Wiki Page (Phriction)](#7-writing-a-wiki-page-phriction)
8. [Useful CLI Commands](#8-useful-cli-commands)

---

## 1. First Login & Admin Setup

After installing Phabricator, open your browser and navigate to your configured base URI (e.g. `http://phabricator.example.com/`).

1. Click **Register a New Account** (the first registered user is automatically made an administrator).
2. Complete the registration form and verify your email address if email is configured.
3. You will be taken to the Phabricator dashboard.

To configure the installation further, go to **⚙ Settings → Administration → Config**.

---

## 2. Creating Users

**Via web UI:**

1. Log in as an administrator.
2. Navigate to **People** (top navigation) → **Create New User**.
3. Fill in username, email, and real name, then click **Create User**.

**Via CLI:**

```bash
./bin/accountadmin
```

Follow the interactive prompts to create or modify an account.

---

## 3. Creating a Project

1. Navigate to **Projects** → **Create Project**.
2. Enter a project name and optional description.
3. Set the **Join Policy** (who can join) and **View Policy** (who can see the project).
4. Add members on the Members tab.
5. Click **Create Project**.

---

## 4. Creating and Reviewing Tasks (Maniphest)

### Creating a Task

1. Navigate to **Maniphest** → **Create Task**.
2. Fill in the title, description, priority, and assignee.
3. Optionally add projects and subscribers.
4. Click **Create Task**.

### Reviewing Tasks

- Use **Maniphest → Queries** to filter tasks by assignee, project, priority, or status.
- Click a task to view its full detail, add comments, change status, or reassign it.

---

## 5. Code Review (Differential)

### Submitting a Diff for Review

Using Arcanist (Phabricator's CLI tool):

```bash
# Install Arcanist
# https://secure.phabricator.com/book/phabricator/article/arcanist/

arc diff HEAD~1  # submit the latest commit for review
```

Or use the web UI: **Differential** → **Create Diff** → paste a diff.

### Reviewing a Diff

1. Open the diff from the **Differential** dashboard or a direct link.
2. Click line numbers to leave inline comments.
3. Use the **Add Action** button to:
   - **Accept Revision** – approve the change.
   - **Request Changes** – ask for modifications.
   - **Comment** – leave general feedback.

---

## 6. Connecting a Repository (Diffusion)

1. Navigate to **Diffusion** → **Create Repository**.
2. Select the VCS type (Git, SVN, or Mercurial).
3. Enter the remote URI (for an imported repository) or let Phabricator host it.
4. Configure the **Staging Area** and **Automation** settings as needed.
5. Click **Create Repository** and then **Activate Repository**.

---

## 7. Writing a Wiki Page (Phriction)

1. Navigate to **Phriction** or enter a URL like `/w/your-page-slug/`.
2. Click **Create This Document** if the page doesn't exist yet.
3. Write content using [Remarkup](https://secure.phabricator.com/book/phabricator/article/remarkup/) — Phabricator's Markdown-like syntax.
4. Click **Save Changes**.

---

## 8. Useful CLI Commands

```bash
# Apply database migrations after an update
./bin/storage upgrade

# Start/stop/status of background daemons
./bin/phd start
./bin/phd status
./bin/phd stop

# Rebuild Celerity static assets
./bin/celerity map

# Reindex all objects (search)
./bin/search index --all

# Manage configuration
./bin/config list
./bin/config set <key> <value>
./bin/config get <key>

# Administer user accounts interactively
./bin/accountadmin

# Trigger a garbage collection pass
./bin/garbage collect
```
