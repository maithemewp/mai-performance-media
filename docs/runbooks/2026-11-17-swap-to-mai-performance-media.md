# Swap every site to Mai Performance Media

Mai Performance Images (`mai-performance-images`, 0.9.1) is now Mai Performance Media (`mai-performance-media`, 1.0.0). The updater only offers an update to a main file with the installed plugin's name, so no site moves on its own, and a site the swap misses stays on 0.9.1 with no warning. Every site is swapped by hand with `mai-sites`, one at a time, so the two plugins never run together. Spec: `docs/specs/2026-11-17-lazy-embeds.md`, section "The rename".

Run each `mai-sites` write yourself. Nothing here is run for you.

## 1. Rename the GitHub repo and repoint origin (Ask first)

Rename `maithemewp/mai-performance-images` to `maithemewp/mai-performance-media`. The updater already points at the new URL.

```
gh repo rename mai-performance-media --repo maithemewp/mai-performance-images
git -C ~/Plugins/mai-performance-images remote set-url origin git@github.com:maithemewp/mai-performance-media.git
```

Check: `git -C ~/Plugins/mai-performance-images remote -v` shows the new URL, and `git -C ~/Plugins/mai-performance-images ls-remote origin` answers. GitHub redirects the old URL, so old links keep working.

## 2. Push, tag and release 1.0.0 from main (Ask first)

`main` is still at 0.9.1 and `develop` holds this work. Do not fast-forward or merge `develop` into `main`, because `develop` may hold commits that are not yours. List your own commits, then cherry-pick only those onto `main`. Replace the placeholder with your own git email.

```
git -C ~/Plugins/mai-performance-images log --oneline --author=<your-git-email> main..develop
```

Check: the list is the commits you mean to ship, and `git -C ~/Plugins/mai-performance-images log --oneline main..develop` shows nothing you do not recognize or have left out on purpose.

Cherry-pick them onto `main`, oldest first.

```
git -C ~/Plugins/mai-performance-images switch main
git -C ~/Plugins/mai-performance-images cherry-pick <oldest-commit>..<newest-commit>
```

Check: `git -C ~/Plugins/mai-performance-images log --oneline -5` shows your commits on top of 0.9.1, and the `Version:` line in `mai-performance-media.php` reads 1.0.0.

Then push `main`, tag and release. Sites that deploy a raw git tree take `main`, and the updater reads `main`.

```
git -C ~/Plugins/mai-performance-images push origin main
git -C ~/Plugins/mai-performance-images tag 1.0.0
git -C ~/Plugins/mai-performance-images push origin 1.0.0
gh release create 1.0.0 --repo maithemewp/mai-performance-media --target main --title 1.0.0 --notes "Renamed to Mai Performance Media. Delays Instagram, X, TikTok and Pinterest embed scripts until they near the screen."
```

Check: `gh release view 1.0.0 --repo maithemewp/mai-performance-media` prints the release, and the Deployable check on `main` passes. Push `develop` too, with `git -C ~/Plugins/mai-performance-images push origin develop`, after you switch back to it.

## 3. Swap each site, one at a time

Run one site at a time on the server named in the build prompt. Parallel WP-CLI runs there hit "Error establishing a database connection" on 9 of 21 sites on 2026-11-17, while the same command one site at a time worked on all of them. Pass `--no-parallel` to every `mai-sites run` in this step, and never give `run` or `push` a server, a group or `all` as the target. One domain per command. On other servers the same one-at-a-time habit is still the safe default.

List the sites that have the old plugin, with each one's status.

```
mai-sites plugins list mai-performance-images
```

Check: each row is a domain, the slug, the version and a status. Keep the list. The status decides what each site gets.

mai-sites has no file backup for a plugin push, only `--backup` for `--db`, and this swap never touches a database. The old plugin stays on disk until the last command below, so a failed swap is undone by activating it again. Before the first site, run the pre-flight reads from the repo folder.

```
mai-sites config lint
mai-sites config check <site>
```

Check: `config check` prints `✓ All checks passed.` for the site.

**Site where the old plugin is active.**

First install the new plugin, inactive. A push only copies files, so nothing runs yet. Dry run first and read the plan.

```
mai-sites push <site> . --dry-run
mai-sites push <site> .
```

Check: the plan names `wp-content/plugins/mai-performance-media` and the push ends with a `✓`.

