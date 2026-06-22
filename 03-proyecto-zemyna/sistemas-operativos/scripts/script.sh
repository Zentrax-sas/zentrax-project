#!/bin/bash

# =====================================================================
# ZEMYNA - Script de Automatización de Infraestructura (Zentrax)
# =====================================================================

echo "===================================================="
echo " Iniciando verificación y despliegue del entorno     "
echo "===================================================="

# FUNCIÓN REUTILIZABLE PARA VERIFICAR E INSTALAR
# Así evitamos repetir el mismo bloque de código para cada programa
verificar_e_instalar() {
    PAQUETE=$1
    echo "Verificando estado de: $PAQUETE..."

    # dpkg -l busca el paquete. Si existe, el comando devuelve un "éxito" (estado 0)
    if dpkg -l | grep -q "^ii  $PAQUETE "; then
        echo "--> [OK] $PAQUETE ya está instalado en este Lubuntu. Omitiendo..."
    else
        echo "--> [AVISO] $PAQUETE no se encontró. Instalando..."
        sudo apt-get install "$PAQUETE" -y
    fi
    echo "----------------------------------------------------"
}

# 1. Asegurarnos de que los repositorios estén actualizados al menos una vez
echo "Actualizando el índice de repositorios de Lubuntu..."
sudo apt-get update -y
echo "----------------------------------------------------"

# 2. Pasar la lista de software que necesita Zemyna
verificar_e_instalar "apache2"
verificar_e_instalar "mysql-server"
verificar_e_instalar "php"
verificar_e_instalar "php-mysql"

# 3. Asegurar que los servicios queden encendidos y habilitados
echo "Garantizando que los servicios estén activos..."
sudo systemctl enable apache2
sudo systemctl start apache2

sudo systemctl enable mysql
sudo systemctl start mysql

echo "===================================================="
echo " Proceso de infraestructura finalizado con éxito!    "
echo "===================================================="