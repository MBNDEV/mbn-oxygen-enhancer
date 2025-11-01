<?php

/*
Plugin Name: MBN Oxygen Enhancer
Plugin URI: https://github.com/MBNDEV/mbn-oxygen-enhancer
Description: Enhances Oxygen Builder with performance optimizations and extra utilities.
Version: 4.0.0
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

add_action('wp_enqueue_scripts', function() {
  if( is_user_logged_in() ) {
    return;
  }

    // Enqueue lazysizes.min.js
    wp_enqueue_script(
        'mbn-lazysizes',
        plugins_url('mbn-enhancer.lazysizes.min.js', __FILE__),
        array(),
        null,
        true
    );

    // Enqueue optimizer.js
    wp_enqueue_script(
        'mbn-optimizer',
        plugins_url('mbn-enhancer.lazyassets.min.js', __FILE__),
        array('mbn-lazysizes'),
        null,
        true
    );

    // Enqueue video.js
    wp_enqueue_script(
        'mbn-video',
        plugins_url('mbn-enhancer-video.min.js', __FILE__),
        array(),
        null,
        true
    );
});


add_action('template_redirect', function () {
  ob_start('mbn_oxygen_enhancer_end_output_buffering');
});

function mbn_oxygen_enhancer_end_output_buffering($buffer) {
  // disable optimization for logged in users
  if( is_user_logged_in() ) {
    return $buffer;
  }

  $buffer = mbn_oxygen_enhancer_localize_third_party_fontstyles( $buffer);
  $buffer = mbn_oxygen_critical_css_optimize( $buffer);
  $buffer = mbn_oxygen_enhancer_scripts_optimize( $buffer);
  $buffer = mbn_oxygen_videos_optimize( $buffer);
  $buffer = mbn_oxygen_images_optimize( $buffer);
  $buffer = mbn_oxygen_jquery_defer_fix( $buffer);
  $buffer = mbn_oxygen_cleanup( $buffer );

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

    // Track localized fonts for preloading (Step 7)
    $font_preloads = array();

    // Track processed URLs to prevent duplicates
    $processed_urls = array();

    // Track CSS links to insert into head
    $css_links_to_insert = '';

    foreach ($matches[1] as $href) {
        // Skip if we've already processed this URL
        if (isset($processed_urls[$href])) {
            continue;
        }

        foreach ($third_party_css_patterns as $pattern) {
            if (preg_match($pattern, $href, $asset_match)) {
                $css_url = $href;
                $css_name = 'thirdparty-fontstyles-' . md5($css_url) . '.css';
                $local_css_file = $upload_base_dir . $css_name;
                $local_css_url = $upload_base_url . $css_name;

                // Mark this URL as processed
                $processed_urls[$href] = true;

                // STEP 1: Get CSS content from use.typekit.net
                $css_content = @file_get_contents($css_url);

                if ($css_content !== false && strlen(trim($css_content)) > 0) {
                    // Remove CSS comments
                    $css_content = preg_replace('#/\*.*?\*/#s', '', $css_content);

                    // Remove @import url(...) statements
                    $css_content = preg_replace('/@import\s+url\([^)]+\)\s*;?/i', '', $css_content);

                    // Ensure all @font-face in CSS set font-display: swap
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

                    // STEPS 2 & 3: Localize fonts and replace font URLs in CSS
                    $css_content = preg_replace_callback(
                        '/@font-face\s*\{[^}]*\}/is',
                        function($fontface_block_matches) use (&$upload_dir_info, $upload_base_dir, $upload_base_url, &$font_preloads) {
                            $block = $fontface_block_matches[0];

                            // Extract font-family for preload tracking
                            $font_family = '';
                            if (preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)["\']?\s*;/i', $block, $family_match)) {
                                $font_family = trim($family_match[1]);
                            }

                            // Find src: urls that are likely remote (http/https)
                            if (preg_match_all('/url\([\'"]?(https?:\/\/[^\'"\)]+)[\'"]?\)\s*format\([\'"]?([a-z0-9]+)[\'"]?\)/i', $block, $url_matches, PREG_SET_ORDER)) {
                                $local_sources = [];
                                $srcs_found = false;

                                foreach ($url_matches as $m) {
                                    $srcs_found = true;
                                    $remote_url = $m[1];
                                    $format = strtolower($m[2]);

                                    // Skip opentype fonts - we don't want them
                                    if ($format === 'opentype' || $format === 'otf') {
                                        continue;
                                    }

                                    // Use format from CSS @font-face declaration (not URL filename)
                                    // Third-party fonts like Typekit don't include file extensions in URLs
                                    $ext = ($format === 'woff2' || $format === 'woff' || $format === 'ttf') ? $format : 'woff2';
                                    $basename = 'font-' . md5($remote_url) . "." . $ext;
                                    $local_file_path = trailingslashit($upload_base_dir . 'fonts') . $basename;
                                    $local_file_url  = trailingslashit($upload_base_url . 'fonts') . $basename;

                                    // Create the fonts directory if needed
                                    if (!file_exists(dirname($local_file_path))) {
                                        wp_mkdir_p(dirname($local_file_path));
                                    }

                                    // STEP 2: Download font if not exists or empty
                                    if (!file_exists($local_file_path) || filesize($local_file_path) === 0) {
                                        $font_content = @file_get_contents($remote_url);
                                        if ($font_content !== false) {
                                            @file_put_contents($local_file_path, $font_content);
                                        }
                                    }

                                    // STEP 3: Replace with local URL if successfully downloaded
                                    if (file_exists($local_file_path) && filesize($local_file_path) > 0) {
                                        $final_url = $local_file_url;

                                        // Track woff2 fonts for preloading (Step 7)
                                        if ($format === 'woff2' && $font_family && !isset($font_preloads[$font_family])) {
                                            $font_preloads[$font_family] = $final_url;
                                        }
                                    } else {
                                        $final_url = $remote_url;
                                    }

                                    $local_sources[] = 'url("' . esc_url($final_url) . '") format("' . esc_attr($format) . '")';
                                }

                                if ($srcs_found && $local_sources) {
                                    // Replace the whole src: line(s) in the block with the local_sources
                                    $block = preg_replace(
                                        '/src\s*:[^;]+;/is',
                                        'src: ' . implode(', ', $local_sources) . ';',
                                        $block
                                    );
                                }
                            }
                            return $block;
                        },
                        $css_content
                    );

                    // STEP 4: Save modified CSS file locally
                    @file_put_contents($local_css_file, $css_content);

                    // STEP 5: Build deferred CSS link to insert into head
                    $deferred_link = '<link rel="stylesheet" href="' . esc_attr($local_css_url) . '" media="print" onload="this.media=\'all\'">' . "\n";
                    $deferred_link .= '<noscript><link rel="stylesheet" href="' . esc_attr($local_css_url) . '"></noscript>' . "\n";
                    $css_links_to_insert .= $deferred_link;

                    // STEP 6: Remove all Typekit CSS link tags from buffer
                    $buffer = preg_replace_callback(
                        '#<link\b[^>]*>#i',
                        function($match) use ($css_url) {
                            $link_tag = $match[0];
                            // If this link contains the Typekit URL, remove it
                            if (stripos($link_tag, $css_url) !== false) {
                                return ''; // Remove the tag
                            }
                            return $link_tag; // Keep other links
                        },
                        $buffer
                    );
                }

                // Once matched and processed, don't check further patterns for this href
                break;
            }
        }
    }

    // STEP 6: Insert CSS links into <head>
    if (!empty($css_links_to_insert) && preg_match('/<head[^>]*>/i', $buffer, $head_tag, PREG_OFFSET_CAPTURE)) {
        $head_pos = $head_tag[0][1] + strlen($head_tag[0][0]);
        $buffer = substr_replace($buffer, $css_links_to_insert, $head_pos, 0);
    }

    // STEP 7: Preload localized fonts
    if (!empty($font_preloads)) {
        $preload_links = '';
        foreach ($font_preloads as $font_url) {
            $preload_links .= '<link rel="preload" as="font" href="' . esc_url($font_url) . '" type="font/woff2" crossorigin>' . "\n";
        }

        // Insert preload links just after <head>
        if (preg_match('/<head[^>]*>/i', $buffer, $head_tag, PREG_OFFSET_CAPTURE)) {
            $head_pos = $head_tag[0][1] + strlen($head_tag[0][0]);
            $buffer = substr_replace($buffer, $preload_links, $head_pos, 0);
        }
    }

    return $buffer;
}


