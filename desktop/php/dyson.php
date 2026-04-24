<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
sendVarToJS('eqType', 'dyson');
sendVarToJS('dyson_apikey', config::byKey('api'));
$eqLogics = eqLogic::byType('dyson');
?>

<div class="row row-overflow">

    <!-- ══ LISTE ══ -->
    <div class="col-xs-12 eqLogicThumbnailDisplay">

        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i><br>{{Ajouter manuellement}}
            </div>
        </div>

        <legend><i class="fas fa-fan"></i> {{Mes appareils Dyson}}</legend>
        <div class="eqLogicThumbnailContainer">
        <?php foreach ($eqLogics as $eq):
            $pt       = $eq->getConfiguration('product_type', '');
            $label    = dyson::PRODUCT_TYPES[$pt]['label'] ?? 'Dyson ' . $pt;
            $serial   = $eq->getConfiguration('serial_number', '');
            $hasIp    = trim($eq->getConfiguration('mqtt_hostname', '')) !== '';
            $connected = $eq->getCmd('info', 'connected');
            $isOnline = is_object($connected) && $connected->execCmd() == 1;
            ?>
            <div class="eqLogicDisplayCard cursor <?php echo $eq->getIsEnable() ? '' : 'opacity05'; ?>"
                 data-eqlogic_id="<?php echo $eq->getId(); ?>">
                <img src="plugins/dyson/plugin_info/dyson_icon.png"
                     onerror="this.src='core/img/eqlogic.png'"
                     class="img-responsive" style="max-height:75px" />
                <br>
                <span class="name"><?php echo $eq->getHumanName(true, true); ?></span><br>
                <small><?php echo htmlspecialchars($label); ?></small><br>
                <?php if ($serial): ?>
                    <span class="label label-default" style="font-size:10px"><?php echo $serial; ?></span>
                <?php endif; ?>
                <?php if (!$hasIp): ?>
                    <br><span class="label label-warning" style="font-size:10px">IP manquante</span>
                <?php elseif ($isOnline): ?>
                    <br><span class="label label-success" style="font-size:10px">Connecté</span>
                <?php else: ?>
                    <br><span class="label label-danger" style="font-size:10px">Hors ligne</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ FORMULAIRE ══ -->
    <div class="col-xs-12 eqLogic" style="display:none;">

        <div class="pull-right" style="display:inline-flex;gap:5px">
            <a class="btn btn-sm btn-default eqLogicAction" data-action="configure">
                <i class="fas fa-cogs"></i> {{Config avancée}}
            </a>
            <a class="btn btn-sm btn-success eqLogicAction" data-action="save">
                <i class="fas fa-check-circle"></i> {{Sauvegarder}}
            </a>
            <a class="btn btn-sm btn-danger eqLogicAction" data-action="remove">
                <i class="fas fa-minus-circle"></i> {{Supprimer}}
            </a>
        </div>
        <a class="btn btn-sm btn-default" id="bt_back_list">
            <i class="fas fa-arrow-left"></i> {{Retour}}
        </a>

        <ul class="nav nav-tabs" role="tablist" style="margin-top:10px">
            <li role="presentation" class="active"><a href="#tab-eq" data-toggle="tab">{{Équipement}}</a></li>
            <li role="presentation"><a href="#tab-cmds" data-toggle="tab">{{Commandes}}</a></li>
        </ul>

        <div class="tab-content">

            <!-- ─ Équipement ─ -->
            <div role="tabpanel" class="tab-pane active" id="tab-eq">
                <br>
                <div class="row">

                    <div class="col-xs-12 col-md-6">
                        <fieldset>
                            <legend>{{Général}}</legend>
                            <div class="form-group">
                                <label class="col-xs-4 control-label">{{Nom}}</label>
                                <div class="col-xs-8">
                                    <input type="text" class="eqAttr form-control" data-key="name" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-xs-4 control-label">{{Objet parent}}</label>
                                <div class="col-xs-8">
                                    <select class="eqAttr form-control" data-key="object_id">
                                        <option value="">{{Aucun}}</option>
                                        <?php foreach (jeeObject::all() as $obj): ?>
                                            <option value="<?php echo $obj->getId(); ?>"><?php echo $obj->getName(); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="col-xs-offset-4 col-xs-8">
                                    <label class="checkbox-inline">
                                        <input type="checkbox" class="eqAttr" data-key="isEnable" /> {{Activer}}
                                    </label>
                                    <label class="checkbox-inline" style="margin-left:15px">
                                        <input type="checkbox" class="eqAttr" data-key="isVisible" /> {{Visible}}
                                    </label>
                                </div>
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend>{{Identification Dyson}}</legend>
                            <div class="form-group">
                                <label class="col-xs-4 control-label">{{Numéro de série}}</label>
                                <div class="col-xs-8">
                                    <input type="text" class="eqAttr form-control" data-key="configuration.serial_number" readonly style="background:#eee;cursor:default" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-xs-4 control-label">{{Type produit}}</label>
                                <div class="col-xs-8">
                                    <input type="text" class="eqAttr form-control" data-key="configuration.product_type" readonly style="background:#eee;cursor:default" />
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <div class="col-xs-12 col-md-6">
                        <fieldset>
                            <legend>
                                {{Connexion MQTT locale}}
                                <span id="span_ip_warning" class="label label-warning" style="display:none">
                                    <i class="fas fa-exclamation-triangle"></i> {{IP requise}}
                                </span>
                                <span id="span_ip_ok" class="label label-success" style="display:none">
                                    <i class="fas fa-check"></i> {{IP configurée}}
                                </span>
                            </legend>
                            <div class="form-group">
                                <label class="col-xs-4 control-label">{{Adresse IP}}</label>
                                <div class="col-xs-8">
                                    <input type="text" class="eqAttr form-control" id="input_mqtt_hostname"
                                           data-key="configuration.mqtt_hostname" placeholder="192.168.1.x" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-xs-4 control-label">{{Port}}</label>
                                <div class="col-xs-4">
                                    <input type="number" class="eqAttr form-control" data-key="configuration.mqtt_port" placeholder="1883" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-xs-4 control-label">{{Utilisateur MQTT}}</label>
                                <div class="col-xs-8">
                                    <input type="text" class="eqAttr form-control" data-key="configuration.mqtt_username" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-xs-4 control-label">{{Mot de passe MQTT}}</label>
                                <div class="col-xs-8">
                                    <input type="password" class="eqAttr form-control" data-key="configuration.mqtt_password" />
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="col-xs-offset-4 col-xs-8">
                                    <label class="checkbox-inline">
                                        <input type="checkbox" class="eqAttr" data-key="configuration.use_tls" /> {{TLS}}
                                    </label>
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="col-xs-offset-4 col-xs-8">
                                    <button class="btn btn-info btn-sm" id="bt_recreate_cmds">
                                        <i class="fas fa-sync"></i> {{Recréer les commandes}}
                                    </button>
                                </div>
                            </div>
                        </fieldset>
                    </div>

                </div>
            </div>

            <!-- ─ Commandes ─ -->
            <div role="tabpanel" class="tab-pane" id="tab-cmds">
                <br>
                <table class="table table-bordered table-condensed table-hover">
                    <thead>
                        <tr>
                            <th>{{Nom}}</th>
                            <th>{{Identifiant logique}}</th>
                            <th>{{Type}}</th>
                            <th>{{Unité}}</th>
                            <th>{{Valeur actuelle}}</th>
                            <th>{{Historiser}}</th>
                            <th>{{Visible}}</th>
                        </tr>
                    </thead>
                    <tbody id="cmd_table_body"></tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<script>
