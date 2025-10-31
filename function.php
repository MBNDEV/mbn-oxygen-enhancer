<?php

/*
Plugin Name: MBN Oxygen Enhancer
Plugin URI: https://github.com/MBNDEV/mbn-oxygen-enhancer
Description: Enhances Oxygen Builder with performance optimizations and extra utilities.
Version: 1.0.0
Author: My Biz Niche
Author URI: https://www.mybizniche.com/
License: GPL2
Text Domain: mbn-oxygen-enhancer
*/

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
 PucFactory::buildUpdateChecker(
  'https://github.com/MBNDEV/mbn-oxygen-enhancer',
  __FILE__,
  'mbn-oxygen-enhancer'
);

add_action('wp_head', function() {
  $css_file = plugin_dir_path(__FILE__) . 'style.css';
  $css_content = file_get_contents($css_file);
  echo '<style id="mbn-oxygen-enhancer-inline-css">' . $css_content . '</style>';
}, 100);

add_action('wp_footer', function() {
  $js_file = plugin_dir_path(__FILE__) . 'script.js';
  $js_content = file_get_contents($js_file);
  echo '<script id="mbn-oxygen-enhancer-inline-js">' . $js_content . '</script>';
}, 100);


add_action('template_redirect', function () {
  ob_start('mbn_oxygen_enhancer_end_output_buffering');
});

function mbn_oxygen_enhancer_end_output_buffering($buffer) {
  // disable optimization for logged in users
  if( is_user_logged_in() ) {
    return $buffer;
  }

  $buffer = mbn_oxygen_enhancer_localize_third_party_fontstyles( $buffer);
  $buffer = mbn_oxygen_enhancer_importcss_local( $buffer );

  $buffer = mbn_oxygen_critical_css_optimize( $buffer);
  $buffer = mbn_oxygen_analytics_optimize( $buffer);
  $buffer = mbn_oxygen_preconnect_optimize( $buffer);
  $buffer = mbn_oxygen_enhancer_scripts_optimize( $buffer);

  $buffer = mbn_oxygen_videos_optimize( $buffer);
  $buffer = mbn_oxygen_images_optimize( $buffer);

  return $buffer;
}


function mbn_oxygen_enhancer_localize_third_party_fontstyles($buffer) {
    // Find all <link> tags with href
    // Match only <link> with rel="stylesheet" (in any attribute order, rel can be anywhere) and capture href
    preg_match_all(
        '#<link\b(?=[^>]*\brel\s*=\s*["\']stylesheet["\'])(?=[^>]*\bhref\s*=\s*["\']([^"\']+)["\'])[^>]*>#i',
        $buffer,
        $matches
    );

    // Define patterns to check for third-party CSS
    $third_party_css_patterns = array(
        '#^https?://use\.typekit\.net/([a-zA-Z0-9_-]+\.css)#i',
        // Add more patterns here if needed
    );

    $upload_dir_info = wp_upload_dir();
    $upload_base_dir = trailingslashit($upload_dir_info['basedir']) . 'mbn-enhancer/';
    $upload_base_url = trailingslashit($upload_dir_info['baseurl']) . 'mbn-enhancer/';

    // Make sure the upload directory exists
    if (!file_exists($upload_base_dir)) {
        wp_mkdir_p($upload_base_dir);
    }

    foreach ($matches[1] as $href) {
        foreach ($third_party_css_patterns as $pattern) {
            if (preg_match($pattern, $href, $asset_match)) {
                $css_url = $href;
                $css_name = 'thirdparty-fontstyles-' . md5($css_url) . '.css';
                $local_css_file = $upload_base_dir . $css_name;

                // Download and save if it does not exist (or is stale, optional)
                if (!file_exists($local_css_file)) {
                    $css_content = @file_get_contents($css_url);
                    if ($css_content !== false) {
                        @file_put_contents($local_css_file, $css_content);
                    }
                } else {
                    $css_content = @file_get_contents($local_css_file);
                }

                // Inline only if we have valid CSS content
                if ($css_content !== false && strlen(trim($css_content)) > 0) {
                    // Replace link with <style> in the HTML buffer
                    // Ensure all @font-face in CSS set font-display: swap (add if not present)
                    $css_content = preg_replace_callback(
                        '/@font-face\s*{[^}]*}/is',
                        function($matches) {
                            $block = $matches[0];
                            // If font-display exists, replace its value with swap
                            if (preg_match('/font-display\s*:\s*[^;]+;/i', $block)) {
                                $block = preg_replace('/font-display\s*:\s*[^;]+;/i', 'font-display: swap;', $block);
                            } else {
                                // Insert font-display: swap; before closing }
                                $block = preg_replace('/}/', '    font-display: swap; }', $block, 1);
                            }
                            return $block;
                        },
                        $css_content
                    );

                    $pattern_replace = '#<link\s+[^>]*href=[\'"]' . preg_quote($css_url, '#') . '[\'"][^>]*>#i';
                    $style_tag = '<style id="mbn-o2-inline-' . md5($css_url) . '">' . $css_content . '</style>';
                    $buffer = preg_replace($pattern_replace, $style_tag, $buffer, 1);
                    $buffer = preg_replace($pattern_replace, '', $buffer);
                }
                // Once matched and processed, don't check further patterns for this href
                break;
            }
        }
    }

    return $buffer;
}


