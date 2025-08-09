/* global L, Chart, jQuery, salCore */
jQuery(function ($) {
    var map, marker, chart;
    var history = [];
    function initMap() {
        var el = document.getElementById('sal-balance-map');
        if (!el) return;
        map = L.map(el).setView([0, 0], 2);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(map);
        marker = L.marker([0, 0]).addTo(map);
    }
    function initChart() {
        var canvas = document.getElementById('sal-balance-speed');
        if (!canvas) return;
        chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {
                        label: 'SOG (kt)',
                        data: [],
                        fill: false,
                        tension: 0.1
                    }
                ]
            },
            options: {
                scales: {
                    x: {
                        type: 'time',
                        time: { unit: 'minute' },
                        title: { display: true, text: 'Tempo' }
                    },
                    y: {
                        title: { display: true, text: 'SOG (kt)' }
                    }
                },
                plugins: {
                    legend: { display: true },
                    title: { display: true, text: 'Histórico de Velocidade' }
                }
            }
        });
    }
    function updateDisplay(point) {
        var lat = parseFloat(point.lat);
        var lon = parseFloat(point.lon);
        var sog = parseFloat(point.sog);
        if (!isNaN(lat) && !isNaN(lon)) {
            marker.setLatLng([lat, lon]);
            map.setView([lat, lon], 10);
        }
        var ts = new Date(point.ts + 'Z');
        history.push({ t: ts, y: sog });
        if (history.length > 60) history.shift();
        chart.data.labels = history.map(function (d) { return d.t; });
        chart.data.datasets[0].data = history.map(function (d) { return d.y; });
        chart.update();
    }
    function poll() {
        $.getJSON(salCore.restLast)
            .done(function (data) {
                if (data && data.ts) {
                    updateDisplay(data);
                }
            })
            .always(function () {
                setTimeout(poll, 30000);
            });
    }
    initMap();
    initChart();
    poll();
});