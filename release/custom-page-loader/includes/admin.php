<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Enqueue Scripts for Autocomplete
add_action( 'admin_enqueue_scripts', 'cpl_enqueue_admin_scripts' );

function cpl_enqueue_admin_scripts( $hook ) {
    // Only load on our plugin page
    // Using loose check to be safer against translation or slug shifts
    if ( strpos( $hook, 'custom-static-pages' ) === false ) {
        return;
    }
    wp_enqueue_script( 'jquery-ui-autocomplete' );
}

// AJAX Handler for Search
add_action( 'wp_ajax_cpl_search_pages', 'cpl_search_pages_callback' );

function cpl_search_pages_callback() {
    // Basic security check - ensure user can manage options
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    $term = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : '';
    
    // Return empty if term is effectively empty
    if ( strlen( $term ) < 1 ) {
        echo json_encode( [] );
        wp_die();
    }
    
    $args = [
        'post_type'      => 'page',
        // Broaden status to include drafts/private just in case they are looking for those
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'], 
        's'              => $term,
        'posts_per_page' => 10, // Increase limit slightly
    ];

    $query = new WP_Query( $args );
    $results = [];

    if ( $query->have_posts() ) {
        foreach ( $query->posts as $post ) {
            $results[] = [
                'label' => $post->post_title . ' (Slug: ' . $post->post_name . ')',
                'value' => $post->post_name
            ];
        }
    } else {
        // Return a special item to indicate no results found
        $results[] = [
            'label' => 'No pages found for "' . esc_html( $term ) . '"',
            'value' => ''
        ];
    }

    echo json_encode( $results );
    wp_die();
}

// Add Admin Menu
add_action( 'admin_menu', 'cpl_add_admin_menu' );

function cpl_add_admin_menu() {
    // Top Level Menu
	add_menu_page(
		'Custom Page Loader',
		'Custom Page Loader',
		'manage_options',
		'cpl-main',
		'cpl_render_static_pages_page',
		'dashicons-media-code',
		20
	);
    
    // Submenu: Static Pages (Default)
    add_submenu_page(
        'cpl-main',
        'Static Pages',
        'Static Pages',
        'manage_options',
        'cpl-main',
        'cpl_render_static_pages_page'
    );

    // Submenu: Post Template
    add_submenu_page(
        'cpl-main',
        'Single Post Template',
        'Post Template',
        'manage_options',
        'cpl-post-template',
        'cpl_render_post_template_page'
    );
}