Then deactivate the old plugin, activate the new one and delete the old one in one command, so the two never run together. If the first step fails, nothing after it runs.

```
mai-sites run <site> --no-parallel --shell='wp plugin deactivate mai-performance-images && wp plugin activate mai-performance-media && wp plugin delete mai-performance-images'
```

Check: the command exits 0, and `mai-sites run <site> --yes -- wp plugin list` shows `mai-performance-media` active at 1.0.0 and no `mai-performance-images` row.

**Site where the old plugin is inactive.** The site keeps what it had, so it stays without the plugin. Do not install the new one. Only delete the old one.

```
mai-sites run <site> --no-parallel -- wp plugin delete mai-performance-images
```

Check: `mai-sites run <site> --yes -- wp plugin list` shows no `mai-performance-images` row and no `mai-performance-media` row.

If a site goes wrong after the swap, activate the old plugin again with `mai-sites run <site> --no-parallel -- wp plugin activate mai-performance-images` and deactivate the new one. This only works before the old one is deleted, so check the site before you run the delete on the next one.

What I could not confirm: I read the `--help` and the `run.md` and `push-pull.md` notes for `mai-sites`, and ran no write. The notes say `--shell` runs a string on the server in the site's WordPress root, and is never treated as a read. They do not show a `&&` chain through `--shell`, or a `wp plugin delete` after `--` as an accepted write, so run the first site and read its output before you do the rest. If `--shell` refuses the chain, run the three `wp plugin` commands as three separate `mai-sites run` commands, deactivate and activate back to back.

## 4. Check each site

After each swap, check the site boots and serves a page with one `fetchpriority="high"` image. The `mpi` query is a random cache-buster, so a page cache cannot serve an old page. Use any random value.

```
curl -sk 'https://<site>/?mpi=<random>' | rg -c 'fetchpriority="high"'
```

Check: the site answers with a page and the count is 1. Open one post that has an Instagram or X embed and check the loader is there.

```
curl -sk 'https://<site>/<post-path>/?mpi=<random>' | rg -c 'id="mai-performance-media-lazy-embeds"'
```

Check: the count is 1 on a post with a delayed embed. A site that boots but prints a fatal error, or a blank page, is a failed swap, so stop and fix it before the next site.

## 5. Confirm no site is left

Rerun this until it lists nothing. A site the swap missed stays on 0.9.1 with no warning, so this is the only completion check.

```
mai-sites plugins list mai-performance-images
```

Check: no rows. Any row is a site still to swap, so go back to step 3 for it.

Then see every site on the new plugin.

```
mai-sites plugins list mai-performance-media
```

Check: every site that was active before is listed as active at 1.0.0. A site that had the old plugin inactive is not listed, on purpose.

## 6. Update the outside references

After the swap, change these to the new name and slug.

- The `bizbudding-two` theme, `includes/ajax.php` line 40: the GitHub URL in its plugin list.
- Each client theme with a `setup/design.json` plugin list: the plugin path. At least one local copy has it, so search them all.
- The skills `~/.agents/skills/mai-performance-images/SKILL.md` (rename the folder and the skill) and `~/.agents/skills/mai-plugin-scaffold/SKILL.md` (it mentions the plugin).
- Hindsight needs nothing. The `mai::plugins` mapping picks up the new folder through the daily updater.

Find what is left with this search.

```
rg -l 'mai-performance-images' ~/Plugins ~/.agents/skills ~/Herd --glob '!node_modules' --glob '!vendor'
```

Check: nothing in the list that you did not mean to leave. Old release notes and the git history may still name the old plugin, and that is fine.

Last, rename the local folder and repoint the local Herd symlinks. Each of the 16 local sites already has a link named `mai-performance-media`, but it points at the old folder name.

```
mv ~/Plugins/mai-performance-images ~/Plugins/mai-performance-media
```

Repoint one site's link, then repeat the same command for each of the other sites.

```
ln -sfn ~/Plugins/mai-performance-media ~/Herd/<site>/wp-content/plugins/mai-performance-media
```

Check: `ls -l ~/Herd/<site>/wp-content/plugins/mai-performance-media` shows the new target. To count the links that still point at the old folder, run `ls -l ~/Herd/*/wp-content/plugins/mai-performance-media | rg -c 'mai-performance-images'`, which should print 0. Folders and tools that cached the old path, such as open editor windows, need the new path too.
