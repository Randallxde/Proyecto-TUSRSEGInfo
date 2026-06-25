 <!-- MODAL DETALLE SITIO -->
<div class="modal fade" id="modalSitio" tabindex="-1" aria-labelledby="modalSitioLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="modalSitioLabel">Detalle del sitio</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">

        <p><strong>Ubicación:</strong> <span id="modalUbicacion"></span></p>

        <!-- 🔥 MAPA -->
        <div id="mapaSitio" style="height: 300px; border-radius:10px;" class="mb-3"></div>

        <p><strong>Descripción:</strong></p>
        <p id="modalDescripcion" class="text-muted"></p>

        <p><strong>Precio de entrada:</strong> <span id="modalPrecio"></span></p>

        <p><strong>Valoración:</strong> <span id="modalRating"></span></p>

        <p><strong>Total valoraciones:</strong> <span id="modalCount"></span></p>

      </div>

    </main>
    <footer class="footer-turseginfo mt-5 py-4">
        <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
            <div><div class="fw-semibold">TurSegInfo</div><small class="text-muted">Plataforma turística con enfoque en experiencia, seguridad.</small></div>
            <div class="text-muted small"><i class="fa-regular fa-map me-1"></i>SCRUM TEAM 6</div>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="<?= e(url('assets/js/main.js')) ?>"></script>
</body>
</html>