// Render Static Pages (Main Tab)
function cpl_render_static_pages_page() {
    // Check for Inspection View
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'inspect' && ! empty( $_GET['slug'] ) ) {
        cpl_render_inspection_page( sanitize_text_field( $_GET['slug'] ) );
        return;
    }

	// Handle Form Submission
	if ( isset( $_POST['cpl_action'] ) && check_admin_referer( 'cpl_upload_action', 'cpl_nonce' ) ) {
        cpl_handle_form_submission();
    }

    $pages = get_option( 'cpl_static_pages', [] );
	?>
	<div class="wrap">
		<h1>Custom Static Pages Manager</h1>
        <p>Manage custom HTML templates for specific pages (Home, About, etc).</p>
        
        <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
            <div class="card" style="flex: 1; min-width: 300px; padding: 20px; margin-top: 0;">
                <h2>Upload New Version</h2>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="cpl_action" value="upload">
                    <?php wp_nonce_field( 'cpl_upload_action', 'cpl_nonce' ); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="page_search">Search Page</label></th>
                            <td>
                                <input type="text" id="page_search" class="regular-text" placeholder="Type page title...">
                                <p class="description">Search existing pages (autocomplete for 400+ pages).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="page_slug">Target Page Slug</label></th>
                            <td>
                                <input name="page_slug" type="text" id="page_slug" value="" class="regular-text" placeholder="e.g. home, landing, my-page">
                                <p class="description">Auto-filled on selection. Use 'home' for front page.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="static_file">Upload Zip</label></th>
                            <td>
                                <!-- Hidden File Input -->
                                <input name="static_file" type="file" id="static_file" accept=".zip" style="display:none;">
                                
                                <!-- Trigger Button -->
                                <button type="button" id="cpl_trigger_modal" class="button button-secondary">
                                    <span class="dashicons dashicons-upload" style="margin-top:3px;"></span> Choose Zip File
                                </button>
                                
                                <span id="cpl_file_chosen" style="margin-left: 10px; color: #666; font-style: italic;">No file chosen</span>

                                <p class="description">Click to see required file structure before uploading.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="submit" id="submit" class="button button-primary" value="Upload & Deploy">
                    </p>
                </form>
            </div>

            <div class="card" style="flex: 1; min-width: 300px; padding: 20px; margin-top: 0; max-height: 500px; overflow-y: auto;">
                <h2>Select Existing Page</h2>
                <?php
                $all_pages = get_pages([
                    'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
                    'number' => 100, // Limit to 100 to avoid performance issues
                    'sort_column' => 'post_modified',
                    'sort_order' => 'desc'
                ]);
                
                if ( ! empty( $all_pages ) ) {
                    echo '<ul id="cpl-page-list" style="margin: 0;">';
                    foreach ( $all_pages as $p ) {
                        $title = get_the_title( $p );
                        if ( empty( $title ) ) $title = '(no title)';
                        $slug = $p->post_name;
                        echo '<li style="border-bottom: 1px solid #eee; padding: 8px 0;">';
                        echo '<a href="#" class="cpl-select-page" data-slug="' . esc_attr($slug) . '" data-title="' . esc_attr($title) . '" style="text-decoration: none; display: block;">';
                        echo '<strong>' . esc_html($title) . '</strong><br>';
                        echo '<small style="color: #666;">/' . esc_html($slug) . '</small>';
                        echo '</a>';
                        echo '</li>';
                    }
                    echo '</ul>';
                    if ( count( $all_pages ) >= 100 ) {
                        echo '<p style="text-align: center; color: #888; margin-top: 10px;"><em>Showing recent 100 pages. Use search for others.</em></p>';
                    }
                } else {
                    echo '<p>No pages found.</p>';
                }
                ?>
            </div>
        </div>

        <h2>Active Static Pages</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Slug</th>
                    <th>Folder Path</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $pages ) ) : ?>
                    <?php foreach ( $pages as $slug => $data ) : ?>
                        <tr>
                            <td><?php echo esc_html( $slug ); ?></td>
                            <td><?php echo esc_html( $data['folder'] ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=custom-static-pages&action=inspect&slug=' . urlencode( $slug ) ) ); ?>" class="button">Inspect / Troubleshoot</a>
                                
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="cpl_action" value="delete">
                                    <input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>">
                                    <?php wp_nonce_field( 'cpl_upload_action', 'cpl_nonce' ); ?>
                                    <button type="submit" class="button button-link-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="3">No static pages configured yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
	</div>

    <!-- Structure Info Modal -->
    <div id="cpl_structure_modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999;">
        <div style="background:#fff; width:90%; max-width:550px; margin: 100px auto; padding:30px; border-radius:8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); position:relative;">
            
            <h2 style="margin-top:0; color:#d63638; display:flex; align-items:center gap:10px;">
                <span class="dashicons dashicons-warning" style="font-size:30px; width:30px; height:30px; margin-right:10px;"></span> 
                Important: ZIP File Structure
            </h2>
            
            <p>To ensure your custom page loads correctly, your ZIP file must have the <strong>index.html</strong> file at the root level.</p>
            
            <div style="display:flex; gap:20px; margin: 25px 0;">
                <div style="flex:1; background:#f0f6e6; border:1px solid #7ad03a; padding:15px; border-radius:4px;">
                    <strong style="color:#00a32a; display:block; margin-bottom:10px;">✅ Correct Structure</strong>
                    <code style="display:block; white-space:pre; font-family:monospace; background:none; padding:0;">my-design.zip
├── index.html
├── style.css
└── assets/</code>
                </div>
                
                <div style="flex:1; background:#fbeaea; border:1px solid #d63638; padding:15px; border-radius:4px;">
                    <strong style="color:#d63638; display:block; margin-bottom:10px;">❌ Incorrect Structure</strong>
                    <code style="display:block; white-space:pre; font-family:monospace; background:none; padding:0;">my-design.zip
└── my-folder/
    ├── index.html
    └── ...</code>
                </div>
            </div>

            <p style="font-size:13px; color:#666;">
                <strong>Tip:</strong> Select your files (index.html, css, etc.), right-click, and choose "Compress to ZIP". Do not compress the folder itself.
            </p>
            
            <div style="text-align:right; margin-top:25px; border-top:1px solid #eee; padding-top:20px;">
                <button type="button" class="button" id="cpl_close_modal">Cancel</button>
                <button type="button" class="button button-primary button-large" id="cpl_continue_upload">
                    I Understand, Select File
                </button>
            </div>
        </div>
    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Modal Logic
        $('#cpl_trigger_modal').on('click', function(e) {
            e.preventDefault();
            $('#cpl_structure_modal').fadeIn(200);
        });

        $('#cpl_close_modal').on('click', function() {
            $('#cpl_structure_modal').fadeOut(200);
        });

        // Close when clicking outside
        $('#cpl_structure_modal').on('click', function(e) {
            if(e.target === this) {
                $(this).fadeOut(200);
            }
        });

        $('#cpl_continue_upload').on('click', function() {
            $('#cpl_structure_modal').fadeOut(200);
            $('#static_file').click();
        });

        $('#static_file').on('change', function() {
            var fileName = $(this).val().split('\\').pop();
            if(fileName) {
                $('#cpl_file_chosen').text(fileName).css({'color': '#00a32a', 'font-weight': 'bold', 'font-style': 'normal'});
                $('#cpl_trigger_modal').text('Change File');
            } else {
                $('#cpl_file_chosen').text('No file chosen').css({'color': '#666', 'font-weight': 'normal', 'font-style': 'italic'});
            }
        });

        // Autocomplete logic
        $('#page_search').autocomplete({
            source: function(request, response) {
                $.ajax({
                    url: ajaxurl,
                    dataType: "json",
                    data: {
                        action: 'cpl_search_pages',
                        term: request.term
                    },
                    success: function(data) {
                        response(data);
                    }
                });
            },
            minLength: 2,
            select: function(event, ui) {
                $('#page_slug').val(ui.item.value);
                event.preventDefault();
                $('#page_search').val(ui.item.label);
            }
        });

        // Quick Select Logic
        $('.cpl-select-page').on('click', function(e) {
            e.preventDefault();
            var slug = $(this).data('slug');
            var title = $(this).data('title');
            
            $('#page_slug').val(slug);
            $('#page_search').val(title + ' (Slug: ' + slug + ')');
            
            // Visual feedback
            $('.cpl-select-page').parent().css('background-color', 'transparent');
            $(this).parent().css('background-color', '#f0f0f1');
        });
    });
    </script>
    <style>
        ul.ui-autocomplete {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin: 0;
            padding: 0;
            list-style: none;
            position: absolute;
            z-index: 10000;
            max-width: 300px;
        }
        ul.ui-autocomplete li.ui-menu-item {
            margin: 0;
            padding: 0;
        }
        ul.ui-autocomplete li.ui-menu-item div {
            padding: 5px 10px;
            cursor: pointer;
            line-height: 1.5;
        }
        ul.ui-autocomplete li.ui-menu-item div:hover,
        ul.ui-autocomplete li.ui-menu-item.ui-state-focus div {
            background-color: #2271b1;
            color: #fff;
        }
        .ui-helper-hidden-accessible { display: none; }
    </style>
	<?php
}

