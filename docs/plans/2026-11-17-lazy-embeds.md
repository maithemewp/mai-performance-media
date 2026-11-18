# Lazy embeds and the rename to Mai Performance Media: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delay Instagram, X, TikTok and Pinterest embed scripts until each embed nears the screen, then rename the plugin and its repo to Mai Performance Media and ship it as 1.0.0.

**Architecture:** A new `EmbedLoading` class adds its own callback to WordPress's finished-page filter (`wp_template_enhancement_output_buffer`), removes the `src` from known embed script tags with `WP_HTML_Tag_Processor`, and prints a small inline loader before `</body>`. The loader takes each embed's marker off, watches it with an IntersectionObserver (200px margin, and 1600px for X once the reader scrolls), and puts the marker back, loads the network's script once and asks the network to render that embed as it nears. The rename is a separate mechanical task after the feature works.

**Tech Stack:** PHP 8.1, WordPress 6.9+ HTML API, plain browser JavaScript (no build step for the loader), PHPUnit with wp-phpunit, Playwright and Lighthouse for browser checks.

**Spec:** `docs/specs/2026-11-17-lazy-embeds.md`. Read it first. It holds the measurements and the reasons; this plan only holds the decisions.

## Global Constraints

- `Requires PHP: 8.1`, `Requires at least: 6.9`. No PHP 8.2+ syntax (no readonly classes, no DNF types).
- `declare(strict_types=1);` in every new PHP file. Namespace everything. Type every parameter, return and property.
- HTML edits use `WP_HTML_Tag_Processor`. Never `DOMDocument`, never regex over HTML. A `strripos` for the last `</body>` is a string search, not parsing, and is allowed.
- Docblocks explain why, in plain words, like the existing classes.
- No private site, client or server names anywhere in the repo. A commit hook blocks them. Local test sites are named only in the build prompt.
- No em-dashes in anything a person reads: UI text, admin notices, CHANGES.md, README, commit messages, docs.
- Never push, tag, create a GitHub repo or release without asking first and getting a yes for that specific action.
- Headless browsers by default. A real browser only through `~/.agents/scripts/bg-chrome` (it never takes the foreground), and `bg-chrome stop` afterwards.
- The filter is `mai_performance_media_lazy_embeds` from the first commit, even before the rename.
- Margin: `200px`; X also uses 1600px once the reader scrolls (amended 2026-11-17, see the spec's Layout shift section). Fixed script URLs: `https://www.instagram.com/embed.js`, `https://platform.twitter.com/widgets.js`, `https://www.tiktok.com/embed.js`, `https://assets.pinterest.com/js/pinit.js`.
- Network keys used between PHP and JS: `instagram`, `x`, `tiktok`, `pinterest`.

## Review Focus

1. A pasted `src` in an unusual form (`http:`, `https:`, protocol-relative `//`, upper case, a query string such as `?v=2`) must still be delayed. Test in Task 1.
2. A page with an embed script but no `</body>` (a partial template or a page closed early) must still get the loader, appended at the end. Test in Task 1.
3. A Pinterest share plugin's own `pinit.js` (with `data-pin-hover` or any `data-pin-*` on the tag) must keep loading at page load, even on a page with a pasted pin. Test in Task 1.
4. A network script already loaded by something else (a theme printing `widgets.js` in the head, outside the content) must not be loaded twice, and its embeds must still render. Covered in Task 2's browser check by a page fixture that loads `widgets.js` in the head.
5. Text that looks like a script tag inside an HTML comment or inside another script's body must not be touched. Test in Task 1.

---

### Task 1: The finished-page pass

**Files:**
- Create: `classes/enum-embed-network.php` (enum `EmbedNetwork`)
- Create: `classes/class-embed-loading.php` (class `EmbedLoading`)
- Create: `assets/js/lazy-embeds.js` (a placeholder IIFE for now; Task 2 writes the real loader)
- Modify: `mai-performance-images.php` (instantiate `EmbedLoading`)
- Modify: `vendor/composer/autoload_classmap.php`, `autoload_static.php` (regenerate with `composer dump-autoload --no-dev`)
- Test: `tests/integration/EmbedLoadingTest.php`

**Interfaces:**
- Produces: `enum EmbedNetwork: string { case Instagram = 'instagram'; case X = 'x'; case TikTok = 'tiktok'; case Pinterest = 'pinterest'; }` with `public static function from_src( string $src ): ?self` and `public function script_url(): string`.
- Produces: `final class EmbedLoading` with `public function __construct()` (adds the `wp_before_include_template` action at priority 10, like `LoadingAttributes`), `public function add_page_filter(): void`, and `public function filter_page( mixed $html ): string`.
- Produces: the inline loader tag carries `data-networks="instagram,x"` (comma list of the networks delayed, in `EmbedNetwork` case order) and an `id` of `mai-performance-media-lazy-embeds`.

- [ ] **Step 1: Write the failing tests** in `tests/integration/EmbedLoadingTest.php`, extending `TestCase`, calling `( new EmbedLoading() )->filter_page( $html )` directly. Fixtures are real pasted markup (blockquote plus script). Tests:
  - `test_each_library_is_delayed`: one data provider row each for `//www.instagram.com/embed.js`, `https://platform.twitter.com/widgets.js`, `https://platform.x.com/widgets.js`, `https://www.tiktok.com/embed.js`. Assert the output has no `src=` containing the path, still has the `<blockquote` unchanged byte for byte, and has exactly one `id="mai-performance-media-lazy-embeds"`.
  - `test_unusual_src_forms_are_delayed`: `http://`, upper-case host, `?v=2` query. (Review Focus 1.)
  - `test_mai_embed_fixer_tag_is_delayed`: `<script async class="mai-instagram-script" src="//www.instagram.com/embed.js" charset="utf-8"></script>`.
  - `test_three_pasted_copies_give_one_loader`: three Instagram embeds each with its own script; assert one loader and `data-networks="instagram"`.
  - `test_networks_listed_in_order`: Instagram plus X plus TikTok on one page gives `data-networks="instagram,x,tiktok"`.
  - `test_loader_goes_before_the_last_body_close`: assert `strripos( $out, '</body>' )` is after the loader's position.
  - `test_loader_is_appended_without_body_close` (Review Focus 2).
  - `test_pinit_delayed_only_with_a_pasted_pin`: `pinit.js` plus `<a data-pin-do="embedPin" href="https://www.pinterest.com/pin/1/">` is delayed; `pinit.js` alone on a page with no pin keeps its `src`.
  - `test_pinit_with_data_pin_attributes_is_left_alone` (Review Focus 3): `<script async defer data-pin-hover="true" src="//assets.pinterest.com/js/pinit.js">` keeps its `src` even with a pasted pin on the page, and if it is the only `pinit.js`, `pinterest` is not in `data-networks`.
  - `test_other_scripts_are_untouched`: a page with jQuery and an inline script comes back identical.
  - `test_comment_and_script_text_are_untouched` (Review Focus 5): `<!-- <script src="//www.instagram.com/embed.js"></script> -->` and a script whose body contains the string `instagram.com/embed.js` come back identical, and no loader is added.
  - `test_filter_turns_it_off`: `add_filter( 'mai_performance_media_lazy_embeds', '__return_false' )` returns the page identical.
  - `test_page_without_embeds_is_identical`.
  - `test_through_the_real_page_buffer`: with `$this->page()` from `TestCase` (after also calling `( new EmbedLoading() )->add_page_filter()` there, see Step 3), an Instagram embed comes out delayed. This proves the hook timing.

- [ ] **Step 2: Run them to see them fail**

Run: `composer test -- --filter EmbedLoadingTest`
Expected: errors, class `Mai\PerformanceImages\EmbedLoading` not found.

- [ ] **Step 3: Implement.** `EmbedNetwork::from_src()` lower-cases the `src`, strips the scheme, and matches on the paths in the spec (`instagram.com/embed.js`, `platform.twitter.com/widgets.js`, `platform.x.com/widgets.js`, `tiktok.com/embed.js`, `assets.pinterest.com/js/pinit.js`). `filter_page()`:
  1. Return `$html` unchanged if it isn't a string, if `apply_filters( 'mai_performance_media_lazy_embeds', true )` is false, or if a `stripos` finds none of the paths.
  2. Walk `script` tags with `WP_HTML_Tag_Processor`. For a match: skip Pinterest when the tag has any attribute starting with `data-pin-` (use `get_attribute_names_with_prefix( 'data-pin-' )`), and skip Pinterest when the page has no `data-pin-do` (a `stripos` check before the walk is enough). Otherwise `remove_attribute( 'src' )` and record the network.
  3. If anything was recorded, build the loader with `wp_get_inline_script_tag( $js, [ 'id' => 'mai-performance-media-lazy-embeds', 'data-networks' => $list ] )`, where `$js` is the contents of `assets/js/lazy-embeds.js` read once per request, and insert it before the last `</body>` (`strripos`), or append it.
  Add `EmbedLoading` to `TestCase::page()` next to `LoadingAttributes::instance()->add_page_filter()`. In `mai-performance-images.php`, add `new EmbedLoading();` after `LoadingAttributes::instance();`. The placeholder `assets/js/lazy-embeds.js` is `( function () {} )();`. Run `composer dump-autoload --no-dev`.

- [ ] **Step 4: Run the tests**

Run: `composer test`
Expected: every test passes, the 132 existing ones and the new ones.

- [ ] **Step 5: Commit**

```bash
git add classes/enum-embed-network.php classes/class-embed-loading.php assets/js/lazy-embeds.js mai-performance-images.php tests/TestCase.php tests/integration/EmbedLoadingTest.php vendor/composer
git commit -m "Delay embed scripts on the finished page"
```

### Task 2: The loader, and checking it in a browser

**Files:**
- Modify: `assets/js/lazy-embeds.js`
- Create: `bin/check-embeds.mjs` (`bin/` is already in `.distignore`)
- Modify: `package.json` (add `playwright` to `devDependencies`, add `"check:embeds": "node bin/check-embeds.mjs"`)

**Interfaces:**
- Consumes: the loader tag's `id` and `data-networks` from Task 1. The loader reads them with `document.currentScript.dataset.networks`.
- Produces: `bin/check-embeds.mjs [--block <file>] [--pace <ms>] <url> [<url>...]` (pace is the pause between 400px steps, 700ms by default) prints one pretty-printed JSON object per URL with `pace`, `scriptsAtLoad` (embed scripts requested before any scrolling), `scripts` (each embed script URL requested, with its time), `unrendered` (embeds still a blockquote or pin link after scrolling), `cls`, and exits non-zero when `unrendered` is not 0 for Instagram or X, or when `cls` is above 0.1. Whether a script loaded too early is read from `scriptsAtLoad` by the person running it.

- [ ] **Step 1: Write the loader** in `assets/js/lazy-embeds.js`, plain ES2017, no dependencies, wrapped in an IIFE and a `try`/`catch` so a failure leaves the fallbacks as they are. The behaviour is the spec's "In the browser: the loader" section, steps 1 to 8. The decisions it can't infer:
  - The marker attribute for a hidden Instagram or X embed is `data-mpm-embed="<network>"`. For a Pinterest pin, `data-pin-do`'s value moves to `data-mpm-pin-do` and is restored from there.
  - One IntersectionObserver, `rootMargin: '200px 0px'`, for every network from page load. X also gets a `'1600px 0px'` observer once a 1px mark at the top of the page leaves the screen (the reader scrolled). Each observer unobserves on first intersection, and an embed is shown once.
  - One promise per network for the script load, deleted on `error` so a later embed retries.
  - Already loaded means `window.instgrm && window.instgrm.Embeds`, `window.twttr && window.twttr.widgets`, `window.PinUtils && window.PinUtils.build`. TikTok has no check.
  - Render calls: `instgrm.Embeds.process()`, `twttr.ready( t => t.widgets.load( el.parentNode ) )`, `PinUtils.build( el.parentNode )`. TikTok has none: its script renders all TikTok embeds when it loads.
  - X widgets that keep their class (`a.twitter-timeline`, `a.twitter-follow-button`, `a.twitter-share-button`, `a.twitter-hashtag-button`, `a.twitter-mention-button`) are observed, and on intersection load the script and call the X render.
  - No `integrity` attribute on the scripts it adds.

- [ ] **Step 2: Lint**

Run: `npx wp-scripts lint-js assets/js/lazy-embeds.js`
Expected: no errors.

- [ ] **Step 3: Write `bin/check-embeds.mjs`.** Start from the spike's `scroll.mjs` (path in the build prompt). Headless Chromium, 412 by 823 viewport, mobile. Count layout shift with a `PerformanceObserver` on `layout-shift` entries without recent input. Scroll the whole page in 400px steps with a 350ms pause, then wait 4 seconds.

- [ ] **Step 4: Run it** on a local site with the plugin symlinked (sites and post IDs are in the build prompt): one post with one Instagram embed, one with five Instagram embeds, one with X embeds, and the X post again with `widgets.js` already loaded in the `<head>` (Review Focus 4). For that last one, add a throwaway mu-plugin to the local site that prints `<script async src="https://platform.twitter.com/widgets.js"></script>` in `wp_head` when `?mpm_head_widgets=1` is in the URL, and delete it after the check.

Run: `npm run check:embeds -- <urls>`
Expected: exit 0. No embed script at page load on the posts whose first embed is below the first screen, every Instagram and X embed rendered, `cls` 0.1 or under at the default pace, and on the head fixture `widgets.js` requested once.

- [ ] **Step 5: Check Pinterest in a real browser.** Start `~/.agents/scripts/bg-chrome`, connect Playwright with `chromium.connectOverCDP( 'http://127.0.0.1:9333' )`, open the Pinterest post from the build prompt in a new tab from `browser.contexts()[0]`, scroll it, and compare the count of rendered pin elements (`span[data-pin-href]`) with the plugin's filter returning false and true. Run `~/.agents/scripts/bg-chrome stop` afterwards.
Expected: the same count both ways, and `pinit.js` requested once with the feature on.

- [ ] **Step 6: Commit**

```bash
git add assets/js/lazy-embeds.js bin/check-embeds.mjs package.json package-lock.json
git commit -m "Load each embed's script as it nears the screen"
```

### Task 3: Rename to Mai Performance Media

**Files:** every file `rg -l 'mai-performance-images|mai_performance_images|PerformanceImages|Mai Performance Images' -g '!vendor' -g '!tests/vendor' -g '!node_modules' -g '!docs'` lists, plus `vendor/composer/autoload_*`, `build/`, and the main file, which is renamed.

**Interfaces:**
- Produces: namespace `Mai\PerformanceMedia` (tests: `Mai\PerformanceMedia\Tests`), main file `mai-performance-media.php`, text domain `mai-performance-media`, filters `mai_performance_media_eager_count`, `mai_performance_media_entry_loading`, `mai_performance_media_lazy_embeds`.

- [ ] **Step 1: Rename the main file** with `git mv mai-performance-images.php mai-performance-media.php`. Header: `Plugin Name: Mai Performance Media`, `Version: 1.0.0`, `Text Domain: mai-performance-media`, and a description covering images and embeds in one plain sentence, for example: "Loads each page's main image right away, lazy loads the rest, and loads social embeds as readers scroll to them." Delete `remove_conversion_leftovers()`, `remove_settings_leftovers()`, their `admin_init` actions and `MAI_PERFORMANCE_IMAGES_VERSION`, and delete `tests/integration/OptionsTest.php`.

- [ ] **Step 2: Rename everything else** listed above, by the Interfaces names: namespaces, `use` lines, the two filters (code and tests), the text domain in PHP and `src/block-settings.js`, the script handle in `classes/class-image-loading.php`, the ACF field group key, title and accordion label in `classes/class-mai-engine.php` (keep every `field_63f9...` key and every field `name`), the updater in `classes/class-updater.php` (`https://github.com/maithemewp/mai-performance-media/`, `mai-performance-media.php`, slug `mai-performance-media`), `composer.json` name and PSR-4 namespace, `tests/composer.json`, `tests/plugin-loader.php`, `tests/phpunit.xml.dist`, `tests/wp-tests-config.php` (`mai_performance_media_tests`), and README. Leave `data-mpi-loading` and the `?mpi=` cache-buster as they are. Leave `docs/` alone: dated documents keep the names they had.

- [ ] **Step 3: Rebuild** with `composer dump-autoload --no-dev`, `composer update --lock -d tests` (the lock's content hash covers the package name), and `npm run build`.

- [ ] **Step 4: Check nothing old is left**

Run: `rg -n 'mai-performance-images|mai_performance_images|PerformanceImages|Mai Performance Images' -g '!docs' -g '!CHANGES.md' -g '!tests/vendor' -g '!node_modules' .`
Expected: no output. (The test database must exist under its new name first: `mysql -e 'CREATE DATABASE IF NOT EXISTS mai_performance_media_tests'`.)

Run: `composer test`
Expected: all tests pass (132, minus the 2 in `OptionsTest`, plus Task 1's).

- [ ] **Step 5: Docs.** `CHANGES.md` gets a `## 1.0.0` entry: the rename (with the old and new slug, and a line that saved settings carry over), the embed feature, and the dropped cleanup. Plain sentences, no em-dashes, same shape as the existing entries. `TODO.md` gets the three lines from the spec's TODO.md section. `STATE.md` is rewritten for the new state.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Rename to Mai Performance Media, 1.0.0"
```

### Task 4: Measure before release

No code. Repeat the spike's Lighthouse comparison with the real plugin, using the spike's harness (path in the build prompt). Point it at the filter instead of `?embeds=`: add a `mai_performance_media_lazy_embeds` toggle on a query var in a local mu-plugin for the run only, and delete that mu-plugin afterwards.

- [ ] **Step 1:** On two local sites from the build prompt, one multi-embed post each, ads blocked, 5 runs each of feature off and on, simulated throttling. Then the same with `throttlingMethod: 'devtools'`.
Expected: embed bytes and embed main-thread time near 0 with the feature on, and the same direction as the spike (spec, "What the spike measured"). If the feature on is worse on any median, stop and report.

- [ ] **Step 2:** Add the medians to `STATE.md` under Verify, with the date and Lighthouse version. Commit.

### Task 5: The swap runbook

**Files:**
- Create: `docs/runbooks/2026-11-17-swap-to-mai-performance-media.md`

- [ ] **Step 1: Write the runbook.** Steps, in order, each with its exact command and how to check it worked:
  1. Rename the GitHub repo from `maithemewp/mai-performance-images` to `maithemewp/mai-performance-media`, and point the local `origin` at the new URL. (Ask first.)
  2. Push, tag and release 1.0.0 from `main`. (Ask first.)
  3. For each site with the old plugin, from `mai-sites plugins list mai-performance-images`: install the new plugin (a `mai-sites push` of the new folder, or `wp plugin install` from the 1.0.0 release zip), then in one command deactivate the old plugin, activate the new one and delete the old one. A site where the old plugin was inactive only gets the old one deleted. Run one site at a time on the server the build prompt names.
  4. Check each site boots and serves a page with one `fetchpriority="high"` image.
  5. Rerun `mai-sites plugins list mai-performance-images` until it lists no site, and `mai-sites plugins list mai-performance-media` to see every site active.
  6. Update the outside references the spec lists (theme plugin lists, skills), and rename the local folder to `~/Plugins/mai-performance-media` with the local site symlinks pointed at it.
  No site names in the runbook; it refers to `mai-sites` targets.

- [ ] **Step 2: Commit** the runbook on `develop`. Do not push. Report and stop: everything after this needs a yes.

## Done when

- `composer test` passes, `npm run check:embeds` exits 0 on the build prompt's posts, the Pinterest check matches, and Task 4's numbers are in `STATE.md`.
- The renamed plugin is committed locally as 1.0.0, and the runbook is written. Nothing is pushed, tagged or created on GitHub.
- Run `/pr-review-toolkit:review-pr` on the branch before asking to push.
