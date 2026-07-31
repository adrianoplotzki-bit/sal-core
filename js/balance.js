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

    // Dois relógios em vez de um. /agora é uma linha do banco e muda a cada
    // minuto; /rota e /series varrem a tabela inteira e mudam devagar. Buscar
    // tudo no mesmo ritmo obrigaria a escolher entre painel lento e servidor
    // castigado — separando, dá para ter os dois.
    var INTERVALO_VIVO = 60 * 1000;
    var INTERVALO_PESADO = 5 * 60 * 1000;
    var janelaAtual = '24h';
    var ultimoAgora = null, ultimaRota = null, chaveEnquadrada = null;
    var falhasSeguidas = 0, ultimaBusca = 0;

    // Depois de tantas falhas seguidas em /agora, o painel avisa. Três
    // minutos de silêncio: menos que isso pisca à toa em rede de celular.
    var FALHAS_PARA_AVISAR = 3;

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
        { chave: 'bateria_pct', rotulo: 'Bateria', unidade: '%' },
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

    // Só a ressalva de precisão. A situação e o lugar saíram daqui porque já
    // estavam no cartão e no próprio mapa, e o "agora" vive no ponto verde do
    // cartão de situação — um lugar só a dizer se a leitura é do momento.
    function texto(agora) {
        if (!agora || !agora.area) { return 'Sem transmissão do Balanço no momento.'; }
        return 'Posição aproximada, num raio de ' + agora.area.raio_km + ' km.';
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

        // Reenquadra só quando o que há para mostrar muda de fato. Com o
        // painel atualizando a cada minuto, um fitBounds por ciclo desfaria o
        // zoom e o arrasto do leitor sem parar.
        var chave = (agora && agora.area ? agora.area.lat + ',' + agora.area.lon + ',' + agora.area.raio_km : '-') +
            '|' + (rota && rota.pontos ? rota.pontos.length : 0) +
            '|' + (rota && rota.lugares ? rota.lugares.length : 0);

        if (limites.length && chave !== chaveEnquadrada) {
            var caixa = L.latLngBounds(limites);
            // Estende pelo círculo inteiro, não só pelo centro dele: senão o
            // enquadramento cortaria metade da área publicada.
            if (camadaArea) { caixa = caixa.extend(camadaArea.getBounds()); }
            mapa.fitBounds(caixa, { padding: [24, 24] });
            chaveEnquadrada = chave;
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
            // O ponto só acende quando o barco está mesmo transmitindo: com
            // dado velho, a situação continua sendo verdade, mas não é "agora".
            html += '<div class="sal-vital sal-vital--estado' +
                (agora.transmitindo ? ' is-vivo' : '') +
                '"><span class="sal-vital__valor">' + escapar(estado) + '</span>' +
                '<span class="sal-vital__rotulo">Situação</span></div>';
        }

        VITAIS.forEach(function (d) {
            if (typeof v[d.chave] !== 'number') { return; }
            html += '<div class="sal-vital"><span class="sal-vital__valor">' + v[d.chave] +
                '<small>' + d.unidade + '</small></span>' +
                '<span class="sal-vital__rotulo">' + d.rotulo + '</span></div>';
        });

        elCartoes.innerHTML = html ||
            '<p class="sal-balanco__vazio">Sem leituras no momento.</p>';

        desenharMare();
    }

    // Rodapé do gráfico de profundidade: é ali que a informação de maré tem
    // contexto, ao lado da curva prevista e da medida. Fica num alvo próprio
    // para acompanhar o ciclo de 60 s sem precisar redesenhar os gráficos,
    // que só se refazem a cada 5 min.
    function desenharMare() {
        var alvo = document.getElementById('sal-balance-mare');
        if (!alvo) { return; }
        var mare = ultimoAgora && ultimoAgora.mare;
        if (!mare) { alvo.innerHTML = ''; return; }

        var p = partes(Math.floor(Date.parse(mare.proxima_em) / 1000));
        var frases = ['Maré ' + mare.sentido + '.'];
        frases.push((mare.proxima === 'alta' ? 'Preamar' : 'Baixamar') +
            ' prevista para ' + p.hora + ', mais ' + mare.variacao_m + ' m.');

        if (typeof mare.sob_quilha_m === 'number') {
            var agoraQ = mare.sob_quilha_m;
            var minQ = mare.sob_quilha_min_m;
            frases.push('Sob a quilha: ' + agoraQ.toFixed(1) + ' m agora' +
                (minQ < agoraQ ? ', ' + minQ.toFixed(1) + ' m na baixamar' : '') + '.');
        }

        // A ressalva não é enfeite. O modelo é oceânico e de grade grossa;
        // comparado à estação de Maceió ele adiantou de 16 a 60 min (medido
        // em 2026-07-31). Dentro de baía ou estuário essa diferença é a
        // regra, e quem decide fundear com base nisso precisa saber.
        alvo.innerHTML = '<span>' + frases.join(' ') + '</span>' +
            '<em>Previsão de modelo oceânico global; em baías e estuários o ' +
            'horário costuma atrasar em relação a ela.</em>';
    }

    /* ------------------------------------------------------------ gráficos */

    // Todos os horários do painel usam o fuso do SITE, não o do navegador. A
    // maré acontece na hora do barco: quem abrir a página de outro fuso veria
    // a preamar deslocada se o relógio fosse o dele.
    // parseFloat, não typeof: wp_localize_script serializa todo escalar como
    // STRING, então fuso_horas chega "-3". Com a checagem de tipo o
    // deslocamento zerava e o painel mostrava UTC — três horas erradas, sem
    // nenhum sinal de erro.
    var FUSO_S = (parseFloat(cfg.fuso_horas) || 0) * 3600;

    function partes(unix) {
        var d = new Date((unix + FUSO_S) * 1000);
        function pad(n) { return (n < 10 ? '0' : '') + n; }
        return {
            dia: pad(d.getUTCDate()) + '/' + pad(d.getUTCMonth() + 1),
            hora: pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes())
        };
    }

    function formatarInstante(unix, span) {
        var p = partes(unix);
        // Até dois dias o que importa é a hora; acima disso, a data.
        return span <= 2 * 86400 ? p.hora : p.dia;
    }

    function svgGrafico(chave, serie, t0, t1) {
        var P = serie.pontos;
        var W = 640, H = 150, ESQ = 44, DIR = 8, TOPO = 14, BASE = 24;

        // Quebra a série em trechos contíguos. Buraco vira interrupção na
        // linha, não um segmento reto atravessando o gráfico: o barco esteve
        // sem transmitir, e a reta afirmaria um dado que não existe.
        var trechos = [], atual = [], validos = [], i;
        for (i = 0; i < P.length; i++) {
            var v = P[i][1];
            if (v === null || v === undefined) {
                if (atual.length) { trechos.push(atual); atual = []; }
                continue;
            }
            atual.push(P[i]);
            validos.push(v);
        }
        if (atual.length) { trechos.push(atual); }

        // Um ponto só não é uma série temporal: os três rótulos do eixo X
        // sairiam com a mesma hora e a linha não existiria. Melhor dizer que
        // ainda não há histórico do que desenhar uma grade vazia.
        if (validos.length < 2) { return ''; }

        // A faixa mín..máx do intervalo. Só a média esconderia a rajada, e num
        // canal de vela é o pico que conta a história do dia.
        var faixas = [], faixaAtual = [], extremos = validos.slice();
        if (serie.faixa) {
            for (i = 0; i < serie.faixa.length; i++) {
                var fx = serie.faixa[i];
                if (fx[1] === null || fx[2] === null) {
                    if (faixaAtual.length > 1) { faixas.push(faixaAtual); }
                    faixaAtual = [];
                    continue;
                }
                faixaAtual.push(fx);
                extremos.push(fx[1], fx[2]);
            }
            if (faixaAtual.length > 1) { faixas.push(faixaAtual); }
        }

        var previsao = [];
        if (serie.previsao) {
            serie.previsao.forEach(function (q) {
                if (q[1] === null || q[0] < t0 || q[0] > t1) { return; }
                previsao.push(q);
                extremos.push(q[1]);
            });
        }

        var min = Math.min.apply(null, extremos);
        var max = Math.max.apply(null, extremos);
        if (max - min < 1e-9) { min -= 1; max += 1; }   // série constante vira linha no meio
        var folga = (max - min) * 0.12;
        min -= folga; max += folga;

        // t0/t1 vêm do recorte COMUM da resposta, não do primeiro e último
        // ponto desta série. Cada gráfico com o próprio eixo faria curvas de
        // durações diferentes parecerem alinhadas.
        if (t1 <= t0) { t1 = t0 + 1; }
        var span = t1 - t0;

        function px(t) { return ESQ + (t - t0) / span * (W - ESQ - DIR); }
        function py(v) { return TOPO + (1 - (v - min) / (max - min)) * (H - TOPO - BASE); }

        var chao = (H - BASE).toFixed(1);
        var dLinha = '', dArea = '';
        trechos.forEach(function (trecho) {
            var pedaco = trecho.map(function (p) {
                return px(p[0]).toFixed(1) + ' ' + py(p[1]).toFixed(1);
            });
            dLinha += 'M' + pedaco.join(' L') + ' ';
            dArea += 'M' + px(trecho[0][0]).toFixed(1) + ' ' + chao +
                ' L' + pedaco.join(' L') +
                ' L' + px(trecho[trecho.length - 1][0]).toFixed(1) + ' ' + chao + ' Z ';
        });

        var grade = '';
        [max, (max + min) / 2, min].forEach(function (nivel) {
            var y = py(nivel).toFixed(1);
            grade += '<line x1="' + ESQ + '" y1="' + y + '" x2="' + (W - DIR) + '" y2="' + y +
                '" class="sal-gr__grade"/>' +
                '<text x="' + (ESQ - 6) + '" y="' + (parseFloat(y) + 3.5) +
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

        // Faixa: sobe pelos máximos e volta pelos mínimos, fechando a área.
        var dFaixa = '';
        faixas.forEach(function (bloco) {
            var topo = bloco.map(function (f) { return px(f[0]).toFixed(1) + ' ' + py(f[2]).toFixed(1); });
            var base = [];
            for (var k = bloco.length - 1; k >= 0; k--) {
                base.push(px(bloco[k][0]).toFixed(1) + ' ' + py(bloco[k][1]).toFixed(1));
            }
            dFaixa += 'M' + topo.join(' L') + ' L' + base.join(' L') + ' Z ';
        });

        // Maré prevista, deslocada para o mesmo eixo da sondagem. Tracejada
        // porque é previsão, não medição — e é da distância horizontal entre
        // ela e a curva cheia que se lê o atraso do estuário.
        var dPrev = '';
        if (previsao.length > 1) {
            dPrev = '<path d="M' + previsao.map(function (q) {
                return px(q[0]).toFixed(1) + ' ' + py(q[1]).toFixed(1);
            }).join(' L') + '" class="sal-gr__previsao"/>';
        }

        var id = 'salgr-' + chave;
        var ultimo = validos[validos.length - 1];

        // Com faixa desenhada, o gradiente sob a linha vira ruído: são duas
        // áreas coloridas disputando a mesma leitura.
        var fundo = dFaixa
            ? '<path d="' + dFaixa.trim() + '" fill="' + escapar(serie.cor) +
              '" fill-opacity="0.16" stroke="none"/>'
            : '<path d="' + dArea.trim() + '" fill="url(#' + id + ')" stroke="none"/>';

        // Duas formas de marcar uma virada de maré, conforme o eixo Y.
        var dMarcas = '';

        // Na própria curva de maré: ponto na altura prevista, com o valor.
        if (serie.marcas) {
            serie.marcas.forEach(function (mc) {
                if (mc.ts < t0 || mc.ts > t1) { return; }
                var x = px(mc.ts), y = py(mc.valor);
                var alta = mc.tipo === 'alta';
                dMarcas += '<circle cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) +
                    '" r="2.6" class="sal-gr__mare"/>' +
                    '<text x="' + x.toFixed(1) + '" y="' + (y + (alta ? -7 : 13)).toFixed(1) +
                    '" class="sal-gr__mare-txt" text-anchor="middle">' +
                    (alta ? '▲' : '▼') + ' ' + mc.valor + '</text>';
            });
        }

        // Em outro gráfico (profundidade): só o INSTANTE. A altura da maré
        // está noutro eixo — desenhar 1,15 m num gráfico que vai de 5 a 7 m
        // colocaria a marca fora da área. O risco vertical deixa comparar
        // quando a maré DEVIA virar com quando a sondagem de fato virou, que
        // é o atraso do estuário.
        if (serie.marcas_tempo) {
            serie.marcas_tempo.forEach(function (mc) {
                if (mc.ts < t0 || mc.ts > t1) { return; }
                var xn = px(mc.ts);
                var x = xn.toFixed(1);
                // O horário encosta na linha, do lado que tiver espaço: perto
                // da borda direita o rótulo centralizado sairia do gráfico.
                var perto = xn > (W - DIR) - 46;
                dMarcas += '<line x1="' + x + '" y1="' + TOPO + '" x2="' + x + '" y2="' + (H - BASE) +
                    '" class="sal-gr__virada"/>' +
                    '<text x="' + (perto ? xn - 4 : xn + 4).toFixed(1) + '" y="' + (TOPO + 5) +
                    '" class="sal-gr__virada-txt" text-anchor="' + (perto ? 'end' : 'start') + '">' +
                    (mc.tipo === 'alta' ? '▲' : '▼') + ' ' + partes(mc.ts).hora + '</text>';
            });
        }

        var resumo = '';
        if (faixas.length) {
            var todos = [];
            faixas.forEach(function (b) { b.forEach(function (f) { todos.push(f[1], f[2]); }); });
            var lo = Math.min.apply(null, todos), hi = Math.max.apply(null, todos);
            // Arredondar para inteiro sempre transformava "5,4 a 5,9" em
            // "5–6", que não diz nada. A casa decimal entra quando a
            // amplitude é pequena o bastante para o inteiro apagá-la.
            var casas = (hi - lo) < 10 ? 1 : 0;
            resumo = '<span class="sal-gr__faixa">' +
                lo.toFixed(casas) + '–' + hi.toFixed(casas) + '</span>';
        }

        if (previsao.length > 1) {
            resumo += '<span class="sal-gr__legenda">maré prevista</span>';
        }

        return '<figure class="sal-gr">' +
            '<figcaption class="sal-gr__titulo"><span>' + escapar(serie.rotulo) + resumo + '</span>' +
            '<span class="sal-gr__agora">' + ultimo +
            '<small>' + escapar(serie.unidade) + '</small></span></figcaption>' +
            '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-label="' +
            escapar(serie.rotulo + ' em ' + serie.unidade) + '">' +
            '<defs><linearGradient id="' + id + '" x1="0" y1="0" x2="0" y2="1">' +
            '<stop offset="0%" stop-color="' + escapar(serie.cor) + '" stop-opacity="0.35"/>' +
            '<stop offset="100%" stop-color="' + escapar(serie.cor) + '" stop-opacity="0"/>' +
            '</linearGradient></defs>' +
            grade + marcas + fundo + dPrev +
            '<path d="' + dLinha.trim() + '" fill="none" stroke="' + escapar(serie.cor) +
            '" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>' +
            dMarcas +
            '</svg>' +
            (serie.previsao ? '<p class="sal-gr__mare-rodape" id="sal-balance-mare"></p>' : '') +
            '</figure>';
    }

    function desenharGraficos(dados) {
        if (!elGraficos) { return; }
        var html = '';
        if (dados && dados.series) {
            Object.keys(dados.series).forEach(function (chave) {
                html += svgGrafico(chave, dados.series[chave], dados.de_unix, dados.ate_unix);
            });
        }
        elGraficos.innerHTML = html ||
            '<p class="sal-balanco__vazio">Ainda não há histórico suficiente para traçar. ' +
            'Os gráficos aparecem quando o Balanço começar a transmitir.</p>';
        desenharMare();
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

    // Falha de rede NÃO apaga o que já está na tela. Antes, um soluço de
    // sinal zerava cartões e mapa até o ciclo seguinte — a página parecia
    // quebrada por um minuto inteiro. Dado velho visível é melhor que tela
    // em branco, desde que a página diga que está velho.
    function cicloVivo() {
        ultimaBusca = Date.now();
        return buscar(cfg.agora).then(function (agora) {
            if (!agora) {
                falhasSeguidas++;
                if (falhasSeguidas >= FALHAS_PARA_AVISAR) { marcarDesatualizado(); }
                return;
            }
            falhasSeguidas = 0;
            ultimoAgora = agora;
            desenharCartoes(agora);
            desenharMapa(agora, ultimaRota);
        });
    }

    function cicloPesado() {
        buscar(cfg.rota).then(function (rota) {
            if (!rota) { return; }
            ultimaRota = rota;
            desenharMapa(ultimoAgora, rota);
        });
        carregarSeries();
    }

    function marcarDesatualizado() {
        if (!elEstado || /sem conexão/.test(elEstado.textContent)) { return; }
        elEstado.textContent = texto(ultimoAgora) +
            ' Os dados podem estar desatualizados: sem conexão com o servidor.';
    }

    // Navegador estrangula (e às vezes congela) temporizador de aba em
    // segundo plano. Sem isto, voltar para a aba mostra o painel parado no
    // tempo até o próximo tique — que pode demorar minutos.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { return; }
        if (Date.now() - ultimaBusca < 20000) { return; }   // não martelar ao alternar abas
        cicloVivo();
        if (Date.now() - ultimaBusca > INTERVALO_PESADO) { cicloPesado(); }
    });

    cicloVivo();
    cicloPesado();
    setInterval(cicloVivo, INTERVALO_VIVO);
    setInterval(cicloPesado, INTERVALO_PESADO);
}());
