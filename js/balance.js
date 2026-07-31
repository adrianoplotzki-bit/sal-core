/* Painel do Balanço — mapa, sinais vitais ao vivo e histórico.
 *
 * Vanilla, sem jQuery e sem biblioteca de gráfico. A única dependência é o
 * Leaflet, para o mapa. Os gráficos são SVG montado à mão: dependência nova
 * neste projeto é conversa antes de código (CLAUDE.md §7), e uma série
 * temporal simples não justifica 80 KB de CDN sem versão fixada.
 *
 * Regra de desenho que não deve ser afrouxada: a posição atual é um CÍRCULO,
 * nunca um alfinete. Um pino no centro da célula afirma uma precisão que não
 * existe e convida o leitor a dar zoom em cima de uma coordenada que é do
 * centro da célula, não do barco.
 */
/* global L, salBalanco */
(function () {
    'use strict';

    var cfg = window.salBalanco;
    var elMapa = document.getElementById('sal-balance-map');
    var elEstado = document.getElementById('sal-balance-estado');
    var elCartoes = document.getElementById('sal-balance-cartoes');
    var elGraficos = document.getElementById('sal-balance-graficos');
    var elPeriodo = document.querySelector('.sal-balanco__periodo');
    if (!cfg || !elMapa || typeof L === 'undefined') { return; }

    var INTERVALO = 5 * 60 * 1000;
    var janelaAtual = '24h';

    var ESTADOS = {
        moored: 'atracado',
        anchored: 'fundeado',
        sailing: 'navegando à vela',
        motoring: 'navegando a motor',
        'motor sailing': 'a vela e motor',
        driving: 'navegando',
        'not under command': 'sem governo'
    };

    var VITAIS = [
        { chave: 'bateria_v', rotulo: 'Baterias', unidade: 'V' },
        { chave: 'profundidade_m', rotulo: 'Profundidade', unidade: 'm' },
        { chave: 'vento_no', rotulo: 'Vento', unidade: 'nós' },
        { chave: 'agua_c', rotulo: 'Água', unidade: '°C' }
    ];

    var mapa = L.map(elMapa, { scrollWheelZoom: false }).setView([-15, -40], 4);
    var camadaArea = null, camadaRota = null, camadaLugares = null;

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap'
    }).addTo(mapa);

    function buscar(url) {
        return fetch(url, { credentials: 'omit' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; });
    }

    function escapar(s) {
        return String(s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    /* ---------------------------------------------------------------- mapa */

    function texto(agora) {
        if (!agora || !agora.area) { return 'Sem transmissão do Balanço no momento.'; }
        var onde = agora.area.nome ? ('perto de ' + agora.area.nome) : 'em algum ponto desta área';
        var estado = ESTADOS[agora.estado];
        return (agora.transmitindo ? 'Agora: ' : 'Última posição conhecida: ') +
            (estado ? estado + ', ' : '') + onde +
            ' — posição aproximada, num raio de ' + agora.area.raio_km + ' km.';
    }

    function desenharMapa(agora, rota) {
        var limites = [];

        if (camadaArea) { mapa.removeLayer(camadaArea); camadaArea = null; }
        if (camadaRota) { mapa.removeLayer(camadaRota); camadaRota = null; }
        if (camadaLugares) { mapa.removeLayer(camadaLugares); camadaLugares = null; }

        if (rota && rota.pontos && rota.pontos.length) {
            var coords = rota.pontos.map(function (p) { return [p.lat, p.lon]; });
            camadaRota = L.polyline(coords, { color: '#2563eb', weight: 3, opacity: 0.85 }).addTo(mapa);
            limites = limites.concat(coords);
        }

        // Lugares são pontos avulsos: círculos nomeados, nunca ligados por
        // linha. A ordem em que aparecem no banco não é a ordem em que foram
        // visitados, então qualquer linha entre eles seria ficção.
        if (rota && rota.lugares && rota.lugares.length) {
            camadaLugares = L.layerGroup(rota.lugares.map(function (lugar) {
                var m = L.circleMarker([lugar.lat, lugar.lon], {
                    radius: 5, color: '#f59e0b', weight: 2,
                    fillColor: '#f59e0b', fillOpacity: 0.9
                });
                if (lugar.nome) { m.bindPopup(escapar(lugar.nome)); }
                limites.push([lugar.lat, lugar.lon]);
                return m;
            })).addTo(mapa);
        }

        if (agora && agora.area) {
            camadaArea = L.circle([agora.area.lat, agora.area.lon], {
                radius: agora.area.raio_km * 1000,
                color: '#dc2626', weight: 2,
                fillColor: '#dc2626', fillOpacity: 0.12
            }).addTo(mapa);
            limites.push([agora.area.lat, agora.area.lon]);
        }

        if (limites.length) {
            var caixa = L.latLngBounds(limites);
            // Estende pelo círculo inteiro, não só pelo centro dele: senão o
            // enquadramento cortaria metade da área publicada.
            if (camadaArea) { caixa = caixa.extend(camadaArea.getBounds()); }
            mapa.fitBounds(caixa, { padding: [24, 24] });
        }

        if (elEstado) { elEstado.textContent = texto(agora); }
    }

    /* ------------------------------------------------------------ cartões */

    function desenharCartoes(agora) {
        if (!elCartoes) { return; }
        var v = (agora && agora.vitais) || {};
        var html = '';

        var estado = agora && ESTADOS[agora.estado];
        if (estado) {
            html += '<div class="sal-vital sal-vital--estado"><span class="sal-vital__valor">' +
                escapar(estado) + '</span><span class="sal-vital__rotulo">Situação</span></div>';
        }

        VITAIS.forEach(function (d) {
            if (typeof v[d.chave] !== 'number') { return; }
            html += '<div class="sal-vital"><span class="sal-vital__valor">' + v[d.chave] +
                '<small>' + d.unidade + '</small></span>' +
                '<span class="sal-vital__rotulo">' + d.rotulo + '</span></div>';
        });

        elCartoes.innerHTML = html;
    }

    /* ------------------------------------------------------------ gráficos */

    function formatarInstante(unix, span) {
        var d = new Date(unix * 1000);
        function pad(n) { return (n < 10 ? '0' : '') + n; }
        var dia = pad(d.getDate()) + '/' + pad(d.getMonth() + 1);
        var hora = pad(d.getHours()) + ':' + pad(d.getMinutes());
        // Até dois dias o que importa é a hora; acima disso, a data.
        return span <= 2 * 86400 ? hora : dia;
    }

    function svgGrafico(serie) {
        var P = serie.pontos;
        var W = 640, H = 150, ESQ = 46, DIR = 10, TOPO = 12, BASE = 24;
        var validos = [];
        var i;
        for (i = 0; i < P.length; i++) {
            if (P[i][1] !== null && P[i][1] !== undefined) { validos.push(P[i][1]); }
        }
        if (!validos.length) { return ''; }

        var min = Math.min.apply(null, validos);
        var max = Math.max.apply(null, validos);
        if (max - min < 1e-9) { min -= 1; max += 1; }   // série constante vira linha no meio
        var folga = (max - min) * 0.12;
        min -= folga; max += folga;

        var t0 = P[0][0], t1 = P[P.length - 1][0];
        if (t1 <= t0) { t1 = t0 + 1; }
        var span = t1 - t0;

        function px(t) { return ESQ + (t - t0) / span * (W - ESQ - DIR); }
        function py(v) { return TOPO + (1 - (v - min) / (max - min)) * (H - TOPO - BASE); }

        // Buracos na série viram interrupção na linha, não um segmento reto
        // atravessando o gráfico: o barco esteve sem transmitir, e inventar
        // uma reta ali seria afirmar um dado que não existe.
        var d = '', desenhando = false;
        for (i = 0; i < P.length; i++) {
            var v = P[i][1];
            if (v === null || v === undefined) { desenhando = false; continue; }
            d += (desenhando ? 'L' : 'M') + px(P[i][0]).toFixed(1) + ' ' + py(v).toFixed(1) + ' ';
            desenhando = true;
        }

        var linhas = '';
        [max, (max + min) / 2, min].forEach(function (nivel) {
            var y = py(nivel).toFixed(1);
            linhas += '<line x1="' + ESQ + '" y1="' + y + '" x2="' + (W - DIR) + '" y2="' + y +
                '" class="sal-gr__grade"/>' +
                '<text x="' + (ESQ - 6) + '" y="' + (parseFloat(y) + 4) +
                '" class="sal-gr__eixo" text-anchor="end">' + nivel.toFixed(1) + '</text>';
        });

        var marcas = '';
        [0, 0.5, 1].forEach(function (f) {
            var t = t0 + span * f;
            marcas += '<text x="' + px(t).toFixed(1) + '" y="' + (H - 6) +
                '" class="sal-gr__eixo" text-anchor="' +
                (f === 0 ? 'start' : (f === 1 ? 'end' : 'middle')) + '">' +
                formatarInstante(t, span) + '</text>';
        });

        var ultimo = validos[validos.length - 1];
        return '<figure class="sal-gr">' +
            '<figcaption class="sal-gr__titulo">' + escapar(serie.rotulo) +
            ' <span class="sal-gr__agora">' + ultimo + ' ' + escapar(serie.unidade) + '</span></figcaption>' +
            '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' +
            escapar(serie.rotulo + ' em ' + serie.unidade) + '">' +
            linhas + marcas +
            '<path d="' + d.trim() + '" fill="none" stroke="' + escapar(serie.cor) +
            '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>' +
            '</svg></figure>';
    }

    function desenharGraficos(dados) {
        if (!elGraficos) { return; }
        if (!dados || !dados.series || !Object.keys(dados.series).length) {
            elGraficos.innerHTML = '<p class="sal-balanco__vazio">Sem histórico neste período.</p>';
            return;
        }
        var html = '';
        Object.keys(dados.series).forEach(function (chave) {
            html += svgGrafico(dados.series[chave]);
        });
        elGraficos.innerHTML = html;
    }

    function carregarSeries() {
        if (elGraficos && !elGraficos.innerHTML) {
            elGraficos.innerHTML = '<p class="sal-balanco__vazio">Carregando histórico…</p>';
        }
        return buscar(cfg.series + '?janela=' + encodeURIComponent(janelaAtual)).then(desenharGraficos);
    }

    if (elPeriodo) {
        elPeriodo.addEventListener('click', function (ev) {
            var btn = ev.target.closest('button[data-janela]');
            if (!btn || btn.dataset.janela === janelaAtual) { return; }
            janelaAtual = btn.dataset.janela;
            Array.prototype.forEach.call(elPeriodo.querySelectorAll('button'), function (b) {
                var ativo = b === btn;
                b.classList.toggle('is-ativo', ativo);
                b.setAttribute('aria-pressed', ativo ? 'true' : 'false');
            });
            elGraficos.innerHTML = '';
            carregarSeries();
        });
    }

    function atualizar() {
        Promise.all([buscar(cfg.agora), buscar(cfg.rota)]).then(function (r) {
            desenharMapa(r[0], r[1]);
            desenharCartoes(r[0]);
        });
        carregarSeries();
    }

    atualizar();
    setInterval(atualizar, INTERVALO);
}());
