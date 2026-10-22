/**
 * AI Theme Studio admin app.
 *
 * Hash-routed single page app on top of the plugin REST API. All dynamic text
 * is inserted with textContent (never innerHTML) because it may contain
 * AI-generated or user-provided content.
 */
( function () {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const root = document.getElementById( 'ats-app' );
	if ( ! root ) {
		return;
	}

	/* ---------------------------------------------------------------- utils */

	const api = ( path, opts = {} ) => apiFetch( { path: '/' + ATS.ns + path, ...opts } );
	const post = ( path, data ) => api( path, { method: 'POST', data: data || {} } );

	function h( tag, attrs, ...children ) {
		const el = document.createElement( tag );
		for ( const [ k, v ] of Object.entries( attrs || {} ) ) {
			if ( v === null || v === undefined || v === false ) {
				continue;
			}
			if ( k === 'class' ) {
				el.className = v;
			} else if ( k.startsWith( 'on' ) && typeof v === 'function' ) {
				el.addEventListener( k.slice( 2 ).toLowerCase(), v );
			} else if ( k === 'value' ) {
				el.value = v;
			} else if ( k === 'checked' || k === 'disabled' || k === 'selected' || k === 'multiple' ) {
				el[ k ] = !! v;
			} else {
				el.setAttribute( k, v === true ? '' : String( v ) );
			}
		}
		append( el, children );
		return el;
	}

	function append( el, children ) {
		for ( const c of children.flat( Infinity ) ) {
			if ( c === null || c === undefined || c === false ) {
				continue;
			}
			el.appendChild( c instanceof Node ? c : document.createTextNode( String( c ) ) );
		}
		return el;
	}

	function clear( el ) {
		while ( el.firstChild ) {
			el.removeChild( el.firstChild );
		}
		return el;
	}

	function safeUrl( url ) {
		try {
			const u = new URL( url, window.location.origin );
			return [ 'http:', 'https:' ].includes( u.protocol ) ? u.href : '#';
		} catch ( e ) {
			return '#';
		}
	}

	const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
	const errMsg = ( e ) => ( e && e.message ) || __( 'خطای ناشناخته رخ داد.', 'ai-theme-studio' );

	function notice( type, text ) {
		return h( 'div', { class: 'ats-notice ats-notice--' + type, role: type === 'error' ? 'alert' : 'status' }, text );
	}

	function toast( type, text ) {
		const box = document.querySelector( '.ats-toasts' ) || document.body.appendChild( h( 'div', { class: 'ats-toasts', 'aria-live': 'assertive' } ) );
		const t = h( 'div', { class: 'ats-toast ats-toast--' + type }, text );
		box.appendChild( t );
		setTimeout( () => t.remove(), 6000 );
	}

	function field( label, input, help ) {
		const id = input.id || 'ats-f-' + Math.random().toString( 36 ).slice( 2, 9 );
		input.id = id;
		return h( 'div', { class: 'ats-field' }, h( 'label', { for: id }, label ), input, help ? h( 'p', { class: 'ats-help' }, help ) : null );
	}

	function select( options, value, attrs ) {
		return h(
			'select',
			attrs || {},
			options.map( ( [ v, l ] ) => h( 'option', { value: v, selected: v === value }, l ) )
		);
	}

	function button( label, onClick, cls, attrs ) {
		const b = h( 'button', { type: 'button', class: 'button ' + ( cls || '' ), ...( attrs || {} ) }, label );
		b.addEventListener( 'click', async ( ev ) => {
			if ( b.disabled ) {
				return;
			}
			b.disabled = true;
			b.classList.add( 'is-busy' );
			try {
				await onClick( ev );
			} catch ( e ) {
				toast( 'error', errMsg( e ) );
			} finally {
				b.disabled = false;
				b.classList.remove( 'is-busy' );
			}
		} );
		return b;
	}

	function card( title, ...children ) {
		return h( 'section', { class: 'ats-card' }, title ? h( 'h2', { class: 'ats-card__title' }, title ) : null, ...children );
	}

	function badge( status ) {
		const labels = {
			draft: __( 'پیش‌نویس', 'ai-theme-studio' ),
			generating: __( 'در حال تولید', 'ai-theme-studio' ),
			review_sitemap: __( 'منتظر بررسی نقشه سایت', 'ai-theme-studio' ),
			ready: __( 'آماده بررسی', 'ai-theme-studio' ),
			failed: __( 'ناموفق', 'ai-theme-studio' ),
			cancelled: __( 'لغوشده', 'ai-theme-studio' ),
			published: __( 'منتشرشده', 'ai-theme-studio' ),
			queued: __( 'در صف', 'ai-theme-studio' ),
			running: __( 'در حال اجرا', 'ai-theme-studio' ),
			waiting: __( 'منتظر شما', 'ai-theme-studio' ),
			completed: __( 'کامل شد', 'ai-theme-studio' ),
			publish: __( 'منتشرشده', 'ai-theme-studio' ),
		};
		return h( 'span', { class: 'ats-badge ats-badge--' + status }, labels[ status ] || status );
	}

	function scoreBadge( score ) {
		if ( score === null || score === undefined ) {
			return h( 'span', { class: 'ats-score ats-score--na' }, '—' );
		}
		const cls = score >= 90 ? 'good' : score >= 50 ? 'mid' : 'bad';
		return h( 'span', { class: 'ats-score ats-score--' + cls }, String( score ) );
	}

	const fmtDate = ( s ) => {
		if ( ! s ) {
			return '';
		}
		const d = new Date( String( s ).replace( ' ', 'T' ) + ( /Z|[+-]\d\d:?\d\d$/.test( s ) ? '' : 'Z' ) );
		return isNaN( d ) ? s : d.toLocaleString( document.documentElement.lang || 'fa-IR' );
	};

	/* ---------------------------------------------------------------- state */

	const state = {
		system: null,
		poller: 0,
	};

	const PAGE_LABELS = {
		home: __( 'خانه', 'ai-theme-studio' ),
		about: __( 'درباره ما', 'ai-theme-studio' ),
		services: __( 'خدمات', 'ai-theme-studio' ),
		service: __( 'صفحه خدمت', 'ai-theme-studio' ),
		products: __( 'محصولات', 'ai-theme-studio' ),
		product: __( 'صفحه محصول', 'ai-theme-studio' ),
		blog: __( 'وبلاگ', 'ai-theme-studio' ),
		contact: __( 'تماس', 'ai-theme-studio' ),
		faq: __( 'سؤالات متداول', 'ai-theme-studio' ),
		pricing: __( 'تعرفه‌ها', 'ai-theme-studio' ),
		portfolio: __( 'نمونه‌کارها', 'ai-theme-studio' ),
		case_study: __( 'مطالعه موردی', 'ai-theme-studio' ),
		team: __( 'تیم', 'ai-theme-studio' ),
		privacy: __( 'حریم خصوصی', 'ai-theme-studio' ),
		terms: __( 'قوانین و مقررات', 'ai-theme-studio' ),
		not_found: __( 'صفحه ۴۰۴', 'ai-theme-studio' ),
		landing: __( 'صفحه فرود', 'ai-theme-studio' ),
		custom: __( 'سفارشی', 'ai-theme-studio' ),
	};

	const SITE_LABELS = {
		auto: __( 'تشخیص خودکار', 'ai-theme-studio' ),
		business: __( 'کسب‌وکار', 'ai-theme-studio' ),
		agency: __( 'آژانس', 'ai-theme-studio' ),
		portfolio: __( 'نمونه‌کار', 'ai-theme-studio' ),
		blog: __( 'وبلاگ', 'ai-theme-studio' ),
		restaurant: __( 'رستوران و کافه', 'ai-theme-studio' ),
		saas: __( 'نرم‌افزار / SaaS', 'ai-theme-studio' ),
		ecommerce: __( 'فروشگاه', 'ai-theme-studio' ),
		education: __( 'آموزشی', 'ai-theme-studio' ),
		medical: __( 'پزشکی و سلامت', 'ai-theme-studio' ),
		nonprofit: __( 'غیرانتفاعی', 'ai-theme-studio' ),
		personal: __( 'شخصی', 'ai-theme-studio' ),
		custom: __( 'سایر', 'ai-theme-studio' ),
	};

	const ROLE_LABELS = {
		auto: __( 'تشخیص خودکار', 'ai-theme-studio' ),
		logo: __( 'لوگو', 'ai-theme-studio' ),
		hero: __( 'تصویر اصلی (Hero)', 'ai-theme-studio' ),
		homepage_reference: __( 'نمونه طراحی (فقط الهام)', 'ai-theme-studio' ),
		product: __( 'محصول', 'ai-theme-studio' ),
		team: __( 'تیم', 'ai-theme-studio' ),
		gallery: __( 'گالری', 'ai-theme-studio' ),
		background: __( 'پس‌زمینه', 'ai-theme-studio' ),
		inspiration: __( 'الهام سبک', 'ai-theme-studio' ),
		unknown: __( 'نامشخص', 'ai-theme-studio' ),
	};

	/* --------------------------------------------------------------- router */

	function route() {
		const hash = window.location.hash.replace( /^#\/?/, '' );
		const parts = hash.split( '/' ).filter( Boolean );
		if ( ! parts.length && ATS.route ) {
			parts.push( ATS.route );
		}
		return parts;
	}

	function go( path ) {
		window.location.hash = '#/' + path;
	}

	async function render() {
		window.clearTimeout( state.poller );
		state.poller = 0;
		state.renderId = ( state.renderId || 0 ) + 1;
		const parts = route();
		clear( root );
		root.appendChild( nav( parts[ 0 ] || '' ) );
		const main = root.appendChild( h( 'div', { class: 'ats-main' } ) );
		try {
			if ( ! state.system ) {
				state.system = await api( '/system' );
			}
			switch ( parts[ 0 ] ) {
				case 'new':
					return viewNew( main );
				case 'settings':
					return viewSettings( main );
				case 'system':
					return viewSystem( main );
				case 'project':
					return viewProject( main, parseInt( parts[ 1 ], 10 ), parts[ 2 ] || 'overview' );
				default:
					return viewDashboard( main );
			}
		} catch ( e ) {
			main.appendChild( notice( 'error', errMsg( e ) ) );
		}
	}

	function nav( active ) {
		const items = [
			[ '', __( 'داشبورد', 'ai-theme-studio' ) ],
			[ 'new', __( 'پروژه جدید', 'ai-theme-studio' ) ],
			[ 'system', __( 'وضعیت سیستم', 'ai-theme-studio' ) ],
		];
		if ( ! state.system || state.system.can.settings ) {
			items.push( [ 'settings', __( 'تنظیمات', 'ai-theme-studio' ) ] );
		}
		return h(
			'nav',
			{ class: 'ats-nav', 'aria-label': __( 'بخش‌های استودیو', 'ai-theme-studio' ) },
			items.map( ( [ r, l ] ) => h( 'a', { href: '#/' + r, class: active === r || ( active === 'project' && r === '' ) ? 'is-active' : '', 'aria-current': active === r ? 'page' : null }, l ) )
		);
	}

	window.addEventListener( 'hashchange', render );

	/* ------------------------------------------------------------ dashboard */

	async function viewDashboard( main ) {
		const sys = state.system;
		main.appendChild( environmentCard( sys, true ) );
		const projects = await api( '/projects' );
		const list = card(
			__( 'پروژه‌ها', 'ai-theme-studio' ),
			h( 'p', {}, h( 'a', { href: '#/new', class: 'button button-primary' }, __( 'ساخت پروژه جدید', 'ai-theme-studio' ) ) )
		);
		if ( ! projects.length ) {
			list.appendChild( h( 'p', { class: 'ats-empty' }, __( 'هنوز پروژه‌ای ندارید. با توضیح سایت موردنظرتان شروع کنید.', 'ai-theme-studio' ) ) );
		} else {
			list.appendChild(
				h(
					'table',
					{ class: 'widefat striped ats-table' },
					h( 'thead', {}, h( 'tr', {}, h( 'th', {}, __( 'نام', 'ai-theme-studio' ) ), h( 'th', {}, __( 'وضعیت', 'ai-theme-studio' ) ), h( 'th', {}, __( 'صفحات', 'ai-theme-studio' ) ), h( 'th', {}, __( 'تصاویر', 'ai-theme-studio' ) ), h( 'th', {}, __( 'آخرین تغییر', 'ai-theme-studio' ) ) ) ),
					h(
						'tbody',
						{},
						projects.map( ( p ) =>
							h(
								'tr',
								{},
								h( 'td', {}, h( 'a', { href: '#/project/' + p.id }, p.name ) ),
								h( 'td', {}, badge( p.status ) ),
								h( 'td', {}, String( p.pages ) ),
								h( 'td', {}, String( p.images ) ),
								h( 'td', {}, fmtDate( p.modified ) )
							)
						)
					)
				)
			);
		}
		main.appendChild( list );
	}

	function environmentCard( sys, compact ) {
		const items = [];
		sys.elementor.issues.forEach( ( i ) => items.push( notice( 'error', i ) ) );
		if ( ! sys.api_key_set && ! sys.provider_error ) {
			items.push( notice( 'warning', __( 'کلید API سرویس هوش مصنوعی تنظیم نشده است.', 'ai-theme-studio' ) ) );
		}
		if ( sys.provider_error ) {
			items.push( notice( 'error', sys.provider_error ) );
		}
		if ( ! sys.permalinks ) {
			items.push( notice( 'warning', __( 'پیوندهای یکتا روی حالت «ساده» است؛ برای سئو بهتر، ساختار «نام نوشته» را در تنظیمات > پیوندهای یکتا انتخاب کنید.', 'ai-theme-studio' ) ) );
		}
		if ( ! compact ) {
			sys.elementor.notices.forEach( ( n ) => items.push( notice( 'info', n ) ) );
		}
		if ( ! items.length && compact ) {
			return h( 'div' );
		}
		return card( compact ? null : __( 'هشدارها و محدودیت‌ها', 'ai-theme-studio' ), items.length ? items : h( 'p', {}, __( 'مشکلی یافت نشد.', 'ai-theme-studio' ) ) );
	}

	/* --------------------------------------------------------------- system */

	function viewSystem( main ) {
		const s = state.system;
		const e = s.elementor;
		const yes = __( 'بله', 'ai-theme-studio' );
		const no = __( 'خیر', 'ai-theme-studio' );
		const rows = [
			[ __( 'نسخه افزونه', 'ai-theme-studio' ), s.version ],
			[ 'PHP', s.php ],
			[ 'WordPress', s.wp ],
			[ __( 'المنتور', 'ai-theme-studio' ), e.installed ? ( e.active ? e.version : __( 'غیرفعال', 'ai-theme-studio' ) ) : __( 'نصب نیست', 'ai-theme-studio' ) ],
			[ __( 'حداقل نسخه المنتور', 'ai-theme-studio' ), e.min_version ],
			[ __( 'المنتور پرو', 'ai-theme-studio' ), e.pro ? e.pro_version : no ],
			[ 'Flexbox Container', e.containers ? yes : no ],
			[ 'Theme Builder', e.theme_builder ? yes : no ],
			[ __( 'افزونه فرم تماس', 'ai-theme-studio' ), e.contact_form ? e.contact_form.plugin : no ],
			[ __( 'افزونه سئو', 'ai-theme-studio' ), s.seo_plugin.label ],
			[ __( 'سرویس هوش مصنوعی', 'ai-theme-studio' ), s.provider ? s.provider.id + ' / ' + s.provider.model : '—' ],
			[ __( 'پشتیبانی از تحلیل تصویر', 'ai-theme-studio' ), s.provider ? ( s.provider.vision ? yes : no ) : '—' ],
			[ __( 'پیوندهای یکتا', 'ai-theme-studio' ), s.permalinks ? yes : no ],
			[ __( 'قابل مشاهده برای موتورهای جستجو', 'ai-theme-studio' ), s.blog_public ? yes : no ],
			[ 'WP-Cron', s.cron_disabled ? __( 'غیرفعال (پیشرفت فقط هنگام باز بودن این صفحه)', 'ai-theme-studio' ) : yes ],
			[ __( 'قالب بلوکی', 'ai-theme-studio' ), s.block_theme ? yes : no ],
		];
		main.appendChild( card( __( 'وضعیت سیستم', 'ai-theme-studio' ), h( 'table', { class: 'widefat striped ats-table' }, h( 'tbody', {}, rows.map( ( [ k, v ] ) => h( 'tr', {}, h( 'th', { scope: 'row' }, k ), h( 'td', {}, String( v ) ) ) ) ) ) ) );
		main.appendChild( environmentCard( s, false ) );
	}

	/* ------------------------------------------------------------- settings */

	async function viewSettings( main ) {
		if ( ! state.system.can.settings ) {
			main.appendChild( notice( 'error', __( 'دسترسی کافی ندارید.', 'ai-theme-studio' ) ) );
			return;
		}
		const s = await api( '/settings' );
		const f = {};
		const providers = [
			[ 'openai', 'OpenAI' ],
			[ 'anthropic', 'Anthropic (Claude)' ],
			[ 'gemini', 'Google Gemini' ],
			[ 'openai_compatible', __( 'سازگار با OpenAI (آدرس دلخواه)', 'ai-theme-studio' ) ],
		];
		f.provider = select( providers, s.provider );
		f.model = h( 'input', { type: 'text', class: 'regular-text', value: s.model, dir: 'ltr' } );
		f.api_key = h( 'input', { type: 'password', class: 'regular-text', value: '', placeholder: s.api_key || __( 'وارد نشده', 'ai-theme-studio' ), autocomplete: 'off', dir: 'ltr', disabled: s.api_key_from_constant } );
		f.base_url = h( 'input', { type: 'url', class: 'regular-text', value: s.base_url, placeholder: 'https://…/v1', dir: 'ltr' } );
		f.vision = select( [ [ 'auto', __( 'خودکار (بر اساس نام مدل)', 'ai-theme-studio' ) ], [ 'on', __( 'فعال', 'ai-theme-studio' ) ], [ 'off', __( 'غیرفعال', 'ai-theme-studio' ) ] ], s.vision );
		f.json_mode = h( 'input', { type: 'checkbox', checked: s.json_mode } );
		f.timeout = h( 'input', { type: 'number', min: 15, max: 600, value: s.timeout } );
		f.max_retries = h( 'input', { type: 'number', min: 0, max: 5, value: s.max_retries } );
		f.max_output_tokens = h( 'input', { type: 'number', min: 1000, max: 64000, step: 500, value: s.max_output_tokens } );
		f.rate_limit_per_hour = h( 'input', { type: 'number', min: 1, max: 500, value: s.rate_limit_per_hour } );
		f.max_upload_mb = h( 'input', { type: 'number', min: 1, max: 50, value: s.max_upload_mb } );
		f.max_images = h( 'input', { type: 'number', min: 1, max: 30, value: s.max_images } );
		f.seo_output = h( 'input', { type: 'checkbox', checked: s.seo_output } );
		f.pagespeed_api_key = h( 'input', { type: 'password', class: 'regular-text', value: '', placeholder: s.pagespeed_api_key || __( 'اختیاری', 'ai-theme-studio' ), autocomplete: 'off', dir: 'ltr' } );
		f.delete_on_uninstall = h( 'input', { type: 'checkbox', checked: s.delete_on_uninstall } );

		const baseRow = field( __( 'آدرس پایه API (فقط HTTPS)', 'ai-theme-studio' ), f.base_url );
		const toggleBase = () => ( baseRow.hidden = f.provider.value !== 'openai_compatible' );
		f.provider.addEventListener( 'change', toggleBase );
		toggleBase();

		const result = h( 'div', { class: 'ats-test-result', 'aria-live': 'polite' } );
		const collect = () => {
			const out = {};
			for ( const [ k, el ] of Object.entries( f ) ) {
				if ( el.type === 'checkbox' ) {
					out[ k ] = el.checked;
				} else if ( ( k === 'api_key' || k === 'pagespeed_api_key' ) && el.value === '' ) {
					continue;
				} else {
					out[ k ] = el.value;
				}
			}
			return out;
		};
		const save = async () => {
			await post( '/settings', collect() );
			state.system = null;
			toast( 'success', __( 'تنظیمات ذخیره شد.', 'ai-theme-studio' ) );
		};

		main.appendChild(
			card(
				__( 'سرویس هوش مصنوعی', 'ai-theme-studio' ),
				h( 'p', { class: 'ats-help' }, __( 'کلیدها رمزنگاری‌شده ذخیره می‌شوند و هرگز به مرورگر بازگردانده نمی‌شوند. می‌توانید کلید را با ثابت ATS_API_KEY در wp-config.php نیز تعریف کنید.', 'ai-theme-studio' ) ),
				field( __( 'سرویس‌دهنده', 'ai-theme-studio' ), f.provider ),
				field( __( 'مدل', 'ai-theme-studio' ), f.model, __( 'مثال: gpt-4o، claude-sonnet-4-5، gemini-2.5-pro', 'ai-theme-studio' ) ),
				field( __( 'کلید API', 'ai-theme-studio' ), f.api_key, s.api_key_from_constant ? __( 'کلید از wp-config.php خوانده می‌شود.', 'ai-theme-studio' ) : __( 'برای حفظ کلید فعلی خالی بگذارید.', 'ai-theme-studio' ) ),
				s.api_key && ! s.api_key_from_constant ? button( __( 'حذف کلید ذخیره‌شده', 'ai-theme-studio' ), async () => {
					if ( window.confirm( __( 'کلید API حذف شود؟', 'ai-theme-studio' ) ) ) {
						await post( '/settings', { api_key: '__clear__' } );
						state.system = null;
						render();
					}
				}, 'button-link-delete' ) : null,
				baseRow,
				field( __( 'تحلیل تصویر', 'ai-theme-studio' ), f.vision, __( 'اگر مدل از تصویر پشتیبانی نکند، تحلیل انجام نمی‌شود و این موضوع صادقانه گزارش می‌شود.', 'ai-theme-studio' ) ),
				h( 'label', { class: 'ats-check' }, f.json_mode, __( 'استفاده از حالت خروجی JSON سرویس (در صورت پشتیبانی)', 'ai-theme-studio' ) ),
				h( 'div', { class: 'ats-grid-3' }, field( __( 'مهلت درخواست (ثانیه)', 'ai-theme-studio' ), f.timeout ), field( __( 'تلاش مجدد', 'ai-theme-studio' ), f.max_retries ), field( __( 'حداکثر توکن خروجی', 'ai-theme-studio' ), f.max_output_tokens ) ),
				h( 'p', {}, button( __( 'ذخیره', 'ai-theme-studio' ), save, 'button-primary' ), ' ', button( __( 'آزمون اتصال', 'ai-theme-studio' ), async () => {
					await save();
					clear( result ).appendChild( h( 'span', {}, __( 'در حال آزمون…', 'ai-theme-studio' ) ) );
					try {
						const r = await post( '/settings/test' );
						clear( result ).appendChild( notice( r.ok ? 'success' : 'warning', r.message + ' (' + r.provider + ' / ' + r.model + ' — ' + r.latency_ms + 'ms — ' + ( r.vision ? __( 'با پشتیبانی تصویر', 'ai-theme-studio' ) : __( 'بدون پشتیبانی تصویر', 'ai-theme-studio' ) ) + ')' ) );
					} catch ( e ) {
						clear( result ).appendChild( notice( 'error', errMsg( e ) ) );
					}
				} ) ),
				result
			)
		);
		main.appendChild(
			card(
				__( 'عمومی', 'ai-theme-studio' ),
				h( 'div', { class: 'ats-grid-3' }, field( __( 'سقف درخواست AI در ساعت (هر کاربر)', 'ai-theme-studio' ), f.rate_limit_per_hour ), field( __( 'حداکثر حجم تصویر (MB)', 'ai-theme-studio' ), f.max_upload_mb ), field( __( 'حداکثر تصویر در پروژه', 'ai-theme-studio' ), f.max_images ) ),
				h( 'label', { class: 'ats-check' }, f.seo_output, __( 'خروجی متا و داده ساختاریافته وقتی هیچ افزونه سئو فعال نیست', 'ai-theme-studio' ) ),
				field( __( 'کلید PageSpeed Insights API', 'ai-theme-studio' ), f.pagespeed_api_key, __( 'اختیاری؛ بدون کلید، سهمیه عمومی گوگل استفاده می‌شود.', 'ai-theme-studio' ) ),
				h( 'label', { class: 'ats-check' }, f.delete_on_uninstall, __( 'هنگام حذف افزونه، داده‌های افزونه (پروژه‌ها، تاریخچه، تنظیمات) پاک شود. صفحات ساخته‌شده هرگز حذف نمی‌شوند.', 'ai-theme-studio' ) ),
				h( 'p', {}, button( __( 'ذخیره', 'ai-theme-studio' ), save, 'button-primary' ) )
			)
		);
	}

	/* ----------------------------------------------------- new project form */

	function preferenceFields( input ) {
		const sys = state.system;
		const v = input || {};
		const f = {};
		f.prompt = h( 'textarea', { rows: 12, class: 'ats-prompt', maxlength: sys.limits.prompt_max, required: true, placeholder: __( 'مثال: سایت یک کلینیک دندانپزشکی در شیراز با خدمات ایمپلنت، ارتودنسی و زیبایی. مخاطبان خانواده‌ها هستند. لحن صمیمی و قابل اعتماد. می‌خواهیم بیمار بتواند به‌راحتی نوبت بگیرد…', 'ai-theme-studio' ) } );
		f.prompt.value = v.prompt || '';
		const counter = h( 'span', { class: 'ats-counter' } );
		const updateCounter = () => ( counter.textContent = sprintf( __( '%1$d از %2$d نویسه', 'ai-theme-studio' ), f.prompt.value.length, sys.limits.prompt_max ) );
		f.prompt.addEventListener( 'input', updateCounter );
		updateCounter();

		f.category = select( [ [ 'auto', SITE_LABELS.auto ], ...sys.vocabulary.site_types.map( ( t ) => [ t, SITE_LABELS[ t ] || t ] ) ], v.category || 'auto' );
		f.language = select( [ [ 'fa', 'فارسی' ], [ 'ar', 'العربية' ], [ 'en', 'English' ], [ 'de', 'Deutsch' ], [ 'fr', 'Français' ], [ 'tr', 'Türkçe' ] ], v.language || 'fa' );
		f.direction = select( [ [ 'auto', __( 'خودکار بر اساس زبان', 'ai-theme-studio' ) ], [ 'rtl', __( 'راست‌به‌چپ', 'ai-theme-studio' ) ], [ 'ltr', __( 'چپ‌به‌راست', 'ai-theme-studio' ) ] ], v.direction || 'auto' );

		f.color_mode = select( [ [ 'ai', __( 'پیشنهاد هوش مصنوعی', 'ai-theme-studio' ) ], [ 'custom', __( 'رنگ‌های من', 'ai-theme-studio' ) ] ], ( v.colors && v.colors.mode ) || 'ai' );
		f.primary = h( 'input', { type: 'color', value: ( v.colors && v.colors.primary ) || '#1E5EFF' } );
		f.secondary = h( 'input', { type: 'color', value: ( v.colors && v.colors.secondary ) || '#0F172A' } );
		f.background = h( 'input', { type: 'color', value: ( v.colors && v.colors.background ) || '#FFFFFF' } );
		const colorRow = h( 'div', { class: 'ats-grid-3' }, field( __( 'رنگ اصلی', 'ai-theme-studio' ), f.primary ), field( __( 'رنگ دوم', 'ai-theme-studio' ), f.secondary ), field( __( 'پس‌زمینه', 'ai-theme-studio' ), f.background ) );
		const toggleColors = () => ( colorRow.hidden = f.color_mode.value !== 'custom' );
		f.color_mode.addEventListener( 'change', toggleColors );
		toggleColors();

		const fontOptions = () => {
			const lang = f.language.value;
			const list = sys.vocabulary.fonts[ lang ] || sys.vocabulary.fonts.en;
			return [ [ 'auto', __( 'پیشنهاد هوش مصنوعی', 'ai-theme-studio' ) ], ...list.map( ( x ) => [ x, x ] ) ];
		};
		f.heading_font = select( fontOptions(), ( v.fonts && v.fonts.heading ) || 'auto' );
		f.body_font = select( fontOptions(), ( v.fonts && v.fonts.body ) || 'auto' );
		f.language.addEventListener( 'change', () => {
			for ( const k of [ 'heading_font', 'body_font' ] ) {
				const n = select( fontOptions(), 'auto' );
				f[ k ].replaceWith( n );
				n.id = f[ k ].id;
				f[ k ] = n;
			}
		} );

		f.style = h( 'input', { type: 'text', class: 'regular-text', value: ( v.style || [] ).join( '، ' ), placeholder: __( 'مینیمال، مدرن، گرم، لوکس…', 'ai-theme-studio' ) } );
		f.density = select( [ [ 'compact', __( 'فشرده', 'ai-theme-studio' ) ], [ 'comfortable', __( 'متعادل', 'ai-theme-studio' ) ], [ 'spacious', __( 'جادار', 'ai-theme-studio' ) ] ], v.density || 'comfortable' );
		f.corners = select( [ [ 'sharp', __( 'تیز', 'ai-theme-studio' ) ], [ 'rounded', __( 'گرد', 'ai-theme-studio' ) ], [ 'pill', __( 'کاملاً گرد', 'ai-theme-studio' ) ] ], v.corners || 'rounded' );
		f.tablet_columns = select( [ [ '1', '۱' ], [ '2', '۲' ], [ '3', '۳' ] ], String( ( v.responsive && v.responsive.tablet_columns ) || 2 ) );

		const chosen = new Set( v.pages || [] );
		f.pages = sys.vocabulary.page_types
			.filter( ( t ) => ! [ 'home', 'not_found', 'service', 'product', 'case_study', 'custom' ].includes( t ) )
			.map( ( t ) => h( 'input', { type: 'checkbox', value: t, checked: chosen.has( t ) } ) );

		const b = v.business || {};
		f.b_name = h( 'input', { type: 'text', class: 'regular-text', value: b.name || '' } );
		f.b_email = h( 'input', { type: 'email', class: 'regular-text', value: b.email || '', dir: 'ltr' } );
		f.b_phone = h( 'input', { type: 'tel', class: 'regular-text', value: b.phone || '', dir: 'ltr' } );
		f.b_address = h( 'textarea', { rows: 2, class: 'regular-text' } );
		f.b_address.value = b.address || '';
		f.b_hours = h( 'input', { type: 'text', class: 'regular-text', value: b.hours || '' } );

		const o = v.options || {};
		f.review_sitemap = h( 'input', { type: 'checkbox', checked: o.review_sitemap !== false } );
		f.apply_globals = h( 'input', { type: 'checkbox', checked: o.apply_globals !== false } );
		f.set_front_page = h( 'input', { type: 'checkbox', checked: o.set_front_page !== false } );

		const collect = () => ( {
			prompt: f.prompt.value,
			category: f.category.value,
			language: f.language.value,
			direction: f.direction.value,
			colors: { mode: f.color_mode.value, primary: f.primary.value, secondary: f.secondary.value, background: f.background.value },
			fonts: { heading: f.heading_font.value, body: f.body_font.value },
			style: f.style.value.split( /[,،]/ ).map( ( s ) => s.trim() ).filter( Boolean ),
			density: f.density.value,
			corners: f.corners.value,
			responsive: { tablet_columns: parseInt( f.tablet_columns.value, 10 ) },
			pages: f.pages.filter( ( c ) => c.checked ).map( ( c ) => c.value ),
			business: { name: f.b_name.value, email: f.b_email.value, phone: f.b_phone.value, address: f.b_address.value, hours: f.b_hours.value },
			options: { review_sitemap: f.review_sitemap.checked, apply_globals: f.apply_globals.checked, set_front_page: f.set_front_page.checked },
		} );

		const nodes = [
			card(
				__( 'توضیح سایت', 'ai-theme-studio' ),
				h( 'p', { class: 'ats-help' }, __( 'کسب‌وکار، مخاطبان، خدمات، لحن و هدف سایت را بنویسید. هر چه دقیق‌تر بنویسید، نتیجه بهتر است. اطلاعات واقعی (نظرات مشتری، آمار، مدارک) را فقط اگر واقعی است بنویسید؛ افزونه هرگز آن‌ها را جعل نمی‌کند.', 'ai-theme-studio' ) ),
				field( __( 'توضیحات', 'ai-theme-studio' ), f.prompt ),
				counter,
				h( 'div', { class: 'ats-grid-3' }, field( __( 'نوع سایت', 'ai-theme-studio' ), f.category ), field( __( 'زبان سایت', 'ai-theme-studio' ), f.language ), field( __( 'جهت', 'ai-theme-studio' ), f.direction ) )
			),
			card(
				__( 'ترجیحات طراحی', 'ai-theme-studio' ),
				field( __( 'رنگ‌ها', 'ai-theme-studio' ), f.color_mode ),
				colorRow,
				h( 'p', { class: 'ats-help' }, __( 'رنگ‌ها از نظر کنتراست (WCAG AA) بررسی می‌شوند و در صورت نیاز اصلاح و گزارش می‌شوند.', 'ai-theme-studio' ) ),
				h( 'div', { class: 'ats-grid-3' }, field( __( 'فونت عناوین', 'ai-theme-studio' ), f.heading_font ), field( __( 'فونت متن', 'ai-theme-studio' ), f.body_font ), field( __( 'ستون‌ها در تبلت', 'ai-theme-studio' ), f.tablet_columns ) ),
				field( __( 'کلمات کلیدی سبک (با ویرگول جدا کنید)', 'ai-theme-studio' ), f.style ),
				h( 'div', { class: 'ats-grid-3' }, field( __( 'فاصله‌گذاری', 'ai-theme-studio' ), f.density ), field( __( 'گوشه‌ها', 'ai-theme-studio' ), f.corners ) )
			),
			card(
				__( 'صفحات موردنیاز', 'ai-theme-studio' ),
				h( 'p', { class: 'ats-help' }, __( 'صفحه اصلی و ۴۰۴ همیشه ساخته می‌شوند. صفحات دیگر را انتخاب کنید یا خالی بگذارید تا بر اساس نوع سایت پیشنهاد شود.', 'ai-theme-studio' ) ),
				h( 'div', { class: 'ats-checks' }, f.pages.map( ( c ) => h( 'label', { class: 'ats-check' }, c, PAGE_LABELS[ c.value ] || c.value ) ) )
			),
			card(
				__( 'اطلاعات تماس واقعی (اختیاری)', 'ai-theme-studio' ),
				h( 'p', { class: 'ats-help' }, __( 'فقط همین اطلاعات در سایت استفاده می‌شود؛ اگر خالی باشد، جای آن مشخص و قابل ویرایش می‌ماند.', 'ai-theme-studio' ) ),
				h( 'div', { class: 'ats-grid-3' }, field( __( 'نام کسب‌وکار', 'ai-theme-studio' ), f.b_name ), field( __( 'ایمیل', 'ai-theme-studio' ), f.b_email ), field( __( 'تلفن', 'ai-theme-studio' ), f.b_phone ) ),
				h( 'div', { class: 'ats-grid-3' }, field( __( 'نشانی', 'ai-theme-studio' ), f.b_address ), field( __( 'ساعات کاری', 'ai-theme-studio' ), f.b_hours ) )
			),
			card(
				__( 'گزینه‌ها', 'ai-theme-studio' ),
				h( 'label', { class: 'ats-check' }, f.review_sitemap, __( 'پیش از تولید صفحات، نقشه سایت را بررسی و ویرایش می‌کنم', 'ai-theme-studio' ) ),
				h( 'label', { class: 'ats-check' }, f.apply_globals, __( 'رنگ‌ها و فونت‌ها به‌عنوان Global در المنتور ثبت شوند (بدون تغییر رنگ‌های فعلی سایت)', 'ai-theme-studio' ) ),
				h( 'label', { class: 'ats-check' }, f.set_front_page, __( 'صفحه اصلی پروژه هنگام انتشار، صفحه نخست سایت شود (در زمان انتشار دوباره تأیید می‌شود)', 'ai-theme-studio' ) )
			),
		];
		return { nodes, collect };
	}

	function viewNew( main ) {
		const name = h( 'input', { type: 'text', class: 'regular-text', placeholder: __( 'اختیاری', 'ai-theme-studio' ) } );
		const form = preferenceFields( null );
		main.appendChild( card( __( 'پروژه جدید', 'ai-theme-studio' ), field( __( 'نام پروژه', 'ai-theme-studio' ), name ), h( 'p', { class: 'ats-help' }, __( 'پس از ذخیره، می‌توانید تصاویر مرجع (لوگو، نمونه طراحی، محصولات…) را بارگذاری کنید و سپس تولید را آغاز کنید.', 'ai-theme-studio' ) ) ) );
		form.nodes.forEach( ( n ) => main.appendChild( n ) );
		main.appendChild(
			h(
				'p',
				{ class: 'ats-actions' },
				button( __( 'ذخیره و ادامه به تصاویر', 'ai-theme-studio' ), async () => {
					const p = await post( '/projects', { name: name.value, input: form.collect() } );
					go( 'project/' + p.id + '/inputs' );
				}, 'button-primary button-hero' )
			)
		);
	}

	/* -------------------------------------------------------------- project */

	const TABS = [
		[ 'overview', __( 'وضعیت', 'ai-theme-studio' ) ],
		[ 'inputs', __( 'ورودی‌ها و تصاویر', 'ai-theme-studio' ) ],
		[ 'sitemap', __( 'نقشه سایت', 'ai-theme-studio' ) ],
		[ 'design', __( 'سیستم طراحی', 'ai-theme-studio' ) ],
		[ 'pages', __( 'صفحات', 'ai-theme-studio' ) ],
		[ 'navigation', __( 'منو', 'ai-theme-studio' ) ],
		[ 'seo', __( 'سئو', 'ai-theme-studio' ) ],
		[ 'performance', __( 'عملکرد', 'ai-theme-studio' ) ],
		[ 'publish', __( 'انتشار', 'ai-theme-studio' ) ],
		[ 'history', __( 'تاریخچه', 'ai-theme-studio' ) ],
	];

	async function viewProject( main, id, tab ) {
		const project = await api( '/projects/' + id );
		main.appendChild(
			h(
				'header',
				{ class: 'ats-project-head' },
				h( 'h2', {}, project.name ),
				badge( project.status ),
				h( 'nav', { class: 'ats-tabs', role: 'tablist' }, TABS.map( ( [ t, l ] ) => h( 'a', { href: '#/project/' + id + '/' + t, role: 'tab', 'aria-selected': t === tab ? 'true' : 'false', class: t === tab ? 'is-active' : '' }, l ) ) )
			)
		);
		const body = main.appendChild( h( 'div', { class: 'ats-tab-body', role: 'tabpanel' } ) );
		const views = { overview: tabOverview, inputs: tabInputs, sitemap: tabSitemap, design: tabDesign, pages: tabPages, navigation: tabNavigation, seo: tabSeo, performance: tabPerformance, publish: tabPublish, history: tabHistory };
		return ( views[ tab ] || tabOverview )( body, project );
	}

	/* overview: progress, cancel, retry, log */

	async function tabOverview( body, project ) {
		const renderId = state.renderId;
		const box = body.appendChild( h( 'div' ) );

		const draw = ( data ) => {
			clear( box );
			const job = data.job;
			const status = data.status;
			if ( status === 'draft' || ! job ) {
				box.appendChild( card( __( 'آماده شروع', 'ai-theme-studio' ), h( 'p', {}, sprintf( __( '%d تصویر بارگذاری شده است.', 'ai-theme-studio' ), project.images.length ) ), h( 'p', {}, h( 'a', { href: '#/project/' + project.id + '/inputs' }, __( 'ویرایش ورودی‌ها و تصاویر', 'ai-theme-studio' ) ) ), startButton( project, false ) ) );
			}
			if ( job ) {
				const active = [ 'queued', 'running' ].includes( job.status );
				const doneIdx = job.steps.findIndex( ( s ) => s.id === job.step );
				box.appendChild(
					card(
						__( 'پیشرفت تولید', 'ai-theme-studio' ),
						h( 'div', { class: 'ats-progress', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': job.progress }, h( 'div', { class: 'ats-progress__bar', style: 'width:' + Math.max( 2, job.progress ) + '%' } ) ),
						h( 'p', {}, badge( job.status ), ' ', job.step_label, job.pages_total ? ' — ' + sprintf( __( '%1$d از %2$d صفحه', 'ai-theme-studio' ), job.pages_done, job.pages_total ) : '' ),
						[ 'regenerate_section', 'regenerate_page' ].includes( job.step ) ? null : h(
							'ol',
							{ class: 'ats-steps' },
							job.steps.map( ( s, i ) => h( 'li', { class: job.step === 'done' || ( doneIdx > -1 && i < doneIdx ) ? 'is-done' : i === doneIdx ? 'is-current' : '' }, s.label ) )
						),
						job.error ? notice( job.status === 'failed' ? 'error' : 'warning', job.error ) : null,
						job.attempts ? h( 'p', { class: 'ats-help' }, sprintf( __( 'تلاش مجدد خودکار: %d', 'ai-theme-studio' ), job.attempts ) ) : null,
						h(
							'p',
							{ class: 'ats-actions' },
							active ? button( __( 'لغو', 'ai-theme-studio' ), async () => {
								if ( window.confirm( __( 'عملیات لغو شود؟ کارهای انجام‌شده حفظ می‌شوند و می‌توانید بعداً ادامه دهید.', 'ai-theme-studio' ) ) ) {
									await post( '/jobs/' + job.id + '/cancel' );
									refresh();
								}
							}, 'button-secondary' ) : null,
							[ 'failed', 'cancelled' ].includes( job.status ) ? button( __( 'ادامه از همان مرحله', 'ai-theme-studio' ), async () => {
								await post( '/jobs/' + job.id + '/resume' );
								refresh();
							}, 'button-primary' ) : null,
							job.status === 'waiting' ? h( 'a', { class: 'button button-primary', href: '#/project/' + project.id + '/sitemap' }, __( 'بررسی و تأیید نقشه سایت', 'ai-theme-studio' ) ) : null,
							[ 'completed', 'failed', 'cancelled' ].includes( job.status ) ? startButton( project, true ) : null
						)
					)
				);
				if ( job.status === 'completed' && status !== 'generating' ) {
					box.appendChild( card( __( 'گام بعدی', 'ai-theme-studio' ), h( 'p', {}, __( 'صفحات به‌صورت پیش‌نویس ساخته شده‌اند. آن‌ها را پیش‌نمایش کنید، در المنتور ویرایش کنید، گزارش سئو و عملکرد را ببینید و در پایان منتشر کنید.', 'ai-theme-studio' ) ), h( 'p', { class: 'ats-actions' }, h( 'a', { class: 'button button-primary', href: '#/project/' + project.id + '/pages' }, __( 'مشاهده صفحات', 'ai-theme-studio' ) ), ' ', h( 'a', { class: 'button', href: '#/project/' + project.id + '/publish' }, __( 'انتشار', 'ai-theme-studio' ) ) ) ) );
				}
			}
			if ( data.notices && data.notices.length ) {
				box.appendChild( card( __( 'یادداشت‌ها و محدودیت‌ها', 'ai-theme-studio' ), h( 'ul', { class: 'ats-list' }, data.notices.map( ( n ) => h( 'li', {}, n ) ) ) ) );
			}
			box.appendChild( logCard( data.log ) );
			return job;
		};

		const refresh = async () => {
			if ( renderId !== state.renderId ) {
				return;
			}
			const data = await api( '/projects/' + project.id + '/job' );
			if ( renderId !== state.renderId ) {
				return;
			}
			const job = draw( data );
			if ( job && [ 'queued', 'running' ].includes( job.status ) ) {
				if ( job.status === 'running' ) {
					await sleep( 4000 );
				}
				if ( renderId !== state.renderId ) {
					return;
				}
				try {
					await post( '/jobs/' + job.id + '/tick' );
				} catch ( e ) {
					/* The next poll reports the job error. */
				}
				state.poller = window.setTimeout( refresh, 300 );
			}
		};
		await refresh();
	}

	function startButton( project, again ) {
		return button( again ? __( 'تولید دوباره کل سایت', 'ai-theme-studio' ) : __( 'شروع تولید', 'ai-theme-studio' ), async () => {
			let force = false;
			if ( again ) {
				if ( ! window.confirm( __( 'کل سایت دوباره تولید شود؟ نسخه فعلی در تاریخچه می‌ماند.', 'ai-theme-studio' ) ) ) {
					return;
				}
				force = window.confirm( __( 'صفحاتی که در المنتور ویرایش کرده‌اید هم جایگزین شوند؟\n«تأیید» = جایگزین شوند (نسخه پشتیبان ذخیره می‌شود)\n«انصراف» = صفحات ویرایش‌شده دست نخورند', 'ai-theme-studio' ) );
			}
			await post( '/projects/' + project.id + '/generate', { force } );
			go( 'project/' + project.id + '/overview' );
			render();
		}, 'button-primary' );
	}

	function logCard( log ) {
		if ( ! log || ! log.length ) {
			return h( 'div' );
		}
		return card(
			__( 'گزارش رویدادها', 'ai-theme-studio' ),
			h( 'ul', { class: 'ats-log' }, log.map( ( l ) => h( 'li', { class: 'ats-log--' + l.level }, h( 'time', {}, fmtDate( l.created_at ) ), ' ', l.message ) ) )
		);
	}

	/* inputs: prompt editor, images, preferences */

	async function tabInputs( body, project ) {
		const sys = state.system;
		const locked = [ 'generating' ].includes( project.status );
		const imgCard = card( __( 'تصاویر مرجع', 'ai-theme-studio' ) );
		const list = h( 'div', { class: 'ats-images' } );
		const vision = sys.provider && sys.provider.vision;
		imgCard.appendChild( h( 'p', { class: 'ats-help' }, __( 'لوگو، نمونه طراحی موردعلاقه، عکس محصول یا تیم را بارگذاری کنید و نقش هر تصویر را مشخص کنید. تصاویر «نمونه طراحی» و «الهام» فقط برای سبک استفاده می‌شوند و در سایت قرار نمی‌گیرند.', 'ai-theme-studio' ) ) );
		if ( ! vision ) {
			imgCard.appendChild( notice( 'warning', __( 'مدل فعلی از تحلیل تصویر پشتیبانی نمی‌کند (یا غیرفعال است)؛ تصاویر فقط بر اساس نقشی که تعیین می‌کنید استفاده می‌شوند.', 'ai-theme-studio' ) ) );
		}
		if ( project.image_status === 'vision_unavailable' ) {
			imgCard.appendChild( notice( 'warning', __( 'در آخرین اجرا تصاویر تحلیل نشدند چون مدل از تصویر پشتیبانی نمی‌کرد.', 'ai-theme-studio' ) ) );
		}

		const drawImages = ( images ) => {
			clear( list );
			images.forEach( ( img ) => {
				const role = select( [ [ 'auto', ROLE_LABELS.auto ], ...sys.vocabulary.image_roles.map( ( r ) => [ r, ROLE_LABELS[ r ] || r ] ) ], img.role, { 'aria-label': __( 'نقش تصویر', 'ai-theme-studio' ) } );
				role.addEventListener( 'change', async () => {
					try {
						await api( '/projects/' + project.id + '/images/' + img.ref, { method: 'PATCH', data: { role: role.value } } );
						toast( 'success', __( 'نقش تصویر ذخیره شد.', 'ai-theme-studio' ) );
					} catch ( e ) {
						toast( 'error', errMsg( e ) );
					}
				} );
				list.appendChild(
					h(
						'figure',
						{ class: 'ats-image' },
						h( 'img', { src: safeUrl( img.thumb || img.url ), alt: img.alt || img.filename, loading: 'lazy', width: 150, height: 150 } ),
						h( 'figcaption', {}, h( 'code', {}, img.ref ), ' ', img.filename, ' (' + img.width + '×' + img.height + ')' ),
						role,
						img.analysis ? h( 'p', { class: 'ats-help' }, img.analysis.description || '', img.analysis.contains_third_party_branding ? h( 'strong', {}, ' ' + __( '(شامل نشان تجاری دیگران)', 'ai-theme-studio' ) ) : null ) : null,
						button( __( 'حذف از پروژه', 'ai-theme-studio' ), async () => {
							await api( '/projects/' + project.id + '/images/' + img.ref, { method: 'DELETE' } );
							images.splice( images.indexOf( img ), 1 );
							drawImages( images );
						}, 'button-link-delete', { disabled: locked } )
					)
				);
			} );
		};
		drawImages( project.images );

		const file = h( 'input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', multiple: true, disabled: locked || ! sys.can.upload } );
		const uploadRole = select( [ [ 'auto', ROLE_LABELS.auto ], ...sys.vocabulary.image_roles.map( ( r ) => [ r, ROLE_LABELS[ r ] || r ] ) ], 'auto' );
		const status = h( 'p', { class: 'ats-help', 'aria-live': 'polite' } );
		const drop = h( 'div', { class: 'ats-drop' }, h( 'p', {}, __( 'تصاویر را اینجا رها کنید یا انتخاب کنید (JPEG، PNG، WebP)', 'ai-theme-studio' ) ), file, field( __( 'نقش تصاویر جدید', 'ai-theme-studio' ), uploadRole ), status );
		const upload = async ( files ) => {
			for ( const f of files ) {
				if ( f.size > sys.limits.max_upload_mb * 1024 * 1024 ) {
					toast( 'error', sprintf( __( '%s بزرگ‌تر از حد مجاز است.', 'ai-theme-studio' ), f.name ) );
					continue;
				}
				status.textContent = sprintf( __( 'در حال بارگذاری %s…', 'ai-theme-studio' ), f.name );
				const fd = new FormData();
				fd.append( 'file', f );
				fd.append( 'role', uploadRole.value );
				try {
					const img = await api( '/projects/' + project.id + '/images', { method: 'POST', body: fd } );
					project.images.push( img );
					drawImages( project.images );
				} catch ( e ) {
					toast( 'error', f.name + ': ' + errMsg( e ) );
				}
			}
			status.textContent = '';
			file.value = '';
		};
		file.addEventListener( 'change', () => upload( Array.from( file.files ) ) );
		drop.addEventListener( 'dragover', ( e ) => {
			e.preventDefault();
			drop.classList.add( 'is-over' );
		} );
		drop.addEventListener( 'dragleave', () => drop.classList.remove( 'is-over' ) );
		drop.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			drop.classList.remove( 'is-over' );
			if ( ! locked ) {
				upload( Array.from( e.dataTransfer.files ) );
			}
		} );
		imgCard.appendChild( drop );
		imgCard.appendChild( list );
		body.appendChild( imgCard );

		const form = preferenceFields( project.input );
		form.nodes.forEach( ( n ) => body.appendChild( n ) );
		body.appendChild(
			h(
				'p',
				{ class: 'ats-actions' },
				button( __( 'ذخیره ورودی‌ها', 'ai-theme-studio' ), async () => {
					await api( '/projects/' + project.id, { method: 'PATCH', data: { input: form.collect() } } );
					toast( 'success', __( 'ذخیره شد. تغییرات در تولید بعدی اعمال می‌شوند.', 'ai-theme-studio' ) );
				}, 'button-secondary', { disabled: locked } ),
				' ',
				[ 'draft', 'failed', 'cancelled' ].includes( project.status ) ? button( __( 'ذخیره و شروع تولید', 'ai-theme-studio' ), async () => {
					await api( '/projects/' + project.id, { method: 'PATCH', data: { input: form.collect() } } );
					await post( '/projects/' + project.id + '/generate', {} );
					go( 'project/' + project.id + '/overview' );
				}, 'button-primary' ) : null,
				' ',
				button( __( 'حذف پروژه', 'ai-theme-studio' ), async () => {
					if ( window.confirm( __( 'پروژه و تاریخچه آن حذف شود؟ صفحات ساخته‌شده در وردپرس باقی می‌مانند.', 'ai-theme-studio' ) ) ) {
						await api( '/projects/' + project.id + '?confirm=1', { method: 'DELETE' } );
						go( '' );
					}
				}, 'button-link-delete' )
			)
		);
	}

	/* sitemap editor */

	async function tabSitemap( body, project ) {
		const data = await api( '/projects/' + project.id + '/sitemap' );
		if ( ! data.sitemap ) {
			body.appendChild( notice( 'info', __( 'نقشه سایت هنوز ساخته نشده است.', 'ai-theme-studio' ) ) );
			return;
		}
		const sitemap = JSON.parse( JSON.stringify( data.sitemap ) );
		const editable = data.editable;
		const wrap = h( 'div' );
		if ( ! editable ) {
			body.appendChild( notice( 'info', __( 'نقشه سایت فقط در مرحله بررسی قابل ویرایش است. پس از تولید، صفحات را از بخش «صفحات» بازتولید کنید.', 'ai-theme-studio' ) ) );
		}

		const sectionOptions = data.sections.map( ( s ) => [ s, s ] );
		const pageOptions = data.page_types.map( ( t ) => [ t, PAGE_LABELS[ t ] || t ] );

		const draw = () => {
			clear( wrap );
			sitemap.pages.forEach( ( page, idx ) => {
				const title = h( 'input', { type: 'text', value: page.title, disabled: ! editable, 'aria-label': __( 'عنوان', 'ai-theme-studio' ) } );
				title.addEventListener( 'input', () => ( page.title = title.value ) );
				const slug = h( 'input', { type: 'text', value: page.slug, dir: 'ltr', disabled: ! editable, 'aria-label': __( 'نامک', 'ai-theme-studio' ) } );
				slug.addEventListener( 'input', () => ( page.slug = slug.value ) );
				const label = h( 'input', { type: 'text', value: page.menu_label || '', disabled: ! editable, 'aria-label': __( 'برچسب منو', 'ai-theme-studio' ) } );
				label.addEventListener( 'input', () => ( page.menu_label = label.value ) );
				const type = select( pageOptions, page.type, { disabled: ! editable || page.key === 'home' } );
				type.addEventListener( 'change', () => ( page.type = type.value ) );
				const parent = select( [ [ '', __( 'بدون والد', 'ai-theme-studio' ) ], ...sitemap.pages.filter( ( p ) => p.key !== page.key ).map( ( p ) => [ p.key, p.title ] ) ], page.parent || '', { disabled: ! editable || page.key === 'home' } );
				parent.addEventListener( 'change', () => ( page.parent = parent.value || null ) );
				const inMenu = h( 'input', { type: 'checkbox', checked: page.in_menu, disabled: ! editable } );
				inMenu.addEventListener( 'change', () => ( page.in_menu = inMenu.checked ) );
				const inFooter = h( 'input', { type: 'checkbox', checked: page.in_footer, disabled: ! editable } );
				inFooter.addEventListener( 'change', () => ( page.in_footer = inFooter.checked ) );
				const purpose = h( 'textarea', { rows: 2, disabled: ! editable, 'aria-label': __( 'هدف صفحه', 'ai-theme-studio' ) } );
				purpose.value = page.purpose || '';
				purpose.addEventListener( 'input', () => ( page.purpose = purpose.value ) );

				const sections = h(
					'ol',
					{ class: 'ats-sections' },
					page.sections.map( ( s, si ) => {
						const st = select( sectionOptions, s.type, { disabled: ! editable } );
						st.addEventListener( 'change', () => ( s.type = st.value ) );
						const brief = h( 'input', { type: 'text', value: s.summary || '', disabled: ! editable, 'aria-label': __( 'توضیح بخش', 'ai-theme-studio' ) } );
						brief.addEventListener( 'input', () => ( s.summary = brief.value ) );
						return h(
							'li',
							{},
							h( 'code', {}, s.id ),
							st,
							brief,
							editable ? button( '↑', () => {
								if ( si > 0 ) {
									page.sections.splice( si - 1, 0, page.sections.splice( si, 1 )[ 0 ] );
									draw();
								}
							}, 'button-small', { 'aria-label': __( 'انتقال به بالا', 'ai-theme-studio' ) } ) : null,
							editable && page.sections.length > 1 ? button( '×', () => {
								page.sections.splice( si, 1 );
								draw();
							}, 'button-small button-link-delete', { 'aria-label': __( 'حذف بخش', 'ai-theme-studio' ) } ) : null
						);
					} )
				);

				wrap.appendChild(
					h(
						'details',
						{ class: 'ats-page-edit', open: idx === 0 },
						h( 'summary', {}, h( 'strong', {}, page.title ), ' ', h( 'code', {}, '/' + page.slug ), ' — ', PAGE_LABELS[ page.type ] || page.type, page.in_menu ? ' · ' + __( 'در منو', 'ai-theme-studio' ) : '' ),
						h( 'div', { class: 'ats-grid-3' }, field( __( 'عنوان', 'ai-theme-studio' ), title ), field( __( 'نامک', 'ai-theme-studio' ), slug ), field( __( 'برچسب منو', 'ai-theme-studio' ), label ) ),
						h( 'div', { class: 'ats-grid-3' }, field( __( 'نوع', 'ai-theme-studio' ), type ), field( __( 'صفحه والد', 'ai-theme-studio' ), parent ) ),
						h( 'p', {}, h( 'label', { class: 'ats-check' }, inMenu, __( 'در منوی اصلی', 'ai-theme-studio' ) ), ' ', h( 'label', { class: 'ats-check' }, inFooter, __( 'در فوتر', 'ai-theme-studio' ) ) ),
						field( __( 'هدف صفحه', 'ai-theme-studio' ), purpose ),
						h( 'h4', {}, __( 'بخش‌ها', 'ai-theme-studio' ) ),
						sections,
						editable ? h(
							'p',
							{},
							button( __( 'افزودن بخش', 'ai-theme-studio' ), () => {
								page.sections.push( { id: 'section-' + ( page.sections.length + 1 ) + '-' + Math.random().toString( 36 ).slice( 2, 5 ).replace( /[^a-z0-9]/g, 'x' ), type: 'content', summary: '' } );
								draw();
							}, 'button-small' ),
							' ',
							page.key !== 'home' ? button( __( 'حذف صفحه', 'ai-theme-studio' ), () => {
								sitemap.pages.splice( idx, 1 );
								draw();
							}, 'button-small button-link-delete' ) : null
						) : null
					)
				);
			} );
		};
		draw();

		body.appendChild( card( __( 'نقشه سایت پیشنهادی', 'ai-theme-studio' ), wrap ) );
		if ( editable ) {
			const addPage = () => {
				const n = sitemap.pages.length + 1;
				sitemap.pages.push( { key: 'page_' + n, type: 'custom', title: __( 'صفحه جدید', 'ai-theme-studio' ), slug: 'page-' + n, parent: null, menu_label: '', in_menu: true, in_footer: false, order: n, purpose: '', noindex: false, sections: [ { id: 'hero', type: 'hero', summary: '' }, { id: 'content', type: 'content', summary: '' } ], seo: { title: __( 'صفحه جدید', 'ai-theme-studio' ), description: __( 'توضیح این صفحه را بنویسید تا در نتایج جستجو نمایش داده شود.', 'ai-theme-studio' ), focus_topic: '' } } );
				draw();
			};
			body.appendChild(
				h(
					'p',
					{ class: 'ats-actions' },
					button( __( 'افزودن صفحه', 'ai-theme-studio' ), addPage ),
					' ',
					button( __( 'ذخیره تغییرات', 'ai-theme-studio' ), async () => {
						const r = await api( '/projects/' + project.id + '/sitemap', { method: 'PUT', data: { sitemap } } );
						( r.warnings || [] ).forEach( ( w ) => toast( 'warning', w ) );
						toast( 'success', __( 'نقشه سایت ذخیره شد.', 'ai-theme-studio' ) );
						render();
					} ),
					' ',
					button( __( 'تأیید و ادامه تولید', 'ai-theme-studio' ), async () => {
						await api( '/projects/' + project.id + '/sitemap', { method: 'PUT', data: { sitemap } } );
						await post( '/projects/' + project.id + '/sitemap/approve' );
						go( 'project/' + project.id + '/overview' );
					}, 'button-primary' )
				)
			);
		}
	}

	/* design system */

	async function tabDesign( body, project ) {
		const data = await api( '/projects/' + project.id + '/design' );
		if ( ! data.design ) {
			body.appendChild( notice( 'info', __( 'سیستم طراحی هنوز تولید نشده است.', 'ai-theme-studio' ) ) );
			return;
		}
		const d = data.design;
		body.appendChild(
			card(
				__( 'رنگ‌ها', 'ai-theme-studio' ),
				h( 'div', { class: 'ats-swatches' }, Object.entries( d.colors ).map( ( [ k, v ] ) => h( 'div', { class: 'ats-swatch' }, h( 'span', { class: 'ats-swatch__chip', style: 'background:' + ( /^#[0-9a-f]{6}$/i.test( v ) ? v : '#fff' ) } ), h( 'code', {}, k ), h( 'code', {}, v ) ) ) ),
				h( 'p', { class: 'ats-help' }, __( 'این رنگ‌ها به‌عنوان رنگ‌های Global المنتور با پیشوند ATS ثبت شده‌اند و رنگ‌های فعلی سایت تغییر نکرده‌اند. اعمال آن‌ها به‌عنوان رنگ‌های اصلی سایت در مرحله انتشار و با تأیید شما انجام می‌شود.', 'ai-theme-studio' ) )
			)
		);
		const t = d.typography;
		body.appendChild(
			card(
				__( 'تایپوگرافی', 'ai-theme-studio' ),
				h( 'p', {}, __( 'فونت عناوین:', 'ai-theme-studio' ), ' ', h( 'strong', {}, t.heading_font ), ' — ', __( 'فونت متن:', 'ai-theme-studio' ), ' ', h( 'strong', {}, t.body_font ) ),
				h( 'p', { class: 'ats-help' }, __( 'فونت‌ها از Google Fonts با font-display: swap بارگذاری می‌شوند. برای عملکرد بهتر، می‌توانید در تنظیمات المنتور بارگذاری محلی فونت را فعال کنید.', 'ai-theme-studio' ) ),
				d.rationale ? h( 'p', {}, d.rationale ) : null
			)
		);
	}

	/* pages: preview, edit, regenerate */

	async function tabPages( body, project ) {
		const pages = await api( '/projects/' + project.id + '/pages' );
		if ( ! pages.length ) {
			body.appendChild( notice( 'info', __( 'هنوز صفحه‌ای ساخته نشده است.', 'ai-theme-studio' ) ) );
			return;
		}
		const canRegen = [ 'ready', 'published' ].includes( project.status );
		const regen = async ( key, section ) => {
			const instruction = window.prompt( section ? __( 'چه تغییری در این بخش می‌خواهید؟ (اختیاری)', 'ai-theme-studio' ) : __( 'چه تغییری در این صفحه می‌خواهید؟ (اختیاری)', 'ai-theme-studio' ), '' );
			if ( instruction === null ) {
				return;
			}
			const path = '/projects/' + project.id + '/pages/' + key + ( section ? '/sections/' + section : '' ) + '/regenerate';
			try {
				await post( path, { instruction } );
			} catch ( e ) {
				if ( e && e.code === 'ats_user_edits' ) {
					if ( ! window.confirm( e.message + '\n\n' + __( 'ادامه می‌دهید؟', 'ai-theme-studio' ) ) ) {
						return;
					}
					await post( path, { instruction, confirm: true } );
				} else {
					throw e;
				}
			}
			go( 'project/' + project.id + '/overview' );
		};

		pages.forEach( ( p ) => {
			body.appendChild(
				card(
					null,
					h( 'div', { class: 'ats-page-head' }, h( 'h3', {}, p.title ), p.status ? badge( p.status ) : null, p.modified ? h( 'span', { class: 'ats-badge ats-badge--edited' }, __( 'ویرایش‌شده در المنتور', 'ai-theme-studio' ) ) : null ),
					h( 'p', { class: 'ats-help' }, h( 'code', {}, '/' + p.slug ), ' · ', PAGE_LABELS[ p.type ] || p.type, ' · ', sprintf( __( '%d پیوند', 'ai-theme-studio' ), p.links ) ),
					p.seo ? h( 'p', { class: 'ats-seo-preview' }, h( 'span', { class: 'ats-seo-preview__title' }, p.seo.title ), h( 'span', { class: 'ats-seo-preview__desc' }, p.seo.description ) ) : null,
					h(
						'p',
						{ class: 'ats-actions' },
						p.preview_url ? h( 'a', { class: 'button', href: safeUrl( p.preview_url ), target: '_blank', rel: 'noopener' }, __( 'پیش‌نمایش', 'ai-theme-studio' ) ) : null,
						' ',
						p.edit_url ? h( 'a', { class: 'button button-primary', href: safeUrl( p.edit_url ), target: '_blank', rel: 'noopener' }, __( 'ویرایش در المنتور', 'ai-theme-studio' ) ) : null,
						' ',
						canRegen && p.generated ? button( __( 'بازتولید صفحه', 'ai-theme-studio' ), () => regen( p.key, null ) ) : null
					),
					p.sections.length ? h(
						'details',
						{},
						h( 'summary', {}, sprintf( __( 'بخش‌ها (%d)', 'ai-theme-studio' ), p.sections.length ) ),
						h( 'ul', { class: 'ats-list' }, p.sections.map( ( s ) => h( 'li', {}, h( 'span', {}, s.label + ' ' ), h( 'code', {}, s.type ), ' ', canRegen ? button( __( 'بازتولید این بخش', 'ai-theme-studio' ), () => regen( p.key, s.id ), 'button-small' ) : null ) ) )
					) : null
				)
			);
		} );
		if ( project.header_edit_url || project.footer_edit_url ) {
			body.appendChild( card( __( 'هدر و فوتر', 'ai-theme-studio' ), h( 'p', { class: 'ats-actions' }, project.header_edit_url ? h( 'a', { class: 'button', href: safeUrl( project.header_edit_url ), target: '_blank', rel: 'noopener' }, __( 'ویرایش هدر', 'ai-theme-studio' ) ) : null, ' ', project.footer_edit_url ? h( 'a', { class: 'button', href: safeUrl( project.footer_edit_url ), target: '_blank', rel: 'noopener' }, __( 'ویرایش فوتر', 'ai-theme-studio' ) ) : null ) ) );
		}
	}

	/* navigation */

	async function tabNavigation( body, project ) {
		const data = await api( '/projects/' + project.id + '/navigation' );
		if ( ! data.proposal || ! data.proposal.length ) {
			body.appendChild( notice( 'info', __( 'پیشنهاد منو پس از ساخت نقشه سایت آماده می‌شود.', 'ai-theme-studio' ) ) );
			return;
		}
		if ( data.block_theme ) {
			body.appendChild( notice( 'warning', __( 'قالب فعال یک قالب بلوکی است و از جایگاه‌های منوی کلاسیک استفاده نمی‌کند. منو ساخته می‌شود و در هدر صفحات تولیدشده نمایش داده می‌شود، اما برای نمایش در قالب باید آن را در ویرایشگر سایت انتخاب کنید.', 'ai-theme-studio' ) ) );
		}
		const tree = ( parent ) => {
			const items = data.proposal.filter( ( i ) => ( i.parent || null ) === parent );
			return items.length ? h( 'ul', { class: 'ats-tree' }, items.map( ( i ) => h( 'li', {}, i.label, tree( i.key ) ) ) ) : null;
		};
		body.appendChild( card( __( 'منوی پیشنهادی', 'ai-theme-studio' ), tree( null ) ) );

		if ( ! state.system.can.menus ) {
			body.appendChild( notice( 'warning', __( 'برای ساخت یا تغییر منو، دسترسی «edit_theme_options» لازم است.', 'ai-theme-studio' ) ) );
			return;
		}
		const mode = select( [ [ 'create', __( 'ساخت منوی جدید (یا به‌روزرسانی منوی همین پروژه)', 'ai-theme-studio' ) ], [ 'reuse', __( 'افزودن موارد به یک منوی موجود', 'ai-theme-studio' ) ] ], data.suggested_menu ? 'reuse' : 'create' );
		const menu = select( data.menus.map( ( m ) => [ String( m.id ), m.name + ' (' + m.count + ')' ] ), String( data.suggested_menu || ( data.menus[ 0 ] && data.menus[ 0 ].id ) || '' ) );
		const name = h( 'input', { type: 'text', class: 'regular-text', value: project.name } );
		const locs = data.locations.map( ( l ) => {
			const assignedTo = data.menus.find( ( m ) => m.id === l.assigned );
			return {
				slug: l.slug,
				el: h( 'input', { type: 'checkbox', value: l.slug } ),
				label: l.label + ( assignedTo ? ' — ' + sprintf( __( 'فعلاً: %s', 'ai-theme-studio' ), assignedTo.name ) : '' ),
				assigned: l.assigned,
			};
		} );
		const replace = h( 'input', { type: 'checkbox' } );
		const menuRow = field( __( 'منوی موجود', 'ai-theme-studio' ), menu );
		const nameRow = field( __( 'نام منوی جدید', 'ai-theme-studio' ), name );
		const toggle = () => {
			menuRow.hidden = mode.value !== 'reuse';
			nameRow.hidden = mode.value === 'reuse';
		};
		mode.addEventListener( 'change', toggle );
		toggle();

		body.appendChild(
			card(
				__( 'اعمال منو', 'ai-theme-studio' ),
				h( 'p', { class: 'ats-help' }, __( 'هیچ منو یا آیتمی حذف نمی‌شود. آیتم‌های تکراری اضافه نمی‌شوند.', 'ai-theme-studio' ) ),
				field( __( 'روش', 'ai-theme-studio' ), mode ),
				data.menus.length ? menuRow : null,
				nameRow,
				locs.length ? h( 'fieldset', {}, h( 'legend', {}, __( 'جایگاه‌های منو در قالب', 'ai-theme-studio' ) ), locs.map( ( l ) => h( 'label', { class: 'ats-check' }, l.el, l.label ) ) ) : h( 'p', { class: 'ats-help' }, __( 'قالب فعال جایگاه منو ثبت نکرده است.', 'ai-theme-studio' ) ),
				locs.length ? h( 'label', { class: 'ats-check' }, replace, __( 'جایگاه‌هایی که منوی دیگری دارند هم جایگزین شوند (منوی قبلی حذف نمی‌شود)', 'ai-theme-studio' ) ) : null,
				h(
					'p',
					{},
					button( __( 'اعمال منو', 'ai-theme-studio' ), async () => {
						const chosen = locs.filter( ( l ) => l.el.checked );
						if ( replace.checked && chosen.some( ( l ) => l.assigned ) && ! window.confirm( __( 'منوی فعلی برخی جایگاه‌ها با منوی جدید جایگزین می‌شود. ادامه می‌دهید؟', 'ai-theme-studio' ) ) ) {
							return;
						}
						const r = await post( '/projects/' + project.id + '/navigation', { mode: mode.value, menu_id: parseInt( menu.value, 10 ) || 0, menu_name: name.value, locations: chosen.map( ( l ) => l.slug ), replace_locations: replace.checked } );
						toast( 'success', sprintf( __( 'منو اعمال شد: %1$d مورد جدید، %2$d مورد موجود.', 'ai-theme-studio' ), r.created || 0, r.updated || 0 ) );
						( r.skipped_locations || [] ).forEach( ( s ) => toast( 'warning', sprintf( __( 'جایگاه %s منوی دیگری دارد و تغییر نکرد.', 'ai-theme-studio' ), s ) ) );
						render();
					}, 'button-primary' )
				)
			)
		);
	}

	/* SEO audit */

	async function tabSeo( body, project ) {
		const data = await api( '/projects/' + project.id + '/audit' );
		const rerun = button( __( 'اجرای دوباره بررسی', 'ai-theme-studio' ), async () => {
			await post( '/projects/' + project.id + '/audit' );
			render();
		} );
		if ( ! data.seo ) {
			body.appendChild( card( null, h( 'p', {}, __( 'هنوز بررسی سئو انجام نشده است.', 'ai-theme-studio' ) ), rerun ) );
			return;
		}
		const s = data.seo;
		body.appendChild(
			card(
				__( 'بررسی سئو', 'ai-theme-studio' ),
				h( 'p', { class: 'ats-big-score' }, scoreBadge( s.score ), ' ', __( 'امتیاز بررسی داخلی (این عدد امتیاز Lighthouse نیست)', 'ai-theme-studio' ) ),
				s.summary ? h( 'ul', { class: 'ats-list' }, Object.entries( s.summary ).map( ( [ k, v ] ) => h( 'li', {}, h( 'code', {}, k ), ': ', typeof v === 'object' ? JSON.stringify( v ) : String( v ) ) ) ) : null,
				rerun
			)
		);
		body.appendChild( issuesCard( s.issues ) );
		if ( data.elementor && data.elementor.length ) {
			body.appendChild(
				card(
					__( 'اعتبارسنجی ساختار المنتور', 'ai-theme-studio' ),
					h( 'ul', { class: 'ats-list' }, data.elementor.map( ( e ) => h( 'li', {}, h( 'strong', {}, e.page ), ' — ', e.valid ? __( 'معتبر', 'ai-theme-studio' ) : __( 'نامعتبر', 'ai-theme-studio' ), ' · ', sprintf( __( '%d عنصر', 'ai-theme-studio' ), e.stats.elements || 0 ), e.modified ? ' · ' + __( 'ویرایش‌شده', 'ai-theme-studio' ) : '', e.errors.length ? h( 'ul', {}, e.errors.slice( 0, 10 ).map( ( x ) => h( 'li', {}, x ) ) ) : null ) ) )
				)
			);
		}
	}

	function issuesCard( issues ) {
		if ( ! issues || ! issues.length ) {
			return card( __( 'مشکلات', 'ai-theme-studio' ), h( 'p', {}, __( 'مشکلی یافت نشد.', 'ai-theme-studio' ) ) );
		}
		const order = { error: 0, warning: 1, info: 2 };
		const sorted = issues.slice().sort( ( a, b ) => ( order[ a.severity ] ?? 3 ) - ( order[ b.severity ] ?? 3 ) );
		return card(
			sprintf( __( 'مشکلات (%d)', 'ai-theme-studio' ), issues.length ),
			h(
				'table',
				{ class: 'widefat striped ats-table' },
				h( 'thead', {}, h( 'tr', {}, h( 'th', {}, __( 'شدت', 'ai-theme-studio' ) ), h( 'th', {}, __( 'صفحه', 'ai-theme-studio' ) ), h( 'th', {}, __( 'شرح', 'ai-theme-studio' ) ) ) ),
				h( 'tbody', {}, sorted.map( ( i ) => h( 'tr', { class: 'ats-sev--' + i.severity }, h( 'td', {}, i.severity ), h( 'td', {}, i.page || '—' ), h( 'td', {}, i.message, i.fix ? h( 'div', { class: 'ats-help' }, i.fix ) : null ) ) ) )
			)
		);
	}

	/* performance */

	async function tabPerformance( body, project ) {
		const [ data, pages ] = await Promise.all( [ api( '/projects/' + project.id + '/audit' ), api( '/projects/' + project.id + '/pages' ) ] );
		body.appendChild(
			notice( 'info', __( 'امتیازهای Lighthouse فقط از اجرای واقعی PageSpeed Insights یا Lighthouse CI نمایش داده می‌شوند. بررسی داخلی زیر، خطرات شناخته‌شده عملکرد را در ساختار صفحات می‌سنجد و جایگزین آزمون واقعی نیست.', 'ai-theme-studio' ) )
		);
		if ( data.performance ) {
			body.appendChild(
				card(
					__( 'بررسی داخلی عملکرد', 'ai-theme-studio' ),
					h(
						'table',
						{ class: 'widefat striped ats-table' },
						h( 'thead', {}, h( 'tr', {}, h( 'th', {}, __( 'صفحه', 'ai-theme-studio' ) ), h( 'th', {}, __( 'عناصر', 'ai-theme-studio' ) ), h( 'th', {}, __( 'ویجت‌ها', 'ai-theme-studio' ) ), h( 'th', {}, __( 'عمق', 'ai-theme-studio' ) ), h( 'th', {}, __( 'DOM تخمینی', 'ai-theme-studio' ) ) ) ),
						h( 'tbody', {}, ( data.performance.pages || [] ).map( ( p ) => h( 'tr', {}, h( 'td', {}, p.page ), h( 'td', {}, String( p.elements ) ), h( 'td', {}, String( p.widgets ) ), h( 'td', {}, String( p.depth ) ), h( 'td', {}, String( p.estimated_dom ) ) ) ) )
					)
				)
			);
			body.appendChild( issuesCard( data.performance.issues ) );
		}

		const results = data.lighthouse || {};
		const published = pages.filter( ( p ) => p.status === 'publish' );
		const psiBox = card( __( 'آزمون واقعی PageSpeed Insights', 'ai-theme-studio' ) );
		if ( ! published.length ) {
			psiBox.appendChild( h( 'p', {}, __( 'PageSpeed فقط صفحات عمومی را آزمون می‌کند. پس از انتشار، یا روی محیط محلی با Lighthouse CI (راهنما در docs/LIGHTHOUSE.md) آزمون کنید.', 'ai-theme-studio' ) ) );
		} else {
			const table = h(
				'table',
				{ class: 'widefat striped ats-table' },
				h( 'thead', {}, h( 'tr', {}, h( 'th', {}, __( 'صفحه', 'ai-theme-studio' ) ), h( 'th', {}, __( 'دستگاه', 'ai-theme-studio' ) ), h( 'th', {}, 'Performance' ), h( 'th', {}, 'Accessibility' ), h( 'th', {}, 'Best Practices' ), h( 'th', {}, 'SEO' ), h( 'th', {}, '' ) ) ),
				h(
					'tbody',
					{},
					published.flatMap( ( p ) =>
						[ 'mobile', 'desktop' ].map( ( st ) => {
							const r = results[ p.key + '/' + st ];
							const sc = ( r && r.scores ) || {};
							return h(
								'tr',
								{},
								h( 'td', {}, p.title ),
								h( 'td', {}, st === 'mobile' ? __( 'موبایل', 'ai-theme-studio' ) : __( 'دسکتاپ', 'ai-theme-studio' ) ),
								h( 'td', {}, scoreBadge( sc.performance ) ),
								h( 'td', {}, scoreBadge( sc.accessibility ) ),
								h( 'td', {}, scoreBadge( sc[ 'best-practices' ] ) ),
								h( 'td', {}, scoreBadge( sc.seo ) ),
								h( 'td', {}, button( r ? __( 'آزمون دوباره', 'ai-theme-studio' ) : __( 'اجرای آزمون', 'ai-theme-studio' ), async () => {
									await post( '/projects/' + project.id + '/pagespeed', { page: p.key, strategy: st } );
									render();
								}, 'button-small' ), r ? h( 'div', { class: 'ats-help' }, fmtDate( r.fetched_at ), r.metrics ? ' · ' + Object.entries( r.metrics ).map( ( [ k, v ] ) => k.replace( /-/g, ' ' ) + ': ' + v ).join( '، ' ) : '' ) : null )
							);
						} )
					)
				)
			);
			psiBox.appendChild( table );
		}
		body.appendChild( psiBox );
		if ( data.config ) {
			body.appendChild( card( __( 'تنظیمات عملکرد المنتور', 'ai-theme-studio' ), h( 'pre', { class: 'ats-pre', dir: 'ltr' }, JSON.stringify( data.config, null, 2 ) ), h( 'p', { class: 'ats-help' }, __( 'برای آزمون Lighthouse روی محیط محلی یا staging (صفحات باید منتشرشده و بدون ورود قابل دسترس باشند)، از lighthouserc.cjs همراه افزونه استفاده کنید (docs/LIGHTHOUSE.md).', 'ai-theme-studio' ) ) ) );
		}
	}

	/* publish */

	function tabPublish( body, project ) {
		const sys = state.system;
		if ( ! sys.can.publish ) {
			body.appendChild( notice( 'warning', __( 'برای انتشار، دسترسی انتشار برگه‌ها لازم است.', 'ai-theme-studio' ) ) );
			return;
		}
		if ( ! [ 'ready', 'published' ].includes( project.status ) ) {
			body.appendChild( notice( 'info', __( 'پیش از انتشار، تولید باید کامل شود.', 'ai-theme-studio' ) ) );
			return;
		}
		const o = project.input.options || {};
		const front = h( 'input', { type: 'checkbox', checked: !! o.set_front_page, disabled: ! sys.can.global_styles } );
		const nf = h( 'input', { type: 'checkbox', checked: true, disabled: ! sys.can.global_styles } );
		const styles = h( 'input', { type: 'checkbox', disabled: ! sys.can.global_styles } );
		const tb = h( 'input', { type: 'checkbox', disabled: ! sys.can.global_styles || ! sys.elementor.theme_builder } );
		const result = h( 'div', { 'aria-live': 'polite' } );
		body.appendChild(
			card(
				__( 'انتشار سایت', 'ai-theme-studio' ),
				h( 'p', {}, __( 'تا این لحظه همه صفحات پیش‌نویس هستند و هیچ تغییری در سایت عمومی شما ایجاد نشده است.', 'ai-theme-studio' ) ),
				h( 'label', { class: 'ats-check' }, front, __( 'صفحه اصلی پروژه، صفحه نخست سایت شود', 'ai-theme-studio' ) ),
				h( 'label', { class: 'ats-check' }, nf, __( 'صفحه ۴۰۴ ساخته‌شده برای خطای ۴۰۴ سایت استفاده شود', 'ai-theme-studio' ) ),
				h( 'label', { class: 'ats-check' }, styles, __( 'رنگ‌ها و فونت‌های اصلی سایت در المنتور (Site Settings) با سیستم طراحی جدید جایگزین شوند (نسخه قبلی ذخیره می‌شود)', 'ai-theme-studio' ) ),
				h( 'label', { class: 'ats-check' }, tb, __( 'هدر و فوتر روی کل سایت فعال شوند (Theme Builder — نیازمند المنتور پرو)', 'ai-theme-studio' ) ),
				! sys.elementor.theme_builder ? h( 'p', { class: 'ats-help' }, __( 'بدون المنتور پرو، هدر و فوتر فقط روی صفحات ساخته‌شده نمایش داده می‌شوند.', 'ai-theme-studio' ) ) : null,
				h(
					'p',
					{ class: 'ats-actions' },
					button( __( 'انتشار', 'ai-theme-studio' ), async () => {
						if ( ! window.confirm( __( 'صفحات منتشر و برای عموم قابل مشاهده شوند؟', 'ai-theme-studio' ) ) ) {
							return;
						}
						const r = await post( '/projects/' + project.id + '/publish', { confirm: true, set_front_page: front.checked, use_404: nf.checked, apply_site_styles: styles.checked, theme_builder: tb.checked } );
						clear( result ).appendChild( notice( r.failed.length ? 'warning' : 'success', sprintf( __( '%d صفحه منتشر شد.', 'ai-theme-studio' ), r.published.length ) ) );
						r.notes.forEach( ( n ) => result.appendChild( notice( 'info', n ) ) );
						if ( r.failed.length ) {
							result.appendChild( notice( 'error', __( 'انتشار این صفحات ناموفق بود: ', 'ai-theme-studio' ) + r.failed.join( '، ' ) ) );
						}
					}, 'button-primary button-hero' ),
					' ',
					project.status === 'published' ? button( __( 'بازگردانی انتشار', 'ai-theme-studio' ), async () => {
						if ( window.confirm( __( 'صفحات به پیش‌نویس برگردند و تنظیمات سراسری (صفحه نخست، ۴۰۴، رنگ‌ها) به حالت قبل بازگردد؟', 'ai-theme-studio' ) ) ) {
							await post( '/projects/' + project.id + '/unpublish' );
							render();
						}
					} ) : null
				),
				result
			)
		);
	}

	/* history */

	async function tabHistory( body, project ) {
		const data = await api( '/projects/' + project.id + '/history' );
		if ( data.backups.length ) {
			body.appendChild(
				card(
					__( 'نسخه‌های پشتیبان صفحات', 'ai-theme-studio' ),
					h( 'p', { class: 'ats-help' }, __( 'پیش از هر بازنویسی، محتوای قبلی المنتور ذخیره می‌شود.', 'ai-theme-studio' ) ),
					h(
						'table',
						{ class: 'widefat striped ats-table' },
						h( 'tbody', {}, data.backups.map( ( b ) => h( 'tr', {}, h( 'td', {}, b.page_key || '#' + b.post_id ), h( 'td', {}, 'v' + b.version ), h( 'td', {}, fmtDate( b.created_at ) ), h( 'td', {}, b.page_key ? button( __( 'بازگردانی', 'ai-theme-studio' ), async () => {
							if ( window.confirm( __( 'محتوای فعلی صفحه با این نسخه جایگزین شود؟ (نسخه فعلی هم پشتیبان‌گیری می‌شود)', 'ai-theme-studio' ) ) ) {
								await post( '/projects/' + project.id + '/pages/' + b.page_key + '/restore', { version: b.version } );
								toast( 'success', __( 'بازگردانی شد.', 'ai-theme-studio' ) );
								render();
							}
						}, 'button-small' ) : null ) ) ) )
					)
				)
			);
		}
		if ( data.prompts.length ) {
			body.appendChild( card( __( 'تاریخچه دستورها', 'ai-theme-studio' ), h( 'ul', { class: 'ats-list' }, data.prompts.map( ( p ) => h( 'li', {}, h( 'code', {}, p.target ), ' — ', p.instruction || '—', ' ', h( 'time', { class: 'ats-help' }, fmtDate( p.created_at ) ) ) ) ) ) );
		}
		body.appendChild(
			card(
				__( 'نسخه‌های خروجی', 'ai-theme-studio' ),
				h( 'table', { class: 'widefat striped ats-table' }, h( 'tbody', {}, data.artifacts.map( ( a ) => h( 'tr', {}, h( 'td', {}, h( 'code', {}, a.type + ( a.key ? '/' + a.key : '' ) ) ), h( 'td', {}, 'v' + a.version ), h( 'td', {}, Math.round( a.size / 1024 ) + ' KB' ), h( 'td', {}, fmtDate( a.created_at ) ) ) ) ) )
			)
		);
		body.appendChild( logCard( data.log ) );
	}

	render();
} )();