// Render Post Template Page
function cpl_render_post_template_page() {
    // Handle Form Submission
	if ( isset( $_POST['cpl_action'] ) && check_admin_referer( 'cpl_post_template_action', 'cpl_nonce' ) ) {
        cpl_handle_post_template_submission();
    }
    
    $post_template = get_option( 'cpl_post_template', [] ); // ['active' => bool, 'enabled' => bool, 'uploaded_at' => date]
    
    // Ensure 'enabled' defaults to true if not set but template is active (backward compatibility)
    $is_enabled = isset($post_template['enabled']) ? $post_template['enabled'] : ( !empty($post_template['active']) );

    ?>
    <div class="wrap">
        <h1>Single Post Template</h1>
        <p>Upload a single HTML design (`.zip`) that will replace the design of <strong>ALL single blog posts</strong>.</p>
        
        <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
            
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:15px;">
                <h2 style="margin:0;">Template Stats</h2>
                
                <?php if ( ! empty( $post_template['active'] ) ) : ?>
                    <form method="post">
                        <input type="hidden" name="cpl_action" value="toggle_post_template">
                        <input type="hidden" name="new_state" value="<?php echo $is_enabled ? '0' : '1'; ?>">
                        <?php wp_nonce_field( 'cpl_post_template_action', 'cpl_nonce' ); ?>
                        
                        <?php if ( $is_enabled ) : ?>
                             <div class="current-status" style="display:inline-block; margin-right:15px; color:#00a32a; font-weight:bold;">
                                ● Live on Site
                             </div>
                             <button type="submit" class="button">Turn OFF</button>
                        <?php else : ?>
                             <div class="current-status" style="display:inline-block; margin-right:15px; color:#d63638; font-weight:bold;">
                                ● Disabled
                             </div>
                             <button type="submit" class="button button-primary">Turn ON</button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $post_template['active'] ) ) : ?>
                <?php if ( $is_enabled ) : ?>
                    <div class="notice notice-success inline" style="margin: 0 0 20px 0;">
                        <p><strong>✅ Template Active</strong><br>Last updated: <?php echo esc_html( $post_template['uploaded_at'] ); ?></p>
                    </div>
                <?php else : ?>
                     <div class="notice notice-warning inline" style="margin: 0 0 20px 0;">
                        <p><strong>⏸ Template Paused</strong><br>Posts are currently using the default WordPress theme.</p>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <div class="notice notice-info inline" style="margin: 0 0 20px 0;">
                    <p>No custom post template uploaded. WordPress default theme is used.</p>
                </div>
            <?php endif; ?>

            <h3 style="margin-top:20px;">Update Template</h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="cpl_action" value="upload_post_template">
                <?php wp_nonce_field( 'cpl_post_template_action', 'cpl_nonce' ); ?>
                
                <p>
                    <input type="file" name="post_template_file" accept=".zip" required>
                </p>
                
                <p class="description">
                    The HTML file should use JavaScript to fetch content based on the current post slug.<br>
                    <a href="#" onclick="jQuery('#cpl_structure_modal').fadeIn(); return false;">See required structure</a>
                </p>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="Upload & Apply">
                    
                    <?php if ( ! empty( $post_template['active'] ) ) : ?>
                        <input type="submit" name="delete_template" class="button button-link-delete" value="Delete Template Files" 
                               onclick="return confirm('Are you sure? This will permanently delete the custom files.');" style="float:right;">
                    <?php endif; ?>
                </p>
            </form>
        </div>
    </div>
    
    <!-- Reuse the same modal structure -->
    <div id="cpl_structure_modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999;">
        <div style="background:#fff; width:90%; max-width:550px; margin: 100px auto; padding:30px; border-radius:8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); position:relative;">
            <h2 style="margin-top:0;">Template Structure</h2>
            <p>Your ZIP must include <code>index.html</code> at the root.</p>
            <div style="background:#f0f6e6; border:1px solid #7ad03a; padding:15px;">
                <code>post-design.zip</code><br>
                <code>├── index.html</code><br>
                <code>├── style.css</code>
            </div>
            <div style="text-align:right; margin-top:20px;">
                <button type="button" class="button" onclick="jQuery('#cpl_structure_modal').fadeOut()">Close</button>
            </div>
        </div>
    </div>
    <?php
}

