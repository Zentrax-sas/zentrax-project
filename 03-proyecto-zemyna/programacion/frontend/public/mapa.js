// 1. Inicializamos el mapa centrado en las coordenadas aproximadas del Municipio CH (Pocitos) con un zoom de 14
const map = L.map('mapa-vedette').setView([-34.9150, -56.1540], 14);

// 2. Cargamos la capa de diseño del mapa (OpenStreetMap)
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// 3. Aquí va tu "Mock" de datos reales del CSV (Reemplazá estas coordenadas por las que conseguiste)


// 4. Recorremos los contenedores simulados y los clavamos en el mapa
// Tu "Mock" con los códigos reales del CSV de la IMM pero con coordenadas de Pocitos



// Cambiamos el fondo tradicional por uno oscuro que combina con var(--dark-bg)
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap contributors © CARTO'
}).addTo(map);

// Tus contenedores simulados reales del Municipio CH
// Los 3 que ya tenías + 17 nuevos para completar los 20 del Municipio CH
const contenedoresSimulados = [
    { id: "CH_DU_RM_CL_101", direccion: "Bulevar España y Juan Benito Blanco", lat: -34.9142, lng: -56.1495, estado: "verde" },
    { id: "CH_DU_RM_CL_102", direccion: "21 de Septiembre y Ellauri", lat: -34.9210, lng: -56.1585, estado: "rojo" },
    { id: "CH_DU_RM_CL_103", direccion: "Luis Alberto de Herrera y 26 de Marzo", lat: -34.9058, lng: -56.1362, estado: "amarillo" },
    
    // --- NUEVOS CONTENEDORES ---
    // Zona Pocitos / Punta Carretas
    { id: "CH_DU_RM_CL_104", direccion: "José Ellauri y Joaquín Nuñez", lat: -34.9242, lng: -56.1612, estado: "verde" },
    { id: "CH_DU_RM_CL_105", direccion: "Bulevar Artigas y Parva Domus", lat: -34.9228, lng: -56.1664, estado: "verde" },
    { id: "CH_DU_RM_CL_106", direccion: "Gabriel Pereira y Lázaro Gadea", lat: -34.9115, lng: -56.1481, estado: "amarillo" },
    
    { id: "CH_DU_RM_CL_108", direccion: "Miguel Barreiro y Berro", lat: -34.9103, lng: -56.1465, estado: "gris" }, // Roto

    // Zona Parque Rodó / Cordón Sur
    { id: "CH_DU_RM_CL_109", direccion: "Avenida Gonzalo Ramírez y Joaquín de Salterain", lat: -34.9125, lng: -56.1691, estado: "verde" },
    { id: "CH_DU_RM_CL_110", direccion: "Bulevar España y Acevedo Díaz", lat: -34.9044, lng: -56.1642, estado: "amarillo" },
    { id: "CH_DU_RM_CL_111", direccion: "Lauro Müller y Juan Manuel Blanes", lat: -34.9148, lng: -56.1715, estado: "rojo" },

    // Zona Buceo
    { id: "CH_DU_RM_CL_112", direccion: "Avenida Rivera y Solano López", lat: -34.8985, lng: -56.1251, estado: "verde" },
    { id: "CH_DU_RM_CL_113", direccion: "Rambla República de Chile y Comercio", lat: -34.9031, lng: -56.1268, estado: "amarillo" },
    { id: "CH_DU_RM_CL_114", direccion: "Santiago Rivas y Demóstenes", lat: -34.8992, lng: -56.1345, estado: "rojo" },

    // Zona Tres Cruces / La Blanqueada
    { id: "CH_DU_RM_CL_115", direccion: "Avenida Italia y Las Heras", lat: -34.8899, lng: -56.1532, estado: "verde" },
    { id: "CH_DU_RM_CL_116", direccion: "Bulevar Artigas y Miguelete", lat: -34.8865, lng: -56.1651, estado: "rojo" },
    { id: "CH_DU_RM_CL_117", direccion: "Avenida 8 de Octubre y Mariano Moreno", lat: -34.8841, lng: -56.1518, estado: "amarillo" },
    { id: "CH_DU_RM_CL_118", direccion: "Garibaldi y Monte Caseros", lat: -34.8828, lng: -56.1589, estado: "gris" }, // Roto

    // Zona Parque Batlle
    { id: "CH_DU_RM_CL_119", direccion: "Avenida Ricaldoni y Lorenzo Merola", lat: -34.8945, lng: -56.1555, estado: "verde" },
    { id: "CH_DU_RM_CL_120", direccion: "Francisco Soca y Libertad", lat: -34.9048, lng: -56.1519, estado: "amarillo" }
];

// Recorremos los contenedores para dibujarlos
// Recorremos los contenedores para dibujarlos con estilo Neón
contenedoresSimulados.forEach(contenedor => {
    
    // Asignamos el color real de neón según el estado del contenedor
    let colorNeon;
    if (contenedor.estado === "verde") colorNeon = "#a8e063";       // Tu verde neón (Limpio)
    else if (contenedor.estado === "amarillo") colorNeon = "#f1c40f";  // Amarillo (Próximo a vencer)
    else if (contenedor.estado === "rojo") colorNeon = "#e74c3c";      // Rojo (Incidencia/Pendiente)
    else colorNeon = "#555555";                                       // Gris/Negro (Roto)

    // En vez de L.marker, usamos L.circleMarker para que parezca una pantalla de radar inteligente
    const marcador = L.circleMarker([contenedor.lat, contenedor.lng], {
        radius: 8,          // Tamaño del circulito
        fillColor: colorNeon,
        color: colorNeon,   // Color del borde
        weight: 2,
        opacity: 1,
        fillOpacity: 0.6    // Un toque transparente adentro para que se vea pro
    }).addTo(map);
    
    // El cartelito emergente
    marcador.bindPopup(`<b>Contenedor:</b> ${contenedor.id}<br>Hacé clic afuera para reportar.`);

    // La magia del clic para rellenar el formulario sigue igual
    marcador.on('click', function() {
        document.getElementById('form-msg-vacio').style.display = 'none';
        document.getElementById('form-reporte').style.display = 'block';
        document.getElementById('form-id-contenedor').value = contenedor.id;
        document.getElementById('form-direccion').value = contenedor.direccion;
    });
});