function mbn_oxygen_enhancer_scripts_optimize( $buffer) {
  // Exclude lazysizes.min.js, lazyassets.js (src or inlined) from being changed
  $excluded_scripts = array(
    'mbn-enhancer.lazysizes.min.js',
    'mbn-enhancer.lazyassets.min.js',
    'aos.js',
    'jquery.min.js',
    'AOS.init'
  );

  // First, defer script tags with src that are not already deferred and not excluded
  $buffer = preg_replace_callback(
    '#<script\b([^>]*)\bsrc\s*=\s*["\']([^"\']+)["\']([^>]*)>(.*?)</script>#is',
    function($matches) {
      $attrs_before = $matches[1];
      $src_url = $matches[2];
      $attrs_after = $matches[3];
      $script_content = $matches[4];

      // Don't touch scripts that already have defer or async
      if (preg_match('/\bdefer\b/i', $attrs_before . ' ' . $attrs_after) ||
          preg_match('/\basync\b/i', $attrs_before . ' ' . $attrs_after)) {
        return $matches[0];
      }

      // Add defer to <script ...>
      $deferred_tag = '<script' . rtrim($attrs_before) . ' src="' . $src_url . '"' . $attrs_after . ' defer>' . $script_content . '</script>';
      return $deferred_tag;
    },
    $buffer
  );

  $buffer = preg_replace_callback(
    '#<script\b([^>]*)>(.*?)</script>#is',
    function($matches) use ($excluded_scripts) {
      $attrs = $matches[1];
      $content = $matches[2];

      // Check if any exclusion keyword (by filename) occurs in src attr or inlined JS content
      foreach ($excluded_scripts as $excluded) {
        // Check src attribute (must match filename as last component of src=)
        if (preg_match('/\bsrc\s*=\s*(["\'])([^"\']+)\1/i', $attrs, $src_match)) {
          $src_url = $src_match[2];
          // parse_url to get the path, then basename
          $src_path = parse_url($src_url, PHP_URL_PATH);
          if ($src_path && basename($src_path) === $excluded) {
            return $matches[0];
          }
        }
        // Check inline content (substring case-insensitive)
        if (stripos($content, $excluded) !== false) {
          return $matches[0];
        }
      }

      // If type attribute present
      if (preg_match('/\stype\s*=\s*[\'"]([^\'"]+)[\'"]/i', $attrs, $type_match)) {
        $type_value = trim(strtolower($type_match[1]));
        if ($type_value === 'text/javascript') {
          // Remove existing type attribute
          $attrs = preg_replace('/\s*type\s*=\s*[\'"][^\'"]*[\'"]/', '', $attrs);
          // Add our new type
          return '<script' . rtrim($attrs) . ' type="mbn-scripts-load">' . $content . '</script>';
        } else {
          // Don't modify
          return $matches[0];
        }
      } else {
        // No type attr, add our type
        return '<script' . rtrim($attrs) . ' type="mbn-scripts-load">' . $content . '</script>';
      }
    },
    $buffer
  );

  return $buffer;
}

