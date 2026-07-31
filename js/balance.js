/* Painel do Balanço — mapa da rota + área aproximada do momento.
 *
 * Vanilla, sem jQuery e sem Chart.js. A única dependência é o Leaflet.
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
    var el = document.getElementById('sal-balance-map');
    var estado = document.getElementById('sal-balance-estado');
    if (!cfg || !el || typeof L === 'undefined') {
        return;
    }

    var INTERVALO = 5 * 60 * 1000; // a célula muda raramente; 30s não faria sentido
    var mapa = L.map(el, { scrollWheelZoom: false }).setView([-15, -40], 4);
    var camadaArea = null;
    var camadaRota = null;

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap'
    }).addTo(mapa);

    function buscar(url) {
        return fetch(url, { credentials: 'omit' }).then(function (r) {
            return r.ok ? r.json() : null;
        }).catch(function () {
            return null;
        });
    }

    function texto(agora, ocultos) {
        if (!agora || !agora.area) {
            return 'Sem transmissão do Balanço no momento.';
        }
        var partes = [];
        var onde = agora.area.nome ? ('perto de ' + agora.area.nome) : 'em algum ponto desta área';
        partes.push(
            (agora.transmitindo ? 'Agora: ' : 'Última posição conhecida: ') +
            onde + ' — posição aproximada, num raio de ' + agora.area.raio_km + ' km.'
        );

        var v = agora.vitais || {};
        var vitais = [];
        if (typeof v.profundidade_m === 'number') { vitais.push(v.profundidade_m + ' m de profundidade'); }
        if (typeof v.vento_no === 'number') { vitais.push(v.vento_no + ' nós de vento'); }
        if (typeof v.bateria_v === 'number') { vitais.push(v.bateria_v + ' V nas baterias'); }
        if (vitais.length) { partes.push(vitais.join(' · ')); }

        if (ocultos > 0) {
            partes.push('O trecho mais recente da rota fica oculto até o Balanço se afastar.');
        }
        return partes.join(' ');
    }

    function desenhar(agora, rota) {
        var limites = [];

        if (camadaArea) { mapa.removeLayer(camadaArea); camadaArea = null; }
        if (camadaRota) { mapa.removeLayer(camadaRota); camadaRota = null; }

        if (rota && rota.pontos && rota.pontos.length) {
            var coords = rota.pontos.map(function (p) { return [p.lat, p.lon]; });
            camadaRota = L.polyline(coords, { color: '#2563eb', weight: 3, opacity: 0.85 }).addTo(mapa);
            limites = limites.concat(coords);
        }

        if (agora && agora.area) {
            camadaArea = L.circle([agora.area.lat, agora.area.lon], {
                radius: agora.area.raio_km * 1000,
                color: '#dc2626',
                weight: 2,
                fillColor: '#dc2626',
                fillOpacity: 0.12
            }).addTo(mapa);
            limites.push([agora.area.lat, agora.area.lon]);
        }

        if (limites.length) {
            var caixa = camadaArea ? camadaArea.getBounds() : L.latLngBounds(limites);
            if (camadaRota) { caixa = caixa.extend(camadaRota.getBounds()); }
            mapa.fitBounds(caixa, { padding: [24, 24] });
        }

        if (estado) {
            estado.textContent = texto(agora, rota ? rota.ocultos : 0);
        }
    }

    function atualizar() {
        Promise.all([buscar(cfg.agora), buscar(cfg.rota)]).then(function (r) {
            desenhar(r[0], r[1]);
        });
    }

    atualizar();
    setInterval(atualizar, INTERVALO);
}());
