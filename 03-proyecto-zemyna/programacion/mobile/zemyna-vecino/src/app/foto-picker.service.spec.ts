import { TestBed } from '@angular/core/testing';
import { ActionSheetController } from '@ionic/angular';
import { vi } from 'vitest';
import { FOTO_CAMERA, FotoPickerService } from './foto-picker.service';

const cameraSources = { Camera: 'CAMERA', Photos: 'PHOTOS' };
const cameraErrors = { TakePhotoCancelled: 'OS-PLUG-CAMR-0006', ChooseMediaCancelled: 'OS-PLUG-CAMR-0020' };

describe('FotoPickerService', () => {
  let service: FotoPickerService;
  let botones: Array<{ text?: string; handler?: () => void }>;
  let opcion: string | null;
  let actionSheet: { present: ReturnType<typeof vi.fn>; onDidDismiss: ReturnType<typeof vi.fn> };
  const cameraMock = { getPhoto: vi.fn() };
  const cameraModuleMock = {
    Camera: cameraMock,
    CameraErrorCode: cameraErrors,
    CameraResultType: { Uri: 'uri' },
    CameraSource: cameraSources,
  };

  beforeEach(() => {
    botones = [];
    opcion = null;
    actionSheet = {
      present: vi.fn(async () => {
        if (opcion) botones.find(boton => boton.text === opcion)?.handler?.();
      }),
      onDidDismiss: vi.fn(async () => ({})),
    };
    TestBed.configureTestingModule({ providers: [
      { provide: FOTO_CAMERA, useValue: async () => cameraModuleMock },
      { provide: ActionSheetController, useValue: { create: vi.fn(async (config: { buttons: typeof botones }) => {
        botones = config.buttons;
        return actionSheet;
      }) } },
    ] });
    cameraMock.getPhoto.mockReset();
    service = TestBed.inject(FotoPickerService);
  });

  afterEach(() => { vi.restoreAllMocks(); TestBed.resetTestingModule(); });

  it.each([
    ['Sacar foto', cameraSources.Camera],
    ['Elegir de galería', cameraSources.Photos],
  ])('muestra la opción %s y convierte el resultado a File', async (texto, source) => {
    opcion = texto;
    cameraMock.getPhoto.mockResolvedValue({
      webPath: 'https://localhost/foto', format: 'jpeg', saved: false,
    });
    vi.stubGlobal('fetch', vi.fn(async () => ({
      ok: true,
      blob: async () => new Blob(['foto'], { type: 'image/jpeg' }),
    })));

    const file = await service.seleccionarFoto('incidencia');

    expect(botones.map(boton => boton.text)).toEqual(['Sacar foto', 'Elegir de galería', 'Cancelar']);
    expect(cameraMock.getPhoto).toHaveBeenCalledWith(expect.objectContaining({ source, saveToGallery: false }));
    expect(file).toBeInstanceOf(File);
    expect(file).toMatchObject({ type: 'image/jpeg', size: 4 });
    expect(file?.name).toMatch(/^foto-\d+\.jpg$/);
  });

  it('no abre cámara o galería si se cancela el Action Sheet', async () => {
    expect(await service.seleccionarFoto('incidencia')).toBeNull();
    expect(cameraMock.getPhoto).not.toHaveBeenCalled();
  });

  it('trata la cancelación de cámara como una salida normal', async () => {
    opcion = 'Sacar foto';
    cameraMock.getPhoto.mockRejectedValue({ code: cameraErrors.TakePhotoCancelled });
    await expect(service.seleccionarFoto('incidencia')).resolves.toBeNull();
  });

  it('recupera un resultado Android solo en el formulario que abrió la cámara', async () => {
    localStorage.setItem('zemyna:camera:pending-form', 'retiro');
    vi.stubGlobal('fetch', vi.fn(async () => ({
      ok: true,
      blob: async () => new Blob(['foto'], { type: 'image/jpeg' }),
    })));
    await service.recibirResultadoRestaurado({
      pluginId: 'Camera', methodName: 'getPhoto', success: true,
      data: { webPath: 'https://localhost/restored-photo', format: 'jpeg', saved: false },
    });

    expect(await service.consumirFotoRestaurada('incidencia')).toBeNull();
    expect(await service.consumirFotoRestaurada('retiro')).toMatchObject({ type: 'image/jpeg', size: 4 });
  });
});