function mbn_oxygen_jquery_defer_fix($buffer) {
  // Find all <script> tags with inline JS (type="text/javascript" or no type)
  // 1. Save each inline script to a separate file under uploads/mbn-enhancer/inline-script-<hash>.js if not already exists.
  // 2. Replace inline <script> with <script src="...uploaded file url..." defer></script>
  // Blob scheme not used (WP needs real URLs for src).
  $upload_dir_info = wp_upload_dir();
  $upload_base_dir = trailingslashit($upload_dir_info['basedir']) . 'mbn-enhancer/';
  $upload_base_url = trailingslashit($upload_dir_info['baseurl']) . 'mbn-enhancer/';

  // Ensure upload dir exists.
  if (!file_exists($upload_base_dir)) {
      wp_mkdir_p($upload_base_dir);
  }

  $buffer = preg_replace_callback(
    // Match <script> tags that don't have src (inline), with optional type="text/javascript" or no type.
    '#<script\b([^>]*)>([\s\S]*?)<\/script>#i',
    function($matches) use ($upload_base_dir, $upload_base_url) {
      $attrs = $matches[1];
      $code = trim($matches[2]);

      // Only move inline scripts with either no type or type="text/javascript"
      $type_match = [];
      $has_type = preg_match('/\stype\s*=\s*[\'"]([^\'"]+)[\'"]/i', $attrs, $type_match);
      $allow = false;
      if (!$has_type) {
        $allow = true;
      } else {
        $type_value = strtolower(trim($type_match[1]));
        if ($type_value === 'text/javascript') {
          $allow = true;
        }
      }
      // If no inline JS, or not allowed type, leave unchanged
      if (!$allow || strlen($code) < 1) {
        return $matches[0];
      }

      // Hash for uniqueness
      $hash = md5($code);
      $script_filename = 'inline-script-' . $hash . '.js';
      $script_filepath = $upload_base_dir . $script_filename;
      $script_fileurl = $upload_base_url . $script_filename;

      // Write file if not exists
      if (!file_exists($script_filepath)) {
        @file_put_contents($script_filepath, $code);
      }

      // Replace with external script, preserve any data- attrs
      // Remove type attribute if there (for cleanliness)
      $attrs = preg_replace('/\s*type\s*=\s*[\'"][^\'"]*[\'"]/', '', $attrs);
      return '<script' . rtrim($attrs) . ' src="' . esc_url($script_fileurl) . '" defer></script>';
    },
    $buffer
  );

  return $buffer;
}


