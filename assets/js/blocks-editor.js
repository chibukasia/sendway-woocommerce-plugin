(function () {
  var wp = window.wp || {};
  if (!wp.blocks || !wp.element || !wp.blockEditor) return;
  var el = wp.element.createElement;
  var settings = (window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting('sendway_data', {})) || {};
  var note = (settings.strings && settings.strings.editorNote) || 'Customers choose their SendWay pickup town or door address here.';

  wp.blocks.registerBlockType('sendway/delivery-picker', {
    apiVersion: 3,
    title: 'SendWay delivery',
    category: 'woocommerce',
    parent: ['woocommerce/checkout-shipping-address-block'],
    attributes: { lock: { type: 'object', default: { remove: true, move: true } } },
    supports: { html: false, multiple: false, reusable: false, inserter: false },
    edit: function () {
      return el('div', wp.blockEditor.useBlockProps({ className: 'sendway-editor-note', style: { padding: '12px 16px', border: '1px dashed #cbd5e1', borderRadius: '8px', fontSize: '14px' } }),
        el('strong', null, 'SendWay delivery'), el('br'), note);
    },
    save: function () {
      return el('div', wp.blockEditor.useBlockProps.save());
    }
  });
})();