function cpl_handle_post_template_submission() {
    // Handle Toggle
    if ( isset($_POST['cpl_action']) && $_POST['cpl_action'] === 'toggle_post_template' ) {
         $new_state = ( $_POST['new_state'] === '1' );
         
         $post_template = get_option( 'cpl_post_template', [] );
         $post_template['enabled'] = $new_state;
         
         update_option( 'cpl_post_template', $post_template );
         
         echo $new_state ? '<div class="notice notice-success"><p>Template Enabled.</p></div>' 
                         : '<div class="notice notice-warning"><p>Template Disabled.</p></div>';
         return;
    }

    if ( isset( $_POST['delete_template'] ) ) {
        cpl_delete_directory( CPL_UPLOAD_DIR . '/_post_template' );
        delete_option( 'cpl_post_template' );
        echo '<div class="notice notice-info"><p>Post template deleted.</p></div>';
        return;
    }

    if ( empty( $_FILES['post_template_file'] ) || $_FILES['post_template_file']['error'] !== UPLOAD_ERR_OK ) {
        echo '<div class="notice notice-error"><p>Upload failed.</p></div>';
        return;
    }

    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    WP_Filesystem();

    $temp_file = $_FILES['post_template_file']['tmp_name'];
    $target_dir = CPL_UPLOAD_DIR . '/_post_template';

    // Clean existing
    cpl_delete_directory( $target_dir );

    $unzip_result = unzip_file( $temp_file, $target_dir );

    if ( is_wp_error( $unzip_result ) ) {
        echo '<div class="notice notice-error"><p>' . $unzip_result->get_error_message() . '</p></div>';
    } else {
        cpl_flatten_directory( $target_dir );
        
        $current_opts = get_option( 'cpl_post_template', [] );
        $is_enabled = isset( $current_opts['enabled'] ) ? $current_opts['enabled'] : true;
        
        update_option( 'cpl_post_template', [
            'active' => true,
            'enabled' => $is_enabled, // Preserve state or default true on first upload
            'folder' => '_post_template',
            'uploaded_at' => current_time( 'mysql' )
        ] );
        
        echo '<div class="notice notice-success"><p>Post template updated!</p></div>';
    }
}

