import { Component, input, output } from '@angular/core';
import { ContenedorPublico } from './contenedores.service';

@Component({
  selector: 'app-resultados-contenedores',
  standalone: true,
  template: `
    <ul aria-label="Contenedores encontrados">
      @for (contenedor of contenedores(); track contenedor.id_contenedor) {
        <li><button type="button" (click)="seleccionar.emit(contenedor)">
          <strong>{{ contenedor.direccion }}</strong>
          <span>{{ contenedor.codigo }}</span>
          @if (distancia(contenedor); as valor) { <span>Distancia: {{ valor }}</span> }
        </button></li>
      }
    </ul>
  `,
  styles: `
    ul { list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 8px; }
    button { width: 100%; padding: 12px; border: 1px solid #36403b; border-radius: 10px;
      background: #151a18; color: #f4f7f5; text-align: left; cursor: pointer; font: inherit; }
    strong, span { display: block; overflow-wrap: anywhere; }
    strong { font-size: 14px; }
    span { margin-top: 4px; color: #c0c9c4; font-size: 12px; }
    button:focus-visible { outline: 2px solid #a8e600; outline-offset: 2px; }
  `,
})
export class ResultadosContenedoresComponent {
  contenedores = input.required<ContenedorPublico[]>();
  distancias = input<Map<string, number> | null>(null);
  seleccionar = output<ContenedorPublico>();

  distancia(contenedor: ContenedorPublico): string | null {
    const metros = this.distancias()?.get(String(contenedor.id_contenedor));
    if (metros === undefined) return null;
    return metros < 1000 ? `${Math.round(metros)} m` : `${(metros / 1000).toFixed(2)} km`;
  }
}
