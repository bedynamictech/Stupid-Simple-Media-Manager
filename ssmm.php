<?php
/**
 * Plugin Name:     Stupid Simple Media Manager
 * Plugin URI:      https://github.com/bedynamictech/Stupid-Simple-Media-Manager
 * Description:     Organize your media into folders.
 * Version:         1.0.1
 * Author:          Dynamic Technologies
 * Author URI:      https://bedynamic.tech
 * License:         GPLv2 or later
 * License URI:     http://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Activation: create custom table for folders
function ssm_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . 'ssm_media_folders';
    $charset = $wpdb->get_charset_collate();
    $sql     = "CREATE TABLE {$table} (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(200) NOT NULL,
        parent_id mediumint(9) NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'ssm_activate' );

// Submenu under Media
function ssm_add_media_folder_page() {
    add_submenu_page(
        'upload.php',
        __( 'Folders', 'stupid-simple-media-manager' ),
        __( 'Folders', 'stupid-simple-media-manager' ),
        'manage_options',
        'ssm-media-folders',
        'ssm_media_folder_page'
    );
}
add_action( 'admin_menu', 'ssm_add_media_folder_page' );

// Management page
function ssm_media_folder_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'ssm_media_folders';

    // Add new
    if ( ! empty( $_POST['ssm_folder_name'] ) ) {
        $name      = sanitize_text_field( wp_unslash( $_POST['ssm_folder_name'] ) );
        $parent_id = intval( $_POST['ssm_parent_folder'] );
        $wpdb->insert( $table, [
            'name'      => $name,
            'parent_id' => $parent_id,
        ] );
    }

    // Delete
    if ( isset( $_GET['delete_folder'] ) ) {
        $wpdb->delete( $table, [ 'id' => intval( $_GET['delete_folder'] ) ] );
    }

    // Fetch
    $folders = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY parent_id, name" );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Folders', 'stupid-simple-media-manager' ); ?></h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th><label for="ssm_folder_name"><?php esc_html_e( 'Folder Name', 'stupid-simple-media-manager' ); ?></label></th>
                    <td><input id="ssm_folder_name" name="ssm_folder_name" type="text" required></td>
                </tr>
                <tr>
                    <th><label for="ssm_parent_folder"><?php esc_html_e( 'Parent Folder', 'stupid-simple-media-manager' ); ?></label></th>
                    <td>
                        <select id="ssm_parent_folder" name="ssm_parent_folder">
                            <option value="0"><?php esc_html_e( '— None —', 'stupid-simple-media-manager' ); ?></option>
                            <?php foreach ( $folders as $f ) : ?>
                                <option value="<?php echo esc_attr( $f->id ); ?>"><?php echo esc_html( $f->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Add Folder', 'stupid-simple-media-manager' ), 'primary', 'ssm_add_folder' ); ?>
        </form>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php esc_html_e( 'Name', 'stupid-simple-media-manager' ); ?></th>
                <th><?php esc_html_e( 'Parent', 'stupid-simple-media-manager' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'stupid-simple-media-manager' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $folders as $f ) :
                $parent = $f->parent_id ? $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d", $f->parent_id ) ) : '-'; ?>
                <tr>
                    <td><?php echo esc_html( $f->name ); ?></td>
                    <td><?php echo esc_html( $parent ); ?></td>
                    <td><a href="<?php echo esc_url( add_query_arg( 'delete_folder', $f->id ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Delete this folder?', 'stupid-simple-media-manager' ); ?>');"><?php esc_html_e( 'Delete', 'stupid-simple-media-manager' ); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Get dropdown HTML
function ssm_get_folder_dropdown_html() {
    global $wpdb;
    $table   = $wpdb->prefix . 'ssm_media_folders';
    $folders = $wpdb->get_results( "SELECT id, name FROM {$table} ORDER BY name" );
    $sel     = isset( $_GET['ssm_folder'] ) ? intval( $_GET['ssm_folder'] ) : 0;
    $html    = '<select name="ssm_folder">';
    $html   .= '<option value="0">' . esc_html__( 'All folders', 'stupid-simple-media-manager' ) . '</option>';
    foreach ( $folders as $f ) {
        $html .= sprintf(
            '<option value="%1$s" %2$s>%3$s</option>',
            esc_attr( $f->id ),
            selected( $sel, $f->id, false ),
            esc_html( $f->name )
        );
    }
    return $html . '</select>';
}

// Inject dropdown in list & grid modes
add_action( 'admin_footer-upload.php', function() {
    $dropdown = ssm_get_folder_dropdown_html();
    ?>
    <script type="text/javascript">
    jQuery(function($) {
        var dd = <?php echo json_encode( $dropdown ); ?>;
        // Append to grid toolbar
        $('.media-toolbar-secondary select[name="m"]').after(dd);
        // Insert in list view
        $('select[name="m"]').after(dd);
    });
    </script>
    <?php
});

// Filter media by folder
function ssm_filter_media_by_folder( $query ) {
    global $pagenow;
    if ( is_admin() && 'upload.php' === $pagenow && ! empty( $_GET['ssm_folder'] ) ) {
        $query->set( 'meta_query', array(
            array(
                'key'     => 'ssm_media_folder',
                'value'   => intval( $_GET['ssm_folder'] ),
                'compare' => '=',
            ),
        ) );
    }
}
add_filter( 'pre_get_posts', 'ssm_filter_media_by_folder' );

// Media modal fields
function ssm_add_media_folder_field( $fields, $post ) {
    $dd   = ssm_get_folder_dropdown_html();
    $html = $dd . '<br><input type="text" name="attachments[' . $post->ID . '][ssm_new_folder]" placeholder="' . esc_attr__( 'Create New Folder', 'stupid-simple-media-manager' ) . '" style="width:100%; margin-top:5px;">';
    $fields['ssm_media_folder'] = array(
        'label' => __( 'Folder', 'stupid-simple-media-manager' ),
        'input' => 'html',
        'html'  => $html,
    );
    return $fields;
}
add_filter( 'attachment_fields_to_edit', 'ssm_add_media_folder_field', 10, 2 );

// Save folder field
function ssm_save_media_folder_field( $post, $attachment ) {
    global $wpdb;
    $table = $wpdb->prefix . 'ssm_media_folders';
    if ( ! empty( $attachment['ssm_new_folder'] ) ) {
        $name      = sanitize_text_field( $attachment['ssm_new_folder'] );
        $wpdb->insert( $table, [ 'name' => $name, 'parent_id' => 0 ] );
        $fid = $wpdb->insert_id;
    } else {
        $fid = intval( $attachment['ssm_media_folder'] );
    }
    if ( $fid ) {
        update_post_meta( $post['ID'], 'ssm_media_folder', $fid );
    } else {
        delete_post_meta( $post['ID'], 'ssm_media_folder' );
    }
    return $post;
}
add_filter( 'attachment_fields_to_save', 'ssm_save_media_folder_field', 10, 2 );

// Add settings link on the Plugins page
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ssm_action_links' );
function ssm_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'upload.php?page=ssm-media-folders' ) ) . '">' 
        . __( 'Settings', 'stupid-simple-media-manager' ) 
        . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
