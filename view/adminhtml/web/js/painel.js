/**
 * SI5 Global - review table in the product form.
 *
 * The product form renders the panel after the page has loaded, so the clicks are handled by delegation on the
 * document and the configuration is read from the panel's data-si5g attribute.
 * @license OSL-3.0
 */
define([], function () {
    'use strict';

    if (window.si5GlobalPainel) {
        return;
    }
    window.si5GlobalPainel = true;

    function texto(v) {
        var d = document.createElement('div');
        d.textContent = v == null ? '' : String(v);
        return d.innerHTML;
    }

    function painel(raiz) {
        var cfg = JSON.parse(raiz.getAttribute('data-si5g'));
        var q = function (sel) { return raiz.querySelector(sel); };
        var msg = function (t, tipo) {
            var m = q('.si5g-msg');
            m.className = 'si5g-msg' + (t ? ' message message-' + (tipo || 'notice') : '');
            m.textContent = t || '';
        };
        var chamar = function (url, campos) {
            var dados = new FormData();
            dados.append('form_key', cfg.formKey);
            Object.keys(campos || {}).forEach(function (k) {
                [].concat(campos[k]).forEach(function (v) { dados.append(k, v); });
            });
            return fetch(url, { method: 'POST', body: dados, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); });
        };

        return { cfg: cfg, q: q, msg: msg, chamar: chamar };
    }

    function linha(cfg, campo, atual, novo) {
        var vazio = novo === '' || novo == null;
        var cel = campo === 'imagem' && !vazio
            ? '<img src="' + texto(novo) + '" alt="" class="si5g-foto" referrerpolicy="no-referrer">'
            : texto(vazio ? '—' : novo);
        return '<tr><th>' + texto(cfg.rotulos[campo]) + '</th><td>' + texto(atual || '—') + '</td><td>' + cel + '</td>' +
            '<td class="si5g-marcar">' + (vazio ? '' : '<input type="checkbox" class="si5g-campo" value="' + campo + '" aria-label="' + texto(cfg.rotulos[campo]) + '">') + '</td></tr>';
    }

    function consultar(p, raiz) {
        p.msg(p.cfg.textos.consultando);
        p.q('.si5g-revisao').hidden = true;
        p.chamar(p.cfg.consultar).then(function (r) {
            if (!r.ok) { p.msg(r.erro, 'warning'); return; }
            var a = r.atual, s = r.sugerido;
            raiz.si5gSugerido = s;
            p.q('.si5g-linhas').innerHTML = [
                linha(p.cfg, 'nome', a.nome, s.nome), linha(p.cfg, 'marca', a.marca, s.marca), linha(p.cfg, 'peso', a.peso, s.peso),
                linha(p.cfg, 'imagem', a.imagem, s.imagem_url), linha(p.cfg, 'ncm', a.ncm, s.ncm), linha(p.cfg, 'cest', a.cest, s.cest)
            ].join('');
            p.q('.si5g-ref').textContent = p.cfg.textos.referencia + (s.ncm_descricao ? ' (NCM: ' + s.ncm_descricao + ')' : '');
            p.q('.si5g-revisao').hidden = false;
            p.msg(p.cfg.textos.marque);
        }).catch(function () { p.msg(p.cfg.textos.falha, 'error'); });
    }

    function aplicar(p, raiz) {
        var campos = [].slice.call(raiz.querySelectorAll('.si5g-campo:checked')).map(function (c) { return c.value; });
        if (!campos.length) { p.msg(p.cfg.textos.nenhum, 'warning'); return; }
        p.msg(p.cfg.textos.aplicando);
        p.chamar(p.cfg.aplicar, { 'campos[]': campos, sugerido: JSON.stringify(raiz.si5gSugerido) }).then(function (r) {
            if (!r.ok) { p.msg(r.erro, 'error'); return; }
            p.q('.si5g-revisao').hidden = true;
            p.msg(p.cfg.textos.atualizado.replace('%1', r.alterados.join(', ')), 'success');
        }).catch(function () { p.msg(p.cfg.textos.falha, 'error'); });
    }

    document.addEventListener('click', function (e) {
        var botao = e.target && e.target.closest ? e.target.closest('.si5g-consultar, .si5g-aplicar, .si5g-cancelar') : null;
        var raiz = botao && botao.closest('.si5g[data-si5g]');
        if (!raiz) {
            return;
        }
        e.preventDefault();
        var p = painel(raiz);
        if (botao.classList.contains('si5g-consultar')) {
            consultar(p, raiz);
        } else if (botao.classList.contains('si5g-aplicar')) {
            aplicar(p, raiz);
        } else {
            p.q('.si5g-revisao').hidden = true;
            p.msg('');
        }
    });
});
