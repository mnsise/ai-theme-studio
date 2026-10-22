#!/usr/bin/env node
/**
 * Extract gettext strings from PHP/JS for ai-theme-studio.
 * Usage: node tools/extract-i18n.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import crypto from 'node:crypto';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const root = path.resolve( __dirname, '..' );
const domain = 'ai-theme-studio';

const SKIP_DIRS = new Set( [
	'vendor',
	'node_modules',
	'.lighthouseci',
	'tests',
	'languages',
	'tools',
] );

/** @type {Map<string, { refs: string[], comments: string[] }>} */
const entries = new Map();

function walk( dir ) {
	for ( const name of fs.readdirSync( dir ) ) {
		if ( name.startsWith( '.' ) ) continue;
		const full = path.join( dir, name );
		const st = fs.statSync( full );
		if ( st.isDirectory() ) {
			if ( SKIP_DIRS.has( name ) ) continue;
			walk( full );
			continue;
		}
		if ( /\.(php|js)$/.test( name ) ) {
			extractFile( full );
		}
	}
}

function add( msgid, ref, comment = '' ) {
	if ( ! msgid || msgid === domain ) return;
	let e = entries.get( msgid );
	if ( ! e ) {
		e = { refs: [], comments: [] };
		entries.set( msgid, e );
	}
	if ( ref && ! e.refs.includes( ref ) ) e.refs.push( ref );
	if ( comment && ! e.comments.includes( comment ) ) e.comments.push( comment );
}

function extractFile( file ) {
	const rel = path.relative( root, file ).replace( /\\/g, '/' );
	const src = fs.readFileSync( file, 'utf8' );
	const isPhp = file.endsWith( '.php' );

	// Match __( '...', 'domain' ), _e, esc_html__, esc_attr__, _x( '...', 'ctx', 'domain' )
	// Also sprintf( __( '...', 'domain' ), ... )
	const patterns = isPhp
		? [
				/(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e)\s*\(\s*(['"])((?:\\.|(?!\1).)*)\1\s*,\s*['"]ai-theme-studio['"]\s*\)/gs,
				/_x\s*\(\s*(['"])((?:\\.|(?!\1).)*)\1\s*,\s*(['"])((?:\\.|(?!\3).)*)\3\s*,\s*['"]ai-theme-studio['"]\s*\)/gs,
				/_n\s*\(\s*(['"])((?:\\.|(?!\1).)*)\1\s*,\s*(['"])((?:\\.|(?!\3).)*)\3\s*,/gs,
		  ]
		: [
				// JS: __( 'text', 'ai-theme-studio' )
				/__\(\s*(['"])((?:\\.|(?!\1).)*)\1\s*,\s*['"]ai-theme-studio['"]\s*\)/gs,
		  ];

	let lineMap = null;
	const lineOf = ( idx ) => {
		if ( ! lineMap ) {
			lineMap = [ 0 ];
			for ( let i = 0; i < src.length; i++ ) {
				if ( src[ i ] === '\n' ) lineMap.push( i + 1 );
			}
		}
		let lo = 0;
		let hi = lineMap.length - 1;
		while ( lo < hi ) {
			const mid = ( lo + hi + 1 ) >> 1;
			if ( lineMap[ mid ] <= idx ) lo = mid;
			else hi = mid - 1;
		}
		return lo + 1;
	};

	const unescape = ( q, s ) => {
		try {
			// Use JSON parsing for double quotes; for single, manual
			if ( q === '"' ) return JSON.parse( `"${ s }"` );
		} catch {
			/* fall through */
		}
		return s
			.replace( /\\'/g, "'" )
			.replace( /\\"/g, '"' )
			.replace( /\\n/g, '\n' )
			.replace( /\\t/g, '\t' )
			.replace( /\\\\/g, '\\' );
	};

	for ( const re of patterns ) {
		re.lastIndex = 0;
		let m;
		while ( ( m = re.exec( src ) ) ) {
			const q = m[ 1 ];
			const raw = m[ 2 ];
			const msgid = unescape( q, raw );
			const line = lineOf( m.index );
			add( msgid, `${ rel }:${ line }` );
		}
	}

	// translators comments immediately above
	const translatorRe =
		/\/\*\s*translators:\s*([^*]+)\*\/\s*(?:\r?\n\s*)*(?:esc_html__|esc_attr__|__|_e)\s*\(\s*(['"])((?:\\.|(?!\2).)*)\2\s*,\s*['"]ai-theme-studio['"]/gs;
	let tm;
	while ( ( tm = translatorRe.exec( src ) ) ) {
		const msgid = unescape( tm[ 2 ], tm[ 3 ] );
		const e = entries.get( msgid );
		if ( e ) e.comments.push( tm[ 1 ].trim() );
	}
}

function poEscape( s ) {
	return s
		.replace( /\\/g, '\\\\' )
		.replace( /"/g, '\\"' )
		.replace( /\n/g, '\\n' )
		.replace( /\t/g, '\\t' );
}

function formatMsgid( msgid ) {
	if ( msgid.includes( '\n' ) ) {
		const parts = msgid.split( '\n' );
		let out = 'msgid ""\n';
		for ( let i = 0; i < parts.length; i++ ) {
			const chunk = parts[ i ] + ( i < parts.length - 1 ? '\n' : '' );
			out += `"${ poEscape( chunk ) }"\n`;
		}
		return out;
	}
	return `msgid "${ poEscape( msgid ) }"\n`;
}

walk( root );

const sorted = [ ...entries.keys() ].sort( ( a, b ) => a.localeCompare( b, 'fa' ) );
console.log( `Extracted ${ sorted.length } unique strings` );

const outDir = path.join( root, 'languages' );
fs.mkdirSync( outDir, { recursive: true } );

// Write JSON list for the translator step
fs.writeFileSync(
	path.join( outDir, '_strings.json' ),
	JSON.stringify( sorted, null, 2 ),
	'utf8'
);

// Write POT (msgid = Persian source, empty msgstr)
const now = new Date().toISOString().replace( 'T', ' ' ).slice( 0, 16 ) + '+0000';
let pot = `# Copyright (C) 2026 AI Theme Studio
# This file is distributed under the GPL-2.0-or-later.
msgid ""
msgstr ""
"Project-Id-Version: AI Theme Studio 1.0.0\\n"
"Report-Msgid-Bugs-To: \\n"
"Last-Translator: \\n"
"Language-Team: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"POT-Creation-Date: ${ now }\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"X-Domain: ${ domain }\\n"
"X-Generator: extract-i18n.mjs\\n"

`;

for ( const msgid of sorted ) {
	const e = entries.get( msgid );
	for ( const c of e.comments ) {
		pot += `#. translators: ${ c }\n`;
	}
	for ( const r of e.refs.slice( 0, 8 ) ) {
		pot += `#: ${ r }\n`;
	}
	pot += formatMsgid( msgid );
	pot += `msgstr ""\n\n`;
}

fs.writeFileSync( path.join( outDir, `${ domain }.pot` ), pot, 'utf8' );
console.log( `Wrote ${ domain }.pot` );

// Also dump refs map for translation script
const map = {};
for ( const msgid of sorted ) {
	map[ msgid ] = entries.get( msgid );
}
fs.writeFileSync( path.join( outDir, '_meta.json' ), JSON.stringify( map, null, 2 ), 'utf8' );

// md5 for JS script path (WordPress make-json style: relative to plugins dir)
const jsRel = 'ai-theme-studio/admin/js/app.js';
console.log( 'JS md5 filename suffix:', crypto.createHash( 'md5' ).update( jsRel ).digest( 'hex' ) );
