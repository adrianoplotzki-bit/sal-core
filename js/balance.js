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

    /*
     * RÉGUA DE PERÍODO — dois controles, e a divisão é proposital.
     *
     * Os botões escolhem a DURAÇÃO da janela; a régua escolhe QUANDO ela cai.
     * Um `input[type=range]` tem uma alça só, e marcar começo e fim com dois
     * deslizadores é justamente o padrão que falha com o polegar num barco
     * balançando.
     *
     * `fimEscolhido` é o instante em que a janela TERMINA, ou null para "ao
     * vivo". A distinção não é cosmética:
     *
     * - Ao vivo, o pedido vai como `?janela=`, e quem calcula "agora" é o
     *   SERVIDOR. Se dependesse do relógio do aparelho, um celular adiantado
     *   uma hora pediria um pedaço de futuro e receberia gráfico vazio, sem
     *   nada na tela explicando por quê.
     * - Fixado, o pedido vai como `?de=&ate=`, e as pontas da régua são
     *   timestamps que vieram do servidor (`extensao`). O relógio de quem lê
     *   não entra na conta em nenhum dos dois casos.
     */
    var DURACOES = { '1h': 3600, '6h': 21600, '24h': 86400, '7d': 604800, '30d': 2592000, tudo: 0 };
    var PASSOS = 1000;
    var fimEscolhido = null;
    var extensao = null;
    var elRegua = document.getElementById('sal-balance-regua');
    var elQuando = document.getElementById('sal-balance-quando');
    var elIntervalo = document.getElementById('sal-balance-intervalo');
    var elAgora = document.getElementById('sal-balance-agora');
    var adiar = null;

    // "Já perguntei pela rota" — não "há rota". Fica true mesmo quando a
    // busca falha, senão uma queda de rede deixaria o mapa parado na vista
    // inicial para sempre, sem nunca enquadrar no barco.
    var rotaConhecida = false;
    var falhasSeguidas = 0, ultimaBusca = 0;

    // Depois de tantas falhas seguidas em /agora, o painel avisa. Três
    // minutos de silêncio: menos que isso pisca à toa em rede de celular.
    var FALHAS_PARA_AVISAR = 3;

    /*
     * O rótulo NÃO diz "a motor". Decisão do Adriano em 2026-08-06.
     *
     * Quem produz este estado é o `signalk-autostate`, no Cerbo, e ele marca
     * `motoring` sempre que o motor gira com o barco em movimento. Só que o
     * motor também é ligado para CARREGAR A BATERIA — e aí o painel afirmaria
     * uma escolha de navegação que não foi feita. Dizer só "navegando" é o
     * que se sabe com certeza: o barco está andando.
     *
     * `sailing` continua nomeando a vela porque ali não há ambiguidade: o
     * autostate só o emite com o motor desligado e o barco em movimento, e é
     * uma afirmação que o Adriano quer fazer.
     */
    var ESTADOS = {
        moored: 'atracado',
        anchored: 'fundeado',
        sailing: 'navegando à vela',
        motoring: 'navegando',
        'motor sailing': 'navegando',
        driving: 'navegando',
        'not under command': 'sem governo'
    };

    var VITAIS = [
        { chave: 'bateria_pct', rotulo: 'Bateria', unidade: '%' },
        { chave: 'profundidade_m', rotulo: 'Profundidade', unidade: 'm' },
        { chave: 'vento_no', rotulo: 'Vento', unidade: 'nós' },
        { chave: 'agua_c', rotulo: 'Água', unidade: '°C' },
        // Velocidade entrou em 2026-08-06, por decisão do Adriano. Até então
        // era retida pela regra da bolha — ver sal_core_metricas() no plugin.
        // O RUMO continua fora: é ele que transforma distância em posição.
        { chave: 'velocidade_no', rotulo: 'Velocidade', unidade: 'nós' }
    ];

    /*
     * ZOOM MÍNIMO 5 — não é gosto, é honestidade cartográfica.
     *
     * Boa parte dos fundeios está em água INTERIOR: Lagoa dos Patos, Guaíba,
     * Baía de Todos os Santos, Baía da Ilha Grande, Cananéia. O basemap do
     * OSM só desenha essas águas a partir do zoom 5 — no 4 elas viram terra
     * firme, e um ponto corretamente ancorado em Tapes aparece no meio do
     * Rio Grande do Sul.
     *
     * Foi medido em 2026-08-02 comparando o mesmo trecho nos dois zooms, e
     * as coordenadas foram conferidas por geocodificação reversa: **não há
     * erro de datum nem de projeção**, os pontos estão certos. O que erra
     * abaixo do 5 é o mapa de fundo.
     *
     * Soma-se a isso a escala: no zoom 4 o disco de 5px do marcador cobre
     * ~35 km, então até um ponto exatamente na linha de costa transborda
     * para dentro do continente.
     *
     * Um mapa que afirma "estive aqui" apontando para o sertão é pior que um
     * mapa que não deixa afastar tanto. Se um dia o basemap mudar, medir de
     * novo antes de baixar este número.
     */
    var ZOOM_MINIMO = 5;

    // Quantos lugares passados entram no enquadramento inicial. A costa
    // inteira vai de -35° a -9°: enquadrar tudo joga o mapa para o zoom 4,
    // que é justamente o que não se quer. Enquadrar SÓ o barco não mostraria
    // nada de onde ele já passou. O meio-termo é o barco mais a vizinhança.
    var LUGARES_NO_ENQUADRE = 12;

    // Teto do enquadramento automático: sem ele, um punhado de lugares
    // grudados levaria o mapa ao nível de rua já na abertura.
    var ZOOM_MAXIMO_INICIAL = 9;

    var mapa = L.map(elMapa, { scrollWheelZoom: false, minZoom: ZOOM_MINIMO })
        .setView([-15, -40], ZOOM_MINIMO);
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

        var raio = 'num raio de ' + agora.area.raio_km + ' km';
        if (agora.transmitindo) {
            return 'Posição aproximada, ' + raio + '.';
        }

        /*
         * DADO VELHO PRECISA DIZER QUE É VELHO.
         *
         * Até 2026-08-06 esta legenda dizia "Posição aproximada, num raio de
         * 78 km" tanto com o barco transmitindo quanto com a última leitura
         * de dois dias atrás. Os cartões (situação, bateria, profundidade)
         * também não mudavam. O único sinal era um PONTO que deixava de
         * acender — invisível para quem não sabe que ele existe.
         *
         * O Adriano estava navegando, viu a posição parada em Maceió e
         * concluiu que o site tinha quebrado. Ele não tinha quebrado: estava
         * mostrando fielmente a última leitura, e calando a idade dela.
         *
         * Preferir o dado antigo a apagar a tela continua certo (um soluço
         * de sinal não pode zerar o painel). O que faltava era o rótulo.
         */
        var quando = agora.atualizado_em && Date.parse(agora.atualizado_em);
        if (!quando) {
            return 'Última posição conhecida, ' + raio + ' — o Balanço não está transmitindo.';
        }

        var idade = Date.now() - quando;
        var p = partes(quando / 1000);
        var desde = idade < 36 * 3600e3
            ? 'às ' + p.hora
            : 'em ' + p.dia + ', às ' + p.hora;

        return 'Última posição conhecida, ' + raio + '. O Balanço não transmite desde ' +
               desde + '.';
    }

    /*
     * Os lugares mais próximos da referência — o barco, ou o fim da rota
     * publicada.
     *
     * Enquadrar TODOS os lugares levava o mapa ao zoom 4, onde o basemap
     * apaga as águas interiores e os pontos parecem estar em terra (ver
     * ZOOM_MINIMO). Enquadrar só o barco não mostraria nada de onde ele já
     * passou, que é o que o leitor quer ver. Abrir na vizinhança resolve os
     * dois: escala honesta, e história por perto. O resto da costa continua
     * a um zoom-out de distância.
     *
     * Sem referência — barco fora do ar e sem rota — cai no conjunto todo, e
     * aí quem segura a escala é o `minZoom` do mapa.
     *
     * A distância é euclidiana em graus, com a longitude corrigida pelo
     * cosseno da latitude. Não precisa ser geodésica: serve para ORDENAR, e
     * a ordem não muda. Sem a correção, um grau de longitude a -30° valeria
     * como um de latitude, e a comparação penalizaria o eixo errado.
     */
    function vizinhos(referencia, lugares) {
        if (!lugares.length || !referencia.length) { return lugares; }

        var ref = referencia[referencia.length - 1];
        var k = Math.cos(ref[0] * Math.PI / 180);

        return lugares.map(function (p) {
            var dy = p[0] - ref[0];
            var dx = (p[1] - ref[1]) * k;
            return { p: p, d: dy * dy + dx * dx };
        }).sort(function (a, b) {
            return a.d - b.d;
        }).slice(0, LUGARES_NO_ENQUADRE).map(function (x) {
            return x.p;
        });
    }

    function desenharMapa(agora, rota) {
        var limites = [];        // o que sempre entra no enquadramento
        var lugaresLatLon = [];  // os lugares passados, escolhidos à parte

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
                // Ponto pequeno com halo branco. Com 150 lugares numa costa,
                // círculos grandes e opacos se sobrepõem e viram uma mancha
                // contínua — deixam de dizer "estive aqui, aqui e aqui" e
                // passam a dizer "estive nessa região inteira", que é
                // justamente o que um marcador não deveria sugerir. O anel
                // claro separa vizinhos encostados sem aumentar o desenho.
                var m = L.circleMarker([lugar.lat, lugar.lon], {
                    radius: 3.5, color: '#ffffff', weight: 1.5, opacity: 0.9,
                    fillColor: '#ea580c', fillOpacity: 1
                });
                if (lugar.nome) { m.bindPopup(escapar(lugar.nome)); }
                lugaresLatLon.push([lugar.lat, lugar.lon]);
                return m;
            })).addTo(mapa);
        }

        if (agora && agora.area) {
            camadaArea = L.circle([agora.area.lat, agora.area.lon], {
                radius: agora.area.raio_km * 1000,
                color: '#dc2626', weight: 2,
                fillColor: '#dc2626', fillOpacity: 0.12
            }).addTo(mapa);
            // Com um período do passado fixado, o círculo continua desenhado
            // — tirar "onde ele está" do mapa seria pior — mas fica FORA do
            // enquadramento. Senão o mapa teria de conter o trecho de então e
            // a posição de agora ao mesmo tempo, e afastaria até caber os
            // dois, que é exatamente o oposto de aproximar num momento.
            if (fimEscolhido === null) {
                limites.push([agora.area.lat, agora.area.lon]);
            }
        }

        // Reenquadra só quando o que há para mostrar muda de fato. Com o
        // painel atualizando a cada minuto, um fitBounds por ciclo desfaria o
        // zoom e o arrasto do leitor sem parar.
        // O período entra na chave: dois trechos diferentes podem ter a MESMA
        // contagem de pontos, e sem ele o mapa ficaria parado no enquadramento
        // anterior ao arrastar a régua — parecendo que o controle não pegou.
        var chave = (agora && agora.area ? agora.area.lat + ',' + agora.area.lon + ',' + agora.area.raio_km : '-') +
            '|' + (rota && rota.pontos ? rota.pontos.length : 0) +
            '|' + (rota && rota.lugares ? rota.lugares.length : 0) +
            '|' + janelaAtual + '@' + fimEscolhido;

        // Só enquadra depois de saber se há lugares. `/agora` chega antes de
        // `/rota`, e enquadrar naquele instante daria um mapa só com o
        // círculo do barco, que salta para trás um segundo depois quando os
        // lugares chegam. "Ainda não sei" é diferente de "sei que não há" —
        // e a flag distingue os dois, inclusive quando a busca falha.
        if (chave !== chaveEnquadrada && rotaConhecida) {
            var enquadre = limites.concat(vizinhos(limites, lugaresLatLon));
            if (enquadre.length) {
                var caixa = L.latLngBounds(enquadre);
                // Estende pelo círculo inteiro, não só pelo centro dele:
                // senão o enquadramento cortaria metade da área publicada.
                if (camadaArea) { caixa = caixa.extend(camadaArea.getBounds()); }
                mapa.fitBounds(caixa, { padding: [24, 24], maxZoom: ZOOM_MAXIMO_INICIAL });
                chaveEnquadrada = chave;
            }
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

        var baixo = Math.min.apply(null, extremos);
        var alto = Math.max.apply(null, extremos);
        var min = baixo, max = alto;
        if (max - min < 1e-9) { min -= 1; max += 1; }   // série constante vira linha no meio
        var folga = (max - min) * 0.12;
        min -= folga; max += folga;

        // A folga não pode inventar valor impossível. Numa bateria a 100% ela
        // desenhava um eixo até 106,6%, e escala que passa do máximo faz
        // duvidar do número: se 106 existe, 100 não é cheio.
        //
        // O limite só entra quando os DADOS já estão dentro dele — assim ele
        // nunca corta a linha. Se um dia chegar leitura fora da faixa, o
        // gráfico a mostra inteira, que é como se percebe o defeito.
        var lim = serie.limites;
        if (lim) {
            if (lim[0] !== null && lim[0] !== undefined && baixo >= lim[0]) {
                min = Math.max(min, lim[0]);
            }
            if (lim[1] !== null && lim[1] !== undefined && alto <= lim[1]) {
                max = Math.min(max, lim[1]);
            }
            if (max - min < 1e-9) { max = min + 1; }   // tudo colado no limite
        }

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

        // A extensão do registro chega junto com as séries — é o que dá
        // pontas à régua sem uma segunda requisição só para isso.
        if (dados && dados.extensao) {
            extensao = dados.extensao;
            atualizarRegua();
        }

        var html = '';
        if (dados && dados.series) {
            Object.keys(dados.series).forEach(function (chave) {
                html += svgGrafico(chave, dados.series[chave], dados.de_unix, dados.ate_unix);
            });
        }

        // Vazio tem duas causas diferentes, e confundi-las faz o painel
        // parecer quebrado: "ainda não começou" é do site, "o barco não
        // transmitiu nessas horas" é do barco. Ao aproximar num silêncio, a
        // segunda é a resposta certa — e é uma resposta, não um defeito.
        elGraficos.innerHTML = html || (fimEscolhido !== null
            ? '<p class="sal-balanco__vazio">O Balanço não transmitiu nesse período. ' +
              'Arraste a régua ou escolha uma janela maior.</p>'
            : '<p class="sal-balanco__vazio">Ainda não há histórico suficiente para traçar. ' +
              'Os gráficos aparecem quando o Balanço começar a transmitir.</p>');
        desenharMare();
    }

    /* ----------------------------------------------------- régua e período */

    function duracao() { return DURACOES[janelaAtual] || 0; }

    // As pontas da régua: a janela mais antiga que cabe inteira no registro,
    // e a mais recente. Null quando não há como arrastar — histórico mais
    // curto que a própria janela, ou "Tudo", que já é o registro inteiro.
    function limitesRegua() {
        var d = duracao();
        if (!d || !extensao) { return null; }
        var min = extensao.de_unix + d;
        var max = extensao.ate_unix;
        return max - min > 60 ? { min: min, max: max } : null;
    }

    function intervaloAtual() {
        if (fimEscolhido === null) { return null; }
        var d = duracao();
        return d ? { de: fimEscolhido - d, ate: fimEscolhido } : null;
    }

    function paramsPeriodo() {
        var iv = intervaloAtual();
        return iv
            ? '?de=' + iv.de + '&ate=' + iv.ate
            : '?janela=' + encodeURIComponent(janelaAtual);
    }

    function rotuloIntervalo() {
        var iv = intervaloAtual();
        if (!iv) { return ''; }
        var a = partes(iv.de), b = partes(iv.ate);
        // Mesmo dia: repetir a data dos dois lados só rouba espaço na tela
        // estreita, que é onde este painel é lido.
        return a.dia === b.dia
            ? a.dia + ', ' + a.hora + ' às ' + b.hora
            : a.dia + ' ' + a.hora + ' → ' + b.dia + ' ' + b.hora;
    }

    function atualizarRegua() {
        if (!elRegua) { return; }
        var lim = limitesRegua();
        // Some quando não há o que arrastar — MAS não quando alguém chegou
        // por um link com período fixado e a janela dele não permite andar.
        // Esconder ali trancaria a pessoa no passado sem botão de volta.
        elRegua.hidden = !lim && fimEscolhido === null;
        if (elQuando) { elQuando.disabled = !lim; }
        if (elAgora) { elAgora.hidden = fimEscolhido === null; }
        if (elIntervalo) {
            elIntervalo.textContent = fimEscolhido === null
                ? 'Ao vivo — arraste para trás para ver um período anterior.'
                : rotuloIntervalo();
        }
        if (!lim || !elQuando) { return; }
        var v = fimEscolhido === null
            ? PASSOS
            : Math.round((fimEscolhido - lim.min) / (lim.max - lim.min) * PASSOS);
        elQuando.value = String(Math.max(0, Math.min(PASSOS, v)));
    }

    // O endereço carrega o período para o link poder ser compartilhado: "olha
    // o vento nessa tarde" vale muito mais que "abre e arrasta até uns três
    // dias atrás". replaceState, não pushState — arrastar a régua não é
    // navegar, e encher o histórico do navegador faria o botão Voltar do
    // celular deixar de sair da página.
    function gravarEndereco() {
        if (typeof history === 'undefined' || !history.replaceState) { return; }
        var h = '#p=' + janelaAtual + (fimEscolhido === null ? '' : '@' + fimEscolhido);
        try { history.replaceState(null, '', h); } catch (e) { /* about:blank etc. */ }
    }

    function lerEndereco() {
        if (typeof location === 'undefined' || !location.hash) { return; }
        var m = /[#&]p=([a-z0-9]+)(?:@(\d+))?/i.exec(location.hash);
        if (!m || !DURACOES.hasOwnProperty(m[1])) { return; }
        janelaAtual = m[1];
        fimEscolhido = m[2] ? parseInt(m[2], 10) : null;
        if (elPeriodo) {
            Array.prototype.forEach.call(elPeriodo.querySelectorAll('button'), function (b) {
                var ativo = b.dataset.janela === janelaAtual;
                b.classList.toggle('is-ativo', ativo);
                b.setAttribute('aria-pressed', ativo ? 'true' : 'false');
            });
        }
    }

    function recarregarPeriodo() {
        gravarEndereco();
        atualizarRegua();
        carregarSeries();
        carregarRota();
    }

    function carregarSeries() {
        if (elGraficos && !elGraficos.innerHTML) {
            elGraficos.innerHTML = '<p class="sal-balanco__vazio">Carregando histórico…</p>';
        }
        return buscar(cfg.series + paramsPeriodo()).then(desenharGraficos);
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

            // Trocar a duração pode deixar a janela fixada pendurada fora do
            // registro (30 dias terminando onde só cabiam 6 horas). Reancorar
            // nas pontas é mais previsível que recusar o clique.
            var lim = limitesRegua();
            if (fimEscolhido !== null && lim) {
                fimEscolhido = Math.max(lim.min, Math.min(lim.max, fimEscolhido));
            } else if (!lim) {
                fimEscolhido = null;
            }

            elGraficos.innerHTML = '';
            recarregarPeriodo();
        });
    }

    if (elQuando) {
        elQuando.addEventListener('input', function () {
            var lim = limitesRegua();
            if (!lim) { return; }
            var v = parseInt(elQuando.value, 10);
            // A ponta direita é "ao vivo", não "a janela que termina no último
            // ponto": é o que devolve o painel ao servidor como fonte do
            // "agora" e faz a atualização automática voltar a andar.
            fimEscolhido = v >= PASSOS ? null : Math.round(lim.min + (lim.max - lim.min) * (v / PASSOS));

            // O rótulo responde ao dedo; a busca espera a mão parar. Sem isso
            // um arrasto vira dezenas de requisições — e quem lê está no 4G do
            // barco, não no wi-fi de casa.
            if (elIntervalo) {
                elIntervalo.textContent = fimEscolhido === null
                    ? 'Ao vivo — arraste para trás para ver um período anterior.'
                    : rotuloIntervalo();
            }
            if (elAgora) { elAgora.hidden = fimEscolhido === null; }

            if (adiar) { clearTimeout(adiar); }
            adiar = setTimeout(function () { adiar = null; recarregarPeriodo(); }, 350);
        });
    }

    if (elAgora) {
        elAgora.addEventListener('click', function () {
            fimEscolhido = null;
            recarregarPeriodo();
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

    /*
     * O mapa segue a régua SÓ quando um momento é fixado.
     *
     * Ao vivo ele responde "onde ele está e por onde andou" — a travessia
     * inteira, que é a vista icônica do painel e não deveria encolher porque
     * alguém apertou "1 h" para olhar um gráfico. Com um período fixado a
     * pergunta muda para "onde ele estava naquele momento", e aí o recorte é
     * exatamente o que se quer ver.
     */
    function carregarRota() {
        var iv = intervaloAtual();
        return buscar(cfg.rota + (iv ? '?de=' + iv.de + '&ate=' + iv.ate : '')).then(function (rota) {
            var primeira = !rotaConhecida;
            rotaConhecida = true;
            if (!rota) {
                // Falhou. Redesenha assim mesmo na primeira vez, para o
                // enquadramento sair do lugar — com o que houver.
                if (primeira) { desenharMapa(ultimoAgora, ultimaRota); }
                return;
            }
            ultimaRota = rota;
            desenharMapa(ultimoAgora, rota);
        });
    }

    function cicloPesado() {
        carregarRota();
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
        // O intervalo é medido ANTES de `cicloVivo`, que reescreve
        // `ultimaBusca` logo na primeira linha. Medindo depois, a diferença
        // era sempre ~0 e `cicloPesado` nunca rodava aqui: quem voltasse à
        // aba depois de uma hora via os cartões atualizarem e os gráficos e a
        // rota continuarem parados no tempo, sem sinal nenhum de que estavam.
        var desdeUltima = Date.now() - ultimaBusca;
        if (desdeUltima < 20000) { return; }   // não martelar ao alternar abas
        cicloVivo();
        if (desdeUltima > INTERVALO_PESADO) { cicloPesado(); }
    });

    // O endereço antes da primeira busca: quem abre um link compartilhado tem
    // de cair direto no período dele, sem ver 24 h aparecerem e sumirem.
    lerEndereco();
    atualizarRegua();

    cicloVivo();
    cicloPesado();
    setInterval(cicloVivo, INTERVALO_VIVO);

    // O ciclo pesado continua rodando com o período FIXADO, e de propósito: o
    // produtor do barco guarda o que não conseguiu enviar e reenvia depois,
    // então um pedaço do passado ainda ganha pontos. Como o pedido leva o
    // mesmo `de`/`ate`, redesenhar não move a tela — é a diferença entre
    // atualizar o que se está olhando e ser puxado de volta para agora.
    setInterval(cicloPesado, INTERVALO_PESADO);
}());