function mbn_oxygen_critical_css_optimize( $buffer) {
  
  $css_file_patterns = array(
    '/\/oxygen\/css\/.*\.css/i',
    '/\/automatic-css\/.*\.css/i',
    '/oxygen\.min\.css/i',
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
    '/<video\b([^>]*)>(.*?)<\/video>/is',
    function ($matches) {
      $attrs = $matches[1];
      $inner = $matches[2];

      // Move src attribute to data-src in <video> tag
      if (preg_match('/\bsrc\s*=\s*([\'"])([^\'"]+)\1/i', $attrs, $src_match)) {
        $attrs = preg_replace('/\bsrc\s*=\s*([\'"])[^\'"]+\1/i', '', $attrs);
        $attrs = rtrim($attrs) . ' data-src=' . $src_match[1] . $src_match[2] . $src_match[1];
      }

      // Add preload="none" only if not already present
      if (preg_match('/\bpreload\s*=\s*/i', $attrs)) {
        $preload_none = '';
      } else {
        $preload_none = ' preload="none"';
      }

      // Replace src with data-src in <source> tags within the video
      $inner = preg_replace_callback('/<source\b([^>]*)>/i', function($source_matches) {
        $source_attrs = $source_matches[1];
        if (preg_match('/\bsrc\s*=\s*([\'"])([^\'"]+)\1/i', $source_attrs, $src_match)) {
          $source_attrs = preg_replace('/\bsrc\s*=\s*([\'"])[^\'"]+\1/i', '', $source_attrs);
          $source_attrs = rtrim($source_attrs) . ' data-src=' . $src_match[1] . $src_match[2] . $src_match[1];
        }
        return '<source' . ($source_attrs ? ' ' . trim($source_attrs) : '') . '>';
      }, $inner);

      return '<video' . ($attrs ? ' ' . trim($attrs) : '') . $preload_none . '>' . $inner . '</video>';
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
        // Replace src with a placeholder 1x1 gif, move original to data-src.
        if ($src) {
          // Remove existing src attribute
          $attrs = preg_replace('/\s*src\s*=\s*[\'"][^\'"]*[\'"]/', '', $attrs);
          // Add placeholder src and data-src
          $attrs .= ' src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-src="' . esc_attr($src) . '"';
        }
        // Move srcset to data-srcset if exists
        if (preg_match('/\bsrcset\s*=\s*[\'"]([^\'"]+)[\'"]/i', $attrs, $srcset_match)) {
          $attrs = preg_replace('/\s*srcset\s*=\s*[\'"][^\'"]*[\'"]/', '', $attrs);
          $attrs .= ' data-srcset="' . esc_attr($srcset_match[1]) . '"';
        }
        // Move sizes to data-sizes if exists
        if (preg_match('/\bsizes\s*=\s*[\'"]([^\'"]+)[\'"]/i', $attrs, $sizes_match)) {
          $attrs = preg_replace('/\s*sizes\s*=\s*[\'"][^\'"]*[\'"]/', '', $attrs);
          $attrs .= ' data-sizes="' . esc_attr($sizes_match[1]) . '"';
        }
        // Add class lazyload (merge with existing class if any)
        if (preg_match('/\bclass\s*=\s*[\'"]([^\'"]*)[\'"]/i', $attrs, $class_match)) {
          $existing_class = $class_match[1];
          // Update class attr to add lazyload if not present
          if (strpos($existing_class, 'lazyload') === false) {
            $new_class_val = trim($existing_class . ' lazyload');
            $attrs = preg_replace('/\bclass\s*=\s*[\'"][^\'"]*[\'"]/', ' class="' . esc_attr($new_class_val) . '"', $attrs);
          }
        } else {
          $attrs .= ' class="lazyload"';
        }
      }
      $img_tag = '<img' . $attrs . '>';

      return $img_tag;
    },
    $buffer
  );

  return $buffer;
}


