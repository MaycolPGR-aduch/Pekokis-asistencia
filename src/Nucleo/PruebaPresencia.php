<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Qué demostró que el trabajador estaba en el taller. Se guarda en cada marca.
 */
enum PruebaPresencia: string {
	case WIFI          = 'wifi';
	case QR            = 'qr';
	case WIFI_QR       = 'wifi+qr';
	case SIN_PRESENCIA = 'sin_presencia';
	case MANUAL        = 'manual';
}
