# Pekokis Asistencia

Sistema para que el personal del taller de Pekokis registre sus entradas y salidas desde su propio celular. Cada marca se confirma con la passkey del teléfono (huella, rostro o PIN) y exige estar en el taller: conectado a su WiFi o escaneando el QR de la pantalla de la entrada.

- **Plan técnico:** [docs/plan-tecnico.md](docs/plan-tecnico.md)
- **Tareas y bitácora:** [TAREAS.md](TAREAS.md)
- **Despliegue:** [docs/despliegue-hostinger.md](docs/despliegue-hostinger.md)

Estado: MVP funcional, listo para el piloto en Hostinger (`asistencia.pekokis.com`).

## Desarrollo

```bash
composer install            # librerías y PHPUnit
composer test               # pruebas del núcleo
php scripts/empaquetar.php  # zip listo para subir en dist/
```

La prueba de punta a punta (`tests/integracion/flujo-marcas.php`) corre con WP-CLI contra un WordPress local y vacía las tablas del plugin.
