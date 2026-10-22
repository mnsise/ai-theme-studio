#!/usr/bin/env node
/**
 * Build English (en_US) gettext catalogs from Persian source msgids.
 * Requires: languages/_strings.json, languages/_meta.json (from extract-i18n.mjs)
 *           languages/en-translations.json
 * Also runs msgfmt if available.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const root = path.resolve( __dirname, '..' );
const langDir = path.join( root, 'languages' );
const domain = 'ai-theme-studio';
const locale = 'en_US';

const strings = JSON.parse( fs.readFileSync( path.join( langDir, '_strings.json' ), 'utf8' ) );
const meta = JSON.parse( fs.readFileSync( path.join( langDir, '_meta.json' ), 'utf8' ) );
const translations = JSON.parse(
	fs.readFileSync( path.join( langDir, 'en-translations.json' ), 'utf8' )
);

const missing = strings.filter( ( s ) => ! translations[ s ] || translations[ s ] === '' );
if ( missing.length ) {
	console.error( `Missing ${ missing.length } English translations:` );
	for ( const m of missing.slice( 0, 40 ) ) console.error( ' -', m );
	if ( missing.length > 40 ) console.error( ` … and ${ missing.length - 40 } more` );
	process.exit( 1 );
}

function poEscape( s ) {
	return s
		.replace( /\\/g, '\\\\' )
		.replace( /"/g, '\\"' )
		.replace( /\n/g, '\\n' )
		.replace( /\t/g, '\\t' );
}

function formatMsg( keyword, text ) {
	if ( text.includes( '\n' ) ) {
		const parts = text.split( '\n' );
		let out = `${ keyword } ""\n`;
		for ( let i = 0; i < parts.length; i++ ) {
			const chunk = parts[ i ] + ( i < parts.length - 1 ? '\n' : '' );
			out += `"${ poEscape( chunk ) }"\n`;
		}
		return out;
	}
	return `${ keyword } "${ poEscape( text ) }"\n`;
}

const now = new Date().toISOString().replace( 'T', ' ' ).slice( 0, 16 ) + '+0000';
let po = `# Copyright (C) 2026 AI Theme Studio
# This file is distributed under the GPL-2.0-or-later.
msgid ""
msgstr ""
"Project-Id-Version: AI Theme Studio 1.0.0\\n"
"Report-Msgid-Bugs-To: \\n"
"POT-Creation-Date: ${ now }\\n"
"PO-Revision-Date: ${ now }\\n"
"Last-Translator: AI Theme Studio\\n"
"Language-Team: English\\n"
"Language: en_US\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
"X-Domain: ${ domain }\\n"
"X-Generator: build-en-locale.mjs\\n"

`;

for ( const msgid of strings ) {
	const e = meta[ msgid ] || { refs: [], comments: [] };
	for ( const c of e.comments || [] ) {
		po += `#. translators: ${ c }\n`;
	}
	for ( const r of ( e.refs || [] ).slice( 0, 8 ) ) {
		po += `#: ${ r }\n`;
	}
	po += formatMsg( 'msgid', msgid );
	po += formatMsg( 'msgstr', translations[ msgid ] );
	po += '\n';
}

const poPath = path.join( langDir, `${ domain }-${ locale }.po` );
fs.writeFileSync( poPath, po, 'utf8' );
console.log( 'Wrote', path.relative( root, poPath ) );

const moPath = path.join( langDir, `${ domain }-${ locale }.mo` );
try {
	execFileSync( 'msgfmt', [ '-o', moPath, poPath ], { stdio: 'inherit' } );
	console.log( 'Wrote', path.relative( root, moPath ) );
} catch ( err ) {
	console.warn( 'msgfmt failed; .mo not built:', err.message );
}

// Jed/JSON for wp_set_script_translations (handle-based + md5-based)
const localeData = {
	[ domain ]: {
		'': {
			domain,
			lang: locale,
			'plural-forms': 'nplurals=2; plural=(n != 1);',
		},
	},
};
for ( const msgid of strings ) {
	localeData[ domain ][ msgid ] = [ translations[ msgid ] ];
}

const jed = {
	domain,
	locale_data: localeData,
};

const handleJson = path.join( langDir, `${ domain }-${ locale }-ats-admin.json` );
fs.writeFileSync( handleJson, JSON.stringify( jed ), 'utf8' );
console.log( 'Wrote', path.relative( root, handleJson ) );

const jsRel = 'ai-theme-studio/admin/js/app.js';
const md5 = crypto.createHash( 'md5' ).update( jsRel ).digest( 'hex' );
const md5Json = path.join( langDir, `${ domain }-${ locale }-${ md5 }.json` );
fs.writeFileSync( md5Json, JSON.stringify( jed ), 'utf8' );
console.log( 'Wrote', path.relative( root, md5Json ) );

console.log( `Done: ${ strings.length } strings → en_US` );
