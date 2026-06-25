document.addEventListener('DOMContentLoaded', () => {
    console.log("JS CARGADO ✅");

    // 🔴 LÍNEAS TEMPORALES PARA DEPURAR EN EL CELULAR:
    alert("¿Es un Arreglo?: " + Array.isArray(window.turSegInfoPlaces));
    alert("Datos recibidos: " + JSON.stringify(window.turSegInfoPlaces));
    
    // ... el resto de tu código de validación y mapa ...

// ✅ CORREGIDO: 'document' en minúscula
document.addEventListener('DOMContentLoaded', () => {

    console.log("JS CARGADO ✅");

    // ✅ 1. Validación de formularios (Bootstrap)
    document.querySelectorAll('.needs-validation').forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });

    // ✅ 2. Inicialización del Mapa
    const mapEl = document.getElementById('mapa');

    if (mapEl && typeof L !== 'undefined') {
        try {
            const mapa = L.map('mapa').setView([4.5709, -74.2973], 5);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(mapa);

            const places = Array.isArray(window.turSegInfoPlaces) ? window.turSegInfoPlaces : [];

            places.forEach(place => {
                if (!place) return;

                const lat = parseFloat(place.lat);
                const lng = parseFloat(place.lng);

                if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

                const name = escapeHtml(place.name ?? '');
                const city = escapeHtml(place.city_name ?? '');
                const dept = escapeHtml(place.department_name ?? '');
                const rating = Number(place.average_rating || 0).toFixed(1);

                const popupContent = `
                    <div style="min-width:180px;">
                        <strong>${name}</strong><br>
                        <small>${city}${city && dept ? ' · ' : ''}${dept}</small><br>
                        ⭐ ${rating}
                    </div>
                `;

                const marker = L.marker([lat, lng])
                    .addTo(mapa)
                    .bindPopup(popupContent);

                // 🔥 CLICK EN MARCADOR
                marker.on('click', () => {
                    console.log("CLICK MARCADOR ✅");

                    llenarModal({
                        city,
                        dept,
                        description: place.description,
                        price: place.entry_cost,
                        rating,
                        reviews: place.rating_count,
                        map_url: place.map_url
                    });
                });

            });

            setTimeout(() => {
                mapa.invalidateSize();
            }, 200);

        } catch (error) {
            console.error("Error mapa:", error);
        }
    }


    // 🔥 3. CLICK GLOBAL (SOLUCIÓN DEFINITIVA)
    document.addEventListener('click', function (e) {
        const card = e.target.closest('.sitio-card');
        if (!card) return;

        console.log("CLICK TARJETA ✅");

        e.preventDefault();
        e.stopPropagation();

        const data = {
            city: card.dataset.city,
            dept: card.dataset.department,
            description: card.dataset.description,
            price: card.dataset.cost,
            rating: parseFloat(card.dataset.rating || 0).toFixed(1),
            reviews: card.dataset.reviews,
            map_url: null
        };

        llenarModal(data);
    });

});


// 🔥 FUNCIÓN MODAL (ROBUSTA Y OPTIMIZADA)
function llenarModal(data) {

    console.log("ABRIENDO MODAL ✅", data);

    const modalEl = document.getElementById('modalSitio');

    if (!modalEl) {
        console.error("❌ NO EXISTE EL MODAL");
        return;
    }

    const ubicacionEl = document.getElementById('modalUbicacion');

    if (data.map_url) {
        ubicacionEl.innerHTML = `
            ${data.city}, ${data.dept}<br>
            <a href="${data.map_url}" target="_blank">Ver en Google Maps</a>
        `;
    } else {
        ubicacionEl.textContent = `${data.city}, ${data.dept}`;
    }

    document.getElementById('modalDescripcion').textContent =
        data.description ?? 'Sin descripción';

    document.getElementById('modalPrecio').textContent =
        data.price && data.price !== "0"
            ? `$${Number(data.price).toLocaleString()}`
            : 'Gratis';

    document.getElementById('modalRating').textContent =
        data.rating ?? '0';

    document.getElementById('modalCount').textContent =
        data.reviews ?? '0';

    // ✅ OPTIMIZADO: Reutiliza la instancia del modal si ya existe para evitar fugas de memoria o bugs de backdrop
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl, {
        backdrop: true
    });

    modal.show();
}


// ✅ Seguridad
function escapeHtml(str) {
    return String(str)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
    