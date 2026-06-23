#!/bin/bash

echo "Creando estructura..."

mkdir -p 01-emprendedurismo-zentrax/{identidad-corporativa,constitucion-empresa,planificacion,marketing,documentacion-comercial,documentacion-interna,evidencias}

mkdir -p 03-proyecto-zemyna/relevamiento/{entrevistas,encuestas,observaciones}

mkdir -p 03-proyecto-zemyna/ingenieria-software/{metodologia,requisitos,casos-de-uso}

mkdir -p 03-proyecto-zemyna/testing

mkdir -p 03-proyecto-zemyna/programacion/base-datos

echo "Moviendo documentos de emprendedurismo..."

mv "01-emprendedurismo-zentrax/Diseño y fundamentación del logo.pdf" \
   "01-emprendedurismo-zentrax/identidad-corporativa/" 2>/dev/null

mv "01-emprendedurismo-zentrax/Mision, visión, etc.pdf" \
   "01-emprendedurismo-zentrax/identidad-corporativa/" 2>/dev/null

mv "01-emprendedurismo-zentrax/Estatuto Social – Zentrax SAS.pdf" \
   "01-emprendedurismo-zentrax/constitucion-empresa/" 2>/dev/null

mv "01-emprendedurismo-zentrax/SAS-constitucion-2020.pdf" \
   "01-emprendedurismo-zentrax/constitucion-empresa/" 2>/dev/null

mv "01-emprendedurismo-zentrax/Inscripción de una sociedad por acciones simplificada en formación (SAS) _ Trámites.pdf" \
   "01-emprendedurismo-zentrax/constitucion-empresa/" 2>/dev/null

mv "01-emprendedurismo-zentrax/Diagrama de Gantt.jpeg" \
   "01-emprendedurismo-zentrax/planificacion/" 2>/dev/null

mv "01-emprendedurismo-zentrax/Resumen Ejecutivo – Zentrax SAS.pdf" \
   "01-emprendedurismo-zentrax/documentacion-comercial/" 2>/dev/null

mv "01-emprendedurismo-zentrax/Link redes.pdf" \
   "01-emprendedurismo-zentrax/marketing/" 2>/dev/null

mv "01-emprendedurismo-zentrax/caratula.jpeg" \
   "01-emprendedurismo-zentrax/evidencias/" 2>/dev/null

mv "01-emprendedurismo-zentrax/equipo de trabajo.jpeg" \
   "01-emprendedurismo-zentrax/evidencias/" 2>/dev/null

mv "01-emprendedurismo-zentrax/primera reunion.jpeg" \
   "01-emprendedurismo-zentrax/evidencias/" 2>/dev/null

echo "Moviendo documentación interna..."

mv "04-docs/Reglamento interno.docx" \
   "01-emprendedurismo-zentrax/documentacion-interna/" 2>/dev/null

echo "Reorganizando Ingeniería de Software..."

mv "03-proyecto-zemyna/ingenieria-software/metodologia.md" \
   "03-proyecto-zemyna/ingenieria-software/metodologia/" 2>/dev/null

mv "03-proyecto-zemyna/ingenieria-software/ieee830.md" \
   "03-proyecto-zemyna/ingenieria-software/requisitos/" 2>/dev/null

mv "03-proyecto-zemyna/ingenieria-software/requerimientos-funcionales.md" \
   "03-proyecto-zemyna/ingenieria-software/requisitos/" 2>/dev/null

mv "03-proyecto-zemyna/ingenieria-software/requerimientos-no-funcionales.md" \
   "03-proyecto-zemyna/ingenieria-software/requisitos/" 2>/dev/null

mv "03-proyecto-zemyna/ingenieria-software/reglas-negocio.md" \
   "03-proyecto-zemyna/ingenieria-software/requisitos/" 2>/dev/null

mv "03-proyecto-zemyna/ingenieria-software/casos-de-uso.md" \
   "03-proyecto-zemyna/ingenieria-software/casos-de-uso/" 2>/dev/null

echo "Moviendo testing..."

mv testing/* 03-proyecto-zemyna/testing/ 2>/dev/null

echo "Renombrando database -> base-datos..."

if [ -d "03-proyecto-zemyna/programacion/database" ]; then
    mv "03-proyecto-zemyna/programacion/database" \
       "03-proyecto-zemyna/programacion/base-datos"
fi

echo "Eliminando carpeta temporal de docs si quedó vacía..."

rmdir 04-docs 2>/dev/null
rmdir testing 2>/dev/null

echo "Reorganización completada."