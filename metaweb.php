<?php

/*
Plugin Name: Metaweb TopicBlocks
Plugin URI: http://www.metaweb.com/wordpress
Description: The Metaweb plugin is a WordPress plugin that allows you to easily add Metaweb TopicBlocks to your blog posts directly from within WordPress. You can quickly add TopicBlocks to individual blog posts or have TopicBlocks automatically inserted whenever you use certain tags.  
Version:1.9
Author: Metaweb
Author URI: http://www.metaweb.com
*/

/*  Copyright 2010  Metaweb Inc  (email : plugin@metaweb.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


#Turn this to false if you want to let writes through without a nonce (easier for local debugging)
$FB_VALIDATE = True;

#Per request - holds scripts that should be appended once at footer of public pages
$FB_SCRIPTS = array();


#################  OPTIONS  ################

$FB_OPTIONS_KEY = 'fb_options';
$FB_OPTIONS = array();
$FB_DEFAULT_OPTIONS_URL = "http://blogin.freebaseapps.com/options?v=1";
$FB_OPTIONS_TIMESTAMP = 'fb_options_timestamp';

$MW_JSON = null;

function fb_get_options() { 

  global $FB_OPTIONS_KEY;
  global $FB_OPTIONS;

  if (count($FB_OPTIONS)) { 
    return $FB_OPTIONS;
  }

  $FB_OPTIONS = get_option($FB_OPTIONS_KEY);

  if (!$FB_OPTIONS) { 
    #erm - can't do much at this point 
    $FB_OPTIONS = array();
  }
  
  return $FB_OPTIONS;

}

function fb_set_option($key, $value) { 
  global $FB_OPTIONS_KEY;
  global $FB_OPTIONS;

  $op = fb_get_options();
  $op[$key] = $value;
  $FB_OPTIONS = $op;
  update_option($FB_OPTIONS_KEY, $op);

}

function fb_get_option($key) { 
  $op = fb_get_options();
  if ($op[$key]) { 
    return $op[$key];
  } else { 
    return null;
  }

}

function fb_http_get_content($url) { 

  if (!$url) { return null; }
  
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  $result = curl_exec($ch);
  curl_close($ch);

  return $result;

}

##################### CACHE #########################

function fb_cache_get($key, $expiry = null) { 

  global $wpdb;

  $value = null;

  #do a cache lookup
  $sql = "SELECT * FROM fb_cache WHERE cache_key='" . $key . "'";

  $results =  $wpdb->get_results($sql);

  if (count($results)) { 
    
    $t_now = time();
    $t_markup = strtotime($results[0]->cache_time);
    
    if (!$expiry || ($t_now - $t_markup <= $expiry))  {
      $value = $results[0]->cache_value;
    }
  }
  
  return $value;

}

function fb_cache_set($key, $value) { 

  global $wpdb;

  $result = $wpdb->get_results("SELECT cache_key FROM fb_cache WHERE cache_key='$key'");
  
  if (count($result)) { 
    $sql = "UPDATE fb_cache SET cache_time = CURRENT_TIMESTAMP, cache_value='" . esc_sql($value) . "' WHERE cache_key='" . $key . "'";
  } else {
    $sql = "INSERT INTO fb_cache (cache_key, cache_value, cache_time) VALUES ('$key', '" . esc_sql($value) . "', CURRENT_TIMESTAMP)";
  }
  $result = $wpdb->query($sql);

  return $result;
}

function fb_cache_flush() { 

  global $wpdb;
  $sql = "DELETE FROM fb_cache";
  return $wpdb->query($sql);

}

##################### JSON #########################



function mw_json_encode($var) {

  global $MW_JSON;

  if ($MW_JSON) { 
    return $MW_JSON->encode($var);
  }

  return json_encode($var);

}

function mw_json_decode($string) { 

  global $MW_JSON;

  if ($MW_JSON) {
    return $MW_JSON->decode($string);
  }
  
  return json_decode($string);

}


##################### TOPICBLOCKS #########################


function fb_get_topicblocks_markup($fb_id=array(), $params=null) { 

  global $wpdb;

  #figure out the URL 

  if (!$params) { 
    $params = fb_get_option('topicblocks_placeholder_params');
  } else {
    $params .= "&" . fb_get_option('topicblocks_placeholder_params');
  }

  foreach ($fb_id as $id) { 
    $params .= "&id=" . $id;
  }

  $url = fb_get_option('topicblocks_placeholder_url') . "?" . $params;
  $cache_key = sha1($url);

  #cache get
  $content = fb_cache_get($cache_key, fb_get_option('cache_topicblocks_markup'));

  if (!$content) {
    #error_log('get http ' . $url);
    #cache miss - do http request
    $content = fb_http_get_content($url);

    #cache set
    if ($content) {
      fb_cache_set($cache_key, $content);
    }

  }
  #var_dump($content);
  $blocks = mw_json_decode($content);
  #error_log($content);
  if ($blocks && count($blocks) && $blocks->html) { 
    return $blocks;
  }
   
  return null;

}

#public post page - tag-based topicblocks
function fb_tag_topicblocks($content) { 

  $posttags = get_the_tags();
  $scripts = array();

  if ($posttags) { 
    
    fb_get_fb_tags($posttags, true);
    
    $ids = array();
    foreach ($posttags as $tag) { 
      if ($tag->fb_id) { 
        array_push($ids, $tag->fb_id);
      }
    }
    
    $blocks = fb_get_topicblocks_markup($ids, "track=topicblocks_plugin_tags");
      
    if ($blocks && $blocks->html && $blocks->script) { 
      $content .= $blocks->html;
      fb_enqueue_script($blocks->script);
    }
  }

  return $content;

}


#manually injected topicblocks
function fb_shortcode_topicblocks($atts) { 

  extract(shortcode_atts(array(
                               'id' => null,
                               'params' => null
                               ), $atts));


  if (!$id) { return ""; }
  #create the markup, don't save to the database
  if ($params) { 
    $blocks = fb_get_topicblocks_markup(array($id), html_entity_decode($params) . "&track=topicblocks_plugin_manual");
  } else { 
    $blocks = fb_get_topicblocks_markup(array($id), "track=topicblocks_plugin_manual");
  }


  if ($blocks && $blocks->html && $blocks->script) { 
    fb_enqueue_script($blocks->script);
    return $blocks->html;
  }
  
  return null;

}

#####################   FOOTER JS #########################

function fb_emit_js_config() { 

  global $pagenow;

  $metaweb_config = array(
                             "nonce"  => wp_create_nonce("fb_plugin"),
                             "blog_version" => get_bloginfo('version'),
                             "blog_url" => get_bloginfo('url'),
                             "blog_admin_page" => $pagenow,
                             "freebaseapps_base_url" => fb_get_option('freebaseapps_base_url'),
                             "freebaselibs_base_url" => fb_get_option('freebaselibs_base_url'),
                             "topicblocks_builder_url" => fb_get_option('topicblocks_builder_url')
                             );

  echo '<script type="text/javascript"> var metaweb_config = ' . mw_json_encode($metaweb_config) . ';</script>';
  
}


function fb_admin_styles() { 
  echo "<link rel='stylesheet' href='http://ajax.googleapis.com/ajax/libs/jqueryui/1.7.2/themes/smoothness/jquery-ui.css'/>";
  echo "<link rel='stylesheet' href='" . fb_get_option('freebaselibs_base_url') . fb_get_option('metaweb_css') . "'/>";
}
function fb_admin_scripts() {

  fb_emit_js_config();
  wp_enqueue_script('fb_blogin_admin_wordpress_js', fb_get_option('freebaselibs_base_url') . fb_get_option('metaweb_js'), array('jquery', 'jquery-ui-dialog'), False);

}

#######################  TAG OPERATIONS  ########################

function fb_write_fb_tag($tag) { 

  $result = 1;

  $fields = array("term_id", "fb_id", "fb_ignore");

  $request_fields = array();

  foreach ($fields as $field) { 

    if (isset($tag[$field])) { 
      #$request_fields[$field] = "'" . $tag[$field]. "', ";
      $request_fields[$field] = $tag[$field];
    }
  }

  if ($tag['term_id']) { 

    global $wpdb;

    $sql = 'SELECT * FROM fb_tags WHERE term_id = \'' . $tag['term_id'] . '\'';
    $results =  $wpdb->get_results($sql);

    if (isset($tag['fb_id']) || isset($tag['fb_ignore'])) { 
      
#UPDATE TAG MAPPING
      if (count($results)) {
        $wpdb->update('fb_tags', $request_fields, array('term_id' => $tag['term_id']));
#INSERT NEW TAG MAPPING 
      } else {
        return $wpdb->insert('fb_tags', $request_fields);
      }
#DELETE MAPPING
    } else if (count($results)) {
      $wpdb->query("DELETE from fb_tags WHERE term_id = '" . $tag['term_id'] . "'");
    }

  }

  return(array('result' => $result));

}


function extract_term_id($tag) { return $tag->term_id; };

function fb_get_fb_tags($tags = array()) { 

  global $wpdb;

  if (!$tags) { return; }

  $tag_ids = implode(',', array_map("extract_term_id", $tags));

  $sql = 'SELECT term_id, fb_id, fb_ignore FROM fb_tags WHERE fb_tags.term_id IN (' . $tag_ids .')';

  $results =  $wpdb->get_results($sql);

  $fb_tags = array();

  if (count($results)) { 
    foreach ($results as $fb_tag) { 
      foreach ($tags as $tag) { 
        if ($tag->term_id == $fb_tag->term_id) { 
          $tag->fb_id = $fb_tag->fb_id;
          $tag->fb_ignore = $fb_tag->fb_ignore;
        }
      }
    }
  }
}

function fb_get_tags() {

  global $wpdb;

  $args = array();
  $all_tags = array();
  $tag_dict = array();
  $tags = array();
  $posts = array();

  $sql = 'SELECT wp_terms.term_id, wp_terms.name, wp_terms.slug, wp_term_relationships.object_id FROM wp_terms JOIN wp_term_taxonomy ON wp_terms.term_id=wp_term_taxonomy.term_id 
LEFT JOIN wp_term_relationships ON wp_terms.term_id=wp_term_relationships.term_taxonomy_id 
WHERE wp_term_taxonomy.taxonomy="post_tag" ';

  if ($_REQUEST['term_id']) { 
    if (strpos($_REQUEST['term_id'], ',')) { 
      $sql .= ' AND wp_terms.term_id IN (' . $_REQUEST['term_id'] . ')';
    } else {
      $sql .= ' AND wp_terms.term_id=' . $_REQUEST['term_id'];
    }
    $all_tags = $wpdb->get_results($sql);
  }
  else if ($_REQUEST['post_id']) { 
    $posts = explode(",", $_REQUEST['post_id']);
    #$sql .= ' AND wp_term_relationships.object_id=' . $_REQUEST['post_id'];
    $all_tags = $wpdb->get_results($sql);
  } else { 
    $all_tags = $wpdb->get_results($sql);
  }
  
  foreach ($all_tags as $tag) { 
    if ($tag_dict[$tag->term_id] && $tag->object_id) { 
      $tag_dict[$tag->term_id]->count++;
      array_push($tag_dict[$tag->term_id]->posts, $tag->object_id);
    } else {
      $tag_dict[$tag->term_id] = $tag;
      $tag_dict[$tag->term_id]->posts = $tag->object_id ? array($tag->object_id) : array();
      $tag_dict[$tag->term_id]->count = $tag->object_id ? 1 : 0;
    }
  }

  #now put all the tag dictionaries in a list called $tags
  #if there were post_id passed in the URL, make sure we only return tags
  #that are in those posts
  if (count($posts)) { 
    foreach ($tag_dict as $term_id => $tag) { 
      foreach ($posts as $post_id) { 
        if (in_array($post_id, $tag->posts)) { 
          array_push($tags, $tag);
        }
      }
    }
  }
  else { 
    $tags = array_values($tag_dict);
  }

  fb_get_fb_tags($tags);


  $response = array("tags" => $tags);
  return fb_json_response($response, True);

  
}


########################  HTTP JSON WRAPPERS #####################

function fb_json_response($data, $success = True, $error = '') { 

  $callback = $_REQUEST['callback'];

  $response = array('result' => $data, 'status' => $success ? 'ok' : 'error');
  if (!$success && $error) { 
    $response['error'] = $error;
  }

  $jsonresponse = mw_json_encode($response);

  if ($callback) { 
    echo $callback . "(" . $jsonresponse . ");";
  } else { 
    echo $jsonresponse;
  }

}

function fb_validate_write($nonce) { 

  global $FB_VALIDATE;

  return (!$FB_VALIDATE || ($nonce && wp_verify_nonce($nonce, "fb_plugin")));

}


function fb_ajax_get_options() { 
  return fb_json_response(fb_get_options(), True);
}

function fb_ajax_set_option() { 

  if (!fb_validate_write($_REQUEST['nonce'])) {
    return fb_json_response(array(), False, 'You must specify a nonce in your write request.');
  }

  $key = $_REQUEST['key'] ? $_REQUEST['key'] : null;
  $value = ($_REQUEST['value']) ? $_REQUEST['value'] : null;

  if (!$key) { 
    return fb_json_response(array(), False, 'You must specify a key parameter with the key you want to set');
  }

  fb_set_option($key, $value);

  return fb_ajax_get_options();
}

function fb_ajax_reset_options() { 

  if (!fb_validate_write($_REQUEST['nonce'])) {
    return fb_json_response(array(), False, 'You must specify a nonce in your write request.');
  }

  $op = fb_set_defaults();

  return fb_ajax_get_options();

}

function fb_ajax_empty_cache() { 
  return fb_json_response(fb_cache_flush());
}

function fb_ajax_reset_tags() {

  if (!fb_validate_write($_REQUEST['nonce'])) {
    return fb_json_response(array(), False, 'You must specify a nonce in your write request.');
  }

  global $wpdb;
  $sql = "DELETE FROM fb_tags";
  $result = $wpdb->query($sql);

  return fb_json_response($result);


}

function fb_write_tag() { 

  if (!fb_validate_write($_REQUEST['nonce'])) {
    return fb_json_response(array(), False, 'You must specify a nonce in your write request.');
  }


  if ($_REQUEST['term_id']) { 
    $tag = array('term_id' => $_REQUEST['term_id']);

    if (isset($_REQUEST['fb_id'])) { 
      $tag['fb_id'] = $_REQUEST['fb_id'];
    }

    if (isset($_REQUEST['fb_ignore'])) { 
      $tag['fb_ignore'] = $_REQUEST['fb_ignore'];
    }

    $result = fb_write_fb_tag($tag);
    return fb_get_tags();
  }

  return fb_json_response(array(), False, 'You must specify a term_id argument');

}



######### ACTIVATION/DEACTIVATION ########

function fb_activate() { 
  fb_create_tables();
}

function fb_deactivate() { 
  #global $FB_OPTIONS_KEY;
  #blast options
  #update_option($FB_OPTIONS_KEY, null);
}

function fb_create_tables() { 

  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  global $wpdb;

  #create fb_tags table

  if($wpdb->get_var("SHOW TABLES LIKE 'fb_tags'") != 'fb_tags') {

    $sql = "CREATE TABLE fb_tags  (
 term_id BIGINT( 20 ) UNSIGNED NOT NULL ,
 fb_id VARCHAR( 1024 ) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL ,
 fb_ignore BOOL NOT NULL ,
PRIMARY KEY  (term_id));";
  
    dbDelta($sql);

  }

  #create fb_cache table
  if($wpdb->get_var("SHOW TABLES LIKE 'fb_cache'") != 'fb_cache') {
    $sql = "CREATE TABLE fb_cache (
 cache_key VARCHAR( 40 ) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL ,
 cache_value VARCHAR( 8192 ) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL ,
 cache_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ,
PRIMARY KEY  (cache_key));";
 
    dbDelta($sql);

  }
 
}

function fb_set_defaults() { 

  global $FB_OPTIONS_KEY;
  global $FB_OPTIONS;
  global $FB_DEFAULT_OPTIONS_URL;
  global $FB_OPTIONS_TIMESTAMP;

  $FB_OPTIONS = get_option($FB_OPTIONS_KEY);

  $options_url = $FB_DEFAULT_OPTIONS_URL;

  if (isset($FB_OPTIONS['options_url']) && strpos($FB_OPTIONS['options_url'], "http://") === 0) { 
    $options_url = $FB_OPTIONS['options_url'];
    
  }
  $opts = fb_http_get_content($options_url);

  if ($opts && count($opts)) { 
    $opts = mw_json_decode($opts);
  } else { 
    return $FB_OPTIONS;
  }

  $d = array();
  #json_decode return stdClass objects - we need an array
  foreach ($opts as $key => $value) { $d[$key] = $value; }

  update_option($FB_OPTIONS_KEY, $d);
  update_option($FB_OPTIONS_TIMESTAMP, time());
  $FB_OPTIONS = $d;
  return $d;
 

}

#set initial settings upon installation
function fb_init_defaults() { 
  fb_set_option('welcome', 1);
}

function fb_init() {

  global $FB_SCRIPTS;
  global $FB_OPTIONS_TIMESTAMP;

  $FB_SCRIPTS = array();

  $last_options_update =  get_option($FB_OPTIONS_TIMESTAMP);
  #initialize or refresh options
  if (!$last_options_update || (time() - $last_options_update) > fb_get_option('cache_options')) {
    fb_set_defaults();
  }

  if (!$last_options_update) {
    fb_init_defaults();
  }

}

function fb_enqueue_script($script) { 

  global $FB_SCRIPTS;
  $FB_SCRIPTS[$script] = True;

}

function fb_page_footer() {

  global $FB_SCRIPTS;

  foreach ($FB_SCRIPTS as $script => $v) { 
    echo "<script type=\"text/javascript\" src=\"" . $script . "\"></script>";

  }
  
}


############# HOOKS ##############


function mw_not_supported() { 

  echo "<p style='color: red;'><b>Sorry, the Metaweb Topicblocks Plugin is not compatible with your current WordPress and PHP installation. Please deactivate it from your WordPress Plugin page.</b>";

}

#check if necessary functions are available in this php installation
#plugin only works if all of these functions are available
#Note: for JSON, we package a php implementation as well
$MW_COMPATIBLE = True;
$MW_REQUIRED_FUNCS = array("curl_init", "curl_setopt", "curl_exec", "curl_close");
foreach ($MW_REQUIRED_FUNCS as $func) { 
  if (!function_exists($func)) { 
    $MW_COMPATIBLE = False; 
  }
}

if ($MW_COMPATIBLE) {

  #check if json is compiled-in and alternatively set MW_JSON 
  #to hold object of php implementation of json
  if (!(function_exists("json_encode") && function_exists("json_decode"))) { 
    require("metaweb_json.php");
    #SERVICES_JSON_SUPPRESS_ERRORS will return null instead of throwing exceptions
    #this mimicks the native json implementation
    $MW_JSON = new Metaweb_Services_JSON(SERVICES_JSON_SUPPRESS_ERRORS);
  }

  #only attach these hooks in the admin pages
  if (is_admin()) {

    #JS and CSS as well as the metaweb_config JS global 
    if (in_array($pagenow, explode(',', fb_get_option('admin_pages'))) || !fb_get_option('admin_pages')) { 
      add_action('admin_print_scripts', 'fb_admin_scripts');
      add_action('admin_head', 'fb_admin_styles');
    }
    
    #AJAX HTTP interface
    add_action('wp_ajax_fb_get_tags', 'fb_get_tags');
    add_action('wp_ajax_fb_write_tag', 'fb_write_tag');
    
    add_action('wp_ajax_fb_get_options', 'fb_ajax_get_options');
    add_action('wp_ajax_fb_set_option', 'fb_ajax_set_option');
    
    add_action('wp_ajax_fb_reset_options', 'fb_ajax_reset_options');
    add_action('wp_ajax_fb_empty_cache', 'fb_ajax_empty_cache');
    add_action('wp_ajax_fb_reset_tags', 'fb_ajax_reset_tags');
    
  } else {
    #widget.js 
    add_action('wp_footer', 'fb_page_footer');

    #shortcode / manual injection of topicblocks in a post
    add_shortcode('topicblocks', 'fb_shortcode_topicblocks');
    #tag-based injection of topiblocks for a post
    add_filter('the_content', 'fb_tag_topicblocks');
  }
  
  #every page load has this - init options and reset globals per request
  add_action('init', 'fb_init');
  
  register_activation_hook(__FILE__,'fb_activate');
  register_deactivation_hook(__FILE__,'fb_deactivate');

} else if (is_admin()) {
  add_action('admin_notices', 'mw_not_supported');
}