function mbn_oxygen_enhancer_importcss_local($buffer) {
    // Find all <style> tags with inline CSS that contain @import url(...)
    $buffer = preg_replace_callback(
        '#<style([^>]*)>(.*?)</style>#is',
        function($matches) {
            $style_attrs = $matches[1];
            $style_content = $matches[2];

            // Find all @import url(...) in this <style> block
            if (preg_match_all('/@import\s+url\((["\']?)([^"\')]+)\1\)\s*;?/i', $style_content, $import_matches, PREG_SET_ORDER)) {
                foreach ($import_matches as $import_match) {
                    $import_url = $import_match[2];

                    // Use the same upload logic as mbn_oxygen_enhancer_localize_third_party_fontstyles
                    $upload_dir_info = wp_upload_dir();
                    $upload_base_dir = trailingslashit($upload_dir_info['basedir']) . 'mbn-enhancer/';
                    // Make sure the upload directory exists
                    if (!file_exists($upload_base_dir)) {
                      wp_mkdir_p($upload_base_dir);
                    }

                    $css_name = 'thirdparty-importcss-' . md5($import_url) . '.css';
                    $local_css_file = $upload_base_dir . $css_name;

                    // Download and save if it does not exist
                    if (!file_exists($local_css_file)) {
                        $remote_css = @file_get_contents($import_url);
                        if ($remote_css !== false) {
                            @file_put_contents($local_css_file, $remote_css);
                        }
                    } else {
                        $remote_css = @file_get_contents($local_css_file);
                    }

                    // Remove the @import url(...) line from style content
                    $style_content = str_replace($import_match[0], '', $style_content);

                    // Prepend a CSS comment indicating origin, then the downloaded (local) CSS inlined
                    if (!empty($remote_css) && strlen(trim($remote_css)) > 0) {
                        $prepend = "/* Imported from $import_url */\n" . $remote_css . "\n";
                        $style_content = $prepend . $style_content;
                    }
                }
            }

            return '<style' . $style_attrs . '>' . $style_content . '</style>';
        },
        $buffer
    );

    return $buffer;
}


function mbn_oxygen_enhancer_scripts_optimize( $buffer) {
  
  return $buffer;
}

