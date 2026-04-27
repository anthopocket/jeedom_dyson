/* Plugin Dyson – core/js/dyson.js */
/* global $, dyson_apikey, bootbox */

'use strict';

$(function () {

    $(document).off('.dyson');

    var AJAX   = 'plugins/dyson/core/ajax/dyson.ajax.php';
    var APIKEY = (typeof dyson_apikey !== 'undefined') ? dyson_apikey : '';

    /* ═══════════════════════════════════════════════════════════════════
       AJAX helper
    ═══════════════════════════════════════════════════════════════════ */
    function dysonAjax(data, ok, ko) {
        data.apikey = APIKEY;
        $.ajax({
            type     : 'POST',
            url      : AJAX,
            data     : data,
            dataType : 'json',
            success  : function (r) {
                if (r.state !== 'ok') {
                    $.fn.showAlert({ message: r.result, level: 'danger' });
                    if (typeof ko === 'function') { ko(r.result); }
                    return;
                }
                if (typeof ok === 'function') { ok(r.result); }
            },
            error: function (xhr) {
                var msg = 'Erreur HTTP ' + xhr.status;
                $.fn.showAlert({ message: msg, level: 'danger' });
                if (typeof ko === 'function') { ko(msg); }
            }
        });
    }

    function htmlEsc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ═══════════════════════════════════════════════════════════════════
       Navigation
    ═══════════════════════════════════════════════════════════════════ */
    function showList() {
        $('.eqLogic').hide();
        $('.eqLogicThumbnailDisplay').show();
        $('a[href="#eqlogictab"]').tab('show');
    }

    function showForm() {
        $('.eqLogicThumbnailDisplay').hide();
        $('.eqLogic').show();
    }

    $('#bt_back_list').on('click', showList);

    /* ═══════════════════════════════════════════════════════════════════
       Ouvrir un équipement
    ═══════════════════════════════════════════════════════════════════ */
    $(document).on('click.dyson', '.eqLogicDisplayCard', function (e) {
        e.stopImmediatePropagation();
        var id = $(this).data('eqlogic_id');
        dysonAjax({ action: 'get', id: id }, function (data) {
            $('.eqLogic').data('current_id', data.id);
            $('.eqLogicAttr[data-l1key="id"]').val(data.id);
            fillFields(data);
            buildCmdTable(data.cmds || []);
            updateIpBadge();
            showForm();
        });
    });

    /* ═══════════════════════════════════════════════════════════════════
       Remplir les champs
    ═══════════════════════════════════════════════════════════════════ */
    function fillFields(data) {
        $('.eqAttr').each(function () {
            var key = $(this).data('key');
            if (!key) { return; }
            var val = getNestedVal(data, key);
            if ($(this).is(':checkbox')) {
                $(this).prop('checked', val == 1 || val === true);
            } else {
                $(this).val((val !== undefined && val !== null) ? val : '');
            }
        });
    }

    function getNestedVal(obj, dotKey) {
        return dotKey.split('.').reduce(function (o, k) {
            return (o && o[k] !== undefined) ? o[k] : '';
        }, obj);
    }

    /* ═══════════════════════════════════════════════════════════════════
       Tableau des commandes
    ═══════════════════════════════════════════════════════════════════ */
    function buildCmdTable(cmds) {
        var tbody = $('#cmd_table_body').empty();
        $.each(cmds, function (i, c) { tbody.append(buildCmdRow(c)); });
    }

    function buildCmdRow(c) {
        var cfg = c.configuration || {};

        /* ── Paramètres ─────────────────────────────────────────────── */
        var params = '';
        params += '<label class="checkbox-inline">'
                + '<input type="checkbox" class="cmd_attr" data-cmd_id="' + c.id + '" data-key="isVisible"'
                + (c.isVisible == 1 ? ' checked' : '') + '> {{Afficher}}'
                + '</label>';

        if (c.type === 'info') {
            params += ' <label class="checkbox-inline">'
                    + '<input type="checkbox" class="cmd_attr" data-cmd_id="' + c.id + '" data-key="isHistorized"'
                    + (c.isHistorized == 1 ? ' checked' : '') + '> {{Historiser}}'
                    + '</label>';
        }

        if (c.subType === 'slider') {
            params += '<br><small class="text-muted">'
                    + 'min: ' + htmlEsc(String(cfg.minValue !== undefined ? cfg.minValue : 0))
                    + ' / max: ' + htmlEsc(String(cfg.maxValue !== undefined ? cfg.maxValue : 100))
                    + '</small>';
        }

        /* ── État ───────────────────────────────────────────────────── */
        var etat = '';
        if (c.type === 'info') {
            var cv = (c.currentValue !== undefined && c.currentValue !== null) ? c.currentValue : '';
            etat = '<span id="cmd_val_' + c.id + '">' + htmlEsc(String(cv)) + '</span>';
            if (c.unite && cv !== '') {
                etat += '&nbsp;<small class="text-muted">' + htmlEsc(c.unite) + '</small>';
            }
        }

        /* ── Bouton configuration ───────────────────────────────────── */
        var action = '<a class="btn btn-default btn-xs bt_configure_cmd"'
                   + ' title="{{Configuration avancée}}"'
                   + ' data-cmd_id="' + c.id + '">'
                   + '<i class="fas fa-cog"></i></a>';

        /* ── Bouton tester ──────────────────────────────────────────── */
        if (c.type === 'action') {
            if (c.subType === 'slider') {
                var mn = (cfg.minValue !== undefined) ? cfg.minValue : 0;
                var mx = (cfg.maxValue !== undefined) ? cfg.maxValue : 100;
                action += '&nbsp;<div class="input-group input-group-sm"'
                        + ' style="display:inline-flex;width:140px;vertical-align:middle">'
                        + '<input type="number" class="form-control cmd_test_val"'
                        + ' min="' + mn + '" max="' + mx + '" value="' + mn + '">'
                        + '<span class="input-group-btn">'
                        + '<button class="btn btn-sm btn-default bt_test_cmd"'
                        + ' data-cmd_id="' + c.id + '" data-subtype="slider">'
                        + '<i class="fas fa-rss"></i> {{Tester}}</button>'
                        + '</span></div>';
            } else {
                action += '&nbsp;<button class="btn btn-sm btn-default bt_test_cmd"'
                        + ' data-cmd_id="' + c.id + '"'
                        + ' data-subtype="' + htmlEsc(c.subType) + '">'
                        + '<i class="fas fa-rss"></i> {{Tester}}</button>';
            }
        }

        /* ── Labels type ────────────────────────────────────────────── */
        var typeLabel = {
            info   : '<span class="label label-info">Info</span>',
            action : '<span class="label label-warning">Action</span>'
        };

        return '<tr id="cmd_row_' + c.id + '">'
             + '<td>' + htmlEsc(c.name) + '</td>'
             + '<td><small class="text-muted">' + htmlEsc(c.logicalId) + '</small></td>'
             + '<td>' + (typeLabel[c.type] || htmlEsc(c.type)) + '&nbsp;/&nbsp;' + htmlEsc(c.subType) + '</td>'
             + '<td>' + htmlEsc(c.unite || '') + '</td>'
             + '<td>' + params + '</td>'
             + '<td>' + etat + '</td>'
             + '<td>' + action + '</td>'
             + '</tr>';
    }

    /* ═══════════════════════════════════════════════════════════════════
       Badge IP
    ═══════════════════════════════════════════════════════════════════ */
    function updateIpBadge() {
        var ip = $.trim($('#input_mqtt_hostname').val());
        $('#span_ip_warning').toggle(ip === '');
        $('#span_ip_ok').toggle(ip !== '');
    }
    $(document).on('input.dyson change.dyson', '#input_mqtt_hostname', updateIpBadge);

    /* ═══════════════════════════════════════════════════════════════════
       Sauvegarder l'équipement
    ═══════════════════════════════════════════════════════════════════ */
    $('#bt_save').on('click', function () {
        var id  = $('.eqLogic').data('current_id');
        var eq  = { id: id, configuration: {} };

        $('.eqAttr').each(function () {
            var key = $(this).data('key');
            if (!key) { return; }
            var val = $(this).is(':checkbox') ? ($(this).is(':checked') ? 1 : 0) : $(this).val();
            if (key.indexOf('configuration.') === 0) {
                eq.configuration[key.replace('configuration.', '')] = val;
            } else {
                eq[key] = val;
            }
        });

        dysonAjax({ action: 'save', eqLogic: JSON.stringify(eq) }, function () {
            $.fn.showAlert({ message: '{{Équipement sauvegardé}}', level: 'success' });
            var card = $('.eqLogicDisplayCard[data-eqlogic_id="' + id + '"]');
            if (eq.name) { card.find('.name').text(eq.name); }
            updateIpBadge();
        });
    });

    /* ═══════════════════════════════════════════════════════════════════
       Supprimer l'équipement
    ═══════════════════════════════════════════════════════════════════ */
    $('#bt_remove').on('click', function () {
        var id = $('.eqLogic').data('current_id');
        bootbox.confirm('{{Supprimer cet équipement ?}}', function (r) {
            if (!r) { return; }
            dysonAjax({ action: 'remove', id: id }, function () {
                window.location.reload();
            });
        });
    });

    /* ═══════════════════════════════════════════════════════════════════
       Configuration avancée équipement
    ═══════════════════════════════════════════════════════════════════ */
    $('#bt_configure').on('click', function () {
        var id = $('.eqLogic').data('current_id');
        if (!id) { return; }
        var modal = $('#md_modal');
        if (!modal.length) {
            console.error('[Dyson] #md_modal introuvable');
            return;
        }
        modal.dialog({ title: '{{Configuration avancée}}' });
        modal.load('index.php?v=d&modal=eqLogic.configure&eqLogic_id=' + id).dialog('open');
    });

    /* ═══════════════════════════════════════════════════════════════════
       Configuration avancée commande  ← CORRECTION PRINCIPALE
       On attend que la modale soit chargée avant d'essayer de lire
       des éléments à l'intérieur (querySelector sur null résolu).
    ═══════════════════════════════════════════════════════════════════ */
    $(document).on('click.dyson', '.bt_configure_cmd', function () {
        var cmd_id = parseInt($(this).data('cmd_id'), 10);
        if (!cmd_id) { return; }

        var modal = $('#md_modal');
        if (!modal.length) {
            console.error('[Dyson] #md_modal introuvable');
            return;
        }

        /* 1. Ouvrir la modale VIDE d'abord */
        modal.dialog({ title: '{{Configuration de la commande}}' }).dialog('open');

        /* 2. Charger le contenu ET attendre la fin du chargement */
        modal.load(
            'index.php?v=d&modal=cmd.configure&cmd_id=' + cmd_id,
            function (response, status) {
                if (status === 'error') {
                    $.fn.showAlert({
                        message : '{{Impossible de charger la configuration}}',
                        level   : 'danger'
                    });
                    return;
                }
                /* 3. Le DOM est maintenant injecté → on peut accéder aux éléments */
                var inner = modal.find('[data-cmd_id]').first();
                if (inner.length) {
                    inner.trigger('dyson:cmd_modal_ready');
                }
            }
        );
    });

    /* ═══════════════════════════════════════════════════════════════════
       Tester une commande
    ═══════════════════════════════════════════════════════════════════ */
    $(document).on('click.dyson', '.bt_test_cmd', function () {
        var btn     = $(this);
        var cmd_id  = btn.data('cmd_id');
        var subtype = btn.data('subtype');
        var options = {};

        if (subtype === 'slider') {
            options.slider = btn.closest('.input-group').find('.cmd_test_val').val();
        }

        btn.prop('disabled', true);
        dysonAjax(
            { action: 'execute_cmd', cmd_id: cmd_id, options: JSON.stringify(options) },
            function () { $.fn.showAlert({ message: '{{Commande exécutée}}', level: 'success' }); },
            function () {}
        );
        setTimeout(function () { btn.prop('disabled', false); }, 1500);
    });

    /* ═══════════════════════════════════════════════════════════════════
       Recréer les commandes
    ═══════════════════════════════════════════════════════════════════ */
    $('#bt_recreate_cmds').on('click', function () {
        var id = $('.eqLogic').data('current_id');
        if (!id) { return; }
        dysonAjax({ action: 'recreate_cmds', id: id }, function () {
            $.fn.showAlert({ message: '{{Commandes recréées}}', level: 'success' });
            dysonAjax({ action: 'get', id: id }, function (data) {
                buildCmdTable(data.cmds || []);
            });
        });
    });

});
