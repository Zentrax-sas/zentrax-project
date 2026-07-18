# ZEMYNA 🚛🌱
> **Plataforma Ciudadana y de Gestión para la Optimización de Residuos Urbanos**
> Proyecto desarrollado para la carrera de Ingeniería de Software / Tecnicatura en Informática - UTU (Uruguay).

---

## 📋 Descripción del Proyecto
**Zemyna** es una plataforma web integral diseñada para digitalizar, optimizar y transparentar la gestión de residuos urbanos y el mantenimiento de contenedores en la ciudad de Montevideo. 

El sistema divide sus operaciones en dos grandes mundos conectados en tiempo real:
1. **Plataforma Ciudadana (Frontend Vecino):** Una interfaz web limpia, moderna y adaptada a la identidad visual del entorno. Permite a los vecinos de Montevideo reportar de forma interactiva contenedores saturados, roturas o residuos fuera de lugar directamente mapeados sobre su municipio (ej. Municipio CH).
2. **Sistema de Gestión de Operaciones (Backend / API):** Un panel de control (Backoffice) robusto destinado a los administradores del sistema, choferes de camiones recolectores y despachadores de la intendencia, permitiendo coordinar de manera eficiente el flujo logístico de la ciudad.

---

## 🏛️ Arquitectura del Sistema
El backend está construido bajo el patrón de diseño **MVC (Modelo-Vista-Controlador)** utilizando **PHP 8** nativo de forma modular y orientada a servicios (API REST).

La arquitectura se divide en tres capas fundamentales que garantizan el desacoplamiento:
*   **Ventanilla API (`/api`):** Funciona como la puerta de entrada pública de las solicitudes HTTP. Define las cabeceras de comunicación (`JSON`), gestiona las políticas de seguridad `CORS` y enruta los métodos (`GET`, `POST`, `PUT`, `DELETE`) hacia su controlador correspondiente.
*   **Controladores (`/controllers`):** Actúan como los mozos del sistema. Contienen la lógica de negocio, validan las entradas de datos que vienen de internet, coordinan los procesos de seguridad (como el encriptado de credenciales mediante `password_hash` con BCRYPT) y empaquetan las respuestas para el frontend.
*   **Modelos (`/models`):** Son las clases operativas encargadas de estructurar las entidades del negocio y comunicarse directamente con el motor de base de datos a través de sentencias preparadas (PDO) contra inyecciones SQL.

---

## 🛠️ Módulos de Gestión Implementados (Primera Entrega)

### 1. Gestión de Usuarios y Roles (`Usuario`)
*   **Interfaz:** Centralizada dinámicamente mediante el inicio de sesión.
*   **Roles del Negocio:** Administrador del Sistema (`superadmin`), Despachador de Oficina (`dispatcher`), y Chofer de Flota (`driver`).
*   **Atributos:** `id_usuario`, `nombre`, `email`, `password`, `id_rol`.

### 2. Gestión de Infraestructura Urbana (`Contenedor`)
*   **Interfaz:** Integrada con la selección territorial de Montevideo para visualizar novedades localizadas.
*   **Atributos:** `id_contenedor`, `direccion`, `capacidad_litros`, `tipo_residuo` *(Orgánico/Reciclable/Vidrio)*, `estado_llenado` *(Vacío/Medio/Lleno/Saturado)*, `municipio`.

### 3. Gestión de Flota Logística (`Camion`)
*   **Interfaz:** Panel de control de la maestranza municipal para el monitoreo de vehículos de recolección.
*   **Atributos:** `id_camion`, `matricula` *(Formato STM)*, `capacidad_toneladas`, `estado` *(Operativo/En Taller/En Ruta/Fuera de Servicio)*, `id_chofer_asignado`.

---

## 🌐 Endpoints de la API de Gestión
Todos los endpoints aceptan e intercambian información exclusivamente en formato **JSON**. Para esta primera entrega, y debido a los requerimientos de la letra, los métodos de persistencia se encuentran simulados (`Mock Data`) con respuestas estáticas realistas de Montevideo para viabilizar el testeo completo del Frontend.

| Entidad | Endpoint | Método HTTP | Descripción |
| :--- | :--- | :--- | :--- |
| **Usuarios** | `/api/usuario.php` | `GET` | Lista todos los empleados asignados. |
| | `/api/usuario.php` | `POST` | Da de alta un nuevo empleado (Admin). |
| **Contenedores**| `/api/contenedor.php` | `GET` | Devuelve los puntos de reciclaje del mapa. |
| | `/api/contenedor.php` | `POST` | Registra un nuevo contenedor urbano. |
| **Camiones** | `/api/camion.php` | `GET` | Monitorea el estado de la flota de recolección. |
| | `/api/camion.php` | `POST` | Registra una nueva unidad compactadora. |

---

## 🚀 Instalación y Despliegue Local

### Requisitos Previos:
*   Servidor local con soporte para **PHP 8.0 o superior** (XAMPP, Laragon, WampServer).
*   Navegador web moderno.

### Pasos para ejecución:
1. Clonar este repositorio dentro de la carpeta raíz de tu servidor local (ej. `htdocs` o `www`).
2. Levantar el servicio de Apache desde el panel de control de tu servidor.
3. Abrir en el navegador la URL del frontend del vecino: `http://localhost/tu-carpeta-proyecto/frontend/public/landing.html`.
4. Para realizar pruebas directas sobre la **API de Gestión**, podés consumir los endpoints listados arriba mediante clientes HTTP como **Postman**, **Thunder Client** (en VS Code) o comandos `fetch()` nativos de JavaScript.

---
*Desarrollado con dedicación y compromiso por el equipo de Zemyna en UTU - 2026.*
