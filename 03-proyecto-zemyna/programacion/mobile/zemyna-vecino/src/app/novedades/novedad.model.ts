export interface Novedad {
  readonly id: number;
  readonly tipo: 'Incidencia';
  readonly titulo: string;
  readonly fecha: string;
  readonly direccion: string | null;
  readonly estado: 'Pendiente' | 'En Proceso' | 'Resuelta';
}
