# State
Updated: November 17, 2026 by Claude

## Now
1.0.0, Mai Performance Media, is committed locally on `develop`. Nothing is pushed, tagged or renamed on GitHub. Spec: `docs/specs/2026-11-17-lazy-embeds.md`. Plan: `docs/plans/2026-11-17-lazy-embeds.md`.

What 1.0.0 holds:
- Instagram, X, TikTok and Pinterest embed scripts are delayed and each loads as its first embed nears the screen (`classes/class-embed-loading.php`, `assets/js/lazy-embeds.js`). Filter `mai_performance_media_lazy_embeds` turns it off.
- Loader margins: 200px for every network at rest, and X also from 1600px once the reader starts scrolling. The margin study: at 200px alone, fast-scroll CLS had a median of 0.166 on 8 X posts. With the split, no sampled post loaded a script at page load, and at the check's default 700ms pace every check scored cls 0.
- The plugin is renamed: namespace `Mai\PerformanceMedia`, main file `mai-performance-media.php`, slug and text domain `mai-performance-media`, filters `mai_performance_media_*`. The folder and GitHub repo keep the old name for now.
- The 0.7.0 and 0.9.0 cleanup code is gone, since both were forced on every site and rechecked clean.
- Kept on purpose: `data-mpi-loading`, the `?mpi=` cache-buster, the `imgLoading` block attribute, the `image_loading` and `image_loading_count` settings, and every `field_63f9...` ACF key.

How images are decided is unchanged since 0.9.1. See `docs/specs/2026-10-14-one-main-image.md`.

## Next
- Task 5: the runbook in `docs/runbooks/` for swapping each site to the new plugin one at a time.
- Rename the GitHub repo and the local folder by hand, then push and tag 1.0.0 only when asked.
- Update outside the repo once it ships: the `bizbudding-two` theme plugin list, client `setup/design.json` lists, and the two skills that name the plugin.
- Remove the `mai_publisher_html` late pass once https://github.com/bizbudding/mai-publisher/issues/62 lands.
- Check mai-content-areas on real block theme content.

## Blocked / waiting on
- Nothing.

## Verify
- `composer test` and `npx wp-scripts lint-js`. The test database is `mai_performance_media_tests`.
- Fetch a page with `curl -sk` plus a `?mpi=<random>` query, because a page cache serves cached pages otherwise. Expect `id="mai-performance-media-lazy-embeds"` on a page with embeds and exactly one `fetchpriority="high"`.
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
- Parallel `mai-sites run` WP-CLI commands hit database connection errors on one shared server. Run each server one site at a time.
- TikTok's embeds were broken on TikTok's side on November 17, so TikTok rendering after a delayed load is unverified.
- Pinterest renders only in a real browser. Check it in background Chrome, never in front.
- A site the swap misses gets no warning: the updater only offers an update to a main file with the installed name, so an old install stays on 0.9.1.
- The walk keeps at most 8 logos or small images waiting for a slot below them, because the tag processor allows 10 bookmarks.
- Requests that never reach the page buffer (REST, feeds, Ajax) are left to WordPress, plus the editor's and Mai entry's choices.
