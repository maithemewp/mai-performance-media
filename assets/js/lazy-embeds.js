/**
 * Loads each social embed's script as the embed nears the screen.
 *
 * The finished-page pass removed the src from every Instagram, X, TikTok and
 * Pinterest embed script it found, and printed this file inline with the networks it
 * delayed. Here each embed's marker comes off, so the network's script would not
 * render it yet, and goes back when the embed comes within 200px of the screen. Then
 * the script loads, once per network, and renders that embed. X embeds also start
 * 1600px early once the reader scrolls, because a tweet is slow to render and grows.
 *
 * Any error leaves the pasted fallbacks as they are, which is what a reader sees
 * today when a blocker stops the network's script.
 *
 * Printed inline as a classic script, so it stays plain ES2017 with no imports.
 *
 * @since 1.0.0
 */
( function () {
	// Only set while this script first runs, so it is read straight away.
	const loader =
		document.currentScript ||
		document.getElementById( 'mai-performance-media-lazy-embeds' );

	try {
		const networks = {
			instagram: {
				src: 'https://www.instagram.com/embed.js',
				hidden: [ 'blockquote.instagram-media' ],
				loaded: () => window.instgrm && window.instgrm.Embeds,
				render: () => window.instgrm.Embeds.process(),
			},
			x: {
				src: 'https://platform.twitter.com/widgets.js',
				hidden: [
					'blockquote.twitter-tweet',
					'blockquote.twitter-video',
				],
				// Timelines and buttons keep their class. They only wait for the script.
				kept: [
					'a.twitter-timeline',
					'a.twitter-follow-button',
					'a.twitter-share-button',
					'a.twitter-hashtag-button',
					'a.twitter-mention-button',
				],
				// A first tweet took about 1 to 1.2s to render and then grew, so at 200px a
				// fast scroll reached it first and the page jumped. In fast-scroll tests 1600px
				// was the smallest lead that kept the jump under Google's 0.1 line. Used from
				// the start, a wide margin loaded X during page load on many posts, so it
				// waits for a scroll.
				lead: '1600px 0px',
				// X's own snippet makes an empty twttr early, so twttr alone is not enough.
				loaded: () => window.twttr && window.twttr.widgets,
				// X only looks inside the element it is given, and ready() waits until it can.
				render: ( embed ) =>
					window.twttr.ready( ( twttr ) =>
						twttr.widgets.load( embed.parentNode )
					),
			},
			tiktok: {
				src: 'https://www.tiktok.com/embed.js',
				// TikTok has no render call, so its script renders every embed when it loads.
				kept: [ 'blockquote.tiktok-embed' ],
				loaded: () => false,
				render: null,
			},
			pinterest: {
				src: 'https://assets.pinterest.com/js/pinit.js',
				pins: 'a[data-pin-do]',
				loaded: () => window.PinUtils && window.PinUtils.build,
				// Builds the pins inside the element it is given.
				render: ( pin ) => window.PinUtils.build( pin.parentNode ),
			},
		};

		const delayed = ( ( loader && loader.dataset.networks ) || '' )
			.split( ',' )
			.filter( ( name ) => networks[ name ] );

		if ( ! delayed.length ) {
			return;
		}

		// One load per network. A failed load is forgotten so a later embed can retry.
		const loads = {};

		const load = ( name ) => {
			const network = networks[ name ];

			if ( network.loaded() ) {
				return Promise.resolve();
			}

			if ( ! loads[ name ] ) {
				loads[ name ] = new Promise( ( resolve, reject ) => {
					const script = document.createElement( 'script' );

					script.src = network.src;
					script.async = true;
					script.onload = resolve;
					script.onerror = reject;

					( document.body || document.head ).appendChild( script );
				} ).catch( ( error ) => {
					delete loads[ name ];
					throw error;
				} );
			}

			return loads[ name ];
		};

		// Without an observer, load the scripts now, the way the page did before.
		if ( ! ( 'IntersectionObserver' in window ) ) {
			delayed.forEach( ( name ) => load( name ).catch( () => {} ) );
			return;
		}

		// Each embed not shown yet: its network, and what to do when it nears the screen.
		const waiting = new Map();

		// An X embed can be watched with two margins. Whichever observer sees it first
		// shows it and takes it out of waiting, so the other finds nothing to do.
		const observeWithin = ( margin ) => {
			const observer = new window.IntersectionObserver(
				( entries ) => {
					entries.forEach( ( entry ) => {
						// One embed that fails should not stop the rest.
						try {
							if ( ! entry.isIntersecting ) {
								return;
							}

							observer.unobserve( entry.target );

							const item = waiting.get( entry.target );

							waiting.delete( entry.target );

							if ( item ) {
								item.show();
							}
						} catch ( error ) {
							// That embed keeps its fallback.
						}
					} );
				},
				{ rootMargin: margin }
			);

			return observer;
		};

		const near = observeWithin( '200px 0px' );

		// Gives the embed its marker back, loads the script, then renders the embed.
		// Rendering one that already rendered is harmless, since it no longer matches.
		const watch = ( name, embed, restore ) => {
			const network = networks[ name ];

			waiting.set( embed, {
				name,
				show: () => {
					restore();

					load( name )
						.then( () => {
							if ( network.render ) {
								network.render( embed );
							}
						} )
						.catch( () => {} );
				},
			} );

			near.observe( embed );
		};

		delayed.forEach( ( name ) => {
			const network = networks[ name ];

			( network.hidden || [] ).forEach( ( selector ) => {
				const className = selector.split( '.' )[ 1 ];

				document.querySelectorAll( selector ).forEach( ( embed ) => {
					embed.classList.remove( className );
					embed.setAttribute( 'data-mpm-embed', name );

					watch( name, embed, () => {
						embed.removeAttribute( 'data-mpm-embed' );
						embed.classList.add( className );
					} );
				} );
			} );

			( network.kept || [] ).forEach( ( selector ) => {
				document.querySelectorAll( selector ).forEach( ( embed ) => {
					watch( name, embed, () => {} );
				} );
			} );

			if ( network.pins ) {
				document.querySelectorAll( network.pins ).forEach( ( pin ) => {
					pin.setAttribute(
						'data-mpm-pin-do',
						pin.getAttribute( 'data-pin-do' )
					);
					pin.removeAttribute( 'data-pin-do' );

					watch( name, pin, () => {
						pin.setAttribute(
							'data-pin-do',
							pin.getAttribute( 'data-mpm-pin-do' )
						);
						pin.removeAttribute( 'data-mpm-pin-do' );
					} );
				} );
			}
		} );

		const leading = delayed.filter( ( name ) => networks[ name ].lead );

		if ( ! leading.length ) {
			return;
		}

		// A 1px mark at the top of the page leaves the screen once the reader scrolls, or
		// straight away on a page opened partway down. A page nobody scrolls, such as a
		// lab test, keeps the 200px margin, and no scroll listener is needed.
		const mark = document.createElement( 'div' );

		mark.style.cssText =
			'position:absolute;top:0;left:0;width:1px;height:1px;pointer-events:none';
		document.body.appendChild( mark );

		const scrolled = new window.IntersectionObserver( ( entries ) => {
			try {
				if ( entries.every( ( entry ) => entry.isIntersecting ) ) {
					return;
				}

				scrolled.disconnect();
				mark.remove();

				leading.forEach( ( name ) => {
					const early = observeWithin( networks[ name ].lead );

					waiting.forEach( ( item, embed ) => {
						if ( name === item.name ) {
							early.observe( embed );
						}
					} );
				} );
			} catch ( error ) {
				// Those embeds still load at 200px.
			}
		} );

		scrolled.observe( mark );
	} catch ( error ) {
		// The fallbacks stay as they are.
	}
} )();
