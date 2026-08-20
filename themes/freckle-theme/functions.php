<?php
/**
 * Theme setup: supported features, nav menu, and the one stylesheet.
 *
 * Deliberately minimal — this is a blank base, not a starter kit. Add to it
 * per-project rather than growing it into a shared framework.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers theme support and the primary nav menu.
 */
function freckle_theme_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
	);

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'freckle-theme' ),
		)
	);
}
add_action( 'after_setup_theme', 'freckle_theme_setup' );

/**
 * Enqueues the theme's one stylesheet.
 */
function freckle_theme_enqueue_assets() {
	wp_enqueue_style( 'freckle-theme-style', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
}
add_action( 'wp_enqueue_scripts', 'freckle_theme_enqueue_assets' );
