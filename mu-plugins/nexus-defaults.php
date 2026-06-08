<?php
/*
Plugin Name: Nexus Default Settings Seeder
Description: Seeds opinionated WordPress options for a private, localhost-only
             agentic CMS (posts = tasks, comment threads = work log). Runs once
             per seed version on the first request after a fresh install, guarded
             by the `nexus_seed_version` option — so it never clobbers changes you
             later make by hand in wp-admin. Bump NEXUS_SEED_VERSION to roll out a
             new batch of defaults (which re-applies the whole set once).

             Why a seeder instead of wp-cli docs: the values below are required for
             the agent workflow to behave (e.g. pretty permalinks, or the comment
             moderation flags that otherwise leave every agent comment invisible),
             so they should be guaranteed on every fresh volume with zero operator
             steps — not left to a README command someone can skip.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NEXUS_SEED_VERSION', 2 );

add_action( 'init', function () {
	if ( (int) get_option( 'nexus_seed_version', 0 ) >= NEXUS_SEED_VERSION ) {
		return;
	}

	// timezone_string is operator-specific; default to UTC, override via env.
	$timezone = getenv( 'WORDPRESS_TIMEZONE' );
	if ( ! is_string( $timezone ) || $timezone === '' ) {
		$timezone = 'UTC';
	}
	if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
		$timezone = 'UTC';
	}

	$options = array(
		// Privacy / SEO — this is a personal CMS, keep it out of search indexes.
		'blog_public'                  => 0,

		// Comment thread = linear work log: no nesting, no pagination, never
		// auto-close, oldest-first, avatars on (Path B per-agent gravatars).
		'thread_comments'              => 0,
		'page_comments'                => 0,
		'close_comments_for_old_posts' => 0,
		'comment_order'                => 'asc',
		'default_comment_status'       => 'open',
		'show_avatars'                 => 1,
		'show_comments_cookies_opt_in' => 0,

		// No moderation gates — agents post via an authenticated account, and the
		// add-comment ability holds the comment whenever either flag is on, which
		// otherwise makes every agent comment invisible to posts-get.
		'comment_moderation'           => 0,
		'comment_previously_approved'  => 0,

		// No outbound email noise (single-operator local box).
		'comments_notify'              => 0,
		'moderation_notify'            => 0,

		// Not a public blog: kill trackbacks / pingbacks.
		'default_ping_status'          => 'closed',
		'default_pingback_flag'        => 0,

		// Local-time work-log timestamps.
		'timezone_string'              => $timezone,
	);

	foreach ( $options as $name => $value ) {
		update_option( $name, $value );
	}

	// Pretty permalinks are load-bearing: the agents resolve a Nexus URL by its
	// last path segment (the post slug). A fresh install defaults to plain
	// ?p=N, which has no slug. Force /%postname%/ and flush (soft — Caddy/FPM,
	// no .htaccess to write).
	if ( get_option( 'permalink_structure' ) !== '/%postname%/' ) {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$wp_rewrite->flush_rules( false );
	}

	// Remove WordPress' stock sample content. Matched by the default slugs, so
	// only the untouched defaults are ever deleted — real task posts (which have
	// their own slugs) are never matched. Force-deleting the "Hello world!" post
	// also drops the canned "A WordPress Commenter" comment attached to it.
	foreach ( array( 'hello-world', 'sample-page', 'privacy-policy' ) as $slug ) {
		$found = get_posts( array(
			'name'        => $slug,
			'post_type'   => array( 'post', 'page' ),
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
		) );
		if ( ! empty( $found ) ) {
			wp_delete_post( $found[0], true );
		}
	}
	// Clear the privacy-policy page pointer if that page was the one we removed.
	$privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );
	if ( $privacy_id && ! get_post( $privacy_id ) ) {
		update_option( 'wp_page_for_privacy_policy', 0 );
	}

	update_option( 'nexus_seed_version', NEXUS_SEED_VERSION );
} );
