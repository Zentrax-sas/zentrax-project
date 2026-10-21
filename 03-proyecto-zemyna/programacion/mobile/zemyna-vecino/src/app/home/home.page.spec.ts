import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { afterEach } from 'vitest';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { HomePage } from './home.page';

@Component({ standalone: true, template: '<p>formulario de retiro de prueba</p>' })
class SolicitudRetiroDestinoTestPage {}

describe('HomePage', () => {
  let harness: RouterTestingHarness;

  afterEach(() => TestBed.resetTestingModule());

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      providers: [provideRouter([
        { path: 'home', component: HomePage },
        { path: 'solicitud-retiro', component: SolicitudRetiroDestinoTestPage },
        { path: 'consultar-estado', component: SolicitudRetiroDestinoTestPage },
        { path: 'buscar-contenedor', component: SolicitudRetiroDestinoTestPage },
      ])]
    }).compileComponents();
    harness = await RouterTestingHarness.create('/home');
  });

  it('should create', () => {
    expect(harness.routeDebugElement?.componentInstance).toBeInstanceOf(HomePage);
  });

  it('abre la identificación QR desde Home', async () => {
    (harness.routeNativeElement?.querySelector('button.qr') as HTMLButtonElement).click();
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/buscar-contenedor?metodo=qr');
  });

  it('navega desde la tarjeta de estado a la consulta', async () => {
    (harness.routeNativeElement?.querySelector('button.estado') as HTMLButtonElement).click();
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/consultar-estado');
  });

  it('navega desde la tarjeta de retiro al formulario', async () => {
    const button = harness.routeNativeElement?.querySelector('button.retiro') as HTMLButtonElement;
    button.click();
    await harness.fixture.whenStable();

    expect(TestBed.inject(Router).url).toBe('/solicitud-retiro');
    expect(harness.routeNativeElement?.textContent).toContain('formulario de retiro de prueba');
  });
});
