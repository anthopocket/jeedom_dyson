<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin   = plugin::byId('dyson');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<style>
/* ══ DYSON PLUGIN STYLES ══════════════════════════════════════════════ */

/* ── Tableau des commandes ─────────────────────────────── */

/* Lignes lisibles */
#table_cmd tbody tr td {
    vertical-align: middle !important;
}
#table_cmd tbody tr:nth-child(odd) {
    background: #fdfbff;
}
#table_cmd tbody tr:hover {
    background: #f5eeff !important;
}

/* Cartes équipements */
.dyson-card {
    border-radius: 10px;
    border: 1px solid #e0e0e0;
    background: #fff;
    transition: box-shadow .2s, transform .2s;
    padding: 12px 10px 8px;
    text-align: center;
    min-width: 130px;
}
.dyson-card:hover {
    box-shadow: 0 4px 18px rgba(155,77,202,.18);
    transform: translateY(-2px);
    border-color: #9B4DCA;
}
.dyson-card .dyson-icon {
    font-size: 42px;
    color: #9B4DCA;
    margin-bottom: 6px;
}
.dyson-card .name {
    font-weight: 600;
    font-size: 13px;
    display: block;
    margin-bottom: 3px;
    color: #333;
    word-break: break-word;
}
.dyson-card small {
    color: #888;
    font-size: 11px;
}
.dyson-card .dyson-badges {
    margin-top: 5px;
}

/* Fieldset stylé */
.dyson-fieldset {
    border: 1px solid #e8e8e8;
    border-radius: 8px;
    padding: 16px 20px 10px;
    margin-bottom: 18px;
    background: #fafafa;
}
.dyson-fieldset legend {
    font-size: 13px;
    font-weight: 700;
    color: #9B4DCA;
    text-transform: uppercase;
    letter-spacing: .5px;
    padding: 0 8px;
    width: auto;
    border: none;
    margin-bottom: 10px;
}
.dyson-fieldset legend i {
    margin-right: 5px;
}

/* Readonly fields */
.dyson-readonly {
    background: #f0f0f0 !important;
    color: #777;
    cursor: default;
}

