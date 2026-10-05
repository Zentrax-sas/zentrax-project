<?php
function createUploadTokenFixture(PDO $db): void {
    $db->exec('CREATE TABLE incidencia_upload_token (
        token_hash BLOB NOT NULL PRIMARY KEY CHECK(length(token_hash)=32),
        id_incidencia INTEGER NOT NULL REFERENCES incidencia(id_incidencia) ON DELETE CASCADE,
        id_usuario_emisor INTEGER REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
        fecha_creacion TEXT NOT NULL, fecha_expiracion TEXT NOT NULL,
        fecha_consumo TEXT, id_foto INTEGER UNIQUE REFERENCES foto(id_foto) ON DELETE SET NULL,
        CHECK(fecha_expiracion>fecha_creacion),
        CHECK((fecha_consumo IS NULL AND id_foto IS NULL) OR
            (fecha_consumo IS NOT NULL AND fecha_consumo>=fecha_creacion AND fecha_consumo<fecha_expiracion)))');
}
