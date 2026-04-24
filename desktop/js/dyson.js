/* Plugin Dyson - Jeedom JS */
'use strict';

/* ── Initialisation de la page ──────────────────────────────────────── */
$(function () {
    // Affichage d'un équipement sélectionné depuis la liste
    $(document).on('click', '.eqLogicDisplayCard', function () {
        var eqLogic_id = $(this).data('eqlogic_id');
        $('.eqLogicDisplayCard').removeClass('eqLogicSelected');
        $(this).addClass('eqLogicSelected');
        jeedom.eqLogic.get({
            id: eqLogic_id,
            error: function (error) { $.fn.showAlert({ message: error.message, level: 'danger' }); },
            success: function (data) {
                printEqLogic(data);
                $('.eqLogic').show();
                $('.eqLogicThumbnailDisplay').hide();
                displayPageEqLogic();
            }
        });
    });

    // Retour à la liste depuis la config d'un équipement
    $(document).on('click', '.eqLogicAction[data-action="returnToThumbnailDisplay"]', function () {
        $('.eqLogic').hide();
        $('.eqLogicThumbnailDisplay').show();
    });

    // Sauvegarde
    $(document).on('click', '.eqLogicAction[data-action="save"]', function () {
        saveEqLogic();
    });

    // Suppression
    $(document).on('click', '.eqLogicAction[data-action="remove"]', function () {
        bootbox.confirm('{{Êtes-vous sûr de vouloir supprimer cet équipement ?}}', function (result) {
            if (!result) { return; }
            jeedom.eqLogic.remove({
                id: $('.eqLogicAttr[data-l1key="id"]').value(),
                error: function (error) { $.fn.showAlert({ message: error.message, level: 'danger' }); },
                success: function () { window.location.reload(); }
            });
        });
    });

    // Ajout d'un équipement
    $(document).on('click', '.eqLogicAction[data-action="add"]', function () {
        bootbox.prompt('{{Nom du nouvel appareil Dyson}}', function (result) {
            if (result === null || result.trim() === '') { return; }
            jeedom.eqLogic.save({
                eqLogics: [{ name: result, eqType_name: 'dyson', isEnable: 1, isVisible: 1 }],
                error: function (error) { $.fn.showAlert({ message: error.message, level: 'danger' }); },
                success: function (data) { window.location.reload(); }
            });
        });
    });

    // Configuration avancée
    $(document).on('click', '.eqLogicAction[data-action="configure"]', function () {
        var id = $('.eqLogicAttr[data-l1key="id"]').value();
        $('#md_modal').dialog({ title: '{{Configuration avancée}}' });
        $('#md_modal').load('index.php?v=d&modal=eqLogic.configure&eqLogic_id=' + id);
        $('#md_modal').dialog('open');
    });
});

/* ── Affichage d'un équipement ──────────────────────────────────────── */
function printEqLogic(_eqLogic) {
    setJeedomEqLogicAttr(_eqLogic);
    printEqLogicCmd(_eqLogic.cmds);
}

/* ── Affichage des commandes ────────────────────────────────────────── */
function printEqLogicCmd(_cmds) {
    $('#table_cmd tbody').empty();
    if (!isset(_cmds)) { return; }
    _cmds.forEach(function (cmd) { addCmdToTable(cmd); });
}

function addCmdToTable(_cmd) {
    if (!isset(_cmd)) { _cmd = {}; }
    var tr = $('<tr>');

    // Nom
    tr.append($('<td>').append(
        $('<input>').addClass('cmdAttr form-control input-sm').attr({ 'data-l1key': 'name', type: 'text' }).val(isset(_cmd.name) ? _cmd.name : '')
    ));

    // Type
    tr.append($('<td>').append(
        $('<span>').addClass('type').html(jeedom.cmd.availableType())
    ));

    // Sous-type
    tr.append($('<td>').append(
        $('<span>').addClass('subtype')
    ));

    // Unité
    tr.append($('<td>').append(
        $('<input>').addClass('cmdAttr form-control input-sm').attr({ 'data-l1key': 'unite', type: 'text', style: 'width:70px;' })
            .val(isset(_cmd.unite) ? _cmd.unite : '')
    ));

    // Valeur courante
    tr.append($('<td>').append(
        $('<span>').addClass('cmdAttr').attr('data-l1key', 'currentValue').html(isset(_cmd.currentValue) ? _cmd.currentValue : '')
    ));

    // Visible
    tr.append($('<td>').append(
        $('<input>').attr({ type: 'checkbox', 'data-l1key': 'isVisible' }).addClass('cmdAttr').prop('checked', isset(_cmd.isVisible) ? _cmd.isVisible == 1 : true)
    ));

    // Options
    var optTd = $('<td>');
    optTd.append(
        $('<input>').attr({ type: 'checkbox', 'data-l1key': 'isHistorized' }).addClass('cmdAttr').prop('checked', isset(_cmd.isHistorized) ? _cmd.isHistorized == 1 : false)
    ).append('&nbsp;{{Historiser}}');
    tr.append(optTd);

    // Actions
    var actionTd = $('<td>');
    if (isset(_cmd.id)) {
        actionTd.append(
            $('<a>').addClass('btn btn-default btn-xs cmdAction').attr('data-action', 'configure').html('<i class="fas fa-cog"></i>')
        ).append('&nbsp;');
        actionTd.append(
            $('<a>').addClass('btn btn-default btn-xs cmdAction').attr('data-action', 'test')
                .attr('data-type', isset(_cmd.type) ? _cmd.type : 'info')
                .html('<i class="fas fa-rss"></i> {{Tester}}')
        ).append('&nbsp;');
    }
    actionTd.append(
        $('<a>').addClass('btn btn-danger btn-xs cmdAction').attr('data-action', 'remove').html('<i class="fas fa-minus-circle"></i>')
    );
    tr.append(actionTd);

    setCmdAttr(tr, _cmd);
    $('#table_cmd tbody').append(tr);
    tr.find('.type').each(function () {
        jeedom.cmd.changeType($(this), isset(_cmd.type) ? _cmd.type : 'info');
    });
    tr.find('.subtype').each(function () {
        jeedom.cmd.changeSubType($(this), isset(_cmd.subType) ? _cmd.subType : 'other');
    });
}

/* ── Sauvegarde ─────────────────────────────────────────────────────── */
function saveEqLogic() {
    var eqLogic = getJeedomEqLogicAttr();
    eqLogic.eqType_name = 'dyson';
    eqLogic.cmds = [];
    $('#table_cmd tbody tr').each(function () {
        var cmd = getCmdAttr($(this));
        eqLogic.cmds.push(cmd);
    });
    jeedom.eqLogic.save({
        eqLogics: [eqLogic],
        error: function (error) { $.fn.showAlert({ message: error.message, level: 'danger' }); },
        success: function (data) {
            $.fn.showAlert({ message: '{{Équipement sauvegardé}}', level: 'success' });
            window.location.reload();
        }
    });
}