function cpl_handle_form_submission() {
    $pages = get_option( 'cpl_static_pages', [] );

    if ( $_POST['cpl_action'] === 'delete' ) {
        $slug = sanitize_text_field( $_POST['slug'] );
        // Ideally verify user capability again
        if ( isset( $pages[ $slug ] ) ) {
            // Optional: Delete physical files
            cpl_delete_directory( CPL_UPLOAD_DIR . '/' . $pages[$slug]['folder'] );
            unset( $pages[ $slug ] );
            update_option( 'cpl_static_pages', $pages );
            echo '<div class="notice notice-success"><p>Page deleted.</p></div>';
        }
        return;
    }

    if ( $_POST['cpl_action'] === 'upload' ) {
        if ( empty( $_FILES['static_file'] ) || $_FILES['static_file']['error'] !== UPLOAD_ERR_OK ) {
            echo '<div class="notice notice-error"><p>File upload failed.</p></div>';
            return;
        }

        $slug = sanitize_title( $_POST['page_slug'] );
        if ( empty( $slug ) ) {
            echo '<div class="notice notice-error"><p>Slug is required.</p></div>';
            return;
        }

        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        WP_Filesystem();
        
        $temp_file = $_FILES['static_file']['tmp_name'];
        $folder_name = $slug; // Simple folder naming
        $target_dir = CPL_UPLOAD_DIR . '/' . $folder_name;

        // Clean existing
        if ( file_exists( $target_dir ) ) {
            cpl_delete_directory( $target_dir );
        }
        
        $unzip_result = unzip_file( $temp_file, $target_dir );

        if ( is_wp_error( $unzip_result ) ) {
            echo '<div class="notice notice-error"><p>Unzip failed: ' . $unzip_result->get_error_message() . '</p></div>';
        } else {
            // Flatten directory structure (move all files to root)
            cpl_flatten_directory( $target_dir );

            $pages[ $slug ] = [
                'folder' => $folder_name,
                'uploaded_at' => current_time( 'mysql' )
            ];
            update_option( 'cpl_static_pages', $pages );
            echo '<div class="notice notice-success"><p>Static page deployed successfully!</p></div>';
        }
    }
}

