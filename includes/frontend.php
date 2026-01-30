<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure the hook is added with high priority (1)
add_action( 'template_redirect', 'cpl_intercept_request', 1 );

function cpl_intercept_request() {
    // Debug Mode
    if ( isset( $_GET['cpl_debug'] ) && current_user_can( 'manage_options' ) ) {
        cpl_debug_info();
    }

    $pages = get_option( 'cpl_static_pages', [] );
    if ( empty( $pages ) ) {
        return;
    }

    global $wp;
    $request_uri = $_SERVER['REQUEST_URI'];
    // Remove query string
    $request_path = strtok( $request_uri, '?' );
    // Remove trailing slash if mostly equivalent (normalize) but keep root /
    if ( $request_path !== '/' ) {
        $request_path = untrailingslashit( $request_path );
    }

    // --- 1. Aggressive Home Detection ---
    // If we are at root /, and we have a 'home' page configured.
    // We check /index.php too just in case.
    if ( ( $request_path === '/' || $request_path === '/index.php' ) && isset( $pages['home'] ) ) {
        cpl_serve_landing_page( $pages['home'], 'home' );
        exit;
    }

    // --- 2. WP Standard Detection (Backup) ---
    // Fallback if the path didn't match / but WP thinks it is home
    if ( ( is_front_page() || is_home() ) && isset( $pages['home'] ) ) {
        cpl_serve_landing_page( $pages['home'], 'home' );
        exit;
    }

    // --- 3. Sub-page Detection using URL path ---
    // Breakdown the path to find potential slug
    $path_parts = explode( '/', trim( $request_path, '/' ) );
    
    // Iterate over configured pages to see if the current path matches
    foreach ( $pages as $p_slug => $data ) {
        // Skip home here, already handled
        if ( $p_slug === 'home' ) continue;

        // Exact match for the page itself (e.g. /my-landing-page)
        if ( $request_path === '/' . $p_slug ) {
            cpl_serve_landing_page( $data, $p_slug );
            exit;
        }

        // Asset check for this page (e.g. /my-landing-page/assets/style.css)
        if ( strpos( $request_path, '/' . $p_slug . '/' ) === 0 ) {
            $asset_rel_path = substr( $request_path, strlen( '/' . $p_slug . '/' ) );
            if ( cpl_check_and_serve_asset( $data['folder'], $asset_rel_path ) ) {
                exit;
            }
        }
    }
    
    // --- 4. Single Post Template Detection ---
    if ( is_single() ) {
        $post_template = get_option('cpl_post_template', []);
        if ( ! empty( $post_template['active'] ) ) {
            cpl_serve_landing_page( $post_template, 'POST_TEMPLATE' );
            exit;
        }
    }

    // --- 5. Post Template Assets Detection (Virtual Path) ---
    // Detects requests like /_cpl_pt/style.css
    if ( strpos( $request_path, '/_cpl_pt/' ) === 0 ) {
         $asset_rel_path = substr( $request_path, strlen( '/_cpl_pt/' ) );
         $post_template = get_option('cpl_post_template', []);
         if ( ! empty( $post_template['active'] ) ) {
             if ( cpl_check_and_serve_asset( $post_template['folder'], $asset_rel_path ) ) {
                 exit;
             }
         }
    }
    
    // --- 6. Home Assets Fallback ---
    // If we have a home page, maybe this request is for an asset at the root level?
    // e.g. /assets/style.css -> we check if 'home' folder has assets/style.css
    if ( isset( $pages['home'] ) ) {
        $asset_path = trim( $request_path, '/' );
        // Avoid intercepting critical WP paths
        if ( strpos( $asset_path, 'wp-' ) !== 0 && $asset_path !== 'index.php' && $asset_path !== 'xmlrpc.php' ) {
            if ( cpl_check_and_serve_asset( $pages['home']['folder'], $asset_path ) ) {
                exit;
            }
        }
    }
}

