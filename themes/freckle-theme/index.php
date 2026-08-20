<?php
/**
 * The main template file — fallback for every request that doesn't match a
 * more specific template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

	<main id="primary" class="site-main">

	<?php if ( have_posts() ) : ?>

		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
				<?php the_title( '<h2><a href="' . esc_url( get_permalink() ) . '">', '</a></h2>' ); ?>
				<div class="entry-content">
					<?php the_content(); ?>
				</div>
			</article>
			<?php
		endwhile;

		the_posts_navigation();
		?>

	<?php else : ?>

		<p><?php esc_html_e( 'Nothing found.', 'freckle-theme' ); ?></p>

	<?php endif; ?>

	</main>

<?php
get_footer();
