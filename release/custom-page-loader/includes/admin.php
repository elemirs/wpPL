<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Add Admin Menu
add_action( 'admin_menu', 'cpl_add_admin_menu' );

function cpl_add_admin_menu() {
	add_menu_page(
		'Custom Static Pages',
		'Custom Pages',
		'manage_options',
		'custom-static-pages',
		'cpl_render_admin_page',
		'dashicons-media-code',
		20
	);
}

// Render Admin Page
function cpl_render_admin_page() {
	// Handle Form Submission
	if ( isset( $_POST['cpl_action'] ) && check_admin_referer( 'cpl_upload_action', 'cpl_nonce' ) ) {
        cpl_handle_form_submission();
    }

    $pages = get_option( 'cpl_static_pages', [] );
	?>
	<div class="wrap">
		<h1>Custom Static Pages Manager</h1>
        
        <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
            <h2>Upload New Version</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="cpl_action" value="upload">
                <?php wp_nonce_field( 'cpl_upload_action', 'cpl_nonce' ); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="page_slug">Target Page Slug</label></th>
                        <td>
                            <input name="page_slug" type="text" id="page_slug" value="" class="regular-text" placeholder="e.g. home, landing, my-page">
                            <p class="description">Use 'home' for the front page.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="static_file">Upload Zip</label></th>
                        <td>
                            <input name="static_file" type="file" id="static_file" accept=".zip">
                            <p class="description">Upload a .zip file containing index.html and assets.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="Upload & Deploy">
                </p>
            </form>
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
	<?php
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
            $pages[ $slug ] = [
                'folder' => $folder_name,
                'uploaded_at' => current_time( 'mysql' )
            ];
            update_option( 'cpl_static_pages', $pages );
            echo '<div class="notice notice-success"><p>Static page deployed successfully!</p></div>';
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
