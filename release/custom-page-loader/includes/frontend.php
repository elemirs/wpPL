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
            cpl_serve_file( $index_file, $base_url, $base_path );
            exit;
        }
    }
}

function cpl_serve_file( $file_path, $base_url, $base_path_dir ) {
    // Read the file content
    $content = file_get_contents( $file_path );
    
    // 1. Fix paths (The "Smart Path Fixer")
    // Use regex to replace relative or root-relative paths with absolute URLs if files exist
    $content = preg_replace_callback( '/(src|href)=([\'"])(.*?)\2/i', function($matches) use ($base_path_dir, $base_url) {
        $attr = $matches[1];
        $quote = $matches[2];
        $url = $matches[3];

        // Skip absolute URLs, anchors, mailto, etc.
        // Also skip definition of base tag itself if present
        if ( strpos($url, '//') !== false || strpos($url, 'http') === 0 || strpos($url, 'data:') === 0 || strpos($url, '#') === 0 || strpos($url, 'mailto:') === 0 ) {
            return $matches[0];
        }

        // Clean path to check existence (remove leading / or ./)
        $clean_path = ltrim( $url, '/.' );
        
        // Remove query strings for file checking
        $clean_path_file = strtok( $clean_path, '?' );

        if ( file_exists( $base_path_dir . '/' . $clean_path_file ) ) {
            // File exists! Replace with full absolute URL using our base
            // Remove leading slash from cleaned path to avoid double slashes with base URL
            return $attr . '=' . $quote . $base_url . $clean_path . $quote;
        }

        // Try checking without leading "./" if present (Vite often outputs ./assets/...)
        $alt_path = ltrim($url, '.'); // removes . from start
        $alt_path = ltrim($alt_path, '/'); // then removes /
        $alt_path_file = strtok($alt_path, '?');
        
        if ( file_exists( $base_path_dir . '/' . $alt_path_file ) ) {
             return $attr . '=' . $quote . $base_url . $alt_path . $quote;
        }

        return $matches[0];
    }, $content );

    // 2. Inject <base> tag as fallback (for CSS images etc)
    // Inject just after <head>
    $base_tag = '<base href="' . esc_url( $base_url ) . '">';
    if ( stripos( $content, '<head>' ) !== false ) {
        $content = preg_replace( '/<head>/i', "<head>\n" . $base_tag, $content, 1 );
    } else {
        $content = $base_tag . $content;
    }

    // Set correct headers
    header( 'Content-Type: text/html; charset=utf-8' );
    // Prevent caching during development/troubleshooting
    header( 'Cache-Control: no-store, no-cache, must-revalidate' );
    
    echo $content;
}
