<?php

/*
Plugin Name: MBN Oxygen Enhancer
Plugin URI: https://github.com/MBNDEV/mbn-oxygen-enhancer
Description: Enhances Oxygen Builder with performance optimizations and extra utilities.
Version: 2.0.0
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
  $buffer = mbn_oxygen_enhancer_importcss_local( $buffer );
  $buffer = mbn_oxygen_critical_css_optimize( $buffer);
  $buffer = mbn_oxygen_enhancer_scripts_optimize( $buffer);
  $buffer = mbn_oxygen_videos_optimize( $buffer);
  $buffer = mbn_oxygen_images_optimize( $buffer);
  $buffer = mbn_oxygen_preload_font( $buffer);
  $buffer = mbn_oxygen_preconnect_optimize( $buffer);
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
  // Exclude lazysizes.min.js, lazyassets.js (src or inlined) from being changed
  $excluded_scripts = array(
    'mbn-enhancer.lazysizes.min.js',
    'mbn-enhancer.lazyassets.min.js',
    'aos.js',
    'jquery.min.js',
    'AOS.init'
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

function mbn_oxygen_preconnect_optimize($buffer) {
    // Preconnect all third party scripts, styles, or iframes

    $third_party_hosts = array();

    // Find all <script> tags with src
    preg_match_all('#<script\s+[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>#i', $buffer, $scripts);
    // Find all <link rel=stylesheet> tags with href
    preg_match_all('#<link\s+[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>#i', $buffer, $styles);
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

        // Remove all <link rel="stylesheet" ...> tags (as per prompt)
        $buffer = preg_replace('#<link\s+[^>]*rel=[\'"]stylesheet[\'"][^>]*>#i', '', $buffer);

        // Insert preconnect tags right after <head> or before first element in <head>
        if (preg_match('/<head[^>]*>/i', $buffer, $head_tag, PREG_OFFSET_CAPTURE)) {
            $head_pos = $head_tag[0][1] + strlen($head_tag[0][0]);
            $buffer = substr_replace($buffer, $preconnect_tags, $head_pos, 0);
        } else {
            // Fallback: prepend to buffer
            $buffer = $preconnect_tags . $buffer;
        }
    }

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



function mbn_oxygen_preload_font($buffer) {
    // Only preload font URLs if they are from Typekit (use.typekit.net)
    $preload_fonts = array(); // [font-family or null] => font-url

    // Find all @font-face blocks
    if (preg_match_all('/@font-face\s*\{.*?\}/is', $buffer, $font_face_blocks)) {
        foreach ($font_face_blocks[0] as $block) {
            // Extract font-family (if any)
            $font_family = null;
            if (preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)["\']?\s*;/i', $block, $family_match)) {
                $font_family = trim($family_match[1]);
            }
            // Extract all url(...) from src (do not filter on extension, but skip data: urls)
            if (preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $block, $url_matches)) {
                foreach ($url_matches[1] as $font_url) {
                    if (stripos($font_url, 'data:') === 0) continue;
                    // Only include if it's a Typekit URL
                    if (preg_match('~^https?://use\.typekit\.net/~', $font_url)) {
                        // Only add first font URL if we found family, otherwise add all unique
                        if ($font_family) {
                            if (!isset($preload_fonts[$font_family])) {
                                $preload_fonts[$font_family] = $font_url;
                                break; // only first for the family
                            }
                        } else {
                            // fallback for unknown family: just use URL itself (preload unique URLs)
                            $preload_fonts[$font_url] = $font_url;
                        }
                    }
                }
            }
        }
    }

    if (!empty($preload_fonts) && preg_match('/<head[^>]*>/i', $buffer, $head_tag, PREG_OFFSET_CAPTURE)) {
        $inserts = '';
        foreach ($preload_fonts as $font_url) {
            // Type guessing: get extension for type (basic)
            $type = '';
            if (preg_match('/\.(woff2?)($|\?)/i', $font_url, $type_match)) {
                $type = strtolower($type_match[1]);
            }
            $type_attr = $type ? ' type="font/' . esc_attr($type) . '"' : '';
            $inserts .= '<link rel="preload" as="font" href="' . esc_attr($font_url) . '"' . $type_attr . ' crossorigin />' . "\n";
        }
        $head_pos = $head_tag[0][1] + strlen($head_tag[0][0]);
        $buffer = substr_replace($buffer, $inserts, $head_pos, 0);
    }

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