function cpl_flatten_directory( $dir ) {
    if ( ! is_dir( $dir ) ) return;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $target_path = $dir . DIRECTORY_SEPARATOR . $file->getFilename();
            // Move only if not already at root
            if ( $file->getPathname() !== $target_path ) {
                // If file with same name exists at root, it will be overwritten
                @rename( $file->getPathname(), $target_path );
            }
        } else {
            // Remove empty directories
            @rmdir( $file->getPathname() );
        }
    }
}

function cpl_delete_directory( $dir ) {
    if ( ! file_exists( $dir ) ) {
        return true;
    }
    if ( ! is_dir( $dir ) ) {
        return unlink( $dir );
    }
    foreach ( scandir( $dir ) as $item ) {
        if ( $item == '.' || $item == '..' ) {
            continue;
        }
        if ( ! cpl_delete_directory( $dir . DIRECTORY_SEPARATOR . $item ) ) {
            return false;
        }
    }
    return rmdir( $dir );
}

function cpl_render_inspection_page( $slug ) {
    $pages = get_option( 'cpl_static_pages', [] );
    if ( ! isset( $pages[ $slug ] ) ) {
        echo '<div class="wrap"><div class="notice notice-error"><p>Page not found.</p></div><a href="' . admin_url('admin.php?page=custom-static-pages') . '" class="button">Back</a></div>';
        return;
    }

    $folder = $pages[ $slug ]['folder'];
    $dir = CPL_UPLOAD_DIR . '/' . $folder;
    
    // 1. Get all files
    $files = [];
    if ( is_dir( $dir ) ) {
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                // Get relative path
                $full_path = wp_normalize_path( $file->getPathname() );
                $base_path = wp_normalize_path( $dir ) . '/';
                $rel_path = str_replace( $base_path, '', $full_path );
                $files[] = $rel_path;
            }
        }
    }
    sort($files);
    
    // 2. Parse HTML for assets
    $index_path = $dir . '/index.html';
    $assets_status = [];
    $has_index = file_exists( $index_path );
    
    if ( $has_index ) {
        $html = file_get_contents( $index_path );
        // Match src="..." and href="..."
        // Simple regex, not perfect but good for troubleshooting
        preg_match_all( '/(src|href)=["\']([^"\']+)["\']/i', $html, $matches );
        
        $found_assets = array_unique( $matches[2] );
        foreach ( $found_assets as $asset ) {
            // Skip clearly external or special links
            if ( strpos( $asset, 'http' ) === 0 || strpos( $asset, '//' ) === 0 || strpos( $asset, '#' ) === 0 || strpos( $asset, 'mailto:' ) === 0 ) {
                continue;
            }
            
            // Normalize path (remove leading ./)
            $clean_asset = ltrim( $asset, './' );
            // Handling ../ might be complex, assume simplistic structure checking first
            
            // Check if exist in our file list
            $exists = in_array( $clean_asset, $files );
            
            $assets_status[] = [
                'path' => $asset,
                'clean_path' => $clean_asset,
                'found' => $exists
            ];
        }
    }

    // Render View
    ?>
    <div class="wrap">
        <h1 style="display:inline-block;">Inspecting: <?php echo esc_html( $slug ); ?></h1>
        <a href="<?php echo admin_url('admin.php?page=custom-static-pages'); ?>" class="page-title-action">Back to List</a>
        
        <div style="display:flex; gap:20px; margin-top:20px; flex-wrap:wrap;">
            <!-- File Structure Panel -->
            <div class="card" style="flex:1; min-width:300px; padding:20px;">
                <h2 style="margin-top:0;">📁 Server File Structure</h2>
                <p class="description">Files found in: <code>.../uploads/custom-static-pages/<?php echo esc_html($folder); ?>/</code></p>
                
                <div style="background:#f6f7f7; padding:15px; border:1px solid #dcdcde; border-radius:4px; max-height:500px; overflow:auto;">
                    <?php if ( empty( $files ) ) : ?>
                         <p style="color:red;">❌ No files found! Upload failed or directory is empty.</p>
                    <?php else: ?>
                        <?php if ( ! $has_index ) : ?>
                            <div style="background:#fbeaea; border-left:4px solid #d63638; padding:10px; margin-bottom:15px;">
                                <strong>Warning:</strong> <code>index.html</code> is missing from the root!
                            </div>
                        <?php endif; ?>

                        <ul style="list-style:none; margin:0; padding:0; font-family:monospace;">
                            <?php foreach($files as $f): 
                                $is_index = ($f === 'index.html');
                                $style = $is_index ? 'font-weight:bold; color:#005c99;' : '';
                                $icon = $is_index ? '📄' : 'ded';
                                if (strpos($f, '/') !== false) $icon = '📂';
                                if (cpl_str_ends_with($f, '.css') || cpl_str_ends_with($f, '.js')) $icon = '📜';
                                if (cpl_str_ends_with($f, '.png') || cpl_str_ends_with($f, '.jpg')) $icon = '🖼️';
                            ?>
                                <li style="padding: 2px 0; <?php echo $style; ?>">
                                    <?php echo $icon . ' ' . esc_html($f); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Link Checker Panel -->
            <div class="card" style="flex:1; min-width:300px; padding:20px;">
                <h2 style="margin-top:0;">🔗 Asset Link Check</h2>
                <p class="description">Scanning <code>index.html</code> for local files.</p>
                
                <?php if ( ! $has_index ) : ?>
                    <p>Cannot check assets because index.html is missing.</p>
                <?php else: ?>
                    <table class="widefat striped" style="border:1px solid #dcdcde;">
                        <thead>
                            <tr>
                                <th>HTML Reference</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $assets_status ) ) : ?>
                                <tr><td colspan="2">No local assets (css/js/img) found in HTML.</td></tr>
                            <?php else: ?>
                                <?php foreach($assets_status as $stat): ?>
                                    <tr>
                                        <td><code><?php echo esc_html($stat['path']); ?></code></td>
                                        <td>
                                            <?php if($stat['found']): ?>
                                                <span class="dashicons dashicons-yes" style="color:green;"></span> <strong style="color:green;">Found</strong>
                                            <?php else: ?>
                                                <span class="dashicons dashicons-no" style="color:red;"></span> <strong style="color:red;">Missing</strong>
                                                <br><small style="color:#d63638;">Looking for: <?php echo esc_html($stat['clean_path']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <p class="description" style="margin-top:10px;">
                        <strong>Note:</strong> If a file is "Missing", check if it exists in the file list on the left. 
                        If it's there but in a subfolder, you may need to update your HTML paths or ZIP structure.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function cpl_str_ends_with( $haystack, $needle ) {
    $length = strlen( $needle );
    if ( ! $length ) {
        return true;
    }
    return substr( $haystack, -$length ) === $needle;
}