function mbn_oxygen_preconnect_optimize( $buffer) {
  // Preconnect all third party scripts, styles, or iframes

  $third_party_hosts = array();

  // Find all <script> tags with src
  preg_match_all('#<script\s+[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>#i', $buffer, $scripts);
  // Find all <link rel=stylesheet> tags with href
  preg_match_all('#<link\s+[^>]*rel=[\'"]stylesheet[\'"][^>]*href=[\'"]([^\'"]+)[\'"][^>]*>#i', $buffer, $styles);
  // Find all <iframe> tags with src
  preg_match_all('#<iframe\s+[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>#i', $buffer, $iframes);

  // Combine all found third-party URLs
  $urls = array_merge(
    !empty($scripts[1]) ? $scripts[1] : array(),
    !empty($styles[1]) ? $styles[1] : array(),
    !empty($iframes[1]) ? $iframes[1] : array()
  );

  foreach ($urls as $url) {
    $url_parts = parse_url($url);

    if (!empty($url_parts['host'])) {
      // Ignore current domain and localhost
      if (
        strpos($url_parts['host'], $_SERVER['HTTP_HOST']) === false &&
        strpos($url_parts['host'], 'localhost') === false
      ) {
        $scheme = !empty($url_parts['scheme']) ? $url_parts['scheme'] : 'https';
        $host = $scheme . '://' . $url_parts['host'];
        $third_party_hosts[$host] = true;
      }
    }
  }

  if (!empty($third_party_hosts)) {
    // Build preconnect tags for all unique third-party hosts
    $preconnect_tags = '';
    foreach (array_keys($third_party_hosts) as $host) {
      $preconnect_tags .= '<link rel="preconnect" href="' . esc_url($host) . '" crossorigin>' . "\n";
    }

    // Insert before first <script>, <link rel="stylesheet">, <iframe>, or before </head> as fallback
    $inserted = false;
    foreach (array(
      '/(<script\s)/i',
      '/(<link\s+[^>]*rel=[\'"]stylesheet[\'"][^>]*>)/i',
      '/(<iframe\s)/i'
    ) as $pattern) {
      $buffer = preg_replace($pattern, $preconnect_tags . '$1', $buffer, 1, $count);
      if ($count > 0) {
        $inserted = true;
        break;
      }
    }
    if (!$inserted) {
      // fallback: just before </head>
      $buffer = preg_replace('/(<\/head>)/i', $preconnect_tags . '$1', $buffer, 1);
    }
  }

  return $buffer;
}


function mbn_oxygen_critical_css_optimize( $buffer) {
  
  $css_file_patterns = array(
    '/\/oxygen\/css\/.*\.css/i',
    '/\/automatic-css\/.*\.css/i',
    '/oxygen\.min\.css/i'
  );

  // Match <link> tags pointing to the CSS files we want
  $link_pattern = '#<link\s+[^>]*href=[\'"]([^\'"]+)["\'][^>]*>#i';

  // Use preg_replace_callback to process each matching tag
  $buffer = preg_replace_callback($link_pattern, function($matches) use ($css_file_patterns) {
    $href = $matches[1];
    foreach ($css_file_patterns as $pattern) {
      if (preg_match($pattern, $href)) {
        // Remove query string for file_exists validation
        $file_url = strtok($href, '?');
        // Try to resolve the local file path
        $abs_path = $_SERVER['DOCUMENT_ROOT'] . parse_url($file_url, PHP_URL_PATH);
        if (file_exists($abs_path)) {
          $css_content = file_get_contents($abs_path);
          // Inline CSS using <style>
          return '<style id="mbn-o2-inline-' . md5($href) . '">' . $css_content . '</style>';
        }
        // If not found locally, just remove the link
        return '';
      }
    }
    // Not our file, leave unchanged
    return $matches[0];
  }, $buffer);


  // Use a pattern that only matches <link> tags with rel="stylesheet"
  // Match <link> tags containing *both* rel="stylesheet" and href=... in any attribute order
  $link_stylesheet_pattern = '#<link\s+[^>]*\brel=[\'"]stylesheet[\'"][^>]*\bhref=[\'"]([^\'"]+)[\'"][^>]*>|<link\s+[^>]*\bhref=[\'"]([^\'"]+)[\'"][^>]*\brel=[\'"]stylesheet[\'"][^>]*>#i';
  $buffer = preg_replace_callback($link_stylesheet_pattern, function($matches) {
    // The href can match either $matches[1] or $matches[2] depending on order in tag
    $href = !empty($matches[1]) ? $matches[1] : $matches[2];
    $link_tag = '<link rel="stylesheet" href="' . esc_attr($href) . '" media="print" onload="this.media=\'all\'">';
    $noscript_tag = '<noscript><link rel="stylesheet" href="' . esc_attr($href) . '"></noscript>';
    return $link_tag . $noscript_tag;
  }, $buffer);

  return $buffer;
}

