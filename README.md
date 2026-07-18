# ZEMYNA 🚛🌱
### *by Zentrax*

> **Plataforma Ciudadana y de Gestión para la Optimización de Residuos Urbanos**
> Proyecto Académico de Ingeniería de Software - UTU (Uruguay).

---

## 📋 Descripción General
**Zemyna** es una solución integral diseñada por **Zentrax** para optimizar la gestión de residuos urbanos y la interacción ciudadana. El ecosistema conecta en tiempo real el reporte interactivo de los vecinos con un panel de administración logística y de infraestructura de la ciudad.

---

## 🛠️ Arquitectura Técnica y Escalabilidad
Para garantizar que el sistema pueda crecer de forma sostenible y soportar múltiples módulos futuros, se definió una arquitectura modular basada en servicios independientes:

*   **Patrón de Diseño:** **MVC (Modelo-Vista-Controlador)** en **PHP 8** nativo, asegurando la separación limpia entre la base de datos (Modelos), la lógica operativa (Controladores) y la interfaz de usuario (Vistas).
*   **Comunicación:** El sistema se comporta como una **API RESTful**, lo que significa que el backend expone "ventanillas de servicio" estandarizadas que intercambian datos exclusivamente en formato **JSON**. Esto permite que el día de mañana la misma lógica sirva tanto para la web como para aplicaciones móviles o sistemas externos.
*   **Infraestructura de Grado Producción:** Pensando en los entornos reales de despliegue, el proyecto está estructurado para su contenedorización mediante **Docker** y su ejecución sobre entornos estables de arquitectura empresarial como **Rocky Linux**.

---

## 📂 Estructura General del Proyecto
El núcleo del backend está organizado de forma que añadir una nueva funcionalidad sea tan simple como replicar la estructura base:

*   `/api/`: Endpoints públicos encargados de recibir las peticiones HTTP y controlar las cabeceras de red (`CORS`, métodos de acceso).
*   `/controllers/`: Componentes encargados de validar datos, aplicar reglas de negocio y encriptar credenciales de seguridad (`password_hash`).
*   `/models/`: Clases dedicadas a la estructura de las entidades y la ejecución de consultas seguras (PDO) contra la base de datos.

---

## 🚀 Despliegue Local Rápido

### Requisitos Mínimos:
*   Servidor local con soporte para **PHP 8.0 o superior** (XAMPP, Laragon, etc.).

### Pasos:
1. Clonar este repositorio dentro del directorio raíz de tu servidor web local (`htdocs` o `www`).
2. Iniciar el servicio Apache.
3. Acceder al punto de entrada del frontend en el navegador: `http://localhost/tu-carpeta/frontend/public/landing.html`.

---
## 👥 Sobre el Equipo de Desarrollo
Este ecosistema de software es diseñado, mantenido y escalado de forma iterativa por **Zentrax**, combinando tecnología, diseño responsivo y conectividad moderna.

*Zentrax - Montevideo, 2026.*
