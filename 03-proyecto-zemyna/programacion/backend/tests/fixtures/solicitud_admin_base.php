<?php
trait SolicitudAdminBase {
    protected function schema(): void {
        $this->db->exec("CREATE TABLE centro(id_centro INTEGER PRIMARY KEY,nombre TEXT,direccion TEXT);
            CREATE TABLE usuario(id_usuario INTEGER PRIMARY KEY,nombre TEXT,apellido TEXT,email TEXT,contrasena TEXT,fecha_registro TEXT,id_centro INTEGER,activo TEXT DEFAULT 'Activo');
            CREATE TABLE rol(id_rol INTEGER PRIMARY KEY,nombre TEXT);
            CREATE TABLE permiso(id_permiso INTEGER PRIMARY KEY,nombre TEXT);
            CREATE TABLE rol_permiso(id_rol INTEGER,id_permiso INTEGER);
            CREATE TABLE usuario_rol(id_usuario INTEGER,id_rol INTEGER,sector TEXT,fecha_desde TEXT,fecha_hasta TEXT);
            CREATE TABLE cuadrilla(id_cuadrilla INTEGER PRIMARY KEY,nombre TEXT,turno TEXT,id_centro INTEGER);
            CREATE TABLE usuario_cuadrilla(id_usuario_cuadrilla INTEGER PRIMARY KEY,id_usuario INTEGER,id_cuadrilla INTEGER,fecha_inicio TEXT,fecha_fin TEXT,id_usuario_asigna INTEGER,id_usuario_finaliza INTEGER);
            CREATE TABLE tipo_residuo(id_tipo_residuo INTEGER PRIMARY KEY,nombre TEXT);
            CREATE TABLE vehiculo(id_vehiculo INTEGER PRIMARY KEY,matricula TEXT,marca TEXT,modelo TEXT,capacidad_carga NUMERIC,id_tipo_residuo INTEGER,estado TEXT,activo INTEGER DEFAULT 1,funcion_operativa TEXT);
            CREATE TABLE usa(id_usa INTEGER PRIMARY KEY,id_cuadrilla INTEGER,id_vehiculo INTEGER);
            CREATE TABLE ruta(id_ruta INTEGER PRIMARY KEY,nombre TEXT,zona TEXT);
            CREATE TABLE recorrido(id_recorrido INTEGER PRIMARY KEY,id_ruta INTEGER,fecha_inicio TEXT,fecha_fin TEXT,estado TEXT);
            CREATE TABLE participa(id_participa INTEGER PRIMARY KEY,id_usa INTEGER,id_recorrido INTEGER,hora_inicio TEXT,hora_fin TEXT);
            CREATE TABLE incidencia(id_incidencia INTEGER PRIMARY KEY,tracking_number TEXT,descripcion TEXT,fecha_reporte TEXT,estado TEXT,prioridad TEXT,tipo_problema TEXT,id_cuadrilla INTEGER,id_ruta INTEGER);");
        createAsignacionVehiculoFixture($this->db);
    }
    protected function seed(): void {
        $this->db->exec("INSERT INTO centro(id_centro,nombre,direccion) VALUES(1,'Test','Test');
            INSERT INTO usuario(id_usuario,nombre,apellido,email,contrasena,fecha_registro,id_centro) VALUES
            (1,'Gestion','Test','uno@test.invalid','x','2020-01-01',1),(2,'Integrante','Test','dos@test.invalid','x','2020-01-01',1),(3,'Otro','Test','tres@test.invalid','x','2020-01-01',1),(4,'TI','Test','ti@test.invalid','x','2020-01-01',1);
            INSERT INTO rol(id_rol,nombre) VALUES(1,'ADMINISTRATIVO_OPERATIVO'),(2,'ADMINISTRADOR_TI');
            INSERT INTO permiso(id_permiso,nombre) VALUES(1,'incidencia.consultar'),(2,'incidencia.modificar'),(3,'recorrido.consultar'),(4,'recorrido.operar'),(5,'incidencia.operar'),(6,'asignacion_vehiculo.consultar'),(7,'asignacion_vehiculo.modificar');
            INSERT INTO rol_permiso VALUES(1,1),(1,2),(1,3),(1,4),(1,5),(1,6),(1,7);
            INSERT INTO usuario_rol(id_usuario,id_rol,sector,fecha_desde) VALUES(1,1,'OPERACIONES','2020-01-01'),(2,1,'OPERACIONES','2020-01-01'),(3,1,'OPERACIONES','2020-01-01'),(4,2,'TI','2020-01-01');
            INSERT INTO cuadrilla(id_cuadrilla,nombre,turno,id_centro) VALUES(1,'Uno','Matutino',1),(2,'Dos','Matutino',1);
            INSERT INTO usuario_cuadrilla(id_usuario,id_cuadrilla,fecha_inicio,id_usuario_asigna) VALUES(2,1,'2020-01-01',1),(3,2,'2020-01-01',1);
            INSERT INTO tipo_residuo(id_tipo_residuo,nombre) VALUES(1,'Test');
            INSERT INTO vehiculo(id_vehiculo,matricula,marca,modelo,capacidad_carga,id_tipo_residuo,estado,funcion_operativa) VALUES
            (1,'TEST1','Test','Test',1,1,'Disponible','REGULAR'),(2,'TEST2','Test','Test',1,1,'En Servicio','APOYO');
            INSERT INTO usa(id_usa,id_cuadrilla,id_vehiculo) VALUES(1,1,1),(2,2,2),(3,1,2);
            INSERT INTO ruta(id_ruta,nombre,zona) VALUES(1,'Test','Test');
            INSERT INTO recorrido(id_recorrido,id_ruta,fecha_inicio,estado) VALUES(1,1,'2020-01-01','Pendiente');
            INSERT INTO participa(id_participa,id_usa,id_recorrido,hora_inicio) VALUES(1,1,1,'08:00:00');
            INSERT INTO asignacion_vehiculo_operativa(id_asignacion_vehiculo,id_cuadrilla,id_vehiculo,fecha_inicio,id_usuario_asigna) VALUES(1,1,1,'2020-01-01',1),(2,2,2,'2020-01-01',1);
            INSERT INTO incidencia(id_incidencia,tracking_number,descripcion,fecha_reporte,estado,prioridad,tipo_problema,id_ruta) VALUES(1,'INC-TEST1','Test','2020-01-01','Pendiente','Alta','Contenedor Desbordado',1);");
    }

}
