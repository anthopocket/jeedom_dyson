<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin   = plugin::byId('dyson');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">

    <!-- ══ LISTE ══ -->
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-fan"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br>
                <span>{{Ajouter}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
        </div>

        <legend><i class="fas fa-fan"></i> {{Mes appareils Dyson}}</legend>
        <div class="eqLogicThumbnailContainer">
            <?php foreach ($eqLogics as $eq):
                $pt       = $eq->getConfiguration('product_type', '');
                $label    = dyson::PRODUCT_TYPES[$pt]['label'] ?? 'Dyson ' . $pt;
                $serial   = $eq->getConfiguration('serial_number', '');
                $hasIp    = trim($eq->getConfiguration('mqtt_hostname', '')) !== '';
                $conn     = $eq->getCmd('info', 'connected');
                $isOnline = is_object($conn) && $conn->execCmd() == 1;
            ?>
                <div class="eqLogicDisplayCard cursor <?php echo $eq->getIsEnable() ? '' : 'opacity05'; ?>"
                     data-eqLogic_id="<?php echo $eq->getId(); ?>">
                    <img src="<?php echo $eq->getImage(); ?>" />
                    <br>
                    <span class="name"><?php echo $eq->getHumanName(true, true); ?></span>
                    <span class="hiddenAsCard displayTableRight">
                        <?php if ($serial): ?>
                            <span class="label label-default"><?php echo htmlspecialchars($serial); ?></span>
                        <?php endif; ?>
                        <?php if (!$hasIp): ?>
                            <span class="label label-warning">{{IP manquante}}</span>
                        <?php elseif ($isOnline): ?>
                            <span class="label label-success">{{Connecté}}</span>
                        <?php else: ?>
                            <span class="label label-danger">{{Hors ligne}}</span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ FORMULAIRE ÉQUIPEMENT ══ -->
    <div class="col-xs-12 eqLogic" style="display:none;">

        <div class="input-group pull-right" style="display:inline-flex;">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure">
                    <i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
                </a><a class="btn btn-sm btn-success eqLogicAction" data-action="save">
                    <i class="fas fa-check-circle"></i> {{Sauvegarder}}
                </a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove">
                    <i class="fas fa-minus-circle"></i> {{Supprimer}}
                </a>
            </span>
        </div>

        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation">
                <a href="#" class="eqLogicAction" role="tab" data-toggle="tab"
                   data-action="returnToThumbnailDisplay">
                    <i class="fas fa-arrow-circle-left"></i>
                </a>
            </li>
            <li role="presentation" class="active">
                <a href="#eqlogictab" role="tab" data-toggle="tab">
                    <i class="fas fa-tachometer-alt"></i> {{Équipement}}
                </a>
            </li>
            <li role="presentation">
                <a href="#commandtab" role="tab" data-toggle="tab">
                    <i class="fas fa-list"></i> {{Commandes}}
                </a>
            </li>
        </ul>

        <div class="tab-content">

            <!-- ─ Onglet Équipement ─ -->
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <br>
                <form class="form-horizontal">
                    <input type="hidden" class="eqLogicAttr" data-l1key="id" />

                    <div class="col-lg-6">
                        <legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Nom}}</label>
                            <div class="col-sm-8">
                                <input type="text" class="eqLogicAttr form-control"
                                       data-l1key="name" placeholder="{{Nom de l'équipement}}" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Objet parent}}</label>
                            <div class="col-sm-8">
                                <select class="eqLogicAttr form-control" data-l1key="object_id">
                                    <option value="">{{Aucun}}</option>
                                    <?php foreach (jeeObject::buildTree(null, false) as $obj): ?>
                                        <option value="<?php echo $obj->getId(); ?>">
                                            <?php echo str_repeat('&nbsp;&nbsp;', $obj->getConfiguration('parentNumber')); ?>
                                            <?php echo $obj->getName(); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Catégorie}}</label>
                            <div class="col-sm-8">
                                <?php foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value): ?>
                                    <label class="checkbox-inline">
                                        <input type="checkbox" class="eqLogicAttr"
                                               data-l1key="category" data-l2key="<?php echo $key; ?>" />
                                        <?php echo $value['name']; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label"></label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" /> {{Activer}}
                                </label>
                                <label class="checkbox-inline">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" /> {{Visible}}
                                </label>
                            </div>
                        </div>

                        <legend><i class="fas fa-barcode"></i> {{Identification Dyson}}</legend>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Numéro de série}}</label>
                            <div class="col-sm-8">
                                <input type="text" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="serial_number"
                                       placeholder="ex: M4P-EU-UKA4182A" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Type produit}}</label>
                            <div class="col-sm-8">
                                <input type="text" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="product_type"
                                       placeholder="ex: 897" />
                            </div>
                        </div>

                        <!-- ── Saisie manuelle des credentials ── -->
                        <legend><i class="fas fa-key"></i> {{Saisie manuelle des credentials}}
                            <sup><i class="fas fa-question-circle tooltips"
                                title="{{Utilisez cette section si la découverte automatique échoue. Récupérez les credentials depuis un PC avec le script get_dyson.py}}"></i></sup>
                        </legend>
                        <div class="alert alert-info" style="margin:0 15px 10px;">
                            <i class="fas fa-info-circle"></i>
                            {{Renseignez ces champs si la découverte automatique ne fonctionne pas (IP bloquée par Dyson).}}
                            <br>
                            <small>{{Récupérez les credentials depuis un PC avec}} <code>python get_dyson.py</code></small>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Numéro de série}}</label>
                            <div class="col-sm-8">
                                <input type="text" id="manual_serial" class="form-control"
                                       placeholder="ex: M4P-EU-UKA4182A" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Type produit}}</label>
                            <div class="col-sm-8">
                                <input type="text" id="manual_product_type" class="form-control"
                                       placeholder="ex: 897" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Credential MQTT}}</label>
                            <div class="col-sm-8">
                                <input type="text" id="manual_credential" class="form-control"
                                       placeholder="ex: zjF3ex8S..." />
                                <small class="text-muted">{{Valeur "Credential" retournée par get_dyson.py}}</small>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="col-sm-offset-4 col-sm-8">
                                <button class="btn btn-warning btn-sm" id="bt_apply_manual" type="button">
                                    <i class="fas fa-upload"></i> {{Appliquer les credentials}}
                                </button>
                            </div>
                        </div>

                    </div>

                    <div class="col-lg-6">
                        <legend><i class="fas fa-network-wired"></i> {{Connexion MQTT locale}}</legend>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Adresse IP}}</label>
                            <div class="col-sm-8">
                                <input type="text" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="mqtt_hostname"
                                       placeholder="192.168.1.x" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Port}}</label>
                            <div class="col-sm-4">
                                <input type="number" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="mqtt_port"
                                       placeholder="1883" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Utilisateur}}</label>
                            <div class="col-sm-8">
                                <input type="text" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="mqtt_username" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Mot de passe MQTT}}</label>
                            <div class="col-sm-8">
                                <input type="password" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="mqtt_password" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{TLS}}</label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline">
                                    <input type="checkbox" class="eqLogicAttr"
                                           data-l1key="configuration" data-l2key="use_tls" />
                                    {{Activer le chiffrement TLS}}
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="col-sm-offset-4 col-sm-8">
                                <button class="btn btn-info btn-sm" id="bt_recreate_cmds" type="button">
                                    <i class="fas fa-sync"></i> {{Recréer les commandes}}
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ─ Onglet Commandes ─ -->
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <div class="table-responsive">
                    <table id="table_cmd" class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th class="hidden-xs" style="min-width:50px;width:70px;">{{ID}}</th>
                                <th style="min-width:150px;width:300px;">{{Nom}}</th>
                                <th style="width:130px;">{{Type}}</th>
                                <th style="min-width:200px;">{{Paramètres}}</th>
                                <th style="min-width:80px;width:120px;">{{Etat}}</th>
                                <th style="min-width:200px;width:300px;">{{Options}}</th>
                                <th style="min-width:80px;width:160px;">{{Action}}</th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
