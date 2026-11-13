<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Solo hay dos tipos de marca. Toda entrada que llega después de una salida es un regreso.
 */
enum TipoMarca: string {
	case ENTRADA = 'entrada';
	case SALIDA  = 'salida';
}
