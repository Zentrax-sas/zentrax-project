const map = L.map('mapa-vedette').setView([-34.9150, -56.1540], 14);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap contributors © CARTO'
}).addTo(map);

async function cargarContenedoresMapa() {
    try {
        const response = await fetch('/api/contenedores.php');
        const json = await response.json();

        if (!response.ok || !json.success) {
            throw new Error(json.message || 'Error al cargar contenedores');
        }

        const contenedores = json.data || [];
        contenedores.forEach(contenedor => {
            const lat = parseFloat(contenedor.latitud ?? contenedor.lat ?? -34.9150);
            const lng = parseFloat(contenedor.longitud ?? contenedor.lng ?? -56.1540);
            let colorNeon = '#555555';

            if (contenedor.estado === 'verde') colorNeon = '#a8e063';
            else if (contenedor.estado === 'amarillo') colorNeon = '#f1c40f';
            else if (contenedor.estado === 'rojo') colorNeon = '#e74c3c';

            const marcador = L.circleMarker([lat, lng], {
                radius: 8,
                fillColor: colorNeon,
                color: colorNeon,
                weight: 2,
                opacity: 1,
                fillOpacity: 0.6
            }).addTo(map);

            marcador.bindPopup(`<b>Contenedor:</b> ${contenedor.codigo || contenedor.id_contenedor}<br>Hacé clic para reportar.`);

            marcador.on('click', function() {
                document.getElementById('form-msg-vacio').style.display = 'none';
                document.getElementById('form-reporte').style.display = 'block';
                document.getElementById('form-id-contenedor').value = contenedor.id_contenedor;
                document.getElementById('form-direccion').value = contenedor.direccion;
            });
        });
    } catch (error) {
        console.error(error);
    }
}

cargarContenedoresMapa();