# State
Updated: November 18, 2026 by Claude

## Now
1.0.0, Mai Performance Media, is released and on every site. On November 18 the GitHub repo was renamed to `maithemewp/mai-performance-media`, `main` and `develop` were pushed, and tag 1.0.0 was pushed (tags only, no GitHub release, as before). 64 sites were swapped with `mai-sites`: 59 now run 1.0.0 active, and 5 that had the old plugin inactive only had it deleted. `mai-sites plugins list mai-performance-images` lists no site; 3 sites on one server never answered and are left out on purpose. Spec: `docs/specs/2026-11-17-lazy-embeds.md`. Plan: `docs/plans/2026-11-17-lazy-embeds.md`. Runbook: `docs/runbooks/2026-11-17-swap-to-mai-performance-media.md`.

- Instagram, X, TikTok and Pinterest embed scripts are delayed and each loads as its first embed nears the screen (`classes/class-embed-loading.php`, `assets/js/lazy-embeds.js`). Filter `mai_performance_media_lazy_embeds` turns it off. If the loader can't be read or printed, the page is left as it came.
- Loader margins: 200px for every network at rest, and X also from 1600px once the reader starts scrolling. At 200px alone, fast-scroll CLS had a median of 0.166 on 8 X posts. With the split, no sampled post loaded a script at page load, and every check at the 700ms pace scored cls 0.
- Renamed: namespace `Mai\PerformanceMedia`, main file `mai-performance-media.php`, slug and text domain `mai-performance-media`, filters `mai_performance_media_*`. The local folder is `~/Plugins/mai-performance-media` and the 16 local Herd links point at it. The 0.7.0 and 0.9.0 cleanup code is gone.
- Kept on purpose: `data-mpi-loading`, the `?mpi=` cache-buster, the `imgLoading` block attribute, the `image_loading` and `image_loading_count` settings, and every `field_63f9...` ACF key.

## Next
- Check one TikTok post in a real browser once TikTok's embeds work again (TODO.md).
- Update the outside references, runbook step 6: the company theme's plugin list, client themes' `setup/design.json` plugin lists, the two skills, and Hindsight.
- In a few weeks, compare real-user CLS and LCP on two embed-heavy sites (Chrome's field data and the ad network's Core Web Vitals report), especially X-heavy posts.
- Remove the `mai_publisher_html` late pass once https://github.com/bizbudding/mai-publisher/issues/62 lands.
- Check mai-content-areas on real block theme content.

## Blocked / waiting on
- Nothing.

## Verify
- `composer test` and `npx wp-scripts lint-js`. The test database is `mai_performance_media_tests`.
- `npm run check:embeds -- --block <hosts.json> <url>...` with a `?mpi=<random>` query: exit 0, every Instagram and X embed rendered, `scriptsAtLoad` 0 on posts with no embed in the first screen.
- Pinterest, November 17, background Chrome on a post with pasted pins that still exist: 63 pins rendered with the feature off and 63 with it on, and `pinit.js` loaded once.
- Fetch a page with `curl -skL` plus a `?mpi=<random>` query, because a page cache serves cached pages otherwise. Expect `id="mai-performance-media-lazy-embeds"` on a page with embeds and exactly one `fetchpriority="high"`.
- Local Herd sites use a symlink to this folder named `mai-performance-media`.
- Lighthouse 13.6.0 on 2026-11-17, mobile, ads blocked, 5 runs each, feature off then on, medians with ranges. Off to on, same direction as the spike. Embed bytes and embed main-thread time fell to 0 on both posts in every run.
  - Five-Instagram-embed post, simulated throttling:
    - Score 91 (90 to 92) to 93 (93 to 94). LCP 1715ms to 1715ms. TBT 63ms to 63ms.
    - Main thread 951ms (906 to 1208) to 838ms (773 to 905). Total bytes 4.51 MB to 2.54 MB.
    - Embed bytes 1.96 MB to 0. Embed main thread 109ms to 0. CLS 0 to 0.
  - Five-Instagram-embed post, devtools throttling:
    - Score 81 (72 to 82) to 81 (77 to 82). LCP 4201ms (4134 to 5095) to 4100ms (3993 to 4412). TBT 0 to 0.
    - Main thread 1039ms to 816ms. Total bytes 4.40 MB to 2.51 MB.
    - Embed bytes 1.89 MB to 0. Embed main thread 110ms to 0. CLS 0 to 0.
  - Seven-embed post, simulated throttling:
    - Score 81 (80 to 84) to 83 (82 to 87). LCP 3667ms (3143 to 3819) to 3669ms (3216 to 3814), a tie. TBT 62ms to 62ms.
    - Main thread 1326ms to 1041ms. Total bytes 3.29 MB to 2.60 MB.
    - Embed bytes 684 KB to 0. Embed main thread 271ms to 0. CLS 0 to 0.
  - Seven-embed post, devtools throttling:
    - Score 88 (77 to 91) to 88 (87 to 91). LCP 3024ms (2754 to 4561) to 2956ms (2757 to 3096). TBT 44ms (40 to 59) to 8ms (0 to 12).
    - Main thread 1366ms to 999ms. Total bytes 3.31 MB to 2.62 MB.
    - Embed bytes 683 KB to 0. Embed main thread 266ms to 0. CLS 0.064 to 0.064, the same in every run, so not from embeds.
  - Raw runs were kept outside the repo. The devtools run for the seven-embed post is 4 alternating pairs plus 1 pair run afterwards, because the first run was stopped by a time limit.

## Gotchas
- Parallel `mai-sites run` WP-CLI commands hit database connection errors on one shared server. Run one site at a time.
- TikTok's embeds were broken on TikTok's side on November 17, so TikTok rendering after a delayed load is unverified. Pinterest renders only in a real browser: check it in background Chrome, never in front.
- A site the swap misses gets no warning: the updater only offers an update to a main file with the installed name, so an old install stays on 0.9.1.
- The image walk keeps at most 8 logos or small images waiting for a slot below them, because the tag processor allows 10 bookmarks.
