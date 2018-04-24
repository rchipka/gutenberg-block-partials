<?
/**
* @wordpress-plugin
* Plugin Name: Gutenberg Block Partials
* Plugin URI: https://github.com/rchipka/gutenberg-block-partials
* Description: PHP template "partial" support for gutenberg blocks
* Version: 1.0.0
* Author: Robbie Chipka
* Author URI: https://github.com/rchipka
* GitHub Plugin URI: https://github.com/rchipka/gutenberg-block-partials
*/

add_action('wp_ajax_gutenberg_block_template', function () {
  $block = $_REQUEST['block'];

  if (!is_array($block)) {
    $block = json_decode(preg_replace('/\\\\\"/', '"', $_REQUEST['block']), true);
  }
  error_log('BLOCK: ' . json_encode($block). json_last_error());

  $orig_attributes = $block['attributes'];
  $attributes = &$block['attributes'];

  error_log(print_r($_REQUEST, 1));

  $block['content'] = $_REQUEST['content'];
  $block['content'] = apply_filters('gutenberg/template/block_content', $block['content'], $block, $attributes);
  $block['content'] = apply_filters('gutenberg/template/block_content/type=' . $block['name'], $block['content'], $block, $attributes);

  do_action('before_render_block_template', $block);

  $path = get_template_directory() . '/blocks/' . $block['name'] . '.php';

  error_log($path);

  if (is_file($path)) {
    ob_start();

    error_log('loading block template ' . json_decode($path));
    $content = require($path);

    if (is_callable($content)) {
      $content = call_user_func($content, $attributes, $block);
    }

    if (is_string($content)) {
      $block['content'] = $content;
    } else {
      $block['content'] = ob_get_contents();
    }

    ob_end_clean();
  }

  do_action('after_render_block_template', $block);

  $block['attributes'] = apply_filters('gutenberg/template/block_attributes', $attributes, $block);
  $block['attributes'] = apply_filters('gutenberg/template/block_attributes/type=' . $block['name'], $attributes, $block);

  $block['attributes_changed'] = ($orig_attributes != $block['attributes']);

  wp_send_json($block);
});

function gb_find_block_templates($dir, &$templates, $path = '') {
   foreach (scandir($dir) as $file) {
      if (in_array($file, array('.','..'))) {
        continue;
      }

      if (is_dir($dir . DIRECTORY_SEPARATOR . $file)) {
        gb_find_block_templates($dir . DIRECTORY_SEPARATOR . $file, $templates, $path . $file . DIRECTORY_SEPARATOR);
      } else if (preg_match('/php$/', $file)) {
        $templates[] = $path . preg_replace('/\\.php$/', '', $file);
      }
   }

   return $templates;
} 

add_action('admin_footer', function () {
  if (!get_the_ID()) {
    return;
  }

  $templates = [];

  gb_find_block_templates(get_template_directory() . '/blocks/', $templates);
  ?>
<script>
(function ($) {
  var namespace = 'gutenberg/block-templates',
      xhrCache = {},
      contentCache = {},
      blockTemplates = <?php echo json_encode($templates); ?>,
      ignoreNextUpdate = {},
      paramCache = {},
      timeouts = {};

  console.log('templates:', blockTemplates);

  function callBlockFilter(filter, value, props) {
    // console.log('callFilter', filter, value, props);
    var attributes = props.attributes;

    value = wp.hooks.applyFilters(filter, value, props, attributes);

    return value;
  }

  wp.hooks.addFilter('blocks.template.serializeProps', namespace, function (props) {
    return JSON.parse(JSON.stringify(props, function (key, value) {
          if (!value) {
            return value;
          }

          if (typeof value === 'function') {
            return undefined;
          }

          if (typeof value === 'object' &&
              value.hasOwnProperty('ref') &&
              value.hasOwnProperty('type')) {
            return undefined;
          }

          return value;
        }) || '{}');
  });

  wp.hooks.addFilter('blocks.registerBlockType', namespace, function (settings, name) {
    settings.attributes.block_id = { type: 'string' };

    return settings;
  }, 10);

  wp.hooks.addFilter('blocks.getSaveElement', namespace, function (element, blockType, attributes) {
    var content = contentCache[attributes.block_id];

    console.log('blocks.getSaveElement',element, blockType, attributes);
    if (typeof content === 'string') {
      console.log('saving cached content', content);

      element.props.dangerouslySetInnerHTML = {
          __html: content
        };
      attributes.dangerouslySetInnerHTML = {
          __html: content
        };
      // return wp.element.cloneElement(element, {
      //   dangerouslySetInnerHTML: {
      //     __html: content
      //   }
      // });
    } else {
      console.log('saving without cache', attributes);
    }

    return element;
  }, 10);

  wp.hooks.addFilter('editor.BlockListBlock', namespace, wp.element.createHigherOrderComponent(function (element) {
    return function (data) {
      console.log('BlockListBlock', data);
      var props = data.block,
          block_id = props.uid,
          attributes = props.attributes,
          block = wp.element.createElement(element, data);

      if (!data.isSelected) {
        return block;
      }
      
      if (ignoreNextUpdate[block_id]) {
        ignoreNextUpdate[block_id] = false;
        return block;
      }

      var params = callBlockFilter('blocks.template.ajaxRequest', {
            action: 'gutenberg_block_template',
            post_id: <?php echo json_encode(get_the_ID()); ?>,
          }, props),
          paramString = JSON.stringify(params);

      if (!params || !paramString) {
        return block;
      }

      if (!blockTemplates[props.name]) {
        if (paramString === paramCache[block_id]) {
          return block;
        }
      }

      var blockData = wp.hooks.applyFilters('blocks.template.serializeProps', props);

      params.block = JSON.stringify(blockData);

      paramCache[block_id] = paramString;

      contentCache[block_id] = null;
      
      var origContent = callBlockFilter('blocks.template.getSaveContent',
          wp.blocks.getBlockContent(props), blockData);
      
      var el = $(origContent)[0];

      if (el) {
        origContent = el.innerHTML;
      }

      params.content = origContent

      console.log('content', origContent);

      if (xhrCache[block_id]) {
        xhrCache[block_id].abort();
      }

      setTimeout(function () {
        $('.editor-post-publish-button').prop('disabled', true);
      }, 150);

      clearTimeout(timeouts[block_id]);

      timeouts[block_id] = setTimeout(function () {
        xhrCache[block_id] = $.ajax({
          url: ajaxurl,
          method: 'POST',
          data: params,
          success: function (response) {
            response = callBlockFilter('blocks.template.ajaxResponse', response, blockData);

            console.log('response', response);

            if (response.hasOwnProperty('content') &&
                response.content != origContent) {
              // var content = wp.blocks.serialize([props]).replace(origContent, response.content);
              var content = response.content;

              console.log('newContent', content);
              contentCache[block_id] = content;
            }

            if (!response.attributes) {
              return;
            }

            delete response.attributes.content;
            
            if (response.attributes_changed && props) {
              ignoreNextUpdate[block_id] = true;
              // props.setAttributes(response.attributes);
              props.attributes = response.attributes;
              console.log('setAttributes', props.attributes);
            }
          },
          complete: function () {
            setTimeout(function () {
              $('.editor-post-publish-button').prop('disabled', false);
            }, 250);
          }
        });
      }, 150);

      return block;
    }
  }, 'BlockListBlockTemplate'));
})(jQuery);
</script>
<?
}, 0);
