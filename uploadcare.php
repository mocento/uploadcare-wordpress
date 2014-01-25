<?php
/*
Plugin Name: Uploadcare
Plugin URI: http://github.com/uploadcare/uploadcare-wordpress
Description: Implements a way to use Uploadcare inside you Wordpress blog.
Version: 2.0.9
Author: Uploadcare
Author URI: https://uploadcare.com/
License: GPL2
*/


define('UPLOADCARE_PLUGIN_VERSION', '2.0.9-dev');

// FIXME: this does not work with symlinks
define('UPLOADCARE_PLUGIN_URL', plugin_dir_url( __FILE__ ));
// define('UPLOADCARE_PLUGIN_URL', '/wp-content/plugins/uploadcare-dev/');
define('UPLOADCARE_PLUGIN_PATH', plugin_dir_path(__FILE__) );

if (is_admin()) {
  require_once UPLOADCARE_PLUGIN_PATH . '/admin.php';
}

require_once UPLOADCARE_PLUGIN_PATH . '/utils.php';
require_once UPLOADCARE_PLUGIN_PATH . '/filters.php';
require_once UPLOADCARE_PLUGIN_PATH . '/actions.php';
require_once UPLOADCARE_PLUGIN_PATH . '/uploadcare-php/uploadcare/lib/5.2/Uploadcare.php';


function add_uploadcare_js_to_admin($hook) {
  if('post.php' != $hook && 'post-new.php' != $hook) {
    // add js only on add and edit pages
    return;
  }
  wp_enqueue_script('my_custom_script', UPLOADCARE_PLUGIN_URL . 'uploadcare-wp.js');
}
add_action( 'admin_enqueue_scripts', 'add_uploadcare_js_to_admin' );

/**
 * Get Api object
 *
 */
function uploadcare_api() {
    global $wp_version;
    $user_agent = 'Uploadcare Wordpress ' . UPLOADCARE_PLUGIN_VERSION . '/' . $wp_version;
    return new Uploadcare_Api(
        get_option('uploadcare_public'),
        get_option('uploadcare_secret'),
        $user_agent
    );
}

function uploadcare_add_media($context) {
  $api = uploadcare_api();

  $img = plugins_url('logo.png', __FILE__);

  $original = get_option('uploadcare_original') ? "true" : "false";
  $multiple = get_option('uploadcare_multiupload') ? "true" : "false";
  if(get_option('uploadcare_finetuning')) {
    $finetuning = stripcslashes(get_option('uploadcare_finetuning'));
  } else {
    $finetuning = '';
  }
  $widget_tag = $api->widget->getScriptTag();

  $context = <<<HTML
<div style="float: left">
  <a class="button" style="padding-left: .4em;" href="javascript: uploadcareMediaButton();">
    <span class="wp-media-buttons-icon" style="padding-right: 2px; vertical-align: text-bottom; background: url('{$img}') no-repeat 0px 0px;">
    </span>Add Media</a>
</div>
<div style="float: left">
  <a href="#" class="button insert-media add_media" data-editor="content" title="Wordpress Media Library">
    <span class="wp-media-buttons-icon"></span>Wordpress Media Library
  </a>
</div>
<style tyle="text/css">#wp-content-media-buttons>a:first-child { display: none }</style>
<script type="text/javascript">
  UPLOADCARE_WP_ORIGINAL = {$original};
  UPLOADCARE_MULTIPLE = {$multiple};
  {$finetuning}
</script>
{$widget_tag}
HTML;
  return $context;
}
add_action('media_buttons_context', 'uploadcare_add_media');


/**
 * Create WP attachment (add image to media library)
 *
 * @param $file Uploadcare File object to attach
 */
function uploadcare_attach($file) {
    $currentuser = get_current_user_id();
    $filename = $file->data['original_filename'];
    $title = $filename;

    $attachment = array(
     'post_author'    => $currentuser,
     'post_date'      => date('Y-m-d H:i:s'),
     'post_type'      => 'attachment',
     'post_title'     => $title,
     'post_parent'    => (!empty($_REQUEST['post_id']) ? $_REQUEST['post_id'] : null),
     'post_status'    => 'inherit',
     'post_mime_type' => $file->data['mime_type'],
    );

    $attachment_id = wp_insert_post($attachment, true);

    $meta = array('width' => $file->data['image_info']->width,
                  'height' => $file->data['image_info']->height);

    add_post_meta($attachment_id, '_wp_attached_file', $file->data['original_file_url'], true);
    add_post_meta($attachment_id, '_wp_attachment_metadata', $meta, true);
    add_post_meta($attachment_id, 'uploadcare_url', $file->data['original_file_url'], true);
}

function uploadcare_handle() {
  // store file
  $api = uploadcare_api();
  $file_id = $_POST['file_id'];
  $file = $api->getFile($file_id);
  $file->store();

  uploadcare_attach($file);
}
add_action('wp_ajax_uploadcare_handle', 'uploadcare_handle');