function mbn_oxygen_videos_optimize( $buffer) {
  // Add preload="none" to all <video> tags that don't already have a preload attribute
  $buffer = preg_replace_callback(
    '/<video\b([^>]*)>/i',
    function ($matches) {
      if (preg_match('/\bpreload\s*=\s*/i', $matches[1])) {
        return $matches[0];
      }
      $attrs = rtrim($matches[1]);
      return '<video' . ($attrs ? $attrs . ' ' : ' ') . 'preload="none">';
    },
    $buffer
  );

  return $buffer;
}

function mbn_oxygen_images_optimize( $buffer) {

  // Get all <img> tags, count their occurrences, and modify as required
  $img_count = 0;
  $buffer = preg_replace_callback(
    '/<img\b([^>]*)>/i',
    function($matches) use (&$img_count) {
      $img_count++;

      $attrs = $matches[1];

      // Check if width and height are present
      $has_width  = preg_match('/\bwidth\s*=\s*[\'"][^\'"]+[\'"]/i', $attrs);
      $has_height = preg_match('/\bheight\s*=\s*[\'"][^\'"]+[\'"]/i', $attrs);

      // Get src
      $src = null;
      if (preg_match('/\bsrc\s*=\s*[\'"]([^\'"]+)[\'"]/i', $attrs, $src_match)) {
        $src = $src_match[1];
      }

      $width = null;
      $height = null;

      if ((!$has_width || !$has_height) && $src) {
        // Try to get image size for local images
        $is_local = false;
        // Absolute path if possible
        if (isset($_SERVER['DOCUMENT_ROOT'])) {
          $parsed_url = parse_url($src);
          // Only process if relative or matches site host
          if (empty($parsed_url['host']) || 
              (isset($_SERVER['HTTP_HOST']) && $parsed_url['host'] === $_SERVER['HTTP_HOST'])) {
            $path = !empty($parsed_url['path']) ? $parsed_url['path'] : '';
            $file = $_SERVER['DOCUMENT_ROOT'] . $path;
            if (file_exists($file) && function_exists('getimagesize')) {
              $image_info = @getimagesize($file);
              if ($image_info) {
                $width = $image_info[0];
                $height = $image_info[1];
              }
            }
          }
        }
        // If width/height available, add them if missing
        if ($width && !$has_width) {
          $attrs .= ' width="' . intval($width) . '"';
        }
        if ($height && !$has_height) {
          $attrs .= ' height="' . intval($height) . '"';
        }
      }

      // Add loading attribute: eager for first 10, lazy after.
      // Always force replace or set the loading attribute
      // Remove any existing loading attribute
      $attrs = preg_replace('/\s*loading\s*=\s*[\'"][^\'"]*[\'"]/i', '', $attrs);
      if ($img_count <= 10) {
        $attrs .= ' loading="eager"';
      } else {
        $attrs .= ' loading="lazy"';
      }
      $img_tag = '<img' . $attrs . '>';

      return $img_tag;
    },
    $buffer
  );

  return $buffer;
}


function mbn_oxygen_analytics_optimize( $buffer) {

  $patterns = array(
    'gtag', 
    'googletagmanager', 
    'gtm.js', 
    'fbevents.js'
  );

  $buffer = preg_replace_callback(
    '#<script\b([^>]*)>(.*?)</script>#is',
    function($matches) use ($patterns) {
      $attrs = $matches[1];
      $content = $matches[2];

      // Check for match in src attribute or inline content
      $found = false;
      foreach ($patterns as $keyword) {
        // Check src attribute
        if (preg_match('/src\s*=\s*[\'"][^\'"]*' . preg_quote($keyword, '/') . '[^\'"]*[\'"]/i', $attrs)) {
          $found = true;
          break;
        }
        // Check inline JS content
        if (preg_match('/' . preg_quote($keyword, '/') . '/i', $content)) {
          $found = true;
          break;
        }
      }
      if (!$found) {
        return $matches[0];
      }

      // Remove any type attribute
      $attrs = preg_replace('/\s*type=[\'"][^\'"]*[\'"]/', '', $attrs);
      // Rebuild with type="mbn-scripts-load"
      $new_tag = '<script' . rtrim($attrs) . ' type="mbn-scripts-load">' . $content . '</script>';
      return $new_tag;
    },
    $buffer
  );

  return $buffer;
}