# Lazy load social embeds, and rename to Mai Performance Media

Date: 2026-11-17. Status: approved 2026-11-17.

## Why

Pasted Instagram, X, TikTok and Pinterest embeds are a `<blockquote>` (a link, for Pinterest) plus a `<script async>` for the network's library. `loading="lazy"` can't apply to a script, so the library downloads and runs at page load wherever the embed sits. On a post with one Instagram embed that is about 530ms of main-thread time and 1.5 MB on a mobile page view. Embeds are common on the sports, news and entertainment sites that run this plugin: on local copies, 72%, 41%, 28%, 25% and 89% of published posts on five of them carry one.

The plugin already decides image loading on the finished page, and since 0.9.1 keeps WordPress's iframe lazy loading working for YouTube and Vimeo. Script-based embeds are the remaining media that loads up front for no reason. Once the plugin covers them it is no longer only about images, so it is renamed in the same release.

## What the spike measured

A throwaway mu-plugin on local copies of six sites (not in this repo) delayed the embed scripts and loaded them with an IntersectionObserver. Lighthouse 13.6, mobile.

- Where embeds sit: on 200 random embed posts at a 412 x 823 viewport, the first embed starts 1.4 screens down (10th percentile 1.3, 90th 3.4). None was in the first screen. Measured without ads, which only push embeds lower.
- Margin: loading 1500px early still loaded the script at page load on most posts. 200px deferred it on every test post.
- Savings with ads blocked, simulated throttling, 5 runs, 12 posts on 6 sites: 0.5 to 2 MB less per page (15 to 45%), 10 to 25% less main-thread work, score change 0 to +3.
- Savings with ads on (one site, 10 runs): the same milliseconds and megabytes, but ads take 11 to 13 seconds of main-thread time, so the score did not move.
- Real throttling (devtools, ads blocked, 3 runs, 3 posts): on one site LCP fell from 8.4s to 6.5s, because 2 MB of embed downloads were competing with the main image on a slow connection. On another, blocking time fell from 72ms to 0. With 8 runs each on those two posts: on the first, LCP went from 8.4s to 7.0s (median; ranges 7.1 to 13.5s and 6.4 to 9.2s) and the score from 57.5 to 60.5; on the second, blocking time went from 80ms to 6ms (ranges 52 to 208ms and 0 to 19ms), score 77 to 78.
- It works: every Instagram and X embed rendered while scrolling, with a layout shift of 0 in every scroll test and every Lighthouse run.
- TikTok did not render anywhere, even on the untouched page and in a real browser: TikTok's own library was returning 404 that day. Not verified.
- Pinterest renders only in a real browser. In background Chrome it rendered every pin with the feature on.

So this is a data and CPU saving that can help LCP on slow connections. It is not a fix for Lighthouse scores on ad-heavy pages, where ads dominate.

## Goals

- Instagram, X, TikTok and Pinterest embed libraries stop loading at page load. Each loads when its first embed comes within 200px of the screen.
- Each embed renders as it nears the screen, not all at once when the first one does. About a quarter of embed posts have two or more.
- One copy of each library per page, however many pasted copies the content carries.
- The pasted fallback stays visible until the embed renders, and nothing shifts.
- On by default, no settings, one filter to turn it off.
- Works with Mai Embed Fixer's output without either plugin knowing about the other.

## Non-goals

- Facebook: its 414 embed posts on the measured site are all `facebook.com/plugins/post.php` iframes, which already lazy load.
- YouTube and Vimeo click-to-load facades. A TODO maybe.
- Embeds added by JavaScript after the page loads, such as infinite scroll. Their scripts load as they do today.
- Blockquotes with no script on the page (140 Instagram posts on the measured site show only the fallback today). The loader only handles networks whose script it delayed, so those pages stay exactly as they are.

## Anti-goals

- An embed that never renders. Any failure must leave the reader with what they get today when a blocker stops the script: the fallback blockquote and its link.
- Breaking another X widget. `widgets.js` also renders timelines, follow and share buttons.
- Touching the saved post content, or any page without an embed script.

## Approaches considered