/*
TODO: delete table on upgrade

function uploadcare_install() {
  global $wpdb;
  $table_name = $wpdb->prefix . "uploadcare";
  $sql = "CREATE TABLE $table_name (
  id mediumint(9) NOT NULL AUTO_INCREMENT,
  file_id varchar(200) DEFAULT '' NOT NULL,
  is_file tinyint(1) DEFAULT 0 NOT NULL,
  filename varchar(200) DEFAULT '' NOT NULL,
  UNIQUE KEY id (id)
  );";

  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
}
register_activation_hook(__FILE__, 'uploadcare_install');

function uploadcare_uninstall() {
  global $wpdb;
  $thetable = $wpdb->prefix."uploadcare";
  $wpdb->query("DROP TABLE IF EXISTS $thetable");
}
register_deactivation_hook(__FILE__, 'uploadcare_uninstall');
*/

function uploadcare_media_menu($tabs) {
  $newtab = array(
    'uploadcare_files' => __('Uploadcare', 'uploadcare_files')
  );
  return array_merge($newtab, $tabs);
}
add_filter('media_upload_tabs', 'uploadcare_media_menu');

function uploadcare_media_menu_default_tab() {
  return 'uploadcare_files';
}

function uploadcare_media_files() {
  global $wpdb;
  require_once 'uploadcare_media_files_menu_handle.php';
}

function uploadcare_media_files_menu_handle() {
  return wp_iframe('uploadcare_media_files');
}
add_action('media_upload_uploadcare_files', 'uploadcare_media_files_menu_handle');


/**
 * Replace featured image HTML with Uploadcare image if:
 * - use uploadcare for featured images is set
 * - post's meta 'uploadcare_featured_image' is set
 * otherwise, uses default html code.
 */
function uc_post_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr) {
  if (!get_option('uploadcare_replace_featured_image')) {
    return $html;
  }

  $meta = get_post_meta($post_id, 'uploadcare_featured_image');
  if(empty($meta)) {
    return $html;
  }
  $url = $meta[0];
  $sz = uc_thumbnail_size($size);
  $src = "{$url}-/stretch/off/-/scale_crop/$sz/";
  $html = <<<HTML
<img src="{$src}"
     alt=""
/>
HTML;
  return $html;
}
add_filter('post_thumbnail_html', 'uc_post_thumbnail_html', 10, 5);


/* Remove Featured Image Meta */
function uploadcare_remove_wp_featured_image_box() {
  if (get_option('uploadcare_replace_featured_image')) {
    remove_meta_box('postimagediv', NULL, 'side');
  }
}
add_action('do_meta_boxes', 'uploadcare_remove_wp_featured_image_box');

/**
 * Adds a box to the main column on the Post and Page edit screens.
 */
function uploadcare_add_featured_image_box($post_type) {
  if (get_option('uploadcare_replace_featured_image') &&
      post_type_supports($post_type, 'thumbnail')) {

      add_meta_box(
          'myplugin_sectionid',
          __('Featured Image (uploadcare)', 'uploadcare'),
          'uploadcare_featured_image_box',
          $post_type,
          'side'
      );
  }
}
add_action('add_meta_boxes', 'uploadcare_add_featured_image_box');

/**
 * Prints the box content.
 *
 * @param WP_Post $post The object for the current post/page.
 */
function uploadcare_featured_image_box($post) {
  // Add an nonce field so we can check for it later.
  wp_nonce_field('uploadcare_featured_image_box',
                 'uploadcare_featured_image_box_nonce');

  $value = get_post_meta($post->ID, 'uploadcare_featured_image', true);
  $html = <<<HTML
<a title="Set featured image"
   id="uc-set-featured-img"
   href="javascript:;"
   data-uc-url="{$value}">Set featured image</a>
<a title="Remove featured image"
   id="uc-remove-featured-img"
   href="javascript:;"
   class="hidden">Remove featured image</a>
<input type="hidden"
       id="uc-featured-image-input"
       name="uploadcare_featured_image"
       value="{$value}">
HTML;
  echo $html;
}

/**
 * When the post is saved, saves our custom data.
 *
 * @param int $post_id The ID of the post being saved.
 */
function uploadcare_save_postdata($post_id) {
  // at the moment this is used only for featured images, so skip it if
  //   the option is not set
  if (!get_option('uploadcare_replace_featured_image')) {
    return $post_id;
  }

  /*
   * We need to verify this came from the our screen and with proper authorization,
   * because save_post can be triggered at other times.
   */

  // Check if our nonce is set.
  if (!isset( $_POST['uploadcare_featured_image_box_nonce'])) {
    return $post_id;
  }
  $nonce = $_POST['uploadcare_featured_image_box_nonce'];

  // Verify that the nonce is valid.
  if (!wp_verify_nonce($nonce, 'uploadcare_featured_image_box')) {
    return $post_id;
  }

  // If this is an autosave, our form has not been submitted, so we don't want to do anything.
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
    return $post_id;
  }

  // Check the user's permissions.
  if ('page' == $_POST['post_type']) {
    if (!current_user_can('edit_page', $post_id)) {
      return $post_id;
    }
  } else {
    if (!current_user_can('edit_post', $post_id)) {
      return $post_id;
    }
  }

  /* OK, its safe for us to save the data now. */

  // Sanitize user input.
  $mydata = sanitize_text_field($_POST['uploadcare_featured_image']);

  // Update the meta field in the database.
  update_post_meta($post_id, 'uploadcare_featured_image', $mydata);
}
add_action('save_post', 'uploadcare_save_postdata');
