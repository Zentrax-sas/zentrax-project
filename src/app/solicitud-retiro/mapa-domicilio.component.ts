import { Component, input, output } from '@angular/core';

// Misma cartografía/proyección que los mapas Zemyna; sin dependencia de contenedores.
@Component({selector: 'app-mapa-domicilio', standalone: true,
  template: `
    <div class="controles"><button type="button" (click)="zoom = Math.min(19, zoom + 1)" aria-label="Acercar">+</button><button type="button" (click)="zoom = Math.max(12, zoom - 1)" aria-label="Alejar">−</button></div>
    <div class="mapa" (pointerdown)="inicio($event)" (pointerup)="fin($event)" (pointercancel)="arrastre = null">
      <svg viewBox="0 0 320 320" aria-label="Mapa del lugar de retiro" role="img">
        @for (tile of tiles; track tile.url) { <image (error)="fallo.emit()" [attr.href]="tile.url" [attr.x]="tile.x" [attr.y]="tile.y" width="256" height="256"/> }
      </svg>
      <span class="pin" aria-label="Punto de retiro">●</span>
    </div>
    @if (editable()) { <p>Arrastrá el mapa para colocar el punto sobre el lugar del retiro.</p> }
    <div class="ajuste" aria-label="Corregir el punto con flechas">
      @if (editable()) { <button type="button" (click)="mover(0,-24)" aria-label="Mover al norte">↑</button><button type="button" (click)="mover(-24,0)" aria-label="Mover al oeste">←</button><button type="button" (click)="mover(24,0)" aria-label="Mover al este">→</button><button type="button" (click)="mover(0,24)" aria-label="Mover al sur">↓</button> }
    </div>
    <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap contributors</a>`,
  styles: `:host{display:block}.mapa{position:relative;width:min(100%,300px);aspect-ratio:1;margin:8px auto;overflow:hidden;border-radius:12px;touch-action:none;background:#263416}svg{width:100%;height:100%;display:block}.pin{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);display:grid;place-items:center;width:32px;height:32px;border:3px solid #0b0f0d;outline:2px solid #a8e600;border-radius:50%;color:#0b0f0d;background:#a8e600;pointer-events:none}.controles,.ajuste{display:flex;gap:6px}button{min-width:44px;min-height:44px;background:#151c19;color:#a8e600;border:1px solid #36403b;border-radius:8px}button:focus-visible{outline:2px solid #a8e600}a,p{color:#c0c9c4;font-size:12px}p{margin:6px 0}`})
export class MapaDomicilioComponent {
  fallo = output<void>();
  Math = Math;
  latitud = input.required<number>(); longitud = input.required<number>(); editable = input(false);
  cambiar = output<{latitud: number; longitud: number}>(); zoom = 17;
  arrastre: {id: number; x: number; y: number; width: number} | null = null;
  private get escala() { return 256 * 2 ** this.zoom; }
  private get centro() { const sin = Math.sin(this.latitud()*Math.PI/180); return [(this.longitud()+180)/360*this.escala,(.5-Math.log((1+sin)/(1-sin))/(4*Math.PI))*this.escala]; }
  get tiles() { const [x,y]=this.centro; const result=[]; for(let a=Math.floor((x-160)/256);a<=Math.floor((x+160)/256);a++)for(let b=Math.floor((y-160)/256);b<=Math.floor((y+160)/256);b++) result.push({url:`https://tile.openstreetmap.org/${this.zoom}/${a}/${b}.png`,x:a*256-x+160,y:b*256-y+160}); return result; }
  mover(dx: number, dy: number) { if(!this.editable())return;const [x,y]=this.centro;this.cambiar.emit({latitud:Math.max(-85,Math.min(85,Math.atan(Math.sinh(Math.PI*(1-2*(y+dy)/this.escala)))*180/Math.PI)),longitud:((x+dx)/this.escala*360+360)%360-180}); }
  inicio(e: PointerEvent) { if(!this.editable()||e.button!==0)return;const element=e.currentTarget as HTMLElement;this.arrastre={id:e.pointerId,x:e.clientX,y:e.clientY,width:element.getBoundingClientRect().width};element.setPointerCapture(e.pointerId); }
  fin(e: PointerEvent) { const a=this.arrastre;this.arrastre=null;if(a&&a.id===e.pointerId&&a.width>0)this.mover((a.x-e.clientX)*320/a.width,(a.y-e.clientY)*320/a.width); }
}
