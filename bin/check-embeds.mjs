/**
 * Scrolls pages on a phone-sized screen and reports how their social embeds loaded.
 *
 * Usage: npm run check:embeds -- [--block <file>] [--pace <ms>] <url> [<url>...]
 *
 * For each address it prints one JSON object:
 *
 * - scriptsAtLoad: embed scripts requested before scrolling starts, which is 8
 *   seconds after the page's HTML has loaded. Informational, for the person reading
 *   it, since an embed in the first screen loads its script there on purpose.
 * - scripts: every embed script requested, and when.
 * - unrendered: per network, embeds still showing their pasted fallback after
 *   scrolling.
 * - rendered: per network, embeds the network's script replaced with its own markup.
 * - cls: the worst session window of layout shift, as Chrome reports it. 0.1 or
 *   under is good, Google's line. shiftTotal is every shift added up.
 * - failures: why the page failed, empty when it passed.
 *
 * Add a cache-busting query, since a page cache serves the page from before the
 * plugin changed.
 *
 * Ads move the page as they fill in, which has nothing to do with embeds. To leave
 * them out, pass --block with a JSON file holding an array of hostnames, and every
 * request to those hosts is stopped.
 *
 * The page scrolls 400px at a time. --pace is the pause between steps in ms, 700 by
 * default (about 570px a second, a steady read). 350 is a fast flick, about 1140px a
 * second. Each result says which pace it used.
 *
 * Exits 1 when a page fails: it doesn't load or answers with HTTP 400 or above, it
 * has no embeds at all, an Instagram or X embed did not render, or cls is above 0.1.
 * Exits 2 when the arguments are wrong. TikTok and Pinterest are reported but not
 * judged: TikTok's own library can be down, and Pinterest only renders in a real
 * browser, not a headless one.
 */
/* eslint-disable no-console -- A command line check reports on the console. */
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const args = process.argv.slice( 2 );
const blockAt = args.indexOf( '--block' );
const blocked = new Set(
	-1 === blockAt
		? []
		: JSON.parse( readFileSync( args.splice( blockAt, 2 )[ 1 ], 'utf8' ) )
);
const paceAt = args.indexOf( '--pace' );
const pace = -1 === paceAt ? 700 : Number( args.splice( paceAt, 2 )[ 1 ] );
const urls = args;

if ( ! urls.length || ! ( pace > 0 ) ) {
	console.error(
		'Usage: npm run check:embeds -- [--block <file>] [--pace <ms>] <url> [<url>...]'
	);
	process.exit( 2 );
}

// Google's line for good layout shift.
const CLS_LIMIT = 0.1;

// The networks whose embeds have to render for a page to pass.
const JUDGED = [ 'instagram', 'x' ];

// The network libraries, matched on the address without its query.
const SCRIPTS =
	/^https?:\/\/(?:(?:www\.)?instagram\.com\/embed\.js|platform\.(?:twitter|x)\.com\/widgets\.js|(?:www\.)?tiktok\.com\/embed\.js|assets\.pinterest\.com\/js\/pinit\.js)$/;

const browser = await chromium.launch( { headless: true } );

for ( const url of urls ) {
	try {
		const result = await check( url );

		console.log( JSON.stringify( result, null, 2 ) );

		if ( result.failures.length ) {
			process.exitCode = 1;
		}
	} catch ( error ) {
		console.error( `${ url }: ${ error.message }` );
		process.exitCode = 1;
	}
}

await browser.close();

/**
 * Loads one page, scrolls all of it, and reads what rendered.
 *
 * @param {string} url The page to check.
 *
 * @return {Promise<Object>} The result.
 */