$(function () {

    /* ── Appliquer les credentials manuels ── */
    $('#bt_apply_manual').on('click', function () {
        var serial      = $.trim($('#manual_serial').val());
        var productType = $.trim($('#manual_product_type').val());
        var credential  = $.trim($('#manual_credential').val());

        if (!serial || !productType || !credential) {
            $.fn.showAlert({ message: '{{Renseignez le numéro de série, le type produit et le credential}}', level: 'warning' });
            return;
        }

        var id  = $('.eqLogicAttr[data-l1key="id"]').val();
        var btn = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{En cours...}}');

        $.ajax({
            type     : 'POST',
            url      : 'plugins/dyson/core/ajax/dyson.ajax.php',
            data     : {
                action       : 'apply_manual_credentials',
                id           : id,
                serial       : serial,
                product_type : productType,
                credential   : credential,
                apikey       : userProfils.hash,
            },
            dataType : 'json',
            success  : function (r) {
                btn.prop('disabled', false).html('<i class="fas fa-upload"></i> {{Appliquer les credentials}}');
                if (r.state !== 'ok') {
                    $.fn.showAlert({ message: r.result, level: 'danger' });
                    return;
                }
                $.fn.showAlert({ message: '{{Credentials appliqués — cliquez sur Sauvegarder}}', level: 'success' });
                /* Recharger l'équipement pour afficher les nouvelles valeurs */
                $('.eqLogicDisplayCard[data-eqLogic_id="' + id + '"]').trigger('click');
            },
            error: function (xhr) {
                btn.prop('disabled', false).html('<i class="fas fa-upload"></i> {{Appliquer les credentials}}');
                $.fn.showAlert({ message: 'Erreur HTTP ' + xhr.status, level: 'danger' });
            }
        });
    });

    /* ── Recréer les commandes ── */
    $('#bt_recreate_cmds').on('click', function () {
        var id  = $('.eqLogic').attr('data-eqLogic_id');
        if (!id) { return; }
        var btn = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{En cours...}}');
        $.ajax({
            type     : 'POST',
            url      : 'plugins/dyson/core/ajax/dyson.ajax.php',
            data     : { action: 'recreate_cmds', id: id, apikey: userProfils.hash },
            dataType : 'json',
            success  : function (r) {
                btn.prop('disabled', false).html('<i class="fas fa-sync"></i> {{Recréer les commandes}}');
                if (r.state !== 'ok') {
                    $.fn.showAlert({ message: r.result, level: 'danger' });
                    return;
                }
                $.fn.showAlert({ message: '{{Commandes recréées avec succès}}', level: 'success' });
                $('.eqLogicDisplayCard[data-eqLogic_id="' + id + '"]').trigger('click');
            },
            error: function (xhr) {
                btn.prop('disabled', false).html('<i class="fas fa-sync"></i> {{Recréer les commandes}}');
                $.fn.showAlert({ message: 'Erreur HTTP ' + xhr.status, level: 'danger' });
            }
        });
    });

});
</script>

<?php include_file('desktop', 'dyson', 'js', 'dyson'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