/* Header plugin */
.dyson-header {
    background: linear-gradient(135deg, #9B4DCA 0%, #6c2fa0 100%);
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 20px;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 14px;
}
.dyson-header i {
    font-size: 32px;
    opacity: .9;
}
.dyson-header h4 {
    margin: 0 0 3px;
    font-size: 18px;
    font-weight: 700;
}
.dyson-header p {
    margin: 0;
    opacity: .8;
    font-size: 13px;
}

/* Nav tabs custom */
.dyson-tabs > li > a {
    border-radius: 6px 6px 0 0 !important;
    font-weight: 600;
    color: #666;
}
.dyson-tabs > li.active > a,
.dyson-tabs > li.active > a:focus,
.dyson-tabs > li.active > a:hover {
    color: #9B4DCA !important;
    border-top: 3px solid #9B4DCA !important;
}

/* Boutons action */
.dyson-actions .btn {
    border-radius: 6px;
    font-weight: 600;
    padding: 6px 14px;
}

/* Stats bar */
.dyson-stats {
    display: flex;
    gap: 12px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.dyson-stat-box {
    flex: 1;
    min-width: 100px;
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 10px 14px;
    text-align: center;
}
.dyson-stat-box .stat-num {
    font-size: 24px;
    font-weight: 700;
    color: #9B4DCA;
    line-height: 1;
}
.dyson-stat-box .stat-label {
    font-size: 11px;
    color: #999;
    margin-top: 3px;
    text-transform: uppercase;
    letter-spacing: .4px;
}

/* Empty state */
.dyson-empty {
    text-align: center;
    padding: 40px 20px;
    color: #aaa;
}
.dyson-empty i {
    font-size: 56px;
    margin-bottom: 14px;
    opacity: .3;
}
</style>

<div class="row row-overflow">

    <!-- ══ LISTE ══ -->
    <div class="col-xs-12 eqLogicThumbnailDisplay">

        <!-- Header -->
        <div class="dyson-header">
            <i class="fas fa-fan"></i>
            <div>
                <h4>{{Plugin Dyson}}</h4>
                <p>{{Gestion de vos appareils Dyson via MQTT local}}</p>
            </div>
        </div>

        <!-- Stats -->
        <?php
        $total   = count($eqLogics);
        $actifs  = 0;
        $online  = 0;
        foreach ($eqLogics as $eq) {
            if ($eq->getIsEnable()) $actifs++;
            $c = $eq->getCmd('info', 'connected');
            if (is_object($c) && $c->execCmd() == 1) $online++;
        }
        ?>
        <div class="dyson-stats">
            <div class="dyson-stat-box">
                <div class="stat-num"><?php echo $total; ?></div>
                <div class="stat-label">{{Équipements}}</div>
            </div>
            <div class="dyson-stat-box">
                <div class="stat-num" style="color:<?php echo $actifs > 0 ? '#27ae60' : '#ccc'; ?>">
                    <?php echo $actifs; ?>
                </div>
                <div class="stat-label">{{Actifs}}</div>
            </div>
            <div class="dyson-stat-box">
                <div class="stat-num" style="color:<?php echo $online > 0 ? '#2980b9' : '#ccc'; ?>">
                    <?php echo $online; ?>
                </div>
                <div class="stat-label">{{En ligne}}</div>
            </div>
        </div>

        <legend><i class="fas fa-fan" style="color:#9B4DCA"></i> {{Mes appareils}}</legend>

        <div class="eqLogicThumbnailContainer">

            <!-- Bouton configuration plugin -->
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>

            <?php if ($total === 0): ?>
                <div class="dyson-empty" style="width:100%">
                    <i class="fas fa-fan"></i>
                    <p>{{Aucun appareil Dyson trouvé}}</p>
                    <small>{{Configurez vos identifiants dans la configuration du plugin}}</small>
                </div>
            <?php endif; ?>

            <?php foreach ($eqLogics as $eq):
                $pt       = $eq->getConfiguration('product_type', '');
                $label    = dyson::PRODUCT_TYPES[$pt]['label'] ?? 'Dyson ' . $pt;
                $serial   = $eq->getConfiguration('serial_number', '');
                $hasIp    = trim($eq->getConfiguration('mqtt_hostname', '')) !== '';
                $conn     = $eq->getCmd('info', 'connected');
                $isOnline = is_object($conn) && $conn->execCmd() == 1;
            ?>
                <div class="eqLogicDisplayCard cursor dyson-card <?php echo $eq->getIsEnable() ? '' : 'opacity05'; ?>"
                     data-eqLogic_id="<?php echo $eq->getId(); ?>">
                    <div class="dyson-icon">
                        <i class="fas fa-fan <?php echo $isOnline ? '' : 'text-muted'; ?>"></i>
                    </div>
                    <span class="name"><?php echo $eq->getHumanName(true, true); ?></span>
                    <small><?php echo htmlspecialchars($label); ?></small>
                    <div class="dyson-badges">
                        <?php if ($serial): ?>
                            <span class="label label-default" style="font-size:10px;display:block;margin-bottom:3px">
                                <i class="fas fa-barcode"></i> <?php echo htmlspecialchars($serial); ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!$hasIp): ?>
                            <span class="label label-warning" style="font-size:10px">
                                <i class="fas fa-exclamation-triangle"></i> IP manquante
                            </span>
                        <?php elseif ($isOnline): ?>
                            <span class="label label-success" style="font-size:10px">
                                <i class="fas fa-wifi"></i> Connecté
                            </span>
                        <?php else: ?>
                            <span class="label label-danger" style="font-size:10px">
                                <i class="fas fa-wifi"></i> Hors ligne
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ FORMULAIRE ÉQUIPEMENT ══ -->
    <div class="col-xs-12 eqLogic" style="display:none;">

        <!-- Boutons action -->
        <div class="dyson-actions pull-right" style="display:inline-flex;gap:6px;margin-bottom:10px">
            <a class="btn btn-default btn-sm eqLogicAction" data-action="configure">
                <i class="fas fa-cogs"></i> {{Config avancée}}
            </a>
            <a class="btn btn-success btn-sm eqLogicAction" data-action="save">
                <i class="fas fa-check-circle"></i> {{Sauvegarder}}
            </a>
            <a class="btn btn-danger btn-sm eqLogicAction" data-action="remove">
                <i class="fas fa-minus-circle"></i> {{Supprimer}}
            </a>
        </div>

        <ul class="nav nav-tabs dyson-tabs" role="tablist" style="margin-top:10px">
            <li role="presentation">
                <a href="#" class="eqLogicAction" role="tab" data-toggle="tab"
                   data-action="returnToThumbnailDisplay">
                    <i class="fas fa-arrow-circle-left"></i> {{Retour}}
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

        <div class="tab-content" style="padding-top:18px">

            <!-- ─ Onglet Équipement ─ -->
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <form class="form-horizontal">
                    <input type="hidden" class="eqLogicAttr" data-l1key="id" />

                    <div class="col-lg-6">

                        <fieldset class="dyson-fieldset">
                            <legend><i class="fas fa-sliders-h"></i> {{Général}}</legend>
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
                                        <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" />
                                        <strong>{{Activer}}</strong>
                                    </label>
                                    <label class="checkbox-inline" style="margin-left:15px">
                                        <input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" />
                                        <strong>{{Visible}}</strong>
                                    </label>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="dyson-fieldset">
                            <legend><i class="fas fa-barcode"></i> {{Identification Dyson}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Numéro de série}}</label>
                                <div class="col-sm-8">
                                    <input type="text" class="eqLogicAttr form-control dyson-readonly"
                                           data-l1key="configuration" data-l2key="serial_number" readonly />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Type produit}}</label>
                                <div class="col-sm-8">
                                    <input type="text" class="eqLogicAttr form-control dyson-readonly"
                                           data-l1key="configuration" data-l2key="product_type" readonly />
                                </div>
                            </div>
                        </fieldset>

                    </div>

                    <div class="col-lg-6">

                        <fieldset class="dyson-fieldset">
                            <legend><i class="fas fa-network-wired"></i> {{Connexion MQTT locale}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Adresse IP}}</label>
                                <div class="col-sm-8">
                                    <div class="input-group">
                                        <span class="input-group-addon" style="background:#f5f0ff;border-color:#d0b0f0">
                                            <i class="fas fa-server" style="color:#9B4DCA"></i>
                                        </span>
                                        <input type="text" class="eqLogicAttr form-control"
                                               data-l1key="configuration" data-l2key="mqtt_hostname"
                                               placeholder="192.168.1.x" />
                                    </div>
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
                                    <div class="input-group">
                                        <span class="input-group-addon" style="background:#f5f0ff;border-color:#d0b0f0">
                                            <i class="fas fa-user" style="color:#9B4DCA"></i>
                                        </span>
                                        <input type="text" class="eqLogicAttr form-control"
                                               data-l1key="configuration" data-l2key="mqtt_username" />
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Mot de passe}}</label>
                                <div class="col-sm-8">
                                    <div class="input-group">
                                        <span class="input-group-addon" style="background:#f5f0ff;border-color:#d0b0f0">
                                            <i class="fas fa-lock" style="color:#9B4DCA"></i>
                                        </span>
                                        <input type="password" class="eqLogicAttr form-control"
                                               data-l1key="configuration" data-l2key="mqtt_password" />
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{TLS}}</label>
                                <div class="col-sm-8" style="padding-top:7px">
                                    <label class="checkbox-inline">
                                        <input type="checkbox" class="eqLogicAttr"
                                               data-l1key="configuration" data-l2key="use_tls" />
                                        {{Activer le chiffrement TLS}}
                                    </label>
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="col-sm-offset-4 col-sm-8">
                                    <button class="btn btn-info btn-sm" id="bt_recreate_cmds" type="button"
                                            style="border-radius:6px">
                                        <i class="fas fa-sync"></i> {{Recréer les commandes}}
                                    </button>
                                </div>
                            </div>
                        </fieldset>

                    </div>
                </form>
            </div>

            <!-- ─ Onglet Commandes ─ -->
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <div class="table-responsive" style="margin-top:10px">
                    <table id="table_cmd" class="table table-bordered table-condensed table-hover">
                        <thead>
                            <tr style="background:#f5f0ff">
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
    $('#bt_recreate_cmds').on('click', function () {
        var id = $('.eqLogic').attr('data-eqLogic_id');
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
