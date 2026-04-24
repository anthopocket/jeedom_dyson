<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$apikey = config::byKey('api');
?>
<form class="form-horizontal">
    <fieldset>
        <legend>{{Compte Dyson}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Email}}</label>
            <div class="col-sm-4">
                <input type="email" class="configKey form-control" data-l1key="dyson_email" placeholder="votre@email.com" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Mot de passe}}</label>
            <div class="col-sm-4">
                <input type="password" class="configKey form-control" data-l1key="dyson_password" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Pays}}</label>
            <div class="col-sm-3">
                <select class="configKey form-control" data-l1key="dyson_country">
                    <option value="FR">France</option>
                    <option value="GB">Royaume-Uni</option>
                    <option value="US">États-Unis</option>
                    <option value="AU">Australie</option>
                    <option value="CA">Canada</option>
                    <option value="DE">Allemagne</option>
                    <option value="IT">Italie</option>
                    <option value="ES">Espagne</option>
                    <option value="NL">Pays-Bas</option>
                    <option value="BE">Belgique</option>
                    <option value="CH">Suisse</option>
                </select>
            </div>
        </div>

        <!-- Étape 1 -->
        <div class="form-group" id="div_step1">
            <div class="col-sm-offset-3 col-sm-6">
                <button type="button" id="bt_auth_init" class="btn btn-warning">
                    <i class="fas fa-search"></i> {{Découvrir mes appareils Dyson}}
                </button>
            </div>
        </div>

        <!-- Étape 2 : OTP -->
        <div id="div_step2" style="display:none">
            <div class="alert alert-info col-sm-offset-3 col-sm-6">
                <i class="fas fa-envelope"></i>
                <strong>{{Code OTP envoyé par Dyson}}</strong><br>
                {{Vérifiez votre boîte email et entrez le code ci-dessous.}}
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">{{Code OTP}}</label>
                <div class="col-sm-3">
                    <input type="text" id="input_otp" class="form-control"
                           placeholder="000000" maxlength="6" style="font-size:20px;letter-spacing:5px;text-align:center" />
                </div>
                <div class="col-sm-3">
                    <button type="button" id="bt_auth_verify" class="btn btn-success">
                        <i class="fas fa-check-circle"></i> {{Valider et créer les appareils}}
                    </button>
                </div>
            </div>
        </div>

        <!-- Résultat -->
        <div id="div_discover_result" style="display:none">
            <div class="col-sm-offset-3 col-sm-6">
                <div id="div_result_content"></div>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>{{Démon}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Port socket}}</label>
            <div class="col-sm-2">
                <input type="number" class="configKey form-control" data-l1key="socketport" placeholder="55005" />
            </div>
            <span class="col-sm-3 help-block">{{Port de communication interne (défaut : 55005)}}</span>
        </div>
    </fieldset>
</form>

<script>
(function () {
    var AJAX      = 'plugins/dyson/core/ajax/dyson.ajax.php';
    var APIKEY    = '<?php echo $apikey; ?>';
    var challengeId = null;

    function getField(key) { return $('[data-l1key="' + key + '"]').val().trim(); }

    function post(data, ok, fail) {
        data.apikey = APIKEY;
        $.ajax({
            type: 'POST', url: AJAX, data: data, dataType: 'json',
            success: function (r) {
                if (r.state !== 'ok') { if (fail) fail(r.result); return; }
                if (ok) ok(r.result);
            },
            error: function (xhr) { if (fail) fail('HTTP ' + xhr.status + ' : ' + xhr.responseText.substring(0, 300)); }
        });
    }

    function setLoading(btn, loading) {
        if (loading) {
            btn.prop('disabled', true).data('orig', btn.html())
               .html('<i class="fas fa-spinner fa-spin"></i> {{Chargement...}}');
        } else {
            btn.prop('disabled', false).html(btn.data('orig'));
        }
    }

    function showResult(r) {
        var html = '<div class="alert alert-success">'
            + '<b><i class="fas fa-check-circle"></i> {{Découverte réussie !}}</b><br>'
            + r.created + ' {{appareil(s) créé(s)}}, '
            + r.updated + ' {{mis à jour}}, '
            + r.skipped + ' {{ignoré(s)}}.<br><br>'
            + '<i class="fas fa-info-circle"></i> '
            + '{{Rendez-vous dans le plugin Dyson pour saisir l\'adresse IP locale de chaque appareil.}}'
            + '</div>';
        $('#div_result_content').html(html);
        $('#div_discover_result').show();
        $('#div_step2').hide();
    }

    /* ── Étape 1 : demande OTP ── */
    $('#bt_auth_init').on('click', function () {
        var btn      = $(this);
        var email    = getField('dyson_email');
        var password = getField('dyson_password');
        var country  = getField('dyson_country');
        if (!email || !password) {
            $.fn.showAlert({message: '{{Email et mot de passe requis}}', level: 'warning'});
            return;
        }
        setLoading(btn, true);
        $('#div_discover_result').hide();
        post(
            {action: 'auth_init', email: email, password: password, country: country},
            function (data) {
                setLoading(btn, false);
                if (data.type === 'direct') {
                    showResult(data.result);
                } else {
                    // OTP requis
                    challengeId = data.challengeId;
                    $('#div_step2').show();
                    $('#input_otp').val('').focus();
                    $.fn.showAlert({message: '{{Code OTP envoyé à votre adresse email Dyson}}', level: 'info'});
                }
            },
            function (err) {
                setLoading(btn, false);
                $.fn.showAlert({message: err, level: 'danger'});
            }
        );
    });

    /* ── Étape 2 : validation OTP ── */
    $('#bt_auth_verify').on('click', function () {
        var btn     = $(this);
        var otp     = $('#input_otp').val().trim();
        if (!otp || !challengeId) {
            $.fn.showAlert({message: '{{Entrez le code OTP reçu par email}}', level: 'warning'});
            return;
        }
        setLoading(btn, true);
        post(
            {
                action:      'auth_verify',
                email:       getField('dyson_email'),
                password:    getField('dyson_password'),
                country:     getField('dyson_country'),
                challengeId: challengeId,
                otp:         otp
            },
            function (data) {
                setLoading(btn, false);
                showResult(data);
            },
            function (err) {
                setLoading(btn, false);
                $.fn.showAlert({message: err, level: 'danger'});
            }
        );
    });

    /* Validation OTP au clavier */
    $('#input_otp').on('keypress', function (e) {
        if (e.which === 13) { $('#bt_auth_verify').trigger('click'); }
    });
})();
</script>
