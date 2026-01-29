<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'cpl_intercept_request' );

function cpl_intercept_request() {
    $pages = get_option( 'cpl_static_pages', [] );
    if ( empty( $pages ) ) {
        return;
    }

    $current_slug = '';

    if ( is_front_page() || is_home() ) {
        $current_slug = 'home';
    } else {
        global $wp;
        // Get the current slug (e.g. 'about-us' or 'landing/v1')
        $current_slug = $wp->request;
    }

    // Check if we have a mapping for this slug
    if ( isset( $pages[ $current_slug ] ) ) {
        $folder = $pages[ $current_slug ]['folder'];
        $base_path = CPL_UPLOAD_DIR . '/' . $folder;
        $base_url = CPL_UPLOAD_URL . '/' . $folder . '/';
        $index_file = $base_path . '/index.html';

        if ( file_exists( $index_file ) ) {
            // Stop WordPress processing and serve file
            cpl_serve_file( $index_file, $base_url );
            exit;
        }
    }
}

function cpl_serve_file( $file_path, $base_url ) {
    // Read the file content
    $content = file_get_contents( $file_path );
    
    // Inject <base> tag just after <head>
    // This ensures relative links (css, js, images) resolve to the uploaded folder
    $base_tag = '<base href="' . esc_url( $base_url ) . '">';
    
    // Simple string replacement (case incentive logic might be needed for robustness but regex is safer)
    if ( stripos( $content, '<head>' ) !== false ) {
        $content = preg_replace( '/<head>/i', "<head>\n" . $base_tag, $content, 1 );
    } else {
        // Fallback: Prepend if no head found (unlikely for valid html)
        $content = $base_tag . $content;
    }

    // Set correct headers
    header( 'Content-Type: text/html; charset=utf-8' );
    
    echo $content;
}