$(function () {

    var AJAX   = 'plugins/dyson/core/ajax/dyson.ajax.php';
    var APIKEY = dyson_apikey;

    function dysonAjax(data, ok, fail) {
        data.apikey = APIKEY;
        $.ajax({
            type: 'POST', url: AJAX, data: data, dataType: 'json',
            success: function (r) {
                if (r.state !== 'ok') {
                    $.fn.showAlert({message: r.result, level: 'danger'});
                    if (typeof fail === 'function') fail(r.result);
                    return;
                }
                if (typeof ok === 'function') ok(r.result);
            },
            error: function (xhr) {
                var msg = 'Erreur HTTP ' + xhr.status + ' : ' + xhr.responseText.substring(0, 400);
                $.fn.showAlert({message: msg, level: 'danger'});
                if (typeof fail === 'function') fail(msg);
            }
        });
    }

    /* ── Navigation ────────────────────────────────────────────────── */
    function showList() {
        $('.eqLogic').hide();
        $('.eqLogicThumbnailDisplay').show();
    }
    function showForm() {
        $('.eqLogicThumbnailDisplay').hide();
        $('.eqLogic').show();
    }

    $('#bt_back_list').on('click', showList);

    /* ── Badge IP dynamique ────────────────────────────────────────── */
    function updateIpBadge() {
        var ip = $.trim($('#input_mqtt_hostname').val());
        $('#span_ip_warning').toggle(ip === '');
        $('#span_ip_ok').toggle(ip !== '');
    }

    $('#input_mqtt_hostname').on('input', updateIpBadge);

    /* ── Ouvrir un équipement ──────────────────────────────────────── */
    $(document).on('click', '.eqLogicDisplayCard', function () {
        var id = $(this).data('eqlogic_id');
        dysonAjax({action: 'get', id: id}, function (data) {
            $('.eqLogic').data('current_id', data.id);
            fillFields(data);
            buildCmdTable(data.cmds || []);
            updateIpBadge();
            showForm();
        });
    });

    function fillFields(data) {
        $('.eqAttr').each(function () {
            var key = $(this).data('key');
            if (!key) return;
            var val = getNestedVal(data, key);
            if ($(this).is(':checkbox')) {
                $(this).prop('checked', val == 1 || val === true);
            } else {
                $(this).val(val !== undefined && val !== null ? val : '');
            }
        });
    }

    function getNestedVal(obj, dotKey) {
        return dotKey.split('.').reduce(function (o, k) {
            return (o && o[k] !== undefined) ? o[k] : '';
        }, obj);
    }

    function buildCmdTable(cmds) {
        var tbody = $('#cmd_table_body').empty();
        var typeLabel = {
            info:   '<span class="label label-info">Info</span>',
            action: '<span class="label label-warning">Action</span>'
        };
        cmds.forEach(function (c) {
            tbody.append(
                '<tr>' +
                '<td>' + htmlEsc(c.name) + '</td>' +
                '<td><small class="text-muted">' + htmlEsc(c.logicalId) + '</small></td>' +
                '<td>' + (typeLabel[c.type] || c.type) + ' / ' + htmlEsc(c.subType) + '</td>' +
                '<td>' + htmlEsc(c.unite || '') + '</td>' +
                '<td>' + htmlEsc(String(c.currentValue || '')) + '</td>' +
                '<td>' + (c.isHistorized == 1 ? '<i class="fas fa-check text-success"></i>' : '') + '</td>' +
                '<td>' + (c.isVisible   == 1 ? '<i class="fas fa-check text-success"></i>' : '') + '</td>' +
                '</tr>'
            );
        });
    }

    function htmlEsc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    /* ── Ajouter manuellement ──────────────────────────────────────── */
    $(document).on('click', '.eqLogicAction[data-action="add"]', function () {
        bootbox.prompt('{{Nom du nouvel appareil Dyson}}', function (r) {
            if (r === null || $.trim(r) === '') return;
            dysonAjax({action: 'add', name: $.trim(r)}, function () {
                window.location.reload();
            });
        });
    });

    /* ── Sauvegarder (reste sur le formulaire) ─────────────────────── */
    $(document).on('click', '.eqLogicAction[data-action="save"]', function () {
        var id = $('.eqLogic').data('current_id');
        var eq = {id: id, configuration: {}};
        $('.eqAttr').each(function () {
            var key = $(this).data('key');
            if (!key) return;
            var val = $(this).is(':checkbox') ? ($(this).is(':checked') ? 1 : 0) : $(this).val();
            if (key.indexOf('configuration.') === 0) {
                eq.configuration[key.replace('configuration.', '')] = val;
            } else {
                eq[key] = val;
            }
        });
        dysonAjax({action: 'save', eqLogic: JSON.stringify(eq)}, function () {
            $.fn.showAlert({message: '{{Équipement sauvegardé}}', level: 'success'});
            /* Mettre à jour le nom sur la vignette sans recharger la page */
            var newName = eq.name || '';
            var card = $('.eqLogicDisplayCard[data-eqlogic_id="' + id + '"]');
            if (newName) card.find('.name').text(newName);
            updateIpBadge();
        });
    });

    /* ── Supprimer ─────────────────────────────────────────────────── */
    $(document).on('click', '.eqLogicAction[data-action="remove"]', function () {
        var id = $('.eqLogic').data('current_id');
        bootbox.confirm('{{Supprimer cet équipement ?}}', function (r) {
            if (!r) return;
            dysonAjax({action: 'remove', id: id}, function () {
                window.location.reload();
            });
        });
    });

    /* ── Config avancée ────────────────────────────────────────────── */
    $(document).on('click', '.eqLogicAction[data-action="configure"]', function () {
        var id = $('.eqLogic').data('current_id');
        $('#md_modal').dialog({title: '{{Configuration avancée}}'});
        $('#md_modal').load('index.php?v=d&modal=eqLogic.configure&eqLogic_id=' + id).dialog('open');
    });

    /* ── Recréer les commandes ─────────────────────────────────────── */
    $('#bt_recreate_cmds').on('click', function () {
        var id = $('.eqLogic').data('current_id');
        dysonAjax({action: 'recreate_cmds', id: id}, function () {
            $.fn.showAlert({message: '{{Commandes recréées}}', level: 'success'});
            dysonAjax({action: 'get', id: id}, function (data) { buildCmdTable(data.cmds || []); });
        });
    });

});
</script>
