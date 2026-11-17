/**
 * Scrolls pages on a phone-sized screen and reports how their social embeds loaded.
 *
 * Usage: npm run check:embeds -- [--block <file>] <url> [<url>...]
 *
 * For each address it prints one JSON object: the embed scripts requested before any
 * scrolling (scriptsAtLoad), every embed script requested and when (scripts), the
 * embeds still showing their pasted fallback after scrolling (unrendered), and the
 * layout shift: cls is the worst session window, as Chrome reports it, and shiftTotal
 * is every shift added up. Add a cache-busting query, since a page cache serves the
 * page from before the plugin changed.
 *
 * Ads move the page as they fill in, which has nothing to do with embeds. To leave
 * them out, pass --block with a JSON file holding an array of hostnames, and every
 * request to those hosts is stopped.
 *
 * Exits non-zero when an Instagram or X embed did not render, or anything shifted.
 * TikTok and Pinterest are reported but not judged: TikTok's own library can be down,
 * and Pinterest only renders in a real browser, not a headless one.
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
const urls = args;

if ( ! urls.length ) {
	console.error(
		'Usage: npm run check:embeds -- [--block <file>] <url> [<url>...]'
	);
	process.exit( 2 );
}

// The network libraries, matched on the address without its query.
const SCRIPTS =
	/^https?:\/\/(?:www\.instagram\.com\/embed\.js|platform\.(?:twitter|x)\.com\/widgets\.js|www\.tiktok\.com\/embed\.js|assets\.pinterest\.com\/js\/pinit\.js)$/;

const browser = await chromium.launch( { headless: true } );

for ( const url of urls ) {
	try {
		const result = await check( url );

		console.log( JSON.stringify( result, null, 2 ) );

		if (
			result.unrendered.instagram ||
			result.unrendered.x ||
			result.cls > 0
		) {
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

	await page.goto( url, { waitUntil: 'domcontentloaded', timeout: 60000 } );

	// Long enough for the page's own scripts to run before scrolling starts.
	await page.waitForTimeout( 8000 );

	const scriptsAtLoad = scripts.length;
	const height = () =>
		page.evaluate( () => document.documentElement.scrollHeight );

	// The height is read again on every step, because rendered embeds and ads make
	// the page longer as it scrolls. The step cap stops a page that never ends.
	for ( let y = 0, step = 0; y < ( await height() ) && step < 250; step++ ) {
		await page.evaluate( ( top ) => window.scrollTo( 0, top ), y );
		await page.waitForTimeout( 350 );
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
				// TikTok fills its blockquote with an iframe rather than replacing it.
				tiktok: [
					...document.querySelectorAll( 'blockquote.tiktok-embed' ),
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
			shiftTotal: Math.round( window.__mpmShifts.total * 1000 ) / 1000,
		};
	} );

	await context.close();

	return { url, scriptsAtLoad, scripts, ...found };
}