function mbn_oxygen_cleanup( $buffer ) {
  // Remove CSS comments from all <style>...</style> tags (including inline in <head>)
  $buffer = preg_replace_callback(
    '#(<style[^>]*>)(.*?)(</style>)#is',
    function($matches) {
      $start = $matches[1];
      $css = $matches[2];
      $end = $matches[3];
      // Remove CSS comments: /* ... */
      $css = preg_replace('#/\*.*?\*/#s', '', $css);
      return $start . $css . $end;
    },
    $buffer
  );

  // Insert comment <!-- MBN Performance Optimized Buffer --> before </body>
  $mbn_comment = "\n<!-- MBN Performance Optimized Buffer -->\n";
  if (stripos($buffer, '</body>') !== false) {
    $buffer = preg_replace('/(<\/body>)/i', $mbn_comment . '$1', $buffer, 1);
  } else {
    // Fallback: append to end
    $buffer .= $mbn_comment;
  }

  return $buffer;
}



// Add an admin bar option to clear mbn-enhancer cache files

add_action('admin_bar_menu', function($wp_admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }
    $wp_admin_bar->add_node(array(
        'id'    => 'mbn_enhancer_clear_cache',
        'title' => 'Clear MBN Enhancer Files',
        'href'  => wp_nonce_url(
            add_query_arg('mbn_enhancer_clear_cache', '1', admin_url()),
            'mbn_enhancer_clear_cache'
        ),
        'meta'  => array(
            'title' => 'Clear mbn-enhancer asset files (CSS/JS fonts cache)'
        ),
    ));
}, 99);

add_action('admin_init', function() {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (
        isset($_GET['mbn_enhancer_clear_cache']) && $_GET['mbn_enhancer_clear_cache'] == '1'
        && check_admin_referer('mbn_enhancer_clear_cache')
    ) {
        $upload_dir_info = wp_upload_dir();
        $mbn_dir = trailingslashit($upload_dir_info['basedir']) . 'mbn-enhancer/';

        // Recursively delete files in the mbn-enhancer dir
        if (file_exists($mbn_dir)) {
            $objects = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($mbn_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($objects as $fileinfo) {
                if ($fileinfo->isFile() || $fileinfo->isLink()) {
                    @unlink($fileinfo->getRealPath());
                } elseif ($fileinfo->isDir()) {
                    @rmdir($fileinfo->getRealPath());
                }
            }
            // Optionally: leave the mbn-enhancer dir present, or remove it
            // @rmdir($mbn_dir);
        }

        // Redirect to prevent resubmission
        wp_safe_redirect(remove_query_arg(['mbn_enhancer_clear_cache', '_wpnonce']));
        exit;
    }
});
