(function () {
  var wc = window.wc || {};
  var wp = window.wp || {};
  if (!wc.blocksCheckout || !wc.wcSettings || !wp.element || !wp.data) return;

  var el = wp.element.createElement;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var useRef = wp.element.useRef;
  var createPortal = wp.element.createPortal;
  var useSelect = wp.data.useSelect;
  var CART = 'wc/store/cart';

  var cfg = wc.wcSettings.getSetting('sendway_data', {});
  if (!cfg.ready) return;
  var t = cfg.strings || {};
  var towns = cfg.towns || [];

  function update(data) {
    return wc.blocksCheckout.extensionCartUpdate({ namespace: 'sendway', data: data });
  }

  function sendwayRate(packages) {
    for (var i = 0; i < (packages || []).length; i++) {
      var rates = packages[i].shipping_rates || [];
      for (var j = 0; j < rates.length; j++) {
        if (rates[j].method_id === 'sendway') return { pkg: packages[i].package_id, rate: rates[j] };
      }
    }
    return null;
  }

  function selectSendway() {
    var found = sendwayRate(wp.data.select(CART).getShippingRates());
    if (found && !found.rate.selected) {
      wp.data.dispatch(CART).selectShippingRate(found.rate.rate_id, found.pkg);
    }
  }

  function money(rate) {
    var minor = rate.currency_minor_unit || 0;
    var value = parseInt(rate.price, 10) / Math.pow(10, minor);
    var text = value.toLocaleString(undefined, { minimumFractionDigits: minor, maximumFractionDigits: minor });
    return (rate.currency_prefix || '') + text + (rate.currency_suffix || '');
  }

  function townById(id) {
    for (var i = 0; i < towns.length; i++) if (String(towns[i].id) === String(id)) return towns[i];
    return null;
  }

  function subLine(town) {
    return [town.sub_county, town.county].filter(Boolean).join(', ');
  }

  var boxIcon = el('svg', { width: 20, height: 20, viewBox: '0 0 24 24', fill: 'none', stroke: '#e51015', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true },
    el('path', { d: 'M21 8 12 3 3 8v8l9 5 9-5Z' }), el('path', { d: 'm3 8 9 5 9-5' }), el('path', { d: 'M12 13v8' }));
  var pinIcon = el('svg', { width: 20, height: 20, viewBox: '0 0 24 24', fill: 'none', stroke: '#16a34a', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true },
    el('path', { d: 'M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z' }), el('circle', { cx: 12, cy: 10, r: 3 }));

  function TownModal(props) {
    var _q = useState('');
    var q = _q[0];
    var setQ = _q[1];
    var input = useRef(null);

    useEffect(function () {
      if (input.current) input.current.focus();
      function onKey(e) { if (e.key === 'Escape') props.onClose(); }
      document.addEventListener('keydown', onKey);
      return function () { document.removeEventListener('keydown', onKey); };
    }, []);

    var needle = q.toLowerCase().trim();
    var list = needle
      ? towns.filter(function (town) {
          return ((town.name || '') + ' ' + (town.sub_county || '') + ' ' + (town.county || '')).toLowerCase().indexOf(needle) > -1;
        })
      : towns;

    return createPortal(
      el('div', { className: 'sendway-modal' },
        el('div', { className: 'sendway-modal__backdrop', onClick: props.onClose }),
        el('div', { className: 'sendway-modal__panel', role: 'dialog', 'aria-modal': true, 'aria-label': t.chooseTown },
          el('div', { className: 'sendway-modal__head' },
            el('strong', null, t.chooseTown),
            el('button', { type: 'button', className: 'sendway-modal__x', onClick: props.onClose, 'aria-label': t.close }, '×')),
          el('input', { ref: input, type: 'search', className: 'sendway-search', placeholder: t.search, value: q, autoComplete: 'off', onChange: function (e) { setQ(e.target.value); } }),
          el('div', { className: 'sendway-results' },
            list.length
              ? list.map(function (town) {
                  return el('button', { key: town.id, type: 'button', className: 'sendway-row', onClick: function () { props.onPick(town); } },
                    el('span', { className: 'sendway-row__body' },
                      el('span', { className: 'sendway-row__name' }, town.name),
                      el('span', { className: 'sendway-row__sub' }, subLine(town)),
                      el('span', { className: 'sendway-chips' },
                        town.county ? el('span', { className: 'sendway-chip' }, town.county) : null,
                        town.zone ? el('span', { className: 'sendway-chip' }, 'Zone ' + town.zone) : null)));
                })
              : el('div', { className: 'sendway-empty' }, el('strong', null, t.noResult), el('br'), el('span', null, t.tryAgain))))),
      document.body
    );
  }

  function post(action, body) {
    var form = new FormData();
    form.append('action', action);
    form.append('nonce', cfg.door.nonce);
    Object.keys(body).forEach(function (k) { form.append(k, body[k]); });
    return fetch(cfg.door.ajax, { method: 'POST', body: form, credentials: 'same-origin' }).then(function (r) {
      return r.json().then(function (json) {
        if (!r.ok || !json || !json.success) {
          var err = new Error((json && json.data && json.data.message) || '');
          throw err;
        }
        return json.data;
      });
    });
  }

  function newToken() {
    return 'wc' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  }

  function DoorPanel(props) {
    var _q = useState('');
    var _results = useState(null);
    var _hint = useState('');
    var _error = useState('');
    var _editing = useState(!props.door);
    var _directions = useState(props.door ? props.door.directions : '');
    var token = useRef(newToken());
    var timer = useRef(null);

    var q = _q[0], setQ = _q[1];
    var results = _results[0], setResults = _results[1];
    var hint = _hint[0], setHint = _hint[1];
    var error = _error[0], setError = _error[1];
    var editing = _editing[0] || !props.door, setEditing = _editing[1];
    var directions = _directions[0], setDirections = _directions[1];

    function search(value) {
      setQ(value);
      setError('');
      clearTimeout(timer.current);
      if (value.trim().length < 3) { setResults(null); setHint(''); return; }
      timer.current = setTimeout(function () {
        setHint(cfg.door.searching);
        post('sendway_door_search', { query: value.trim(), session_token: token.current })
          .then(function (data) { setHint(''); setResults(data); })
          .catch(function (e) { setHint(''); setResults(null); setError(e.message); });
      }, 350);
    }

    function choose(row) {
      setHint(cfg.door.checking);
      setResults(null);
      post('sendway_door_resolve', { place_id: row.place_id, session_token: token.current })
        .then(function () {
          token.current = newToken();
          setHint('');
          setEditing(false);
          setQ('');
          props.onChosen();
        })
        .catch(function (e) {
          token.current = newToken();
          setHint('');
          setError(e.message);
        });
    }

    var rows = results && results.results ? results.results : [];

    return el('div', { className: 'sendway-door' },
      editing
        ? el('div', { className: 'sendway-door__search' },
            el('input', { type: 'search', className: 'sendway-search', placeholder: t.doorSearch, value: q, autoComplete: 'off', onChange: function (e) { search(e.target.value); } }),
            hint ? el('div', { className: 'sendway-door__results' }, el('div', { className: 'sendway-door__hint' }, hint)) : null,
            results
              ? el('div', { className: 'sendway-door__results' },
                  rows.length
                    ? rows.map(function (r) {
                        return el('button', { key: r.place_id, type: 'button', className: 'sendway-door__row', onClick: function () { choose(r); } },
                          el('span', { className: 'sendway-row__name' }, r.main_text || r.description),
                          el('span', { className: 'sendway-row__sub' }, r.secondary_text || ''));
                      })
                    : el('div', { className: 'sendway-door__hint' }, cfg.door.noMatch),
                  el('div', { className: 'sendway-door__google' }, results.attribution || 'Powered by Google'))
              : null)
        : el('div', { className: 'sendway-chosen' },
            el('span', { className: 'sendway-trigger__icon' }, pinIcon),
            el('span', { className: 'sendway-chosen__body' },
              el('span', { className: 'sendway-chosen__name' }, props.door.address),
              el('span', { className: 'sendway-chosen__sub' }, t.doorChosenSub)),
            el('button', { type: 'button', className: 'sendway-change', onClick: function () { setEditing(true); } }, t.change)),
      !editing
        ? el('input', {
            type: 'text', className: 'sendway-search sendway-door__directions', maxLength: 120, placeholder: t.directions, value: directions,
            onChange: function (e) { setDirections(e.target.value); },
            onBlur: function () { if (directions !== (props.door.directions || '')) update({ action: 'directions', directions: directions }); }
          })
        : null,
      error ? el('p', { className: 'sendway-door__error' }, error) : null,
      el('p', { className: 'sendway-help' }, t.doorHelp));
  }

  function Picker() {
    var sw = useSelect(function (select) {
      var cart = select(CART).getCartData();
      return (cart && cart.extensions && cart.extensions.sendway) || {};
    }, []);
    var packages = useSelect(function (select) { return select(CART).getShippingRates(); }, []);
    var needsShipping = useSelect(function (select) { return select(CART).getNeedsShipping(); }, []);

    var _open = useState(false);
    var _busy = useState(false);
    var _error = useState('');
    var _mode = useState(null);
    var open = _open[0], setOpen = _open[1];
    var busy = _busy[0], setBusy = _busy[1];
    var error = _error[0], setError = _error[1];
    var mode = _mode[0] || sw.mode || 'STATION', setMode = _mode[1];

    if (!sw.ready || !needsShipping) return null;

    function run(data) {
      setBusy(true);
      setError('');
      return update(data)
        .then(function () { selectSendway(); })
        .catch(function () { setError(t.failed); })
        .then(function () { setBusy(false); });
    }

    function switchMode(next) {
      if (next === mode) return;
      setMode(next);
      run({ action: 'mode', mode: next });
    }

    var town = sw.town_id ? townById(sw.town_id) : null;
    var chosen = mode === 'DOOR' ? !!sw.door : !!town;
    var found = sendwayRate(packages);
    var status = null;
    if (busy) status = el('p', { className: 'sendway-status' }, t.updating);
    else if (error) status = el('p', { className: 'sendway-status sendway-status--bad' }, error);
    else if (chosen && found) status = el('p', { className: 'sendway-status sendway-status--ok' }, t.price.replace('%s', money(found.rate)));
    else if (chosen && sw.mode === mode) status = el('p', { className: 'sendway-status sendway-status--bad' }, t.noRate);

    return el('div', { className: 'sendway-field sendway-field--block' },
      el('h2', { className: 'wc-block-components-title sendway-field__title' }, t.title),
      el('p', { className: 'sendway-help sendway-help--intro' }, t.intro),
      cfg.doorEnabled
        ? el('div', { className: 'sendway-modes', role: 'radiogroup' },
            [['STATION', t.station, t.stationSub, boxIcon], ['DOOR', t.door, t.doorSub, pinIcon]].map(function (m) {
              var active = mode === m[0];
              return el('button', { key: m[0], type: 'button', role: 'radio', 'aria-checked': active, className: 'sendway-mode' + (active ? ' is-active' : ''), onClick: function () { switchMode(m[0]); } },
                el('span', { className: 'sendway-trigger__icon' }, m[3]),
                el('span', { className: 'sendway-trigger__body' },
                  el('span', { className: 'sendway-trigger__title' }, m[1]),
                  el('span', { className: 'sendway-trigger__sub' }, m[2])));
            }))
        : null,
      mode === 'DOOR'
        ? el(DoorPanel, { door: sw.door, onChosen: function () { run({ action: 'door' }); } })
        : el('div', null,
            town
              ? el('div', { className: 'sendway-chosen' },
                  el('span', { className: 'sendway-trigger__icon' }, boxIcon),
                  el('span', { className: 'sendway-chosen__body' },
                    el('span', { className: 'sendway-chosen__name' }, town.name),
                    el('span', { className: 'sendway-chosen__sub' }, subLine(town))),
                  el('button', { type: 'button', className: 'sendway-change', onClick: function () { setOpen(true); } }, t.change))
              : el('button', { type: 'button', className: 'sendway-trigger sendway-trigger--empty', onClick: function () { setOpen(true); } },
                  el('span', { className: 'sendway-trigger__icon' }, boxIcon),
                  el('span', { className: 'sendway-trigger__body' },
                    el('span', { className: 'sendway-trigger__title' }, t.chooseTown),
                    el('span', { className: 'sendway-trigger__sub' }, t.chooseTownSub)),
                  el('span', { className: 'sendway-trigger__chev', 'aria-hidden': true }, '›')),
            el('p', { className: 'sendway-help' }, t.stationHelp)),
      status,
      open
        ? el(TownModal, {
            onClose: function () { setOpen(false); },
            onPick: function (picked) {
              setOpen(false);
              run({ action: 'town', town_id: picked.id });
            }
          })
        : null);
  }

  wc.blocksCheckout.registerCheckoutBlock({
    metadata: {
      name: 'sendway/delivery-picker',
      parent: ['woocommerce/checkout-shipping-address-block'],
      attributes: { lock: { type: 'object', default: { remove: true, move: true } } }
    },
    component: Picker,
    force: true
  });
})();