async function check( url ) {
	const context = await browser.newContext( {
		viewport: { width: 412, height: 823 },
		isMobile: true,
		hasTouch: true,
		ignoreHTTPSErrors: true,
		userAgent:
			'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36',
	} );

	try {
		const page = await context.newPage();
		const scripts = [];
		const start = Date.now();

		if ( blocked.size ) {
			await page.route( '**/*', ( route ) =>
				blocked.has( new URL( route.request().url() ).hostname )
					? route.abort()
					: route.continue()
			);
		}

		page.on( 'request', ( request ) => {
			const src = request.url().split( '?' )[ 0 ];

			if ( SCRIPTS.test( src ) ) {
				scripts.push( { src, ms: Date.now() - start } );
			}
		} );

		// Counted from the start, so shifts while the page loads are included too. CLS is
		// the worst session window, the way Chrome reports it: shifts less than 1 second
		// apart, in a window at most 5 seconds long. The plain total is kept alongside.
		await page.addInitScript( () => {
			const shifts = { total: 0, worst: 0, window: 0, first: 0, last: 0 };

			window.__mpmShifts = shifts;

			new window.PerformanceObserver( ( list ) => {
				for ( const entry of list.getEntries() ) {
					if ( entry.hadRecentInput ) {
						continue;
					}

					const time = entry.startTime;

					if (
						shifts.window &&
						time - shifts.last < 1000 &&
						time - shifts.first < 5000
					) {
						shifts.window += entry.value;
					} else {
						shifts.window = entry.value;
						shifts.first = time;
					}

					shifts.last = time;
					shifts.total += entry.value;
					shifts.worst = Math.max( shifts.worst, shifts.window );
				}
			} ).observe( { type: 'layout-shift', buffered: true } );
		} );

		const response = await page.goto( url, {
			waitUntil: 'domcontentloaded',
			timeout: 60000,
		} );

		// A missing page or a login screen has no embeds to judge.
		if ( response && response.status() >= 400 ) {
			throw new Error( `HTTP ${ response.status() }` );
		}

		// Long enough for the page's own scripts to run before scrolling starts.
		await page.waitForTimeout( 8000 );

		const scriptsAtLoad = scripts.length;
		const height = () =>
			page.evaluate( () => document.documentElement.scrollHeight );

		// The height is read again on every step, because rendered embeds and ads make
		// the page longer as it scrolls. The step cap stops a page that never ends.
		for (
			let y = 0, step = 0;
			y < ( await height() ) && step < 250;
			step++
		) {
			await page.evaluate( ( top ) => window.scrollTo( 0, top ), y );
			await page.waitForTimeout( pace );
			y += 400;
		}

		await page.waitForTimeout( 4000 );

		const found = await page.evaluate( () => {
			const count = ( selector ) =>
				document.querySelectorAll( selector ).length;

			return {
				// A fallback the loader hid, or one given back that never rendered.
				unrendered: {
					instagram: count(
						'blockquote.instagram-media, [data-mpm-embed="instagram"]'
					),
					x: count(
						'blockquote.twitter-tweet, blockquote.twitter-video, [data-mpm-embed="x"]'
					),
					// Expected: TikTok fills its blockquote with an iframe rather than
					// replacing it. Not seen yet, since TikTok's library was down.
					tiktok: [
						...document.querySelectorAll(
							'blockquote.tiktok-embed'
						),
					].filter( ( embed ) => ! embed.querySelector( 'iframe' ) )
						.length,
					pinterest: count( 'a[data-pin-do], a[data-mpm-pin-do]' ),
				},
				rendered: {
					instagram: count( 'iframe.instagram-media' ),
					x: count( 'iframe[id^="twitter-widget"]' ),
					tiktok: count( 'iframe[src*="tiktok.com"]' ),
					pinterest: count( 'span[data-pin-href]' ),
				},
				cls: Math.round( window.__mpmShifts.worst * 1000 ) / 1000,
				shiftTotal:
					Math.round( window.__mpmShifts.total * 1000 ) / 1000,
			};
		} );

		return {
			url,
			pace,
			scriptsAtLoad,
			scripts,
			...found,
			failures: failures( found ),
		};
	} finally {
		await context.close();
	}
}

/**
 * Lists why a page failed, from what the check found.
 *
 * @param {Object} found What rendered, what did not, and the layout shift.
 *
 * @return {string[]} The reasons, empty when the page passed.
 */
function failures( found ) {
	const reasons = [];
	const names = Object.keys( found.unrendered );
	const total = names.reduce(
		( sum, name ) =>
			sum + found.unrendered[ name ] + found.rendered[ name ],
		0
	);

	if ( ! total ) {
		reasons.push( 'no embeds found' );
	}

	// One embed left as a fallback fails the page, so a network with embeds and
	// none rendered fails too.
	JUDGED.forEach( ( name ) => {
		const left = found.unrendered[ name ];

		if ( left ) {
			reasons.push(
				`${ name }: ${ left } of ${
					left + found.rendered[ name ]
				} not rendered`
			);
		}
	} );

	if ( found.cls > CLS_LIMIT ) {
		reasons.push( `cls ${ found.cls } is above ${ CLS_LIMIT }` );
	}

	return reasons;
}
