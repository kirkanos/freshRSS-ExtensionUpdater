# Extension Updater

A FreshRSS system extension that watches every installed extension, reports
available updates and installs them on demand.

## How it works

Under **Configuration → Extension updates** the plugin lists each installed
extension with its installed and available version. Every extension with an
installable update gets a button that downloads the archive and swaps the
directory.

### Update sources

Sources are asked in order; the first installable answer wins.

| Source | Covers |
| --- | --- |
| Official index | The ~84 extensions in `FreshRSS/Extensions` |
| Custom indexes | Any `extensions.json` in the same format (one URL per line) |
| GitHub | Extensions whose `metadata.json` points at a GitHub repository — including ones in no index at all. Uses releases, otherwise the `metadata.json` on the default branch |
| Git | Extensions installed as a git clone: reports how many commits behind upstream they are, but does not update them itself (see below) |

The index only names a project page, not an archive. For GitHub the default
branch is resolved through the API; for Codeberg/Forgejo, Gitea and GitLab the
usual archive URLs (`main`, then `master`) are tried in turn.

### What an install does

1. Download the archive (https only; private IP ranges are refused).
2. Check every ZIP entry for path traversal, then extract.
3. Locate the directory in the archive whose `metadata.json` carries the same
   `entrypoint`. A differently named directory is accepted only when the
   archive contains exactly one extension.
4. Copy the current directory to `DATA_PATH/extension-updater/backups/`.
5. Move the old directory aside, copy the new one in, verify it.
6. Roll back to the previous version on any failure.

Every step is written to the FreshRSS log at `notice` level.

## Configuration

| Setting | Default | Purpose |
| --- | --- | --- |
| Use the official extension index | on | |
| Index URL | `…/FreshRSS/Extensions/refs/heads/main/extensions.json` | |
| Additional indexes | empty | One `https` URL per line |
| Check GitHub repositories | on | |
| GitHub token | empty | Raises the API limit from 60 to 5000 requests per hour |
| Check git checkouts | on | Requires `exec()` |
| Cache lifetime | 6 hours | "Check again now" bypasses the cache |

## Limitations

- **Git checkouts are not updated automatically.** Unpacking a ZIP over one
  would destroy the working tree, so the plugin only reports how many commits
  are missing and leaves `git pull` to you.
- **Write permissions.** Many Docker setups mount `extensions/` read-only. The
  plugin detects this and shows a note instead of the button.
- **Version comparison.** Versions that cannot be compared (`nightly`) never
  report an update — better to miss one than to overwrite a working extension
  with an older copy.
- **Installer error messages are deliberately English**: they are diagnostic
  text and appear verbatim in the log. The interface itself is translated
  (de/en).
- After an update, disable and re-enable the affected extension if it ships an
  `install()` migration.

## Installation

```sh
cd FreshRSS/extensions
git clone https://github.com/kirkanos/freshRSS-ExtensionUpdater.git xExtension-ExtensionUpdater
```

Then enable it under **Configuration → Extensions**. FreshRSS accepts any
directory name that holds a valid `metadata.json`; the `xExtension-` prefix is
convention rather than a requirement.

Installed this way the extension is a git checkout, so it updates itself with
`git pull`. It will report its own pending commits but will not overwrite its
working tree — see Limitations. Download the archive instead if you would
rather have it update in place.

Requires admin rights, the PHP `zip` extension and PHP 7.4+.