1. Load each network's script when its first embed nears the screen, and let the script render every embed of that type at once. Simplest, and touches no embed markup. Rejected: a five-embed post loads five iframes when the first one nears.
2. Delay the scripts, take the network's class off each embed in the browser, and give it back as each one nears, then ask the network to render it. Chosen. Instagram and X each have a documented render call: `instgrm.Embeds.process()` ([Meta's Instagram oEmbed docs](https://developers.facebook.com/docs/instagram-platform/oembed)) and `twttr.widgets.load( element )`. Another project's comment embeds use the same render calls, but take the class off on the server, and have not run in production.
3. Click to load. Rejected: readers have to act, and the spike showed loading near the screen is cheap.

Taking the class off in the browser, rather than on the server, keeps the page HTML and any page cache unchanged apart from the script tags, and means a visitor without JavaScript sees exactly what they would today, since the network script would not run for them either.

## Design

### On the server: one finished-page pass

A new class, `EmbedLoading` in `classes/class-embed-loading.php`, adds its own callback to `wp_template_enhancement_output_buffer`, the same filter `LoadingAttributes` uses, from `wp_before_include_template` like `LoadingAttributes::add_page_filter()`. It does not share the image walk: the image walk only visits `img` tags and is careful about bookmarks, and the two jobs have nothing to share.

1. Return the page untouched when the filter `mai_performance_media_lazy_embeds` returns false, or when a quick `stripos` finds none of the script paths.
2. Walk every `script` tag with `WP_HTML_Tag_Processor`. A tag whose `src` matches a known library is delayed: `src` is removed, which leaves an empty script that runs nothing, and the network is noted. The tag processor can't delete a tag, so this is the in-place way to do it. The spike confirmed it.
3. If any network's script was delayed, add the loader once, just before the last `</body>`, with `wp_get_inline_script_tag()` so a site's script attribute filters (such as a CSP nonce) apply. With no `</body>`, append it. The networks delayed are passed to the loader, so it only handles those.

Known libraries, matched on the `src` with or without a protocol:

- Instagram: `instagram.com/embed.js`
- X: `platform.twitter.com/widgets.js` and `platform.x.com/widgets.js` (pasted embeds switched to the second domain; 2 of 761 on the measured site so far)
- TikTok: `tiktok.com/embed.js`
- Pinterest: `assets.pinterest.com/js/pinit.js`, but only on a page that has a pasted pin (`a[data-pin-do]`), and only a tag with no `data-pin-*` attributes of its own. `pinit.js` also runs Pinterest's hover and save buttons, which share plugins configure with `data-pin-hover` and similar attributes on the script tag; those copies load as they do today.

The Mai Publisher late pass (`LATE_FILTERS`) is not used. Mai Publisher inserts ads and sidebar content, not embeds. My call; if that turns out wrong, `EmbedLoading` can add the same late filter.

### In the browser: the loader

About 1 KB, written as a plain readable file at `assets/js/lazy-embeds.js` that the pass reads and prints inline. Inline because it has to be on the page the moment the pass knows there is an embed, which is too late to enqueue a file, and it saves a request. It is linted with the existing `wp-scripts lint-js`.

1. For each delayed Instagram or X network, find its embeds (`blockquote.instagram-media`, `blockquote.twitter-tweet`, `blockquote.twitter-video`). Take the class off, note the network in a data attribute, and observe it. TikTok embeds keep their class and are only observed (step 5).
2. Pinterest pins (`a[data-pin-do]`) are handled like Instagram and X, but the marker is the `data-pin-do` attribute rather than a class: its value is moved to a data attribute of the loader's own and put back when the pin nears. Rendering uses `PinUtils.build( pin.parentNode )`, which looks for pins inside the element it is given (read in Pinterest's `pinit_main.js`; not found in Pinterest's documentation). Tested in real Chrome on a post with 6 pasted copies of `pinit.js`: one copy loaded, and every pin rendered as it neared the screen.
3. X's other widgets (`a.twitter-timeline`, `a.twitter-follow-button`, `a.twitter-share-button`, `a.twitter-hashtag-button`, `a.twitter-mention-button`) are observed too but keep their class. When one nears, the script loads and renders it as it would today.
4. One IntersectionObserver with a 200px margin, the measured value. When an embed comes within it: give its class back, load the network's script once (one shared promise per network, forgotten on failure so a later embed can retry), then render that embed. Every embed is rendered explicitly, the first one too, rather than relying on the library's own scan when it loads. That avoids a race with embeds that get their class back while the script is still loading, and rendering an embed that already rendered is a no-op because it no longer matches. Instagram: `instgrm.Embeds.process()`. X: `twttr.ready( t => t.widgets.load( embed.parentNode ) )`, since X only looks inside the element it is given and `twttr.ready` waits until it can render.
5. The script loaded is one fixed https URL per network, the same ones Mai Embed Fixer uses (`https://www.instagram.com/embed.js`, `https://platform.twitter.com/widgets.js`, `https://www.tiktok.com/embed.js`, `https://assets.pinterest.com/js/pinit.js`), not whichever pasted copy was delayed. If the network is already loaded when an embed nears (`window.instgrm.Embeds`, `window.PinUtils.build`, or `window.twttr.widgets`; X's own snippet creates an empty `twttr` early, so `twttr` alone is not enough), skip loading and go straight to rendering.
6. TikTok has no documented render call, so its embeds keep their class. Its script loads when the first TikTok embed nears, and renders every TikTok embed on the page at once (approach 1, for TikTok only). Decided: TikTok is delayed in v1 even though rendering can't be checked. On 2026-11-17 TikTok's embeds were broken on TikTok's side for every visitor (its library at `lf16-tiktok-web.ttwstatic.com/.../embed_lib_v1.0.15.js` returned 404), so whether TikTok renders when its script starts late is unverified. A `TODO.md` line covers checking one post once TikTok works again; if it doesn't render, stop delaying TikTok in a follow-up release.
7. Nothing else: no polling, no scroll listeners, no MutationObserver.
8. No `integrity` hashes on the scripts it adds. The networks change these files without notice, so a hash would block them (the same reason Mai Embed Fixer has none).

Behaviour in the edge cases:

- An embed in the first screen: the observer fires as soon as the loader runs, at the end of the page, so the script starts a little later than it does today. None of the 200 sampled posts had one there.
- JavaScript off: the delayed scripts would not have run anyway, so the page is the same as today.
- The script blocked or failing: the fallback stays. Same as today.
- The loader failing: the fallback stays, and the embeds never render. This is the one new way to fail, which is why the loader stays small, has no dependencies, and catches its own errors.

### Theme styles on the fallback

Themes style these classes directly (seven of the local copies checked center them, some with `!important`). While the class is off, an Instagram or X fallback loses that styling, and an X fallback, which has no inline styles, picks up Mai Engine's quote styling instead: italic, a left border and padding. Readers rarely see it, because the class comes back 200px before the embed reaches the screen. Accepted, my call. Not used: taking `data-instgrm-permalink` off Instagram embeds instead of the class, which that other project does; it would keep Instagram's styling but is untested and does nothing for X.

### Other plugins' script filters

The loader goes through `wp_get_inline_script_tag()`, so `wp_inline_script_attributes` can change it. A consent or JavaScript-delay plugin could retype or delay it, in which case embeds wait for that plugin, which is still no worse than today. Nothing to build; noted so a report of late embeds on one site has an obvious first suspect.

### Layout shift

No space is reserved. Embeds start loading 200px before they reach the screen, Instagram's pasted fallback carries its own sizing, and every measurement showed a layout shift of 0. My call, from the measurements. If real-user CLS rises after release, a `min-height` per network is the fix.

**Amended 2026-11-17.** In the build's measurements X embeds shifted the page on fast scrolls at 200px. The spike's zero was measured at 1500px. So X also loads from 1200px once the reader starts scrolling, and every network keeps 200px while the page is at rest. A fixed height on X fallbacks is ruled out, because tweet heights vary too much. If real-user CLS rises on X posts after release, the next step is chosen from field data, not a fixed number.

### Mai Embed Fixer

No changes needed in either plugin. The fixer writes one script tag (`<script async class="mai-instagram-script" src="//www.instagram.com/embed.js" charset="utf-8">`, and the X equivalent); the pass delays it like any other. A test uses that exact tag. Separately, the fixer's duplicate removal misses `platform.x.com/widgets.js`; that is a fixer TODO, not this work.

## The rename

The plugin becomes Mai Performance Media in the same release.

- Folder and main file: `mai-performance-media/mai-performance-media.php`.
- Namespace: `Mai\PerformanceMedia`, in `composer.json` and the test namespaces. Class names stay. The committed `vendor/composer/autoload_*` files are rebuilt with `composer dump-autoload --no-dev`, which the deployable workflow checks.
- Filters: `mai_performance_images_eager_count` and `mai_performance_images_entry_loading` become `mai_performance_media_*`. Nothing outside the plugin uses them (searched every local plugin, theme and mu-plugin).
- Text domain `mai-performance-media`, including in `src/block-settings.js`, so `build/` is rebuilt.
- The block settings script handle (`class-image-loading.php:53`), the grid block's ACF field group key, title and accordion label (`class-mai-engine.php:167-171`).
- The updater's repo URL, file and slug (`class-updater.php:35`).
- `tests/composer.json`, `tests/plugin-loader.php`, `tests/wp-tests-config.php` (database name and title), README, `mai-sites.json`, and the local Herd symlinks.
- Description: rewritten to cover images and embeds.
- The 0.7.0 and 0.9.0 cleanup code (`remove_conversion_leftovers()`, `remove_settings_leftovers()`, and the version constant they use) is dropped. On 2026-11-17 both were forced across every site with `mai-sites run`, and a recheck found the conversion marker on every site with the plugin active and the old settings option on none.
- Version 1.0.0.

What does not change: the block attribute `imgLoading`, the Customizer and Mai grid settings `image_loading` and `image_loading_count`, and the grid block's ACF field keys (`field_63f9...`), which are what saved settings are stored under. The field group key does contain the old name, but nothing is saved under it, so renaming it loses nothing. Saved settings carry over untouched. The temporary `data-mpi-loading` marker and the `?mpi=` cache-buster used when checking pages keep their names; they are internal.

Outside the repo, to update once it ships:

- `bizbudding-two` theme, `includes/ajax.php` line 40: the GitHub URL in its plugin list.
- Client themes with a `setup/design.json` plugin list: the plugin path. At least one local copy has it; others may.
- Skills: `~/.agents/skills/mai-performance-images/SKILL.md` (rename it) and `~/.agents/skills/mai-plugin-scaffold/SKILL.md` (mentions the plugin).
- The Hindsight `mai::plugins` mapping picks up the new folder through the daily updater.

Every site is swapped with `mai-sites`: install the new plugin, then deactivate the old one and activate the new one in a single command so the two never run together, then delete the old one. That procedure goes in `docs/runbooks/`. Run it one site at a time on the server named in the build prompt: parallel WP-CLI runs there hit "Error establishing a database connection" on 9 of 21 sites on 2026-11-17, while the same command one site at a time worked on all of them.

A site the swap misses gets no warning. The updater only offers an update when the repo holds a main file with the installed one's name (`plugin-update-checker/Puc/v5p7/Vcs/PluginUpdateChecker.php:81`), so once the repo holds `mai-performance-media.php` an old install stays on 0.9.1 quietly. Decided: the existing repo is renamed on GitHub from `maithemewp/mai-performance-images` to `maithemewp/mai-performance-media`, and the plugin is renamed in it. No new repo, no last release of the old plugin, no notice, nothing archived. Every site is swapped by hand with `mai-sites`, so no site relies on the updater to move. Sites are swapped with a `mai-sites` push or run that installs the new plugin and deletes the old one, and `mai-sites plugins list mai-performance-images` is rerun until it lists no site.

## Testing

- Integration tests in `tests/integration/EmbedLoadingTest.php`, in the style of `IframeLoadingTest.php`: each library and both X domains, with and without a protocol, are delayed; `pinit.js` is delayed only on a page with a pasted pin and never when its tag carries `data-pin-*` attributes; the fixer's exact tag is delayed; the loader is added once, before `</body>`, only when something was delayed; other scripts and the blockquotes are untouched; the filter turns it all off.
- A browser check kept in the repo (the spike's scroll script, tidied up), run headless against a local site: every Instagram and X embed on a one-embed and a multi-embed post renders while scrolling, no script loads at page load, layout shift stays 0.
- Pinterest renders only in a real browser, so it is checked in background Chrome (`~/.agents/scripts/bg-chrome`, never in front): on the local copy with pasted pins named in the build prompt, post 34819, the same pins render with and without the feature, and `pinit.js` loads once. Some old pasted pins were deleted on Pinterest and show as empty links either way; post 34873 is one.
- Before release, the same Lighthouse comparison as the spike on two sites, ads blocked, with both simulated and real (`devtools`) throttling.
- After release, Chrome's real-user data and Mediavine's Core Web Vitals report on two embed-heavy sites, a few weeks later.

## TODO.md

- Add: check one TikTok post in a real browser once TikTok's embeds work again; stop delaying TikTok if it doesn't render.
- Add: Mai Embed Fixer's duplicate removal misses `platform.x.com/widgets.js` (belongs in that plugin's TODO).
- Add: maybe a YouTube and Vimeo click-to-load facade.
- Remove nothing: the Mai Publisher and mai-content-areas lines stay.

## Left to the builder

- The exact loader code, within the shape above.
- How the networks delayed are passed to the loader (a small JSON object in the inline script is fine).
- Test fixture markup, taken from real pasted embeds.

## Open questions

None. Decided while walking the spec on 2026-11-17: separate from Mai Embed Fixer, each embed rendered as it nears, the full rename by renaming the existing repo, with every site swapped by hand, the old cleanup code dropped, TikTok delayed in v1 though unverified, Pinterest in v1.