function cpl_serve_landing_page( $page_data, $slug ) {
    $folder = $page_data['folder'];
    $base_path = CPL_UPLOAD_DIR . '/' . $folder;
    $index_file = $base_path . '/index.html';

    if ( file_exists( $index_file ) ) {
        $content = file_get_contents( $index_file );
        
        // Inject <base> tag. 
        if ( $slug === 'POST_TEMPLATE' ) {
            // Special Virtual Asset Path for Posts
            $virtual_base = home_url( '/_cpl_pt/' );
        } else {
             // Standard Pages
            $virtual_base = home_url( '/' . ($slug === 'home' ? '' : $slug . '/') );
        }
        
        $base_tag = '<base href="' . esc_url( $virtual_base ) . '">';
        
        if ( stripos( $content, '<head>' ) !== false ) {
            $content = preg_replace( '/<head>/i', "<head>\n" . $base_tag, $content, 1 );
        } else {
            $content = $base_tag . $content;
        }

        header( 'Content-Type: text/html; charset=utf-8' );
        echo $content;
    }
}

function cpl_check_and_serve_asset( $folder, $relative_path ) {
    if ( empty( $relative_path ) ) return false;

    // Decode URL
    $relative_path = urldecode( $relative_path );
    $base_dir = CPL_UPLOAD_DIR . '/' . $folder;

    // 1. Try Exact Path
    $full_path = $base_dir . '/' . $relative_path;
    if ( cpl_try_serve_file( $full_path, $base_dir ) ) {
        return true;
    }
    
    // 2. Try Flattened Path (Root of page folder)
    // Helps if HTML asks for "assets/img/logo.png" but user uploaded "logo.png" at root
    $flat_name = basename( $relative_path );
    $flat_path = $base_dir . '/' . $flat_name;
    
    if ( $flat_path !== $full_path ) {
        if ( cpl_try_serve_file( $flat_path, $base_dir ) ) {
            return true;
        }
    }
    
    return false;
}

function cpl_try_serve_file( $path, $base_folder_path ) {
    if ( ! file_exists( $path ) ) return false;
    
    $real_path = realpath( $path );
    $real_base = realpath( $base_folder_path );
    
    // Security check: ensure file is inside the base folder
    if ( $real_path && $real_base && strpos( $real_path, $real_base ) === 0 && ! is_dir( $real_path ) ) {
        cpl_serve_file_content( $real_path );
        return true;
    }
    return false;
}

function cpl_serve_file_content( $file_path ) {
    $mime = wp_check_filetype( $file_path );
    $content_type = $mime['type'];
    
    if ( ! $content_type ) {
        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'svg' => 'image/svg+xml',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'map' => 'application/json'
        ];
        if ( isset( $types[$ext] ) ) {
            $content_type = $types[$ext];
        }
    }

    if ( $content_type ) {
        header( 'Content-Type: ' . $content_type );
    }
    
    header( 'Cache-Control: public, max-age=86400' );
    header( 'Access-Control-Allow-Origin: *' ); 
    
    readfile( $file_path );
    exit;
}

function cpl_debug_info() {
    $pages = get_option( 'cpl_static_pages', [] );
    echo '<div style="background:white; padding:20px; border:5px solid red; position:fixed; top:0; left:0; z-index:99999; color:black; max-width:500px; height: 100vh; overflow:auto;">';
    echo '<h3>CPL Debug Info</h3>';
    echo '<p>If you see this, the plugin is active and running.</p>';
    echo '<pre>';
    echo 'Request URI: ' . $_SERVER['REQUEST_URI'] . "\n";
    echo 'Is Front Page: ' . (is_front_page() ? 'Yes' : 'No') . "\n";
    echo 'Is Home: ' . (is_home() ? 'Yes' : 'No') . "\n";
    echo 'Pages Config: ' . print_r($pages, true);
    echo '</pre>';
    echo '</div>';
    exit; 
}


